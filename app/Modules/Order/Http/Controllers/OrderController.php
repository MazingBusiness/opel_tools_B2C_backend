<?php

namespace App\Modules\Order\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Address\Models\Address;
use App\Modules\Cart\Models\CartItem;
use App\Modules\Order\Http\Requests\StoreOrderRequest;
use App\Modules\Order\Http\Resources\OrderResource;
use App\Modules\Order\Http\Resources\OrderTrackResource;
use App\Modules\Order\Models\Order;
use App\Modules\Order\Services\ZohoPaymentService;
use App\Modules\Order\Support\OrderTimeline;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class OrderController extends Controller
{
    private const FREE_SHIPPING_THRESHOLD = 2999.0;

    private const SHIPPING_FEE = 99.0;

    public function __construct(private ZohoPaymentService $zoho)
    {
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $orders = Order::query()
            ->where('user_id', $request->user()->id)
            ->with('items')
            ->orderByDesc('id')
            ->paginate(20);

        return OrderResource::collection($orders);
    }

    public function show(Request $request, string $order): OrderResource
    {
        return new OrderResource($this->findOwnedOrder($request, $order));
    }

    /**
     * Public track by order number — masked DTO only.
     */
    public function track(string $number): OrderTrackResource
    {
        $order = Order::query()
            ->with('items')
            ->where('number', $number)
            ->firstOrFail();

        return new OrderTrackResource($order);
    }

    public function store(StoreOrderRequest $request): JsonResponse
    {
        $user = $request->user();
        $address = Address::query()
            ->where('user_id', $user->id)
            ->whereKey((int) $request->validated('address_id'))
            ->firstOrFail();

        try {
            $order = DB::transaction(function () use ($user, $address) {
                $cartItems = CartItem::query()
                    ->where('user_id', $user->id)
                    ->with(['product', 'variant'])
                    ->lockForUpdate()
                    ->get();

                if ($cartItems->isEmpty()) {
                    throw new RuntimeException('Cart is empty.');
                }

                $lines = [];
                $subtotal = 0.0;
                $itemCount = 0;

                foreach ($cartItems as $cartItem) {
                    $product = $cartItem->product;
                    $variant = $cartItem->variant;
                    if (! $product || ! $variant || ! $product->published || ! $product->approved) {
                        throw new RuntimeException('Cart contains an unavailable item. Update your cart and try again.');
                    }
                    $stock = (int) $variant->current_stock;
                    $qty = min((int) $cartItem->qty, max(0, $stock));
                    if ($qty < 1) {
                        throw new RuntimeException('Cart contains an out-of-stock item.');
                    }
                    $unit = (float) $variant->unit_price;
                    $lineTotal = round($unit * $qty, 2);
                    $subtotal += $lineTotal;
                    $itemCount += $qty;
                    $lines[] = [
                        'product_id' => $product->id,
                        'variant_id' => $variant->id,
                        'title' => (string) $product->name,
                        'image_url' => $variant->thumbnail_img ?: $product->thumbnail_img,
                        'unit_price' => $unit,
                        'qty' => $qty,
                        'line_total' => $lineTotal,
                    ];
                }

                $shipping = ($subtotal >= self::FREE_SHIPPING_THRESHOLD || $subtotal <= 0)
                    ? 0.0
                    : self::SHIPPING_FEE;
                $grand = round($subtotal + $shipping, 2);

                $order = Order::query()->create([
                    'user_id' => $user->id,
                    'number' => $this->nextOrderNumber(),
                    'status' => 'pending_payment',
                    'address_id' => $address->id,
                    'shipping_name' => $address->name,
                    'shipping_phone' => $address->phone,
                    'shipping_line1' => $address->line1,
                    'shipping_line2' => $address->line2,
                    'shipping_city' => $address->city,
                    'shipping_state' => $address->state,
                    'shipping_pincode' => $address->pincode,
                    'item_count' => $itemCount,
                    'subtotal' => $subtotal,
                    'shipping_fee' => $shipping,
                    'grand_total' => $grand,
                    'currency' => 'INR',
                    'payment_status' => 'unpaid',
                    'timeline' => OrderTimeline::initialPending(),
                    'handoff_status' => null,
                ]);

                foreach ($lines as $line) {
                    $order->items()->create($line);
                }

                return $order->load('items');
            });
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        try {
            $link = $this->zoho->createPaymentLink($order, [
                'email' => $user->email,
                'phone' => $order->shipping_phone,
            ]);
        } catch (RuntimeException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'data' => new OrderResource($order),
                'meta' => [
                    'auth_required' => str_contains($e->getMessage(), 'OAuth required'),
                ],
            ], 503);
        }

        $order->update([
            'payment_link_url' => $link['url'],
            'payment_link_id' => $link['payment_link_id'],
            'payment_link_expires_at' => $link['expires_at'],
            'payment_status' => 'pending',
        ]);

        return (new OrderResource($order->fresh('items')))
            ->additional([
                'meta' => [
                    'payment_url' => $link['url'],
                ],
            ])
            ->response()
            ->setStatusCode(201);
    }

    public function paymentStatus(Request $request, string $order): JsonResponse
    {
        $model = $this->findOwnedOrder($request, $order);

        // Confirm with Zoho when still unpaid/pending — never trust client/webhook alone.
        if (
            $model->payment_status !== 'paid'
            && filled($model->payment_link_id)
        ) {
            $remote = $this->zoho->fetchPaymentLink((string) $model->payment_link_id);
            if (is_array($remote)) {
                if ($this->zoho->isPaidStatus($remote['status'])) {
                    OrderTimeline::applyPaid($model, $remote['payment_id'] ?? null);
                    $model->refresh();
                } elseif ($this->zoho->isFailedStatus($remote['status'])) {
                    $model->update([
                        'status' => 'failed',
                        'payment_status' => 'failed',
                        'timeline' => OrderTimeline::forStatus(
                            'failed',
                            $model->created_at,
                            is_array($model->timeline) ? $model->timeline : null,
                        ),
                    ]);
                    $model->refresh();
                }
            }
        }

        return response()->json([
            'data' => [
                'id' => $model->number,
                'db_id' => $model->id,
                'number' => $model->number,
                'status' => $model->status,
                'payment_status' => $model->payment_status,
                'paid_at' => $model->paid_at?->toIso8601String(),
                'timeline' => is_array($model->timeline)
                    ? $model->timeline
                    : OrderTimeline::forStatus((string) $model->status, $model->created_at, null),
            ],
        ]);
    }

    private function findOwnedOrder(Request $request, string $order): Order
    {
        $query = Order::query()
            ->where('user_id', $request->user()->id)
            ->with('items');

        if (ctype_digit($order)) {
            return $query->whereKey((int) $order)->firstOrFail();
        }

        return $query->where('number', $order)->firstOrFail();
    }

    private function nextOrderNumber(): string
    {
        return 'OPL-'.now()->format('ymd').'-'.Str::upper(Str::random(5));
    }
}
