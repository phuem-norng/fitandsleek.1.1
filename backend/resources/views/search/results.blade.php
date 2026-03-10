@extends('layouts.app')

@section('content')
    <div class="max-w-4xl mx-auto px-4 py-8">
        <h1 class="text-2xl font-semibold mb-6">Search results</h1>

        <form action="{{ route('search') }}" method="get" class="mb-6 flex gap-2">
            <input type="text" name="q" value="{{ old('q', $query) }}" placeholder="Search products..."
                class="flex-1 border border-gray-300 rounded px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500" />
            <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700">Search</button>
        </form>

        @if($query === '')
            <p class="text-gray-600">Enter a query to search products.</p>
        @elseif($products->isEmpty())
            <p class="text-gray-600">No products found for "{{ $query }}".</p>
        @else
            <p class="text-gray-600 mb-4">Found {{ $products->count() }} results for "{{ $query }}"</p>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                @foreach($products as $product)
                    <div class="border rounded p-4 shadow-sm bg-white">
                        <div class="mb-2 font-semibold text-lg">{{ $product->name ?? 'Untitled product' }}</div>
                        @if(!empty($product->image_url))
                            <img src="{{ $product->image_url }}" alt="{{ $product->name ?? 'Product image' }}"
                                class="w-full h-48 object-cover rounded mb-2" />
                        @endif
                        @if(!empty($product->price))
                            <div class="text-blue-700 font-semibold mb-1">${{ number_format($product->price, 2) }}</div>
                        @endif
                        @if(!empty($product->description))
                            <p class="text-gray-700 text-sm line-clamp-3">{{ $product->description }}</p>
                        @endif
                    </div>
                @endforeach
            </div>
        @endif
    </div>
@endsection