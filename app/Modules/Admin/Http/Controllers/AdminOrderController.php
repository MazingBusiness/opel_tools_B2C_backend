<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Admin\Http\Requests\AdminOrderIndexRequest;
use App\Modules\Admin\Http\Requests\UpdateAdminOrderFulfilmentRequest;
use App\Modules\Admin\Http\Requests\UpdateAdminOrderPaymentNotesRequest;
use App\Modules\Admin\Http\Resources\AdminOrderDetailResource;
use App\Modules\Admin\Http\Resources\AdminOrderResource;
use App\Modules\Auth\Models\User;
use App\Modules\Order\Models\Order;
use App\Modules\Order\Support\OrderTimeline;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admin Orders v0 — list/detail + fulfilment advances, COD collected, payment notes.
 * Lookup is always by order number. WhatsApp is out of scope.
 */
class AdminOrderController extends Controller
{
    private const DEFAULT_PER_PAGE = 20;

    private const MAX_PER_PAGE = 50;

    /** Forward-only fulfilment chain. Packed stays a timeline step under processing. */
    private const FULFILMENT_NEXT = [
        'processing' => 'shipped',
        'shipped' => 'delivered',
    ];

    public function index(AdminOrderIndexRequest $request): JsonResponse
    {
        $filters = $request->validated();
        $q = (string) ($filters['q'] ?? '');
        $perPage = min(self::MAX_PER_PAGE, max(1, (int) ($filters['per_page'] ?? self::DEFAULT_PER_PAGE)));

        if (array_key_exists('user_id', $filters) && $filters['user_id'] !== null) {
            $this->assertShopperExists((int) $filters['user_id']);
        }

        $query = Order::query()->with('user');

        if (isset($filters['user_id']) && $filters['user_id'] !== null) {
            $query->where('user_id', (int) $filters['user_id']);
        }

        if ($q !== '') {
            $like = '%'.addcslashes($q, '\\%_').'%';
            $query->where(function (Builder $w) use ($like): void {
                $w->where('number', 'like', $like)
                    ->orWhere('shipping_phone', 'like', $like)
                    ->orWhereHas('user', function (Builder $u) use ($like): void {
                        $u->where('email', 'like', $like)
                            ->orWhere('name', 'like', $like);
                    });
            });
        }

        if (! empty($filters['payment_status'])) {
            $query->where('payment_status', $filters['payment_status']);
        }

        if (! empty($filters['payment_method'])) {
            $query->where('payment_method', $filters['payment_method']);
        }

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        $page = $query
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();

        return response()->json([
            'ok' => true,
            'data' => AdminOrderResource::collection($page->getCollection())->resolve($request),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'from' => $page->firstItem(),
                'to' => $page->lastItem(),
            ],
        ]);
    }

    public function show(string $number): JsonResponse
    {
        return $this->orderPayload($this->findOrder($number));
    }

    public function updateFulfilment(UpdateAdminOrderFulfilmentRequest $request, string $number): JsonResponse
    {
        $order = $this->findOrder($number);
        $target = (string) $request->validated('status');
        $current = (string) $order->status;

        if (! array_key_exists($current, self::FULFILMENT_NEXT)) {
            abort(
                Response::HTTP_UNPROCESSABLE_ENTITY,
                'Fulfilment can only advance orders that are processing or shipped.',
            );
        }

        $allowed = self::FULFILMENT_NEXT[$current];
        if ($target !== $allowed) {
            abort(
                Response::HTTP_UNPROCESSABLE_ENTITY,
                "Next fulfilment status from {$current} must be {$allowed}.",
            );
        }

        $order->update([
            'status' => $target,
            'timeline' => OrderTimeline::forStatus(
                $target,
                $order->created_at,
                is_array($order->timeline) ? $order->timeline : null,
            ),
        ]);

        return $this->orderPayload($order);
    }

    public function markCodCollected(string $number): JsonResponse
    {
        $order = $this->findOrder($number);

        // Non-COD first so a paid Zoho order never gets a soft 200.
        if ($order->payment_method !== 'cod') {
            abort(Response::HTTP_UNPROCESSABLE_ENTITY, 'Only COD orders can be marked collected.');
        }

        // Idempotent: COD already paid → return current payload.
        if ($order->payment_status === 'paid') {
            return $this->orderPayload($order);
        }

        if ($order->payment_status !== 'unpaid') {
            abort(Response::HTTP_UNPROCESSABLE_ENTITY, 'Only unpaid COD orders can be marked collected.');
        }

        OrderTimeline::applyPaid($order, null);

        return $this->orderPayload($order);
    }

    public function updatePaymentNotes(UpdateAdminOrderPaymentNotesRequest $request, string $number): JsonResponse
    {
        $order = $this->findOrder($number);
        $order->update([
            'payment_notes' => $request->validated('payment_notes'),
        ]);

        return $this->orderPayload($order);
    }

    private function findOrder(string $number): Order
    {
        $order = Order::query()->where('number', $number)->first();

        if (! $order instanceof Order) {
            abort(Response::HTTP_NOT_FOUND, 'Order not found.');
        }

        return $order;
    }

    /**
     * Same shopper rule as Admin Users: missing or staff → 404.
     */
    private function assertShopperExists(int $userId): void
    {
        $user = User::query()
            ->where('is_staff', false)
            ->whereKey($userId)
            ->first();

        if (! $user instanceof User) {
            abort(Response::HTTP_NOT_FOUND, 'User not found.');
        }
    }

    private function orderPayload(Order $order): JsonResponse
    {
        $order = $order->fresh(['items', 'user']);

        return response()->json([
            'ok' => true,
            'order' => (new AdminOrderDetailResource($order))->resolve(),
        ]);
    }
}
