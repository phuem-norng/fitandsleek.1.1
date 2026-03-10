<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Services\ImageSearchService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SearchController extends Controller
{
    private ImageSearchService $searchService;

    public function __construct(ImageSearchService $searchService)
    {
        $this->searchService = $searchService;
    }

    public function search(Request $request): View
    {
        $query = (string) $request->query('q', '');
        $productIds = [];
        $products = collect();

        if ($query !== '') {
            try {
                $productIds = $this->searchService->searchByText($query, 10);

                if (!empty($productIds)) {
                    // Preserve Qdrant ranking order
                    $orderList = implode(',', $productIds);
                    $products = Product::whereIn('id', $productIds)
                        ->orderByRaw("FIELD(id, {$orderList})")
                        ->get();
                }
            } catch (\Throwable $e) {
                // You may log the error or show a friendly message; for now we keep it simple.
                $products = collect();
            }
        }

        return view('search.results', [
            'products' => $products,
            'query' => $query,
        ]);
    }
}
