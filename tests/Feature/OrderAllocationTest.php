<?php

namespace Tests\Feature;

use App\Modules\Admin\Models\Admin;
use App\Modules\Auth\Models\User;
use App\Modules\Order\Models\Order;
use App\Modules\Order\Models\OrderItem;
use App\Modules\Order\Support\OrderTimeline;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class OrderAllocationTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        config(['b2b_api.token' => 'test-token', 'b2b_api.base_url' => 'https://b2b.test/api/b2c']);
        $admin = Admin::factory()->super()->create();
        $this->token = $admin->createToken('admin', ['admin'])->plainTextToken;
    }

    private function fakeStock(array $byPart): void
    {
        Http::fake(function (Request $request) use ($byPart) {
            $part = $request->data()['part_no'] ?? '';
            if (! isset($byPart[$part])) {
                return Http::response(['success' => false], 500);
            }
            [$k, $d, $m] = $byPart[$part];

            return Http::response([
                'success' => true,
                'data' => [['part_no' => $part, 'kolkata' => $k, 'delhi' => $d, 'mumbai' => $m, 'total_stock' => $k + $d + $m]],
                'meta' => ['abm_status' => 'available', 'warning' => null],
            ]);
        });
    }

    /** @param  list<array{0: ?string, 1: int}>  $lines  [part_no, qty] */
    private function order(array $lines, array $overrides = []): Order
    {
        $user = User::factory()->create();
        $order = Order::query()->create(array_merge([
            'user_id' => $user->id,
            'number' => 'OPL-'.Str::upper(Str::random(8)),
            'status' => 'processing',
            'shipping_name' => 'A', 'shipping_phone' => '919876543210', 'shipping_line1' => '1 St',
            'shipping_city' => 'Pune', 'shipping_state' => 'Maharashtra', 'shipping_pincode' => '411001',
            'item_count' => count($lines), 'subtotal' => 100, 'shipping_fee' => 0, 'grand_total' => 100,
            'currency' => 'INR', 'payment_method' => 'zoho', 'payment_status' => 'paid',
        ], $overrides));
        foreach ($lines as [$part, $qty]) {
            OrderItem::query()->create([
                'order_id' => $order->id, 'product_id' => 1, 'part_no' => $part, 'title' => 'Part '.($part ?? 'deleted'),
                'unit_price' => 100, 'qty' => $qty, 'line_total' => 100 * $qty,
            ]);
        }

        return $order->refresh();
    }

    private function api(): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($this->token);
    }

    private function allocate(Order $order, array $lines, int $version = 0)
    {
        return $this->api()->postJson("/api/v1/admin/orders/{$order->number}/allocate", [
            'expected_version' => $version, 'lines' => $lines,
        ]);
    }

    public function test_show_returns_live_stock_pending_and_left(): void
    {
        $this->fakeStock(['MZ1' => [5, 0, 2]]);
        $other = $this->order([['MZ1', 3]]);
        $item = $other->items()->first();
        $this->allocate($other, [['order_item_id' => $item->id, 'branches' => ['KOLKATA' => 3]]])->assertOk();

        $order = $this->order([['MZ1', 4]]);
        $res = $this->api()->getJson("/api/v1/admin/orders/{$order->number}/allocation")->assertOk();

        $line = $res->json('allocation.lines.0');
        $this->assertSame(4, $line['unallocated']);
        $this->assertTrue($line['stock_available']);
        $this->assertSame(['live' => 5, 'b2c_pending' => 3, 'left' => 2], $line['stock']['KOLKATA']);

        // Own sub-orders never count as pending against the same order.
        $this->allocate($order, [['order_item_id' => $order->items()->first()->id, 'branches' => ['KOLKATA' => 1]]])
            ->assertJsonPath('allocation.lines.0.stock.KOLKATA.b2c_pending', 3);
        $this->assertSame(['live' => 2, 'b2c_pending' => 0, 'left' => 2], $line['stock']['MUMBAI']);
        $this->assertNull($res->json('allocation.blocked_reason'));
    }

    public function test_stock_failure_is_unavailable_not_zero(): void
    {
        $this->fakeStock([]);
        $order = $this->order([['MZ9', 1]]);
        $line = $this->api()->getJson("/api/v1/admin/orders/{$order->number}/allocation")->json('allocation.lines.0');

        $this->assertFalse($line['stock_available']);
        $this->assertNull($line['stock']['KOLKATA']['live']);
        $this->assertNull($line['stock']['KOLKATA']['left']);
    }

    public function test_clean_empty_data_is_zero_not_found(): void
    {
        Http::fake(['*' => Http::response([
            'success' => true,
            'data' => [['part_no' => 'OTHER', 'kolkata' => 9, 'delhi' => 9, 'mumbai' => 9]],
            'meta' => ['abm_status' => 'available'],
        ])]);
        $order = $this->order([['MZ1', 1]]);
        $line = $this->api()->getJson("/api/v1/admin/orders/{$order->number}/allocation")->json('allocation.lines.0');

        $this->assertSame('not_found', $line['stock_status']);
        $this->assertSame(0, $line['stock']['KOLKATA']['live']);
    }

    public function test_stock_with_abm_status_not_available_is_unavailable(): void
    {
        Http::fake(['*' => Http::response([
            'success' => true,
            'data' => [['part_no' => 'MZ1', 'kolkata' => 9, 'delhi' => 9, 'mumbai' => 9]],
            'meta' => ['abm_status' => 'stale'],
        ])]);
        $order = $this->order([['MZ1', 1]]);
        $line = $this->api()->getJson("/api/v1/admin/orders/{$order->number}/allocation")->json('allocation.lines.0');

        $this->assertFalse($line['stock_available']);
    }

    public function test_allocate_splits_into_one_sub_order_per_branch_and_ignores_stock(): void
    {
        $this->fakeStock(['MZ1' => [0, 0, 0], 'MZ2' => [1, 1, 1]]);
        $order = $this->order([['MZ1', 3], ['MZ2', 2]]);
        [$a, $b] = $order->items()->orderBy('id')->get()->all();

        $res = $this->allocate($order, [
            ['order_item_id' => $a->id, 'branches' => ['KOLKATA' => 2, 'DELHI' => 1]],
            ['order_item_id' => $b->id, 'branches' => ['KOLKATA' => 2]],
        ])->assertOk();

        $subs = $res->json('allocation.sub_orders');
        $this->assertCount(2, $subs);
        $this->assertSame('KOLKATA', $subs[0]['branch']);
        $this->assertMatchesRegularExpression('#^SO/KOL/000001/\d\d-\d\d$#', $subs[0]['number']);
        $this->assertMatchesRegularExpression('#^SO/DEL/000001/\d\d-\d\d$#', $subs[1]['number']);
        $this->assertCount(2, $subs[0]['items']);
        $this->assertSame('approved', $res->json('allocation.approval_status'));
        $this->assertSame(1, $res->json('allocation.allocation_version'));
    }

    public function test_partial_allocation_stays_partial_and_can_continue(): void
    {
        $this->fakeStock(['MZ1' => [1, 0, 0]]);
        $order = $this->order([['MZ1', 3]]);
        $item = $order->items()->first();

        $this->allocate($order, [['order_item_id' => $item->id, 'branches' => ['KOLKATA' => 1]]])
            ->assertOk()->assertJsonPath('allocation.approval_status', 'partial')
            ->assertJsonPath('allocation.lines.0.unallocated', 2);

        $this->allocate($order, [['order_item_id' => $item->id, 'branches' => ['MUMBAI' => 2]]], 1)
            ->assertOk()->assertJsonPath('allocation.approval_status', 'approved')
            ->assertJsonPath('allocation.sub_orders.1.number', fn ($n) => str_starts_with($n, 'SO/MUM/000001/'));
    }

    public function test_stale_version_is_409_and_creates_nothing(): void
    {
        $this->fakeStock(['MZ1' => [5, 5, 5]]);
        $order = $this->order([['MZ1', 2]]);
        $item = $order->items()->first();
        $plan = [['order_item_id' => $item->id, 'branches' => ['KOLKATA' => 1]]];

        $this->allocate($order, $plan)->assertOk();
        $this->allocate($order, $plan)->assertStatus(409);

        $this->assertDatabaseCount('sub_orders', 1);
    }

    public function test_cannot_allocate_more_than_ordered(): void
    {
        $this->fakeStock(['MZ1' => [99, 99, 99]]);
        $order = $this->order([['MZ1', 2]]);
        $item = $order->items()->first();

        $this->allocate($order, [['order_item_id' => $item->id, 'branches' => ['KOLKATA' => 2, 'DELHI' => 1]]])
            ->assertStatus(422);
        $this->assertDatabaseCount('sub_orders', 0);
        $this->assertSame(0, $order->fresh()->allocation_version);
    }

    public function test_line_without_part_no_is_flagged_and_blocked(): void
    {
        $this->fakeStock([]);
        $order = $this->order([[null, 1]]);
        $item = $order->items()->first();

        $this->api()->getJson("/api/v1/admin/orders/{$order->number}/allocation")
            ->assertJsonPath('allocation.lines.0.allocatable', false)
            ->assertJsonPath('allocation.lines.0.stock', null);
        $this->allocate($order, [['order_item_id' => $item->id, 'branches' => ['KOLKATA' => 1]]])->assertStatus(422);
    }

    public function test_unpaid_online_and_failed_orders_cannot_be_allocated(): void
    {
        $this->fakeStock(['MZ1' => [5, 5, 5]]);
        foreach ([
            ['status' => 'pending_payment', 'payment_status' => 'pending'],
            ['status' => 'failed', 'payment_status' => 'failed'],
            ['status' => 'processing', 'payment_status' => 'pending'],
        ] as $state) {
            $order = $this->order([['MZ1', 1]], $state);
            $item = $order->items()->first();
            $this->allocate($order, [['order_item_id' => $item->id, 'branches' => ['KOLKATA' => 1]]])->assertStatus(422);
            $this->assertNotNull(
                $this->api()->getJson("/api/v1/admin/orders/{$order->number}/allocation")->json('allocation.blocked_reason')
            );
        }
        $this->assertDatabaseCount('sub_orders', 0);
    }

    public function test_cod_unpaid_order_can_be_allocated(): void
    {
        $this->fakeStock(['MZ1' => [5, 5, 5]]);
        $order = $this->order([['MZ1', 1]], ['payment_method' => 'cod', 'payment_status' => 'unpaid']);
        $item = $order->items()->first();

        $this->allocate($order, [['order_item_id' => $item->id, 'branches' => ['KOLKATA' => 1]]])->assertOk();
    }

    public function test_cancel_unallocated_only(): void
    {
        $this->fakeStock(['MZ1' => [5, 5, 5]]);
        $order = $this->order([['MZ1', 3]]);
        $item = $order->items()->first();
        $this->allocate($order, [['order_item_id' => $item->id, 'branches' => ['KOLKATA' => 2]]])->assertOk();

        $cancel = fn (int $qty, int $v) => $this->api()->postJson("/api/v1/admin/orders/{$order->number}/cancel-unallocated", [
            'expected_version' => $v, 'lines' => [['order_item_id' => $item->id, 'qty' => $qty]],
        ]);

        $cancel(2, 1)->assertStatus(422);
        $cancel(1, 1)->assertOk()
            ->assertJsonPath('allocation.lines.0.cancelled', 1)
            ->assertJsonPath('allocation.lines.0.unallocated', 0)
            ->assertJsonPath('allocation.approval_status', 'approved');
    }

    public function test_counter_continues_across_orders(): void
    {
        $this->fakeStock(['MZ1' => [5, 5, 5]]);
        foreach ([1, 2] as $n) {
            $order = $this->order([['MZ1', 1]]);
            $item = $order->items()->first();
            $this->allocate($order, [['order_item_id' => $item->id, 'branches' => ['DELHI' => 1]]])
                ->assertJsonPath('allocation.sub_orders.0.number', fn ($v) => str_starts_with($v, sprintf('SO/DEL/%06d/', $n)));
        }
    }

    public function test_no_token_means_unavailable_without_calling_b2b(): void
    {
        config(['b2b_api.token' => null]);
        Http::fake();
        $order = $this->order([['MZ1', 1]]);

        $this->api()->getJson("/api/v1/admin/orders/{$order->number}/allocation")
            ->assertJsonPath('allocation.lines.0.stock_available', false);
        Http::assertNothingSent();
    }

    public function test_replayed_capture_does_not_touch_an_approved_order(): void
    {
        $this->fakeStock(['MZ1' => [5, 5, 5]]);
        $order = $this->order([['MZ1', 1]]);
        $item = $order->items()->first();
        $this->allocate($order, [['order_item_id' => $item->id, 'branches' => ['KOLKATA' => 1]]])->assertOk();

        OrderTimeline::applyPaid($order->fresh(), 'pay_replay');

        $fresh = $order->fresh();
        $this->assertSame('approved', $fresh->approval_status);
        $this->assertSame(1, $fresh->allocation_version);
        $this->assertSame('processing', $fresh->status);
        $this->assertDatabaseCount('sub_orders', 1);
    }

    public function test_cancelled_order_leaves_the_approval_queue(): void
    {
        $this->fakeStock(['MZ1' => [5, 5, 5]]);
        $order = $this->order([['MZ1', 1]], ['status' => 'cancelled']);
        $item = $order->items()->first();

        $this->allocate($order, [['order_item_id' => $item->id, 'branches' => ['KOLKATA' => 1]]])->assertStatus(422);
    }

    public function test_financial_year_uses_india_time_on_1_april(): void
    {
        $this->travelTo(Carbon::parse('2027-04-01 02:00', 'Asia/Kolkata'));
        $this->fakeStock(['MZ1' => [5, 5, 5]]);
        $order = $this->order([['MZ1', 1]]);
        $item = $order->items()->first();

        $this->allocate($order, [['order_item_id' => $item->id, 'branches' => ['KOLKATA' => 1]]])
            ->assertJsonPath('allocation.sub_orders.0.number', 'SO/KOL/000001/27-28');
    }

    public function test_cannot_mark_shipped_while_approval_or_sub_orders_are_open(): void
    {
        $this->fakeStock(['MZ1' => [5, 5, 5]]);
        $order = $this->order([['MZ1', 2]]);
        $item = $order->items()->first();
        $ship = fn () => $this->api()->patchJson("/api/v1/admin/orders/{$order->number}/fulfilment", ['status' => 'shipped']);

        $ship()->assertStatus(422);
        $this->allocate($order, [['order_item_id' => $item->id, 'branches' => ['KOLKATA' => 1]]])->assertOk();
        $ship()->assertStatus(422);
        $this->allocate($order, [['order_item_id' => $item->id, 'branches' => ['DELHI' => 1]]], 1)->assertOk();
        $ship()->assertStatus(422);
        $this->assertSame('processing', $order->fresh()->status);
    }

    public function test_requires_admin(): void
    {
        $order = $this->order([['MZ1', 1]]);
        $this->getJson("/api/v1/admin/orders/{$order->number}/allocation")->assertUnauthorized();
    }
}
