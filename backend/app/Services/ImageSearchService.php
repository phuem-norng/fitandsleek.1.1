<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class ImageSearchService
{
    private string $qdrantUrl;
    private string $collection;
    private int $timeout;
    private int $vectorSize;
    private string $vectorProvider;
    private ?string $cloudflareAccountId;
    private ?string $cloudflareApiToken;
    private string $cloudflareModelPath;
    private ?string $qdrantApiKey;
    private ?string $huggingFaceApiToken;
    private string $huggingFaceModel;
    private ?string $cohereApiKey;
    private string $cohereModel;
    private ?string $groqApiKey;
    private string $groqImageModel;
    private ?float $scoreThreshold;
    private ?string $localClipEndpoint;
    private int $localClipVectorSize;
    private ?string $aiServiceKey;

    public function __construct()
    {
        $this->cloudflareAccountId = env('CLOUDFLARE_ACCOUNT_ID');
        $this->cloudflareApiToken = env('CLOUDFLARE_API_TOKEN');
        // Default to the 1024-dim visual model to match our Qdrant collection; override in .env if needed.
        $this->cloudflareModelPath = (string) env('CLOUDFLARE_MODEL_PATH', '@cf/baai/bge-visual-en-1.5');
        $this->huggingFaceApiToken = (string) config('services.image_search.huggingface_api_key');
        $this->huggingFaceModel = (string) config('services.image_search.huggingface_model', 'openai/clip-vit-base-patch32');
        $this->cohereApiKey = (string) config('services.image_search.cohere_api_key');
        $this->cohereModel = (string) config('services.image_search.cohere_model', 'embed-multilingual-v3.0');
        $this->groqApiKey = (string) config('services.image_search.groq_api_key');
        $this->groqImageModel = (string) config('services.image_search.groq_image_model', 'llava-v1.5-7b-4096-preview');
        $this->scoreThreshold = is_null(config('services.image_search.qdrant_score_threshold'))
            ? null
            : (float) config('services.image_search.qdrant_score_threshold');
        $this->localClipEndpoint = config('services.image_search.local_clip_endpoint');
        $this->localClipVectorSize = (int) config('services.image_search.local_clip_vector_size', 512);
        $this->aiServiceKey = config('services.image_search.ai_service_key');
        $this->vectorProvider = (string) env('VECTOR_PROVIDER', 'cohere');
        $this->qdrantUrl = rtrim((string) config('services.image_search.qdrant_url', 'http://localhost:6333'), '/');
        $this->qdrantApiKey = config('services.image_search.qdrant_api_key');
        $this->collection = (string) config('services.image_search.qdrant_collection', 'products_512');
        $this->vectorSize = (int) config('services.image_search.qdrant_vector_size', 512);
        $this->timeout = max(90, (int) config('services.image_search.timeout', 120));

        // Keep PHP alive while the vectorizer model loads on cold start.
        $bufferSeconds = 15; // small cushion for response handling
        $desiredLimit = $this->timeout + $bufferSeconds;
        $currentLimit = (int) ini_get('max_execution_time');

        // Some hosts ignore set_time_limit; also set ini_max_execution_time for redundancy.
        if ($currentLimit !== 0 && $currentLimit < $desiredLimit) {
            @ini_set('max_execution_time', (string) $desiredLimit);
            if (function_exists('set_time_limit')) {
                @set_time_limit($desiredLimit);
            }
        }

        // Extend socket timeout so curl won't abort earlier than our HTTP timeout.
        $socketTimeout = (int) ini_get('default_socket_timeout');
        if ($socketTimeout !== 0 && $socketTimeout < $this->timeout) {
            @ini_set('default_socket_timeout', (string) $this->timeout);
        }
    }

    public function vectorizeImage(UploadedFile $image): array
    {
        if (function_exists('set_time_limit')) {
            @set_time_limit(120);
        }

        $vector = [];

        // Local CLIP (image-to-image) path
        if ($this->vectorProvider === 'local_clip' && !empty($this->localClipEndpoint)) {
            $vector = $this->getVectorFromLocalClip($image);
        }

        // Cloudflare first (1024-dim visual model). This avoids 768-dim HF vectors when Qdrant expects 1024.
        if (empty($vector) && !empty($this->cloudflareAccountId) && !empty($this->cloudflareApiToken)) {
            $vector = $this->getVectorFromCloudflare($image);
        }

        // Optional HF fallback if Cloudflare is unavailable and HF is configured.
        if (empty($vector) && !empty($this->huggingFaceApiToken)) {
            $vector = $this->getVectorFromHuggingFace($image->getRealPath());
        }

        if (empty($vector)) {
            return [];
        }

        $vector = array_values(array_map(static fn($v) => (float) $v, $vector));

        $actualSize = count($vector);

        Log::info('ImageSearch: vector dimension check', [
            'expected' => $this->vectorSize,
            'received' => $actualSize,
            'source' => (!empty($this->cloudflareAccountId) && !empty($this->cloudflareApiToken)) ? 'cloudflare' : 'huggingface',
            'cloudflare_model' => $this->cloudflareModelPath,
        ]);

        if ($actualSize !== $this->vectorSize) {
            Log::error('ImageSearch: vector dimension mismatch', [
                'expected' => $this->vectorSize,
                'received' => $actualSize,
                'source' => (!empty($this->cloudflareAccountId) && !empty($this->cloudflareApiToken)) ? 'cloudflare' : 'huggingface',
                'cloudflare_model' => $this->cloudflareModelPath,
            ]);

            return [];
        }

        return $vector;
    }

    public function indexProductImage(int $productId, UploadedFile $image, array $metadata = []): void
    {
        $vector = $this->vectorizeImage($image);
        if (empty($vector)) {
            return;
        }
        $this->upsertVector($productId, $vector, $metadata);
    }

    public function upsertVector(int $productId, array $vector, array $metadata = []): void
    {
        $this->ensureCollectionExists();

        $safeMetadata = collect($metadata)
            ->filter(static fn($value) => is_scalar($value) || $value === null)
            ->toArray();

        $payload = [
            'points' => [
                [
                    'id' => $productId,
                    'vector' => $vector,
                    'payload' => array_merge($safeMetadata, [
                        'product_id' => $productId,
                    ]),
                ],
            ],
        ];

        try {
            $response = Http::withoutVerifying()
                ->timeout($this->timeout)
                ->withHeaders($this->qdrantHeaders())
                ->put($this->qdrantUrl . '/collections/' . $this->collection . '/points', $payload);
        } catch (ConnectionException $e) {
            throw new RuntimeException('Qdrant is unreachable: ' . $e->getMessage());
        }

        if ($response->failed()) {
            throw new RuntimeException('Qdrant upsert failed: ' . $response->status() . ' ' . $response->body());
        }
    }

    public function searchSimilarProductIds(UploadedFile $image, int $limit = 12): array
    {
        // Preferred path: image -> Groq vision -> text -> Cohere embedding -> Qdrant
        $description = null;

        if (!empty($this->groqApiKey)) {
            try {
                $description = $this->getDescriptionFromGroq($image);
            } catch (\Throwable $e) {
                Log::warning('ImageSearch: Groq vision failed, will fallback to image embeddings', [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // If using local CLIP provider, skip Groq/Cohere and use image embeddings directly
        if ($this->vectorProvider === 'local_clip' && !empty($this->localClipEndpoint)) {
            $vector = $this->vectorizeImage($image);
            return $this->searchWithVector($vector, $limit);
        }

        // Preferred path: image -> Groq vision -> text -> Cohere embedding -> Qdrant
        $description = null;

        if (!empty($this->groqApiKey)) {
            try {
                $description = $this->getDescriptionFromGroq($image);
            } catch (\Throwable $e) {
                Log::warning('ImageSearch: Groq vision failed, will fallback to image embeddings', [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if (is_string($description) && trim($description) !== '') {
            try {
                return $this->searchByText($description, $limit);
            } catch (\Throwable $e) {
                Log::warning('ImageSearch: text-based search fallback failed, will try image embeddings', [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Fallback: direct image embeddings (Cloudflare/HF) if Groq or Cohere path fails
        $vector = $this->vectorizeImage($image);
        return $this->searchWithVector($vector, $limit);
    }

    public function searchByText(string $query, int $limit = 12): array
    {
        if (function_exists('set_time_limit')) {
            @set_time_limit(60);
        }

        $vector = $this->getVectorFromCohereText($query);
        if (empty($vector)) {
            return [];
        }

        $this->ensureCollectionExists();

        try {
            $payload = [
                'vector' => $vector,
                'limit' => $limit,
                'with_payload' => true,
                'with_vector' => false,
            ];

            if ($this->scoreThreshold !== null) {
                $payload['score_threshold'] = $this->scoreThreshold;
            }

            $response = Http::withoutVerifying()
                ->timeout($this->timeout)
                ->withHeaders($this->qdrantHeaders())
                ->post($this->qdrantUrl . '/collections/' . $this->collection . '/points/search', $payload);
        } catch (ConnectionException $e) {
            throw new RuntimeException('Qdrant search failed: ' . $e->getMessage());
        }

        if ($response->failed()) {
            throw new RuntimeException('Qdrant search failed: ' . $response->status() . ' ' . $response->body());
        }

        $results = $response->json('result', []);

        return collect($results)
            ->map(function ($item) {
                $payloadId = data_get($item, 'payload.product_id');
                if (is_numeric($payloadId)) {
                    return (int) $payloadId;
                }

                $pointId = data_get($item, 'id');
                return is_numeric($pointId) ? (int) $pointId : null;
            })
            ->filter(static fn($id) => $id !== null)
            ->unique()
            ->values()
            ->all();
    }

    private function searchWithVector(array $vector, int $limit): array
    {
        if (empty($vector)) {
            return [];
        }

        $this->ensureCollectionExists();

        try {
            $payload = [
                'vector' => $vector,
                'limit' => $limit,
                'with_payload' => true,
                'with_vector' => false,
            ];

            if ($this->scoreThreshold !== null) {
                $payload['score_threshold'] = $this->scoreThreshold;
            }

            $response = Http::withoutVerifying()
                ->timeout($this->timeout)
                ->withHeaders($this->qdrantHeaders())
                ->post($this->qdrantUrl . '/collections/' . $this->collection . '/points/search', $payload);
        } catch (ConnectionException $e) {
            throw new RuntimeException('Qdrant search failed: ' . $e->getMessage());
        }

        if ($response->failed()) {
            throw new RuntimeException('Qdrant search failed: ' . $response->status() . ' ' . $response->body());
        }

        $results = $response->json('result', []);

        return collect($results)
            ->map(function ($item) {
                $payloadId = data_get($item, 'payload.product_id');
                if (is_numeric($payloadId)) {
                    return (int) $payloadId;
                }

                $pointId = data_get($item, 'id');
                return is_numeric($pointId) ? (int) $pointId : null;
            })
            ->filter(static fn($id) => $id !== null)
            ->unique()
            ->values()
            ->all();
    }

    private function ensureCollectionExists(): void
    {
        try {
            $check = Http::withoutVerifying()
                ->timeout($this->timeout)
                ->withHeaders($this->qdrantHeaders())
                ->get($this->qdrantUrl . '/collections/' . $this->collection);
        } catch (ConnectionException $e) {
            throw new RuntimeException('Qdrant is unreachable: ' . $e->getMessage());
        }

        if ($check->successful()) {
            $existingSize = data_get($check->json(), 'result.config.params.vectors.size')
                ?? data_get($check->json(), 'result.vectors.size');

            if ($existingSize !== null && (int) $existingSize !== $this->vectorSize) {
                throw new RuntimeException(
                    "Qdrant collection '{$this->collection}' exists with vector size {$existingSize}, expected {$this->vectorSize}."
                );
            }

            return;
        }

        if ($check->status() !== 404) {
            throw new RuntimeException('Failed to verify Qdrant collection: ' . $check->status() . ' ' . $check->body());
        }

        try {
            $create = Http::withoutVerifying()
                ->timeout($this->timeout)
                ->withHeaders($this->qdrantHeaders())
                ->put($this->qdrantUrl . '/collections/' . $this->collection, [
                    'vectors' => [
                        'size' => $this->vectorSize,
                        'distance' => 'Cosine',
                    ],
                ]);
        } catch (ConnectionException $e) {
            throw new RuntimeException('Failed to create Qdrant collection: ' . $e->getMessage());
        }

        if ($create->failed()) {
            throw new RuntimeException('Failed to create Qdrant collection: ' . $create->status() . ' ' . $create->body());
        }
    }

    private function extractVector($data): array
    {
        if (!is_array($data)) {
            throw new RuntimeException('Unexpected response format from Cloudflare AI.');
        }

        // Responses can be a flat array or wrapped. Normalize to a flat array.
        if (isset($data[0]) && is_array($data[0]) && count($data) === 1) {
            $data = $data[0];
        }

        if (!is_array($data)) {
            throw new RuntimeException('Invalid vector data from Cloudflare AI.');
        }

        return array_values($data);
    }

    private function qdrantHeaders(): array
    {
        $headers = [];

        if ($this->qdrantApiKey) {
            $headers['api-key'] = $this->qdrantApiKey;
        }

        return $headers;
    }

    private function getVectorFromCohereText(string $text): array
    {
        $apiKey = $this->cohereApiKey;
        $model = $this->cohereModel;

        if (empty($apiKey)) {
            throw new RuntimeException('Cohere API key is missing.');
        }

        $payload = [
            'model' => $model,
            'texts' => [$text],
            'input_type' => 'search_query',
        ];

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $apiKey,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ])
                ->withoutVerifying()
                ->timeout($this->timeout)
                ->post('https://api.cohere.com/v1/embed', $payload);
        } catch (\Exception $e) {
            throw new RuntimeException('Cohere request failed: ' . $e->getMessage());
        }

        if ($response->failed()) {
            throw new RuntimeException('Cohere API error: ' . $response->status() . ' ' . $response->body());
        }

        $data = $response->json();
        $embeddings = $data['embeddings'] ?? null;

        if (!is_array($embeddings) || empty($embeddings[0]) || !is_array($embeddings[0])) {
            throw new RuntimeException('Cohere API error: missing embeddings in response.');
        }

        $vector = array_values(array_map(static fn($v) => (float) $v, $embeddings[0]));

        $actualSize = count($vector);
        if ($actualSize !== $this->vectorSize) {
            throw new RuntimeException("Cohere vector dimension mismatch: expected {$this->vectorSize}, got {$actualSize}.");
        }

        return $vector;
    }

    private function getDescriptionFromGroq(UploadedFile $image): string
    {
        if (empty($this->groqApiKey)) {
            throw new RuntimeException('Groq API key is missing.');
        }

        $binary = file_get_contents($image->getRealPath());
        if ($binary === false) {
            throw new RuntimeException('Unable to read image for Groq vision.');
        }

        try {
            $binary = $this->toJpegBinary($binary);
        } catch (\Throwable $e) {
            throw new RuntimeException('Groq vision: image normalization failed: ' . $e->getMessage());
        }

        $dataUri = 'data:image/jpeg;base64,' . base64_encode($binary);

        try {
            $response = Http::withToken($this->groqApiKey)
                ->withoutVerifying()
                ->acceptJson()
                ->timeout(min($this->timeout, 60))
                ->post('https://api.groq.com/openai/v1/chat/completions', [
                    'model' => $this->groqImageModel,
                    'max_tokens' => 120,
                    'temperature' => 0.2,
                    'messages' => [
                        [
                            'role' => 'user',
                            'content' => [
                                [
                                    'type' => 'text',
                                    'text' => 'Describe this product briefly in English for search embedding. Focus on item type, brand (if visible), color, and key attributes. Limit to under 60 words.',
                                ],
                                [
                                    'type' => 'image_url',
                                    'image_url' => ['url' => $dataUri],
                                ],
                            ],
                        ]
                    ],
                ]);
        } catch (\Exception $e) {
            throw new RuntimeException('Groq vision request failed: ' . $e->getMessage());
        }

        if ($response->failed()) {
            throw new RuntimeException('Groq vision error: ' . $response->status() . ' ' . $response->body());
        }

        $description = data_get($response->json(), 'choices.0.message.content');

        if (!is_string($description) || trim($description) === '') {
            throw new RuntimeException('Groq vision returned an empty description.');
        }

        Log::info('Groq vision caption', ['caption' => trim($description)]);

        return trim($description);
    }

    private function getVectorFromLocalClip(UploadedFile $imageFile): array
    {
        if (empty($this->localClipEndpoint)) {
            return [];
        }

        $binary = file_get_contents($imageFile->getRealPath());
        if ($binary === false) {
            Log::error('Local CLIP: unable to read image file', ['path' => $imageFile->getRealPath()]);
            return [];
        }

        try {
            $response = Http::withHeaders($this->localClipHeaders())
                ->withoutVerifying()
                ->timeout(min($this->timeout, 60))
                ->attach('file', $binary, $imageFile->getClientOriginalName() ?: 'image.jpg')
                ->post($this->localClipEndpoint);
        } catch (\Exception $e) {
            Log::error('Local CLIP request failed', ['error' => $e->getMessage()]);
            return [];
        }

        if ($response->failed()) {
            Log::error('Local CLIP API error', ['status' => $response->status(), 'body' => $response->body()]);
            return [];
        }

        $vector = $response->json('embedding');

        if (!is_array($vector)) {
            Log::error('Local CLIP: missing embedding in response');
            return [];
        }

        $vector = array_values(array_map(static fn($v) => (float) $v, $vector));

        $actualSize = count($vector);
        if ($actualSize !== $this->vectorSize) {
            Log::error('Local CLIP vector dimension mismatch', ['expected' => $this->vectorSize, 'received' => $actualSize]);
            return [];
        }

        return $vector;
    }

    private function getVectorFromCloudflare(UploadedFile $imageFile): ?array
    {
        $accountId = env('CLOUDFLARE_ACCOUNT_ID');
        $apiToken = env('CLOUDFLARE_API_TOKEN');

        // Use Cloudflare embedding model (default visual 1024 dims, override via CLOUDFLARE_MODEL_PATH)
        $endpoint = "https://api.cloudflare.com/client/v4/accounts/{$accountId}/ai/run/" . ltrim($this->cloudflareModelPath, '/');

        $binary = file_get_contents($imageFile->getRealPath());
        if ($binary === false) {
            Log::error('Cloudflare AI: unable to read image file', ['path' => $imageFile->getRealPath()]);
            return [];
        }

        // Normalize to JPEG to avoid unsupported formats
        try {
            $binary = $this->toJpegBinary($binary);
        } catch (\Throwable $e) {
            Log::error('Cloudflare AI: image normalization failed', ['path' => $imageFile->getRealPath(), 'error' => $e->getMessage()]);
            return [];
        }

        // Workers AI expects JSON with a base64-encoded image data URI for visual embeddings.
        $dataUri = 'data:image/jpeg;base64,' . base64_encode($binary);

        try {
            $response = Http::withToken($apiToken)
                ->timeout(60)
                ->withoutVerifying()
                ->acceptJson()
                ->asJson()
                ->post($endpoint, ['image' => $dataUri]);
        } catch (\Exception $e) {
            Log::error('Cloudflare AI Exception', ['error' => $e->getMessage()]);
            return [];
        }

        $data = $response->json();

        if ($response->successful()) {
            // Visual models return image_embedding; some text models return data arrays.
            if (isset($data['result']['image_embedding'])) {
                return $data['result']['image_embedding'];
            }

            if (isset($data['result']['data'])) {
                try {
                    return $this->extractVector($data['result']['data']);
                } catch (RuntimeException $e) {
                    Log::error('Cloudflare: unable to extract vector from data', ['error' => $e->getMessage()]);
                    return [];
                }
            }
        }

        Log::error('Cloudflare API Error', [
            'status' => $response->status(),
            'body' => $data,
            'model' => $this->cloudflareModelPath,
        ]);
        return [];
    }

    private function getVectorFromHuggingFace(string $imagePath): array
    {
        $token = $this->huggingFaceApiToken;
        $model = $this->huggingFaceModel;
        $customEndpoint = (string) config('services.image_search.huggingface_endpoint_url');

        if (empty($token)) {
            return [];
        }

        $binary = file_get_contents($imagePath);
        if ($binary === false) {
            Log::error('Hugging Face: unable to read image file', ['path' => $imagePath]);
            return [];
        }

        try {
            $binary = $this->toJpegBinary($binary);
        } catch (\Throwable $e) {
            Log::error('Hugging Face: image normalization failed', ['error' => $e->getMessage()]);
            return [];
        }

        // Prefer a custom endpoint when provided; otherwise use the serverless router.
        $endpoint = $customEndpoint ?: 'https://router.huggingface.co/models/' . ltrim($model, '/');

        $verifyPath = env('HUGGINGFACE_CA_BUNDLE', env('GEMINI_CA_BUNDLE'));

        $dataUri = 'data:image/jpeg;base64,' . base64_encode($binary);

        try {
            $client = Http::withToken($token)
                ->timeout(60)
                ->acceptJson()
                ->asJson();

            if (!empty($verifyPath) && file_exists($verifyPath)) {
                $client = $client->withOptions(['verify' => $verifyPath]);
            } else {
                $client = $client->withoutVerifying();
            }

            $response = $client
                ->post($endpoint, [
                    'inputs' => [
                        'image' => $dataUri,
                    ],
                ]);
        } catch (\Exception $e) {
            Log::error('Hugging Face API exception', ['error' => $e->getMessage()]);
            return [];
        }

        $data = $response->json();

        if ($response->successful() && is_array($data)) {
            try {
                return $this->extractVector($data);
            } catch (RuntimeException $e) {
                Log::error('Hugging Face: unable to extract vector', ['error' => $e->getMessage()]);
                return [];
            }
        }

        Log::error('Hugging Face API error', ['status' => $response->status(), 'body' => $data]);
        return [];
    }

    private function localClipHeaders(): array
    {
        $headers = [];
        if (!empty($this->aiServiceKey)) {
            $headers['x-api-key'] = $this->aiServiceKey;
        }
        return $headers;
    }

    private function toJpegBinary(string $binary): string
    {
        $image = @imagecreatefromstring($binary);
        if ($image === false) {
            throw new RuntimeException('Invalid image data: cannot decode.');
        }

        $width = imagesx($image);
        $height = imagesy($image);

        // Downscale very large images to reduce payload and avoid model limits.
        $maxDim = 1600;
        if ($width > $maxDim || $height > $maxDim) {
            $scale = min($maxDim / $width, $maxDim / $height);
            $newWidth = (int) max(1, round($width * $scale));
            $newHeight = (int) max(1, round($height * $scale));

            $resized = imagecreatetruecolor($newWidth, $newHeight);
            imagecopyresampled($resized, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
            imagedestroy($image);
            $image = $resized;
        }

        ob_start();
        imagejpeg($image, null, 90);
        imagedestroy($image);
        $jpeg = ob_get_clean();

        if ($jpeg === false || $jpeg === '') {
            throw new RuntimeException('Failed to encode image to JPEG.');
        }

        return $jpeg;
    }
}
