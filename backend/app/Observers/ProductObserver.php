<?php

namespace App\Observers;

use App\Jobs\SyncProductToQdrantJob;
use App\Models\Product;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ProductObserver
{
    public function created(Product $product): void
    {
        SyncProductToQdrantJob::dispatch($product);
    }

    public function updated(Product $product): void
    {
        SyncProductToQdrantJob::dispatch($product);
    }

    public function deleted(Product $product): void
    {
        $this->deleteFromQdrant($product);
    }

    private function deleteFromQdrant(Product $product): void
    {
        $qdrantUrl = rtrim((string) config('services.image_search.qdrant_url'), '/');
        $collection = (string) config('services.image_search.qdrant_collection', 'products');
        $timeout = (int) config('services.image_search.timeout', 60);

        try {
            Http::timeout($timeout)
                ->withHeaders($this->qdrantHeaders())
                ->delete($qdrantUrl . '/collections/' . $collection . '/points/delete', [
                    'points' => [$product->id],
                ]);
        } catch (\Throwable $e) {
            Log::error('ProductObserver: delete failed', [
                'product_id' => $product->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
