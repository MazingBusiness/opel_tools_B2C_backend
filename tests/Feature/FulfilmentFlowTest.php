<?php

namespace Tests\Feature;

use App\Modules\Auth\Models\User;
use App\Modules\Fulfilment\Jobs\PushChallanItemToB2b;
use App\Modules\Fulfilment\Models\B2bPush;
use App\Modules\Fulfilment\Models\Challan;
use App\Modules\Fulfilment\Models\Invoice;
use App\Modules\Fulfilment\Models\SubOrder;
use App\Modules\Fulfilment\Support\IndianState;
use App\Modules\Order\Models\Order;
use App\Modules\Order\Models\OrderItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class FulfilmentFlowTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    private User $shopper;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        config(['b2b_api.token' => 'test-token', 'b2b_api.base_url' => 'https://b2b.test/api/b2c']);
        $this->token = User::factory()->staff()->create()->createToken('admin', ['admin'])->plainTextToken;
        $this->shopper = User::factory()->create();
        Http::fake(['*/closing-stock/*' => Http::response(['success' => false], 500)]);
    }

    private function api(): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($this->token);
    }

    /** @param  list<array{0: string, 1: int, 2: float, 3?: float}>  $lines  [part_no, qty, unit_price, gst_rate] */
    private function order(array $lines, string $state = 'West Bengal', array $overrides = []): Order
    {
        $total = array_sum(array_map(fn ($l) => round($l[1] * $l[2], 2), $lines));
        $order = Order::query()->create(array_merge([
            'user_id' => $this->shopper->id,
            'number' => 'OPL-'.Str::upper(Str::random(8)),
            'status' => 'processing',
            'shipping_name' => 'Asha Roy', 'shipping_phone' => '919876543210', 'shipping_line1' => '1 Park St',
            'shipping_city' => 'Kolkata', 'shipping_state' => $state, 'shipping_pincode' => '700016',
            'item_count' => count($lines), 'subtotal' => $total, 'shipping_fee' => 0, 'grand_total' => $total,
            'currency' => 'INR', 'payment_method' => 'zoho', 'payment_status' => 'paid',
        ], $overrides));
        foreach ($lines as $l) {
            OrderItem::query()->create([
                'order_id' => $order->id, 'product_id' => 1, 'part_no' => $l[0], 'title' => 'Part '.$l[0],
                'unit_price' => $l[2], 'gst_rate' => $l[3] ?? 18, 'hsn' => '8467',
                'qty' => $l[1], 'line_total' => round($l[1] * $l[2], 2),
            ]);
        }

        return $order->refresh();
    }

    /** @param  array<string, array<string, int>>  $plan  part_no => [BRANCH => qty] */
    private function approve(Order $order, array $plan): void
    {
        $lines = [];
        foreach ($order->items as $item) {
            if (isset($plan[$item->part_no])) {
                $lines[] = ['order_item_id' => $item->id, 'branches' => $plan[$item->part_no]];
            }
        }
        $this->api()->postJson("/api/v1/admin/orders/{$order->number}/allocate", [
            'expected_version' => $order->fresh()->allocation_version, 'lines' => $lines,
        ])->assertOk();
    }

    private function sub(Order $order, string $branch): SubOrder
    {
        return SubOrder::query()->where('order_id', $order->id)->where('branch', $branch)->firstOrFail();
    }

    /** Challan every pending unit (or the given part=>qty) on a sub-order. */
    private function challan(SubOrder $sub, ?array $qty = null)
    {
        $sub->refresh();
        $lines = $sub->items->map(fn ($i) => [
            'sub_order_item_id' => $i->id,
            'qty' => $qty[$i->part_no] ?? ($qty === null ? $i->pendingQty() : 0),
        ])->values()->all();

        return $this->api()->postJson("/api/v1/admin/sub-orders/{$sub->id}/challans", [
            'expected_version' => $sub->version, 'lines' => $lines,
        ]);
    }

    // ---- step 2/3: sub-orders + challans ------------------------------------

    public function test_challan_moves_quantity_and_queues_one_push_per_line(): void
    {
        $order = $this->order([['MZ1', 3, 100], ['MZ2', 1, 50]]);
        $this->approve($order, ['MZ1' => ['KOLKATA' => 3], 'MZ2' => ['KOLKATA' => 1]]);
        $sub = $this->sub($order, 'KOLKATA');

        $res = $this->challan($sub, ['MZ1' => 2])->assertOk();

        $this->assertMatchesRegularExpression('#^CH/KOL/000001/\d\d-\d\d$#', $res->json('challan_number'));
        $res->assertJsonPath('sub_order.status', 'partially_challaned')
            ->assertJsonPath('sub_order.items.0.qty_challaned', 2)
            ->assertJsonPath('sub_order.items.0.qty_pending', 1);
        $this->assertSame(1, B2bPush::query()->where('status', 'pending')->count());
        Queue::assertPushed(PushChallanItemToB2b::class, 1);

        $line = Challan::query()->firstOrFail()->items()->first();
        $this->assertSame('100.00', (string) $line->unit_price);
        $this->assertSame('18.00', (string) $line->gst_rate);
    }

    public function test_challan_over_pending_is_422_and_stale_version_is_409(): void
    {
        $order = $this->order([['MZ1', 2, 100]]);
        $this->approve($order, ['MZ1' => ['KOLKATA' => 2]]);
        $sub = $this->sub($order, 'KOLKATA');

        $this->challan($sub, ['MZ1' => 3])->assertStatus(422);
        $stale = $sub->fresh()->version;
        $this->challan($sub, ['MZ1' => 1])->assertOk();
        $this->api()->postJson("/api/v1/admin/sub-orders/{$sub->id}/challans", [
            'expected_version' => $stale, 'lines' => [['sub_order_item_id' => $sub->items()->first()->id, 'qty' => 1]],
        ])->assertStatus(409);
        $this->assertDatabaseCount('challans', 1);
    }

    public function test_sub_order_cancel_only_touches_unchallaned_quantity(): void
    {
        $order = $this->order([['MZ1', 3, 100]]);
        $this->approve($order, ['MZ1' => ['KOLKATA' => 3]]);
        $sub = $this->sub($order, 'KOLKATA');
        $this->challan($sub, ['MZ1' => 2])->assertOk();
        $cancel = fn (int $qty) => $this->api()->postJson("/api/v1/admin/sub-orders/{$sub->id}/cancel", [
            'expected_version' => $sub->fresh()->version,
            'lines' => [['sub_order_item_id' => $sub->items()->first()->id, 'qty' => $qty]],
        ]);

        $cancel(2)->assertStatus(422);
        $cancel(1)->assertOk()->assertJsonPath('sub_order.status', 'challaned');

        $this->assertSame(1, $order->items()->first()->qty_cancelled);
        $this->api()->getJson("/api/v1/admin/orders/{$order->number}/allocation")
            ->assertJsonPath('allocation.lines.0.allocated', 2)
            ->assertJsonPath('allocation.lines.0.cancelled', 1)
            ->assertJsonPath('allocation.lines.0.unallocated', 0);
    }

    // ---- step 3: B2B push outcomes -------------------------------------------

    private function pushFor(?array $response = null, ?\Throwable $throw = null): B2bPush
    {
        $order = $this->order([['MZ1', 2, 118.59]]);
        $this->approve($order, ['MZ1' => ['MUMBAI' => 2]]);
        $this->challan($this->sub($order, 'MUMBAI'))->assertOk();
        $push = B2bPush::query()->latest('id')->firstOrFail();

        Http::fake(function (Request $r) use ($response, $throw) {
            if ($throw) {
                throw $throw;
            }

            return Http::response($response[0], $response[1]);
        });
        (new PushChallanItemToB2b($push->id))->handle();

        return $push->fresh();
    }

    public function test_push_success_sends_sub_order_id_and_warehouse_and_stores_record_id(): void
    {
        $push = $this->pushFor([['success' => true, 'data' => ['b2c_order_record_id' => 77]], 201]);

        $this->assertSame('sent', $push->status);
        $this->assertSame('77', $push->b2c_order_record_id);
        $sub = SubOrder::query()->where('branch', 'MUMBAI')->firstOrFail();
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/order-item')
            && $r['order_id'] === $sub->id && $r['warehouse_id'] === 6 && $r['qty'] === 2
            && $r['price'] === 118.59 && $r['part_no'] === 'MZ1'
            && $r->hasHeader('Authorization', 'Bearer test-token'));
    }

    public function test_push_clear_rejection_is_failed(): void
    {
        $push = $this->pushFor([['success' => false, 'message' => 'Invalid part'], 422]);
        $this->assertSame('failed', $push->status);
        $this->assertStringContainsString('Invalid part', $push->last_error);
    }

    public function test_push_connection_refused_is_failed(): void
    {
        $push = $this->pushFor(null, new ConnectionException('cURL error 7: Failed to connect'));
        $this->assertSame('failed', $push->status);
    }

    public function test_push_dns_failure_is_failed(): void
    {
        $push = $this->pushFor(null, new ConnectionException('cURL error 6: Could not resolve host: b2b.test'));
        $this->assertSame('failed', $push->status);
    }

    public function test_push_connection_reset_after_send_is_needs_check(): void
    {
        $this->assertSame('needs_check', $this->pushFor(null, new ConnectionException('cURL error 56: Recv failure: Connection reset by peer'))->status);
        $this->assertSame('needs_check', $this->pushFor(null, new ConnectionException('cURL error 52: Empty reply from server'))->status);
    }

    public function test_push_success_false_with_200_is_failed(): void
    {
        $this->assertSame('failed', $this->pushFor([['success' => false, 'message' => 'Out of stock'], 200])->status);
    }

    public function test_redelivered_job_does_not_resend_a_push_already_in_flight(): void
    {
        $order = $this->order([['MZ1', 1, 100]]);
        $this->approve($order, ['MZ1' => ['KOLKATA' => 1]]);
        $this->challan($this->sub($order, 'KOLKATA'))->assertOk();
        $push = B2bPush::query()->firstOrFail();
        $push->update(['status' => 'sending', 'attempts' => 1, 'last_attempt_at' => now()]);

        Http::fake();
        (new PushChallanItemToB2b($push->id))->handle();
        Http::assertNothingSent();
        $this->assertSame('sending', $push->fresh()->status);
        // Admin retry cannot race it either.
        $this->api()->postJson("/api/v1/admin/b2b-pushes/{$push->id}/retry", ['confirmed_not_received' => true])->assertStatus(409);
    }

    public function test_sweeper_flags_stuck_pushes_and_requeues_orphans(): void
    {
        $order = $this->order([['MZ1', 1, 100], ['MZ2', 1, 100], ['MZ3', 1, 100]]);
        $this->approve($order, ['MZ1' => ['KOLKATA' => 1], 'MZ2' => ['KOLKATA' => 1], 'MZ3' => ['KOLKATA' => 1]]);
        $this->challan($this->sub($order, 'KOLKATA'))->assertOk();
        [$stuck, $orphan, $fresh] = B2bPush::query()->orderBy('id')->get()->all();
        B2bPush::query()->whereKey($stuck->id)->update(['status' => 'sending', 'last_attempt_at' => now()->subMinutes(30)]);
        B2bPush::query()->whereKey($orphan->id)->update(['updated_at' => now()->subMinutes(30)]);
        foreach ([$stuck, $orphan, $fresh] as $p) {
            Cache::lock('laravel_unique_job:'.PushChallanItemToB2b::class.':'.$p->id)->forceRelease();
        }
        Queue::fake();

        $this->artisan('fulfilment:sweep-b2b-pushes')->assertSuccessful();

        $this->assertSame('needs_check', $stuck->fresh()->status);
        Queue::assertPushed(PushChallanItemToB2b::class, fn ($job) => $job->pushId === $orphan->id);
        Queue::assertPushed(PushChallanItemToB2b::class, 1);
    }

    public function test_push_timeout_is_needs_check(): void
    {
        $push = $this->pushFor(null, new ConnectionException('cURL error 28: Operation timed out'));
        $this->assertSame('needs_check', $push->status);
    }

    public function test_push_unclear_answer_is_needs_check(): void
    {
        $this->assertSame('needs_check', $this->pushFor([['ok' => 1], 200])->status);
        $this->assertSame('needs_check', $this->pushFor(['<html>Bad gateway</html>', 502])->status);
    }

    public function test_sent_push_is_never_sent_again(): void
    {
        $push = $this->pushFor([['success' => true, 'data' => ['b2c_order_record_id' => 1]], 201]);
        Http::fake();
        (new PushChallanItemToB2b($push->id))->handle();
        Http::assertNothingSent();
    }

    public function test_needs_check_retry_requires_confirmation_and_resolve_marks_sent(): void
    {
        $push = $this->pushFor(null, new ConnectionException('cURL error 28: timed out'));

        $this->api()->postJson("/api/v1/admin/b2b-pushes/{$push->id}/retry")->assertStatus(422);
        $this->api()->postJson("/api/v1/admin/b2b-pushes/{$push->id}/resolve")->assertStatus(422);
        $this->api()->postJson("/api/v1/admin/b2b-pushes/{$push->id}/resolve", ['b2c_order_record_id' => '4512'])->assertOk()
            ->assertJsonPath('challan.items.0.push.status', 'sent')
            ->assertJsonPath('challan.items.0.push.b2c_order_record_id', '4512');
        $this->api()->postJson("/api/v1/admin/b2b-pushes/{$push->id}/resolve", ['note' => 'again'])->assertStatus(409);
        $this->api()->postJson("/api/v1/admin/b2b-pushes/{$push->id}/retry")->assertStatus(409);
    }

    public function test_failed_push_can_be_retried(): void
    {
        $push = $this->pushFor([['success' => false], 500]);
        $this->api()->postJson("/api/v1/admin/b2b-pushes/{$push->id}/retry")->assertOk()
            ->assertJsonPath('challan.items.0.push.status', 'pending');
        // The first job is still "in the queue" under Queue::fake, so the unique lock
        // swallows the duplicate; that queued job picks up the pending status itself.
        Queue::assertPushed(PushChallanItemToB2b::class, 1);
        Cache::lock('laravel_unique_job:'.PushChallanItemToB2b::class.':'.$push->id)->forceRelease();
        $this->api()->postJson("/api/v1/admin/b2b-pushes/{$push->id}/retry")->assertStatus(409);
    }

    // ---- step 4: invoices ----------------------------------------------------

    public function test_invoice_back_calculates_gst_intra_state(): void
    {
        $order = $this->order([['MZ1', 1, 118.00]], 'West Bengal');
        $this->approve($order, ['MZ1' => ['KOLKATA' => 1]]);
        $this->challan($this->sub($order, 'KOLKATA'))->assertOk();
        $id = Challan::query()->value('id');

        $inv = $this->api()->postJson('/api/v1/admin/invoices', ['challan_ids' => [$id]])->assertCreated()->json('invoice');

        $this->assertMatchesRegularExpression('#^KOL/B2C/0001/\d\d-\d\d$#', $inv['number']);
        $this->assertTrue($inv['intra_state']);
        $this->assertSame('100.00', $inv['taxable_total']);
        $this->assertSame('9.00', $inv['cgst_total']);
        $this->assertSame('9.00', $inv['sgst_total']);
        $this->assertSame('0.00', $inv['igst_total']);
        $this->assertSame('118.00', $inv['grand_total']);
    }

    public function test_invoice_inter_state_uses_igst(): void
    {
        $order = $this->order([['MZ1', 1, 118.00]], 'Maharashtra');
        $this->approve($order, ['MZ1' => ['KOLKATA' => 1]]);
        $this->challan($this->sub($order, 'KOLKATA'))->assertOk();

        $inv = $this->api()->postJson('/api/v1/admin/invoices', ['challan_ids' => [Challan::query()->value('id')]])->json('invoice');
        $this->assertFalse($inv['intra_state']);
        $this->assertSame('18.00', $inv['igst_total']);
    }

    public function test_state_aliases_count_as_the_same_state(): void
    {
        $order = $this->order([['MZ1', 1, 118.00]], 'NCT of Delhi');
        $this->approve($order, ['MZ1' => ['DELHI' => 1]]);
        $this->challan($this->sub($order, 'DELHI'))->assertOk();

        $inv = $this->api()->postJson('/api/v1/admin/invoices/preview', ['challan_ids' => [Challan::query()->value('id')]])->json('preview');
        $this->assertTrue($inv['intra_state']);
        $this->assertTrue(IndianState::same('Odisha', ' orissa '));
        $this->assertFalse(IndianState::same('West Bengal', 'Maharashtra'));
    }

    public function test_challan_is_blocked_when_no_gst_rate_is_known(): void
    {
        $order = $this->order([['MZ1', 1, 100]]);
        OrderItem::query()->update(['gst_rate' => null, 'product_id' => 999999]);
        $this->approve($order, ['MZ1' => ['KOLKATA' => 1]]);

        $this->challan($this->sub($order, 'KOLKATA'))->assertStatus(422)->assertJsonFragment(['message' => 'No GST rate is set for MZ1. Set it on the product before making a challan.']);
        $this->assertDatabaseCount('challans', 0);
    }

    public function test_invoice_blocked_for_unsettled_push_or_cancelled_order(): void
    {
        $order = $this->order([['MZ1', 1, 100]]);
        $this->approve($order, ['MZ1' => ['KOLKATA' => 1]]);
        $this->challan($this->sub($order, 'KOLKATA'))->assertOk();
        $id = Challan::query()->value('id');

        B2bPush::query()->update(['status' => 'needs_check']);
        $this->api()->postJson('/api/v1/admin/invoices', ['challan_ids' => [$id]])->assertStatus(422);
        B2bPush::query()->update(['status' => 'sent']);
        $order->update(['status' => 'cancelled']);
        $this->api()->postJson('/api/v1/admin/invoices', ['challan_ids' => [$id]])->assertStatus(422);
        $order->update(['status' => 'processing']);
        $this->api()->postJson('/api/v1/admin/invoices', ['challan_ids' => [$id]])->assertCreated();
    }

    public function test_invoice_uses_snapshot_not_live_catalog(): void
    {
        $order = $this->order([['MZ1', 1, 112.00, 12]]);
        $this->approve($order, ['MZ1' => ['KOLKATA' => 1]]);
        $this->challan($this->sub($order, 'KOLKATA'))->assertOk();
        OrderItem::query()->update(['gst_rate' => 28, 'unit_price' => 999]);

        $inv = $this->api()->postJson('/api/v1/admin/invoices', ['challan_ids' => [Challan::query()->value('id')]])->json('invoice');
        $this->assertSame('100.00', $inv['taxable_total']);
        $this->assertSame('112.00', $inv['grand_total']);
        $this->assertSame('12.00', $inv['items'][0]['gst_rate']);
    }

    public function test_invoice_rejects_mixed_branches_addresses_and_double_invoicing(): void
    {
        $a = $this->order([['MZ1', 2, 100]]);
        $this->approve($a, ['MZ1' => ['KOLKATA' => 1, 'DELHI' => 1]]);
        $this->challan($this->sub($a, 'KOLKATA'))->assertOk();
        $this->challan($this->sub($a, 'DELHI'))->assertOk();
        $b = $this->order([['MZ2', 1, 100]], 'West Bengal', ['shipping_line1' => '9 Other Rd']);
        $this->approve($b, ['MZ2' => ['KOLKATA' => 1]]);
        $this->challan($this->sub($b, 'KOLKATA'))->assertOk();
        [$kolA, $delA, $kolB] = Challan::query()->orderBy('id')->pluck('id')->all();

        $this->api()->postJson('/api/v1/admin/invoices', ['challan_ids' => [$kolA, $delA]])->assertStatus(422);
        $this->api()->postJson('/api/v1/admin/invoices', ['challan_ids' => [$kolA, $kolB]])->assertStatus(422);
        $this->api()->postJson('/api/v1/admin/invoices', ['challan_ids' => [$kolA]])->assertCreated();
        $this->api()->postJson('/api/v1/admin/invoices', ['challan_ids' => [$kolA]])->assertStatus(409);
    }

    public function test_same_customer_same_address_combines_across_orders(): void
    {
        $a = $this->order([['MZ1', 1, 100]]);
        $b = $this->order([['MZ2', 1, 50]]);
        $this->approve($a, ['MZ1' => ['KOLKATA' => 1]]);
        $this->approve($b, ['MZ2' => ['KOLKATA' => 1]]);
        $this->challan($this->sub($a, 'KOLKATA'))->assertOk();
        $this->challan($this->sub($b, 'KOLKATA'))->assertOk();

        $this->api()->postJson('/api/v1/admin/invoices', ['challan_ids' => Challan::query()->pluck('id')->all()])
            ->assertCreated()->assertJsonPath('invoice.grand_total', '150.00')
            ->assertJsonCount(2, 'invoice.challans');
    }

    public function test_split_order_with_cancelled_line_reconciles_to_amount_paid(): void
    {
        // Paise-heavy prices exercise the per-line rounding.
        $order = $this->order([['MZ1', 3, 118.59], ['MZ2', 2, 33.33], ['MZ3', 1, 999.99]], 'Delhi');
        $paid = (float) $order->grand_total;
        $this->approve($order, ['MZ1' => ['KOLKATA' => 2, 'DELHI' => 1], 'MZ2' => ['DELHI' => 2]]);
        $mz3 = $order->items()->where('part_no', 'MZ3')->first();
        $this->api()->postJson("/api/v1/admin/orders/{$order->number}/cancel-unallocated", [
            'expected_version' => $order->fresh()->allocation_version,
            'lines' => [['order_item_id' => $mz3->id, 'qty' => 1]],
        ])->assertOk();

        $this->challan($this->sub($order, 'KOLKATA'))->assertOk();
        $this->challan($this->sub($order, 'DELHI'))->assertOk();
        $invoiced = 0.0;
        foreach (Challan::query()->orderBy('id')->get() as $c) {
            $invoiced += (float) $this->api()->postJson('/api/v1/admin/invoices', ['challan_ids' => [$c->id]])
                ->assertCreated()->json('invoice.grand_total');
        }
        $cancelled = $order->items()->get()->sum(fn ($i) => $i->qty_cancelled * (float) $i->unit_price);

        $this->assertEqualsWithDelta($paid, $invoiced + $cancelled, 0.001);
        $this->assertEqualsWithDelta(355.77 + 66.66, $invoiced, 0.001);
        $this->assertSame(2, Invoice::query()->count());
        // Kolkata → Delhi is IGST; Delhi → Delhi is CGST+SGST.
        $this->assertSame(['KOLKATA' => false, 'DELHI' => true],
            Invoice::query()->pluck('intra_state', 'branch')->map(fn ($v) => (bool) $v)->all());
    }

    // ---- step 5: lists -------------------------------------------------------

    public function test_sub_order_list_hides_fully_invoiced_and_filters_by_branch(): void
    {
        $order = $this->order([['MZ1', 2, 100]]);
        $this->approve($order, ['MZ1' => ['KOLKATA' => 1, 'MUMBAI' => 1]]);
        $this->challan($this->sub($order, 'KOLKATA'))->assertOk();
        $this->api()->postJson('/api/v1/admin/invoices', ['challan_ids' => [Challan::query()->value('id')]])->assertCreated();

        $this->api()->getJson('/api/v1/admin/sub-orders')->assertJsonCount(1, 'data')->assertJsonPath('data.0.branch', 'MUMBAI');
        $this->api()->getJson('/api/v1/admin/sub-orders?include_invoiced=1')->assertJsonCount(2, 'data');
        $this->api()->getJson('/api/v1/admin/sub-orders?branch=KOLKATA')->assertJsonCount(0, 'data');
        $this->api()->getJson('/api/v1/admin/challans?invoiced=yes')->assertJsonCount(1, 'data');
        $this->api()->getJson('/api/v1/admin/invoices')->assertJsonCount(1, 'data');
    }
}
