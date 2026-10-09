<?php

namespace Tests\Feature;

use App\Modules\Auth\Models\User;
use App\Modules\Order\Models\Order;
use App\Modules\Order\Models\OrderItem;
use App\Modules\Order\Support\OrderTimeline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminOrdersTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private string $adminToken;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->staff()->create([
            'name' => 'Ops Admin',
            'email' => 'admin-orders@example.com',
        ]);
        $this->adminToken = $this->admin->createToken('opel-b2c-admin', ['admin'])->plainTextToken;
    }

    private function asAdmin(): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($this->adminToken);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeOrder(User $user, array $overrides = []): Order
    {
        $createdAt = $overrides['created_at'] ?? null;
        unset($overrides['created_at']);

        $order = Order::query()->create(array_merge([
            'user_id' => $user->id,
            'number' => 'OPL-'.Str::upper(Str::random(8)),
            'status' => 'processing',
            'shipping_name' => 'Ship To',
            'shipping_phone' => '919876543210',
            'shipping_line1' => '1 Street',
            'shipping_city' => 'Pune',
            'shipping_state' => 'Maharashtra',
            'shipping_pincode' => '411001',
            'item_count' => 1,
            'subtotal' => 500,
            'shipping_fee' => 99,
            'grand_total' => 599,
            'currency' => 'INR',
            'payment_method' => 'zoho',
            'payment_status' => 'paid',
            'paid_at' => now(),
            'timeline' => OrderTimeline::forStatus('processing', now()),
        ], $overrides));

        if ($createdAt !== null) {
            $order->forceFill([
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ])->saveQuietly();
        }

        OrderItem::query()->create([
            'order_id' => $order->id,
            'product_id' => 1,
            'variant_id' => null,
            'title' => 'Test Part',
            'image_url' => null,
            'unit_price' => 500,
            'qty' => 1,
            'line_total' => 500,
        ]);

        return $order->refresh();
    }

    // ---- auth gate -------------------------------------------------------

    public function test_routes_require_admin_token(): void
    {
        $shopper = User::factory()->create();
        $order = $this->makeOrder($shopper);
        $shopperToken = $shopper->createToken('opel-b2c')->plainTextToken;

        $this->getJson('/api/v1/admin/orders')->assertUnauthorized();

        $this->withToken($shopperToken)->getJson('/api/v1/admin/orders')->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->withToken($shopperToken)->getJson("/api/v1/admin/orders/{$order->number}")->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->withToken($shopperToken)
            ->patchJson("/api/v1/admin/orders/{$order->number}/fulfilment", ['status' => 'shipped'])
            ->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->withToken($shopperToken)
            ->postJson("/api/v1/admin/orders/{$order->number}/cod-collected")
            ->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->withToken($shopperToken)
            ->patchJson("/api/v1/admin/orders/{$order->number}/payment-notes", ['payment_notes' => 'x'])
            ->assertForbidden();
    }

    // ---- list ------------------------------------------------------------

    public function test_list_returns_orders_with_envelope_sorted_placed_at_desc(): void
    {
        $shopper = User::factory()->create(['name' => 'Asha', 'email' => 'asha@example.com']);
        $old = $this->makeOrder($shopper, ['created_at' => now()->subDays(2), 'number' => 'OPL-OLDORDER1']);
        $new = $this->makeOrder($shopper, ['created_at' => now()->subDay(), 'number' => 'OPL-NEWORDER1']);

        $this->asAdmin()->getJson('/api/v1/admin/orders')
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.number', $new->number)
            ->assertJsonPath('data.1.number', $old->number)
            ->assertJsonPath('data.0.user.id', $shopper->id)
            ->assertJsonPath('data.0.user.email', 'asha@example.com')
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.per_page', 20)
            ->assertJsonStructure([
                'ok',
                'data' => [[
                    'number', 'status', 'payment_method', 'payment_status',
                    'currency', 'item_count', 'grand_total', 'shipping_name',
                    'shipping_phone', 'placed_at', 'user' => ['id', 'name', 'email'],
                ]],
                'meta' => ['current_page', 'last_page', 'per_page', 'total', 'from', 'to'],
            ]);
    }

    public function test_list_search_matches_number_phone_user_email_name(): void
    {
        $a = User::factory()->create(['name' => 'Ravi Kumar', 'email' => 'ravi@example.com']);
        $b = User::factory()->create(['name' => 'Other', 'email' => 'findme@example.com']);
        $byNumber = $this->makeOrder($a, ['number' => 'OPL-UNIQUE99']);
        $byPhone = $this->makeOrder($a, ['number' => 'OPL-PHONE001', 'shipping_phone' => '919111122222']);
        $byEmail = $this->makeOrder($b, ['number' => 'OPL-EMAIL001']);
        $byName = $this->makeOrder($a, ['number' => 'OPL-NAME0001']);

        $this->asAdmin()->getJson('/api/v1/admin/orders?q=UNIQUE99')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.number', $byNumber->number);

        $this->asAdmin()->getJson('/api/v1/admin/orders?q=91111')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.number', $byPhone->number);

        $this->asAdmin()->getJson('/api/v1/admin/orders?q=findme')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.number', $byEmail->number);

        $this->asAdmin()->getJson('/api/v1/admin/orders?q=Ravi')
            ->assertOk()->assertJsonCount(3, 'data');

        $this->asAdmin()->getJson('/api/v1/admin/orders?q=%25')
            ->assertOk()->assertJsonCount(0, 'data');

        unset($byName); // keep var used for creation
    }

    public function test_list_filters_payment_status_method_status(): void
    {
        $shopper = User::factory()->create();
        $this->makeOrder($shopper, [
            'number' => 'OPL-FILTP001',
            'payment_status' => 'unpaid',
            'payment_method' => 'cod',
            'status' => 'processing',
            'paid_at' => null,
        ]);
        $this->makeOrder($shopper, [
            'number' => 'OPL-FILTZ001',
            'payment_status' => 'paid',
            'payment_method' => 'zoho',
            'status' => 'shipped',
        ]);

        $this->asAdmin()->getJson('/api/v1/admin/orders?payment_status=unpaid')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.number', 'OPL-FILTP001');

        $this->asAdmin()->getJson('/api/v1/admin/orders?payment_method=cod')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.number', 'OPL-FILTP001');

        $this->asAdmin()->getJson('/api/v1/admin/orders?status=shipped')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.number', 'OPL-FILTZ001');

        $this->asAdmin()->getJson('/api/v1/admin/orders?payment_status=bogus')
            ->assertUnprocessable()->assertJsonValidationErrors('payment_status');
    }

    public function test_user_scoped_list_and_404_for_missing_or_staff(): void
    {
        $shopper = User::factory()->create();
        $other = User::factory()->create();
        $staff = User::factory()->staff()->create();
        $this->makeOrder($shopper, ['number' => 'OPL-USERSCP1']);
        $this->makeOrder($other, ['number' => 'OPL-OTHERSC1']);

        $this->asAdmin()->getJson("/api/v1/admin/orders?user_id={$shopper->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.number', 'OPL-USERSCP1')
            ->assertJsonPath('meta.total', 1);

        $this->asAdmin()->getJson("/api/v1/admin/orders?user_id={$staff->id}")
            ->assertNotFound()
            ->assertJsonPath('message', 'User not found.');

        $this->asAdmin()->getJson('/api/v1/admin/orders?user_id=999999')
            ->assertNotFound()
            ->assertJsonPath('message', 'User not found.');
    }

    // ---- detail ----------------------------------------------------------

    public function test_show_returns_detail_with_items_and_payment_notes(): void
    {
        $shopper = User::factory()->create(['name' => 'Detail Shopper', 'email' => 'd@example.com']);
        $order = $this->makeOrder($shopper, [
            'number' => 'OPL-DETAIL01',
            'payment_notes' => 'Called customer',
        ]);

        $this->asAdmin()->getJson("/api/v1/admin/orders/{$order->number}")
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('order.number', 'OPL-DETAIL01')
            ->assertJsonPath('order.payment_notes', 'Called customer')
            ->assertJsonPath('order.user.id', $shopper->id)
            ->assertJsonPath('order.user.name', 'Detail Shopper')
            ->assertJsonCount(1, 'order.items')
            ->assertJsonPath('order.items.0.title', 'Test Part')
            ->assertJsonStructure([
                'order' => [
                    'number', 'status', 'payment_method', 'payment_status', 'payment_notes',
                    'shipping_address' => ['name', 'phone', 'line1', 'city', 'state', 'pincode'],
                    'timeline', 'items', 'user' => ['id', 'name', 'email', 'phone'],
                ],
            ]);
    }

    public function test_show_missing_order_returns_404(): void
    {
        $this->asAdmin()->getJson('/api/v1/admin/orders/OPL-MISSING1')
            ->assertNotFound()
            ->assertJsonPath('message', 'Order not found.');
    }

    public function test_shopper_order_resource_omits_payment_notes(): void
    {
        $shopper = User::factory()->create();
        $token = $shopper->createToken('opel-b2c')->plainTextToken;
        $order = $this->makeOrder($shopper, [
            'number' => 'OPL-SHOPNOTE',
            'payment_notes' => 'Admin only secret',
        ]);

        $response = $this->withToken($token)->getJson("/api/v1/orders/{$order->number}")
            ->assertOk();

        $this->assertArrayNotHasKey('payment_notes', $response->json('data') ?? $response->json());
    }

    // ---- fulfilment transition matrix ------------------------------------

    public function test_fulfilment_forward_only_processing_to_shipped_to_delivered(): void
    {
        $shopper = User::factory()->create();
        $order = $this->makeOrder($shopper, ['number' => 'OPL-FULFILL1', 'status' => 'processing']);

        $this->asAdmin()->patchJson("/api/v1/admin/orders/{$order->number}/fulfilment", ['status' => 'shipped'])
            ->assertOk()
            ->assertJsonPath('order.status', 'shipped');

        $this->assertSame('shipped', $order->refresh()->status);
        $this->assertNotEmpty($order->timeline);

        $this->asAdmin()->patchJson("/api/v1/admin/orders/{$order->number}/fulfilment", ['status' => 'delivered'])
            ->assertOk()
            ->assertJsonPath('order.status', 'delivered');

        $this->assertSame('delivered', $order->refresh()->status);
    }

    public function test_fulfilment_rejects_skips_and_regressions(): void
    {
        $shopper = User::factory()->create();
        $processing = $this->makeOrder($shopper, ['number' => 'OPL-SKIP0001', 'status' => 'processing']);
        $shipped = $this->makeOrder($shopper, ['number' => 'OPL-REGRESS1', 'status' => 'shipped']);
        $delivered = $this->makeOrder($shopper, ['number' => 'OPL-DONE0001', 'status' => 'delivered']);

        // Skip processing → delivered
        $this->asAdmin()->patchJson("/api/v1/admin/orders/{$processing->number}/fulfilment", ['status' => 'delivered'])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Next fulfilment status from processing must be shipped.');

        // Regression shipped → shipped (not next) / try processing not in allowed targets
        $this->asAdmin()->patchJson("/api/v1/admin/orders/{$shipped->number}/fulfilment", ['status' => 'shipped'])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Next fulfilment status from shipped must be delivered.');

        // Delivered has no next
        $this->asAdmin()->patchJson("/api/v1/admin/orders/{$delivered->number}/fulfilment", ['status' => 'delivered'])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Fulfilment can only advance orders that are processing or shipped.');

        // Invalid body status
        $this->asAdmin()->patchJson("/api/v1/admin/orders/{$processing->number}/fulfilment", ['status' => 'processing'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');
    }

    public function test_fulfilment_rejects_pending_payment_and_failed(): void
    {
        $shopper = User::factory()->create();
        $pending = $this->makeOrder($shopper, [
            'number' => 'OPL-PENDING1',
            'status' => 'pending_payment',
            'payment_status' => 'unpaid',
            'payment_method' => 'zoho',
            'paid_at' => null,
        ]);
        $failed = $this->makeOrder($shopper, [
            'number' => 'OPL-FAILED01',
            'status' => 'failed',
            'payment_status' => 'failed',
            'paid_at' => null,
        ]);

        $this->asAdmin()->patchJson("/api/v1/admin/orders/{$pending->number}/fulfilment", ['status' => 'shipped'])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Fulfilment can only advance orders that are processing or shipped.');

        $this->asAdmin()->patchJson("/api/v1/admin/orders/{$failed->number}/fulfilment", ['status' => 'shipped'])
            ->assertUnprocessable();

        $this->assertSame('pending_payment', $pending->refresh()->status);
        $this->assertSame('failed', $failed->refresh()->status);
    }

    // ---- COD collected ---------------------------------------------------

    public function test_cod_collected_marks_paid_and_advances_to_processing(): void
    {
        $shopper = User::factory()->create();
        $order = $this->makeOrder($shopper, [
            'number' => 'OPL-COD00001',
            'status' => 'pending_payment',
            'payment_method' => 'cod',
            'payment_status' => 'unpaid',
            'paid_at' => null,
            'timeline' => OrderTimeline::initialPending(),
        ]);

        $this->asAdmin()->postJson("/api/v1/admin/orders/{$order->number}/cod-collected")
            ->assertOk()
            ->assertJsonPath('order.payment_status', 'paid')
            ->assertJsonPath('order.status', 'processing')
            ->assertJsonPath('order.zoho_payment_id', null);

        $order->refresh();
        $this->assertSame('paid', $order->payment_status);
        $this->assertSame('processing', $order->status);
        $this->assertNotNull($order->paid_at);
        $this->assertNull($order->zoho_payment_id);
    }

    public function test_cod_collected_idempotent_when_already_paid(): void
    {
        $shopper = User::factory()->create();
        $paidAt = now()->subHour();
        $order = $this->makeOrder($shopper, [
            'number' => 'OPL-CODPAID1',
            'status' => 'processing',
            'payment_method' => 'cod',
            'payment_status' => 'paid',
            'paid_at' => $paidAt,
        ]);

        $this->asAdmin()->postJson("/api/v1/admin/orders/{$order->number}/cod-collected")
            ->assertOk()
            ->assertJsonPath('order.payment_status', 'paid')
            ->assertJsonPath('order.status', 'processing');

        $this->assertSame(
            $paidAt->getTimestamp(),
            $order->refresh()->paid_at->getTimestamp(),
        );
    }

    public function test_cod_collected_rejects_non_cod_and_non_unpaid(): void
    {
        $shopper = User::factory()->create();
        $zoho = $this->makeOrder($shopper, [
            'number' => 'OPL-ZOHOUNP1',
            'payment_method' => 'zoho',
            'payment_status' => 'unpaid',
            'status' => 'pending_payment',
            'paid_at' => null,
        ]);
        $codPending = $this->makeOrder($shopper, [
            'number' => 'OPL-CODPEND1',
            'payment_method' => 'cod',
            'payment_status' => 'pending',
            'status' => 'pending_payment',
            'paid_at' => null,
        ]);

        $this->asAdmin()->postJson("/api/v1/admin/orders/{$zoho->number}/cod-collected")
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Only COD orders can be marked collected.');

        $this->asAdmin()->postJson("/api/v1/admin/orders/{$codPending->number}/cod-collected")
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Only unpaid COD orders can be marked collected.');
    }

    public function test_cod_collected_rejects_paid_zoho_not_idempotent_200(): void
    {
        $shopper = User::factory()->create();
        $paidZoho = $this->makeOrder($shopper, [
            'number' => 'OPL-ZOHOPAID',
            'payment_method' => 'zoho',
            'payment_status' => 'paid',
            'status' => 'processing',
            'paid_at' => now()->subHour(),
            'zoho_payment_id' => 'zoho_pay_abc',
        ]);

        $this->asAdmin()->postJson("/api/v1/admin/orders/{$paidZoho->number}/cod-collected")
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Only COD orders can be marked collected.');

        $paidZoho->refresh();
        $this->assertSame('zoho', $paidZoho->payment_method);
        $this->assertSame('paid', $paidZoho->payment_status);
        $this->assertSame('zoho_pay_abc', $paidZoho->zoho_payment_id);
    }

    // ---- payment notes ---------------------------------------------------

    public function test_payment_notes_patch_sets_and_clears(): void
    {
        $shopper = User::factory()->create();
        $order = $this->makeOrder($shopper, ['number' => 'OPL-NOTES001', 'payment_notes' => null]);

        $this->asAdmin()->patchJson("/api/v1/admin/orders/{$order->number}/payment-notes", [
            'payment_notes' => 'Left voicemail',
        ])
            ->assertOk()
            ->assertJsonPath('order.payment_notes', 'Left voicemail');

        $this->assertSame('Left voicemail', $order->refresh()->payment_notes);

        $this->asAdmin()->patchJson("/api/v1/admin/orders/{$order->number}/payment-notes", [
            'payment_notes' => null,
        ])
            ->assertOk()
            ->assertJsonPath('order.payment_notes', null);

        $this->assertNull($order->refresh()->payment_notes);

        $this->asAdmin()->patchJson("/api/v1/admin/orders/{$order->number}/payment-notes", [
            'payment_notes' => str_repeat('a', 2001),
        ])->assertUnprocessable()->assertJsonValidationErrors('payment_notes');
    }
}
