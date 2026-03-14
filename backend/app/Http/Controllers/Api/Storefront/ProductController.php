<?php

namespace App\Http\Controllers\Api\Storefront;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Services\ImageSearchService;
use Illuminate\Http\Request;
use CloudinaryLabs\CloudinaryLaravel\Facades\Cloudinary;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ProductController extends Controller
{
    /**
     * ១. សម្រាប់បញ្ចូលផលិតផលថ្មី (Store Function)
     * រក្សារូបភាពក្នុង Cloudinary, ទិន្នន័យក្នុង Neon, និង Vector ក្នុង Qdrant
     */
    public function store(Request $request, ImageSearchService $imageSearchService)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'price' => 'required|numeric',
            'category_id' => 'required|integer',
            'image' => 'required|image|max:2048', 
        ]);

        try {
            return DB::transaction(function () use ($request, $imageSearchService) {
                
                // បោះរូបភាពទៅ Cloudinary
                $cloudinaryFile = $request->file('image')->storeOnCloudinary('fitandsleek_products');
                $imageUrl = $cloudinaryFile->getSecurePath();

                // បញ្ចូលទៅ Neon
                $product = Product::create([
                    'name'        => $request->name,
                    'slug'        => Str::slug($request->name) . '-' . time(),
                    'price'       => $request->price,
                    'description' => $request->description,
                    'category_id' => $request->category_id,
                    'image_url'   => $imageUrl,
                    'is_active'   => true,
                ]);

                // Sync ទៅ Qdrant សម្រាប់ Scan Image
                $imageSearchService->indexProductImage(
                    $product->id, 
                    $request->file('image'), 
                    [
                        'name' => $product->name, 
                        'price' => (float)$product->price
                    ]
                );

                return response()->json([
                    'success' => true,
                    'message' => 'ផលិតផលត្រូវបានបញ្ចូល និងធ្វើ Index ជោគជ័យ!',
                    'data'    => $product
                ], 201);
            });
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'មានបញ្ហាក្នុងការបញ្ចូលផលិតផល៖ ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * ២. បង្ហាញបញ្ជីផលិតផល (Index Function)
     */
    public function index(Request $request)
    {
        $q = Product::query()->with(['category', 'brand', 'activeSale']);

        $this->applyParentCategoryFilter($q, $request);

        if ($request->filled('category')) {
            $categorySlug = (string) $request->input('category');
            $q->whereHas('category', function ($categoryQuery) use ($categorySlug) {
                $categoryQuery->where('slug', $categorySlug);
            });
        }

        if ($request->filled('category_id')) {
            $q->where('category_id', (int) $request->input('category_id'));
        }

        if ($request->filled('q')) {
            $term = $request->string('q')->toString();
            $q->where(function ($w) use ($term) {
                $w->where('name', 'LIKE', "%{$term}%")
                  ->orWhere('description', 'LIKE', "%{$term}%");
                
                if (strlen($term) >= 4) {
                    $chars = str_split(strtolower($term));
                    foreach ($chars as $i => $char) {
                        if ($i < strlen($term) - 1) {
                            $pattern = substr($term, 0, $i) . substr($term, $i + 1);
                            $w->orWhere('name', 'LIKE', "%{$pattern}%");
                        }
                    }
                }
            });
        }

        if ($request->filled('gender')) {
            $gender = strtoupper($request->string('gender'));
            $q->whereHas('category', fn ($c) => $c->where('gender', $gender));
        }

        if ($request->filled('brand_id')) {
            $q->where('brand_id', (int) $request->input('brand_id'));
        }

        if ($request->filled('brand_slug')) {
            $q->whereHas('brand', function ($b) use ($request) {
                $b->where('slug', $request->string('brand_slug'));
            });
        }

        $products = $q->orderByDesc('id')->paginate(12);

        return response()->json($products);
    }

    /**
     * ៣. បង្ហាញផលិតផលលម្អិត (Show Function)
     */
    public function show(string $slug)
    {
        $product = Product::with(['category', 'activeSale'])->where('slug', $slug)->firstOrFail();
        
        if ($product->activeSale) {
            $product->discount = [
                'type' => $product->activeSale->discount_type,
                'value' => $product->activeSale->discount_value,
                'original_price' => (float) $product->price,
                'sale_price' => (float) $product->activeSale->sale_price,
                'discount_percentage' => $this->calculateDiscountPercentage($product),
                'end_date' => $product->activeSale->end_date,
            ];
        }
        return response()->json($product);
    }

    /**
     * ៤. បង្ហាញផលិតផលដែលមានបញ្ចុះតម្លៃ (Discounts Function)
     */
    public function discounts(Request $request)
    {
        $query = Product::query()
            ->with(['category', 'activeSale'])
            ->whereHas('activeSale')
            ->where('is_active', true);

        if ($request->filled('category_id')) {
            $query->where('category_id', (int) $request->input('category_id'));
        }

        if ($request->filled('category_slug')) {
            $query->whereHas('category', function ($q) use ($request) {
                $q->where('slug', $request->string('category_slug'));
            });
        }

        // Search in discounts
        if ($request->filled('q')) {
            $term = $request->string('q')->toString();
            $query->where(function ($w) use ($term) {
                $w->where('name', 'LIKE', "%{$term}%")
                  ->orWhere('description', 'LIKE', "%{$term}%");
            });
        }

        $products = $query->paginate($request->get('per_page', 12));

        $products->getCollection()->transform(function ($product) {
            if ($product->activeSale) {
                $product->discount = [
                    'type' => $product->activeSale->discount_type,
                    'value' => $product->activeSale->discount_value,
                    'original_price' => (float) $product->price,
                    'sale_price' => (float) $product->activeSale->sale_price,
                    'discount_percentage' => $this->calculateDiscountPercentage($product),
                    'end_date' => $product->activeSale->end_date,
                ];
            }
            return $product;
        });

        return response()->json($products);
    }

    /**
     * Helpers
     */
    private function applyParentCategoryFilter($query, Request $request): void
    {
        if (!$request->filled('parent_category')) return;

        $parent = strtolower(trim((string) $request->input('parent_category')));
        $allowed = ['men' => 'men', 'women' => 'women', 'boys' => 'boys', 'girls' => 'girls'];

        if (!isset($allowed[$parent])) return;

        $prefix = $allowed[$parent];
        $query->whereHas('category', function ($categoryQuery) use ($prefix) {
            $categoryQuery->where(function ($nameQuery) use ($prefix) {
                $nameQuery->whereRaw('LOWER(name) = ?', [$prefix])
                          ->orWhereRaw('LOWER(name) LIKE ?', [$prefix.'-%']);
            });
        });
    }

    private function calculateDiscountPercentage($product)
    {
        if (!$product->activeSale) return 0;
        if ($product->activeSale->discount_type === 'percentage') {
            return (int) $product->activeSale->discount_value;
        }
        return round(($product->activeSale->discount_value / $product->price) * 100);
    }
}
