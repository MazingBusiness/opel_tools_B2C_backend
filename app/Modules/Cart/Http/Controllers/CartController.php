<?php

namespace App\Modules\Cart\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Cart\Http\Requests\StoreCartItemRequest;
use App\Modules\Cart\Http\Requests\UpdateCartItemRequest;
use App\Modules\Cart\Http\Resources\CartItemResource;
use App\Modules\Cart\Models\CartItem;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class CartController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $items = $this->cartQuery($request)->get();

        return CartItemResource::collection($items)
            ->additional(['meta' => $this->totalsMeta($items)])
            ->response();
    }

    public function count(Request $request): JsonResponse
    {
        $items = $this->cartQuery($request)->get();
        $meta = $this->totalsMeta($items);

        return response()->json([
            'data' => [
                'item_count' => $meta['item_count'],
                'line_count' => $meta['line_count'],
            ],
        ]);
    }

    public function store(StoreCartItemRequest $request): JsonResponse
    {
        $userId = (int) $request->user()->id;
        $productId = (int) $request->validated('product_id');
        $qty = (int) ($request->validated('qty') ?? 1);
        $variantId = $request->validated('variant_id');

        $variant = $this->resolveVariant($productId, $variantId !== null ? (int) $variantId : null);
        $this->assertPurchasable($variant, $qty);

        try {
            $item = CartItem::query()->firstOrCreate(
                [
                    'user_id' => $userId,
                    'product_id' => $productId,
                    'variant_id' => $variant->id,
                ],
                [
                    'qty' => $qty,
                ],
            );
        } catch (QueryException $exception) {
            if (! $this->isUniqueConstraintViolation($exception)) {
                throw $exception;
            }
            $item = CartItem::query()
                ->where('user_id', $userId)
                ->where('product_id', $productId)
                ->where('variant_id', $variant->id)
                ->firstOrFail();
        }

        $created = $item->wasRecentlyCreated;
        if (! $created) {
            $nextQty = min(99, $item->qty + $qty);
            $this->assertPurchasable($variant, $nextQty);
            $item->qty = $nextQty;
            $item->save();
        }

        $item->load($this->eagerLoads());
        $items = $this->cartQuery($request)->get();

        return (new CartItemResource($item))
            ->additional([
                'meta' => array_merge($this->totalsMeta($items), [
                    'created' => $created,
                ]),
            ])
            ->response()
            ->setStatusCode($created ? 201 : 200);
    }

    public function update(UpdateCartItemRequest $request, int $itemId): JsonResponse
    {
        $item = $this->findOwnedItemOrFail($request, $itemId);
        $qty = (int) $request->validated('qty');

        $item->load($this->eagerLoads());
        $variant = $item->variant;
        if ($variant === null || $item->product === null) {
            throw ValidationException::withMessages([
                'qty' => ['This cart item is no longer available.'],
            ]);
        }

        // Always require published+approved (same as store) — do not trust loaded product alone.
        $this->assertPurchasable($variant, $qty);

        $item->qty = $qty;
        $item->save();
        $item->load($this->eagerLoads());

        $items = $this->cartQuery($request)->get();

        return (new CartItemResource($item))
            ->additional(['meta' => $this->totalsMeta($items)])
            ->response();
    }

    public function destroy(Request $request, int $itemId): JsonResponse
    {
        $item = CartItem::query()
            ->where('user_id', $request->user()->id)
            ->whereKey($itemId)
            ->first();

        $removed = false;
        if ($item) {
            $item->delete();
            $removed = true;
        }

        $items = $this->cartQuery($request)->get();

        return response()->json([
            'data' => [
                'id' => $itemId,
                'removed' => $removed,
            ],
            'meta' => $this->totalsMeta($items),
        ]);
    }

    public function clear(Request $request): JsonResponse
    {
        CartItem::query()
            ->where('user_id', $request->user()->id)
            ->delete();

        return response()->json([
            'data' => [
                'cleared' => true,
            ],
            'meta' => $this->totalsMeta(collect()),
        ]);
    }

    private function resolveVariant(int $productId, ?int $variantId): ProductVariant
    {
        if ($variantId !== null) {
            $variant = ProductVariant::query()
                ->whereKey($variantId)
                ->where('product_id', $productId)
                ->first();
            if ($variant) {
                return $variant;
            }

            throw ValidationException::withMessages([
                'variant_id' => ['Variant does not belong to this product.'],
            ]);
        }

        $variant = ProductVariant::query()
            ->where('product_id', $productId)
            ->where('is_default', true)
            ->first()
            ?? ProductVariant::query()->where('product_id', $productId)->orderBy('id')->first();

        if (! $variant) {
            throw ValidationException::withMessages([
                'product_id' => ['Product has no purchasable variants.'],
            ]);
        }

        return $variant;
    }

    private function assertPurchasable(ProductVariant $variant, int $qty): void
    {
        $product = Product::query()->published()->whereKey($variant->product_id)->first();

        if (! $product) {
            throw ValidationException::withMessages([
                'product_id' => ['Product is not available.'],
            ]);
        }

        $minQty = max(1, (int) ($variant->min_qty ?? $product->min_qty ?? 1));
        $stock = (int) $variant->current_stock;

        if ($stock <= 0) {
            throw ValidationException::withMessages([
                'qty' => ['This product is out of stock.'],
            ]);
        }

        if ($qty < $minQty) {
            throw ValidationException::withMessages([
                'qty' => ["Minimum quantity is {$minQty}."],
            ]);
        }

        if ($qty > min(99, $stock)) {
            throw ValidationException::withMessages([
                'qty' => ["Only {$stock} in stock."],
            ]);
        }
    }

    /**
     * @return list<string>
     */
    private function eagerLoads(): array
    {
        return [
            'product.brand',
            'variant',
        ];
    }

    private function cartQuery(Request $request)
    {
        return CartItem::query()
            ->where('user_id', $request->user()->id)
            ->with($this->eagerLoads())
            ->orderByDesc('updated_at');
    }

    private function findOwnedItemOrFail(Request $request, int $itemId): CartItem
    {
        return CartItem::query()
            ->where('user_id', $request->user()->id)
            ->whereKey($itemId)
            ->firstOrFail();
    }

    /**
     * @param  \Illuminate\Support\Collection<int, CartItem>  $items
     * @return array<string, float|int>
     */
    private function totalsMeta($items): array
    {
        $itemCount = 0;
        $lineCount = 0;
        $subtotal = 0.0;
        $originalTotal = 0.0;

        foreach ($items as $item) {
            $product = $item->product;
            $variant = $item->variant;
            if (! $product || ! $variant || ! $product->published || ! $product->approved) {
                continue;
            }

            $stock = (int) $variant->current_stock;
            $qty = min((int) $item->qty, max(0, $stock));
            if ($qty <= 0) {
                continue;
            }

            $unit = (float) $variant->unit_price;
            $mrp = (float) ($variant->mrp ?? $variant->list_price ?? $variant->unit_price);

            $lineCount++;
            $itemCount += $qty;
            $subtotal += $unit * $qty;
            $originalTotal += $mrp * $qty;
        }

        return [
            'item_count' => $itemCount,
            'line_count' => $lineCount,
            'subtotal' => round($subtotal, 2),
            'savings' => round(max(0, $originalTotal - $subtotal), 2),
            // Address / shipping / checkout left to FE static for now.
        ];
    }

    private function isUniqueConstraintViolation(QueryException $exception): bool
    {
        $sqlState = $exception->errorInfo[0] ?? null;
        $driverCode = (int) ($exception->errorInfo[1] ?? 0);

        return $sqlState === '23000'
            || $sqlState === '23505'
            || $driverCode === 1062
            || str_contains(strtolower($exception->getMessage()), 'unique');
    }
}
