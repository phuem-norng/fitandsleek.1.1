<?php

namespace App\Jobs;

use App\Models\Product;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class SyncProductToQdrantJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public Product $product;

    public int $tries = 3;

    public function backoff(): array
    {
        return [10, 30, 60];
    }

    public function __construct(Product $product)
    {
        $this->product = $product;
    }

    public function handle(): void
    {
        $product = $this->product->fresh();
        if (!$product) {
            return;
        }

        $imagePath = $this->resolveImagePath($product->image_url);
        if (!$imagePath) {
            $this->markVectorStatus($product, false, 'Image file not found');
            Log::warning('SyncProductToQdrantJob: image file not found', ['product_id' => $product->id, 'image_url' => $product->image_url]);
            return;
        }

        try {
            $vector = $this->vectorizeImage($imagePath);
            $this->ensureCollectionExists();
            $this->upsertVector($product, $vector);
            $this->markVectorStatus($product, true, null);
        } catch (Throwable $e) {
            $this->markVectorStatus($product, false, $e->getMessage());
            Log::error('SyncProductToQdrantJob: sync failed', [
                'product_id' => $product->id,
                'error' => $e->getMessage(),
            ]);
            throw $e; // rethrow so queue can retry
        }
    }

    private function resolveImagePath(?string $imageUrl): ?string
    {
        if (!$imageUrl) {
            return null;
        }

        $candidates = [
            $imageUrl,
            public_path($imageUrl),
            storage_path('app/public/' . ltrim($imageUrl, '/')),
            storage_path(ltrim($imageUrl, '/')),
        ];

        foreach ($candidates as $path) {
            if ($path && is_file($path)) {
                return $path;
            }
        }

        return null;
    }

    private function vectorizeImage(string $path): array
    {
        $apiKey = config('services.image_search.huggingface_api_key');
        $model = (string) config('services.image_search.huggingface_model', 'openai/clip-vit-base-patch32');
        $timeout = max(60, (int) config('services.image_search.timeout', 60));
        $vectorSize = (int) config('services.image_search.qdrant_vector_size', 512);
        $customEndpoint = (string) config('services.image_search.huggingface_endpoint_url');

        if (!$apiKey) {
            throw new RuntimeException('Hugging Face API key is missing.');
        }

        $endpoint = $customEndpoint ?: 'https://router.huggingface.co/models/' . ltrim($model, '/');

        $binary = file_get_contents($path);

        if ($binary === false) {
            throw new RuntimeException('Unable to read image file for vectorization.');
        }

        $dataUri = 'data:image/jpeg;base64,' . base64_encode($binary);

        $response = Http::withoutVerifying()
            ->timeout($timeout)
            ->withHeaders([
                'Authorization' => 'Bearer ' . $apiKey,
                'Accept' => 'application/json',
            ])
            ->post($endpoint, [
                'inputs' => [
                    'image' => $dataUri,
                ],
            ]);

        if ($response->failed()) {
            throw new RuntimeException('Hugging Face error: ' . $response->status() . ' ' . $response->body());
        }

        $data = $response->json();
        $vector = $this->extractVector($data);

        if (count($vector) !== $vectorSize) {
            throw new RuntimeException('Hugging Face returned invalid vector size.');
        }

        return array_map(static fn($v) => (float) $v, $vector);
    }

    private function extractVector($data): array
    {
        if (!is_array($data)) {
            throw new RuntimeException('Unexpected response format from Hugging Face.');
        }

        // Responses can be [512 floats] or [[512 floats]]; normalize.
        if (isset($data[0]) && is_array($data[0]) && count($data) === 1) {
            $data = $data[0];
        }

        if (!is_array($data)) {
            throw new RuntimeException('Invalid vector data from Hugging Face.');
        }

        return array_values($data);
    }

    private function ensureCollectionExists(): void
    {
        $qdrantUrl = rtrim((string) config('services.image_search.qdrant_url'), '/');
        $collection = (string) config('services.image_search.qdrant_collection', 'products');
        $vectorSize = (int) config('services.image_search.qdrant_vector_size', 512);
        $timeout = max(60, (int) config('services.image_search.timeout', 60));

        $check = Http::withoutVerifying()
            ->timeout($timeout)
            ->withHeaders($this->qdrantHeaders())
            ->get($qdrantUrl . '/collections/' . $collection);

        if ($check->successful()) {
            return;
        }

        if ($check->status() !== 404) {
            throw new RuntimeException('Qdrant collection check failed: ' . $check->status() . ' ' . $check->body());
        }

        $create = Http::withoutVerifying()
            ->timeout($timeout)
            ->withHeaders($this->qdrantHeaders())
            ->put($qdrantUrl . '/collections/' . $collection, [
                'vectors' => [
                    'size' => $vectorSize,
                    'distance' => 'Cosine',
                ],
            ]);

        if ($create->failed()) {
            throw new RuntimeException('Qdrant collection create failed: ' . $create->status() . ' ' . $create->body());
        }
    }

    private function upsertVector(Product $product, array $vector): void
    {
        $qdrantUrl = rtrim((string) config('services.image_search.qdrant_url'), '/');
        $collection = (string) config('services.image_search.qdrant_collection', 'products');
        $timeout = max(60, (int) config('services.image_search.timeout', 60));

        $payload = [
            'points' => [
                [
                    'id' => $product->id,
                    'vector' => $vector,
                    'payload' => [
                        'product_id' => $product->id,
                        'name' => $product->name,
                        'price' => (float) $product->price,
                        'image_url' => $product->image_url,
                    ],
                ],
            ],
        ];

        $response = Http::withoutVerifying()
            ->timeout($timeout)
            ->withHeaders($this->qdrantHeaders())
            ->put($qdrantUrl . '/collections/' . $collection . '/points', $payload);

        if ($response->failed()) {
            throw new RuntimeException('Qdrant upsert failed: ' . $response->status() . ' ' . $response->body());
        }
    }

    private function qdrantHeaders(): array
    {
        $headers = [];
        $apiKey = config('services.image_search.qdrant_api_key');

        if ($apiKey) {
            $headers['api-key'] = $apiKey;
        }

        return $headers;
    }

    private function markVectorStatus(Product $product, bool $isIndexed, ?string $error): void
    {
        if (!$product->isFillable('is_vector_indexed')) {
            return;
        }

        $product->forceFill([
            'is_vector_indexed' => $isIndexed,
            'vector_indexed_at' => $isIndexed ? Date::now() : null,
            'vector_index_error' => $error,
        ])->saveQuietly();
    }
}
