<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Services\ImageSearchService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class ImageSearchController extends Controller
{
    public function search(Request $request, ImageSearchService $imageSearchService)
    {
        // Keep request alive during cold starts / model warmup (up to 3 minutes)
        @set_time_limit(180);

        Log::info('ImageSearchController: request received', [
            'has_file' => $request->hasFile('image'),
            'limit' => $request->input('limit'),
        ]);

        $validated = $request->validate([
            'image' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:50'],
            'mock' => ['sometimes', 'boolean'],
        ]);

        Log::info('ImageSearchController: validation passed');

        $image = $request->file('image');
        $limit = (int) ($validated['limit'] ?? 10);

        if (!$image || !$image->isValid()) {
            return response()->json([
                'message' => 'Image file is missing or invalid.',
            ], 422);
        }

        // Optional mock mode to test frontend without hitting HF/Qdrant
        if ($request->boolean('mock')) {
            return response()->json([
                'match_reason' => 'mock',
                'products' => [
                    [
                        'id' => 1,
                        'slug' => 'mock-product',
                        'name' => 'Mock Product',
                        'description' => 'Mock response for frontend testing.',
                        'price' => 19.99,
                        'final_price' => 19.99,
                        'discount_price' => null,
                        'discount_percentage' => null,
                        'has_discount' => false,
                        'image_url' => '/images/mock.jpg',
                        'similarity_rank' => 1,
                    ],
                ],
                'total' => 1,
            ]);
        }

        try {
            Log::info('ImageSearchController: starting search', ['limit' => $limit, 'filename' => $image->getClientOriginalName()]);
            $productIds = $imageSearchService->searchSimilarProductIds($image, $limit);

            Log::info('ImageSearchController: search completed', ['count' => count($productIds)]);
        } catch (Throwable $e) {
            Log::error('ImageSearch failed', [
                'error' => $e->getMessage(),
                'code' => $e->getCode(),
            ]);

            return response()->json([
                'message' => 'Image similarity search failed.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 503);
        }

        if (empty($productIds)) {
            return response()->json([
                'products' => [],
                'total' => 0,
                'match_reason' => 'qdrant_similarity',
            ]);
        }

        $products = Product::query()
            ->whereIn('id', $productIds)
            ->where('is_active', true)
            ->get();

        $rankedProducts = collect($productIds)
            ->map(function (int $id, int $index) use ($products) {
                $product = $products->firstWhere('id', $id);
                if (!$product) {
                    return null;
                }

                return [
                    'id' => $product->id,
                    'slug' => $product->slug,
                    'name' => $product->name,
                    'description' => $product->description,
                    'price' => (float) $product->price,
                    'final_price' => (float) ($product->final_price ?? $product->price),
                    'discount_price' => $product->discount_price,
                    'discount_percentage' => $product->discount_percentage,
                    'has_discount' => (bool) $product->has_discount,
                    'image_url' => $product->image_url,
                    'similarity_rank' => $index + 1,
                ];
            })
            ->filter()
            ->values();

        return response()->json([
            'match_reason' => 'qdrant_similarity',
            'products' => $rankedProducts,
            'total' => $rankedProducts->count(),
        ]);
    }
}
