<?php

namespace App\Modules\Wishlist\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Models\Product;
use App\Modules\Wishlist\Http\Requests\StoreWishlistItemRequest;
use App\Modules\Wishlist\Http\Requests\SyncWishlistRequest;
use App\Modules\Wishlist\Http\Resources\WishlistItemResource;
use App\Modules\Wishlist\Models\WishlistItem;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class WishlistController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $items = $this->wishlistQuery($request)->get();

        return WishlistItemResource::collection($items)
            ->additional([
                'meta' => [
                    'count' => $this->availableCount($request),
                ],
            ]);
    }

    public function count(Request $request): JsonResponse
    {
        return response()->json([
            'data' => [
                'count' => $this->availableCount($request),
            ],
        ]);
    }

    public function store(StoreWishlistItemRequest $request): JsonResponse
    {
        $userId = (int) $request->user()->id;
        $productId = (int) $request->validated('product_id');
        $variantId = $request->validated('variant_id');

        try {
            $item = WishlistItem::query()->firstOrCreate(
                [
                    'user_id' => $userId,
                    'product_id' => $productId,
                ],
                [
                    // Only applied on create — existing rows keep server variant_id.
                    'variant_id' => $variantId,
                ],
            );
        } catch (QueryException $exception) {
            // Concurrent insert hit unique (user_id, product_id) — re-fetch.
            if (! $this->isUniqueConstraintViolation($exception)) {
                throw $exception;
            }

            $item = WishlistItem::query()
                ->where('user_id', $userId)
                ->where('product_id', $productId)
                ->firstOrFail();
        }

        $created = $item->wasRecentlyCreated;
        $item->load($this->productEagerLoads());

        return (new WishlistItemResource($item))
            ->additional([
                'meta' => [
                    'count' => $this->availableCount($request),
                    'created' => $created,
                ],
            ])
            ->response()
            ->setStatusCode($created ? 201 : 200);
    }

    /**
     * Idempotent remove — 200 even if the row was already gone (optimistic FE toggles).
     * Uses raw product id so unpublished products still leave the wishlist table.
     */
    public function destroy(Request $request, int $productId): JsonResponse
    {
        $deleted = WishlistItem::query()
            ->where('user_id', $request->user()->id)
            ->where('product_id', $productId)
            ->delete();

        return response()->json([
            'data' => [
                'product_id' => $productId,
                'removed' => $deleted > 0,
            ],
            'meta' => [
                'count' => $this->availableCount($request),
            ],
        ]);
    }

    public function sync(SyncWishlistRequest $request): JsonResponse
    {
        $userId = (int) $request->user()->id;
        $requestedIds = array_values(array_unique(array_map(
            'intval',
            $request->validated('product_ids'),
        )));

        // Soft-filter: ignore missing / unpublished / unapproved ids instead of 422.
        $productIds = Product::query()
            ->published()
            ->whereIn('id', $requestedIds)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        $ignored = count($requestedIds) - count($productIds);

        $beforeIds = WishlistItem::query()
            ->where('user_id', $userId)
            ->whereIn('product_id', $productIds)
            ->pluck('product_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        $now = now();
        $rows = array_map(
            fn (int $productId): array => [
                'user_id' => $userId,
                'product_id' => $productId,
                'variant_id' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            $productIds,
        );

        if ($rows !== []) {
            // Concurrent syncs: ignore duplicate key instead of 500.
            WishlistItem::query()->insertOrIgnore($rows);
        }

        $afterIds = WishlistItem::query()
            ->where('user_id', $userId)
            ->whereIn('product_id', $productIds)
            ->pluck('product_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        $added = count(array_diff($afterIds, $beforeIds));
        $skipped = count($productIds) - $added;

        $items = $this->wishlistQuery($request)->get();

        return WishlistItemResource::collection($items)
            ->additional([
                'meta' => [
                    'count' => $this->availableCount($request),
                    'added' => $added,
                    // Conflict = already on server → keep server row / ignore duplicate.
                    'skipped' => $skipped,
                    // Requested ids that were not published/approved (or missing).
                    'ignored' => $ignored,
                ],
            ])
            ->response();
    }

    /**
     * @return array<int, string|\Closure>
     */
    private function productEagerLoads(): array
    {
        return [
            'product' => function ($query): void {
                $query
                    ->with(['brand', 'category', 'categoryGroup', 'defaultVariant'])
                    ->withCount('variants')
                    ->withExists([
                        'variants as in_stock' => function ($variant): void {
                            $variant->where('current_stock', '>', 0);
                        },
                    ]);
            },
        ];
    }

    private function wishlistQuery(Request $request)
    {
        return WishlistItem::query()
            ->where('user_id', $request->user()->id)
            ->with($this->productEagerLoads())
            ->orderByDesc('created_at');
    }

    /**
     * Header/list count: only rows whose product is still published+approved.
     */
    private function availableCount(Request $request): int
    {
        return WishlistItem::query()
            ->where('user_id', $request->user()->id)
            ->whereHas('product', function ($query): void {
                $query->published();
            })
            ->count();
    }

    private function isUniqueConstraintViolation(QueryException $exception): bool
    {
        $sqlState = $exception->errorInfo[0] ?? null;
        $driverCode = (int) ($exception->errorInfo[1] ?? 0);

        // SQLSTATE 23000 + MySQL 1062 / SQLite / Postgres unique_violation
        return $sqlState === '23000'
            || $sqlState === '23505'
            || $driverCode === 1062
            || str_contains(strtolower($exception->getMessage()), 'unique');
    }
}
