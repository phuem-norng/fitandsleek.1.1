<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class QdrantSetupCommand extends Command
{
    protected $signature = 'qdrant:setup';

    protected $description = 'Initialize Qdrant products collection with configured vector size';

    public function handle(): int
    {
        $qdrantUrl = rtrim((string) config('services.image_search.qdrant_url', 'http://localhost:6333'), '/');
        $collection = (string) config('services.image_search.qdrant_collection', 'products_cohere_1024');
        $vectorSize = (int) config('services.image_search.qdrant_vector_size', 1024);
        $headers = [];

        $apiKey = config('services.image_search.qdrant_api_key');
        if ($apiKey) {
            $headers['api-key'] = $apiKey;
        }

        $this->info("Checking Qdrant collection '{$collection}' at {$qdrantUrl}...");

        try {
            $checkResponse = Http::withoutVerifying()
                ->timeout(20)
                ->withHeaders($headers)
                ->get("{$qdrantUrl}/collections/{$collection}");
        } catch (ConnectionException $e) {
            $this->error('Unable to connect to Qdrant: ' . $e->getMessage());
            return self::FAILURE;
        }

        if ($checkResponse->successful()) {
            $existingSize = data_get($checkResponse->json(), 'result.config.params.vectors.size')
                ?? data_get($checkResponse->json(), 'result.vectors.size');

            if ($existingSize !== null && (int) $existingSize !== $vectorSize) {
                $this->error("Collection exists with size {$existingSize}, expected {$vectorSize}. Please recreate it.");
                return self::FAILURE;
            }

            $sizeText = $existingSize ?? 'unknown';
            $this->info("Collection '{$collection}' already exists with size {$sizeText}. Nothing to do.");
            return self::SUCCESS;
        }

        if ($checkResponse->status() !== 404) {
            $this->error('Failed to verify collection: ' . $checkResponse->status() . ' ' . $checkResponse->body());
            return self::FAILURE;
        }

        $this->warn("Collection '{$collection}' not found. Creating...");

        try {
            $createResponse = Http::withoutVerifying()
                ->timeout(20)
                ->withHeaders($headers)
                ->put("{$qdrantUrl}/collections/{$collection}", [
                    'vectors' => [
                        'size' => $vectorSize,
                        'distance' => 'Cosine',
                    ],
                ]);
        } catch (ConnectionException $e) {
            $this->error('Unable to create collection: ' . $e->getMessage());
            return self::FAILURE;
        }

        if ($createResponse->failed()) {
            $this->error('Create collection failed: ' . $createResponse->status() . ' ' . $createResponse->body());
            return self::FAILURE;
        }

        $this->info("Collection '{$collection}' created successfully (size: {$vectorSize}, distance: Cosine).");

        return self::SUCCESS;
    }
}
