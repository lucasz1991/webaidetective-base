@props(['product'])

<div class="mt-4 text-right">
    @if ($product->discount > 0)
        <p class="text-sm text-gray-500 line-through">
            € {{ number_format($product->price, 2, ',', '.') }}
        </p>
        <h4 class="text-md text-red-500 font-bold">
            € {{ number_format($product->discount_price, 2, ',', '.') }}
            
        </h4>
    @else
        <h4 class="text-lg text-gray-800 font-bold">
            € {{ number_format($product->price, 2, ',', '.') }}
        </h4>
    @endif
</div>
