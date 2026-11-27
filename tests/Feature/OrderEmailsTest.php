<?php

namespace Tests\Feature;

use App\Modules\Auth\Models\User;
use App\Modules\Order\Mail\OrderMail;
use App\Modules\Order\Models\Order;
use App\Modules\Order\Models\OrderItem;
use App\Modules\Order\Support\OrderTimeline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class OrderEmailsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        config(['mail.order_alert_to' => null]);
    }

    /** @param  array<string, mixed>  $overrides */
    private function placeOrder(?User $user = null, array $overrides = []): Order
    {
        $user ??= User::factory()->create(['email' => 'buyer@example.com']);

        return DB::transaction(function () use ($user, $overrides) {
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
                'item_count' => 2,
                'subtotal' => 1000,
                'shipping_fee' => 0,
                'grand_total' => 1000,
                'currency' => 'INR',
                'payment_method' => 'cod',
                'payment_status' => 'unpaid',
                'timeline' => OrderTimeline::forStatus('processing', now()),
            ], $overrides));
            OrderItem::query()->create([
                'order_id' => $order->id,
                'product_id' => 1,
                'part_no' => 'PT-1',
                'title' => 'Angle Grinder | 4in',
                'unit_price' => 500,
                'qty' => 2,
                'line_total' => 1000,
            ]);

            return $order;
        });
    }

    /** @return list<string> */
    private function sentEvents(): array
    {
        return Mail::sent(OrderMail::class)->map(fn (OrderMail $m) => $m->event)->values()->all();
    }

    public function test_cod_order_sends_placed_email_to_the_shopper_with_its_items(): void
    {
        $order = $this->placeOrder();

        Mail::assertSent(OrderMail::class, fn (OrderMail $m) => $m->event === OrderMail::PLACED
            && $m->hasTo('buyer@example.com')
            && $m->order->is($order));
        $this->assertSame([OrderMail::PLACED], $this->sentEvents());

        $html = (new OrderMail($order->fresh(), OrderMail::PLACED))->render();
        $this->assertStringContainsString($order->number, $html);
        $this->assertStringContainsString('Angle Grinder | 4in', $html);
        $this->assertStringContainsString('/profile/orders/'.$order->number, $html);
        $this->assertNotNull(DB::table('order_notifications')->where('order_id', $order->id)->value('sent_at'));
    }

    public function test_online_order_sends_placed_only_once_it_is_paid_and_only_once(): void
    {
        $order = $this->placeOrder(null, ['status' => 'pending_payment', 'payment_method' => 'zoho']);
        $this->assertSame([], $this->sentEvents());

        OrderTimeline::applyPaid($order->fresh(), 'pay_1');
        OrderTimeline::applyPaid($order->fresh(), 'pay_1');
        $order->fresh()->update(['status' => 'failed']);
        $order->fresh()->update(['status' => 'processing']);

        $this->assertSame([OrderMail::PLACED], $this->sentEvents());
    }

    public function test_approval_rejection_shipping_and_delivery_each_send_one_email(): void
    {
        $order = $this->placeOrder();
        $order->update(['approval_status' => 'partial']);
        $order->update(['approval_status' => 'approved']);
        $order->update(['status' => 'shipped']);
        $order->update(['status' => 'delivered']);
        $order->update(['status' => 'delivered', 'payment_notes' => 'touch']);

        $this->assertSame(
            [OrderMail::PLACED, OrderMail::APPROVED, OrderMail::SHIPPED, OrderMail::DELIVERED],
            $this->sentEvents(),
        );

        $rejected = $this->placeOrder(User::factory()->create(['email' => 'other@example.com']));
        $rejected->update(['approval_status' => 'cancelled']);
        Mail::assertSent(OrderMail::class, fn (OrderMail $m) => $m->event === OrderMail::REJECTED && $m->order->is($rejected));
    }

    public function test_staff_get_a_new_order_alert_when_configured(): void
    {
        config(['mail.order_alert_to' => 'ops@example.com, bad-address, sales@example.com']);

        $this->placeOrder();

        Mail::assertSent(OrderMail::class, fn (OrderMail $m) => $m->event === OrderMail::STAFF_NEW_ORDER
            && $m->hasTo('ops@example.com') && $m->hasTo('sales@example.com') && ! $m->hasTo('buyer@example.com'));
    }

    public function test_shopper_without_email_gets_nothing_and_the_gap_is_recorded(): void
    {
        $user = User::factory()->create(['email' => null, 'phone' => '919800000001']);

        $order = $this->placeOrder($user);

        Mail::assertNothingSent();
        $row = DB::table('order_notifications')->where('order_id', $order->id)->first();
        $this->assertNull($row->sent_at);
        $this->assertSame('No recipient email.', $row->error);
    }

    public function test_rolled_back_order_sends_nothing(): void
    {
        $user = User::factory()->create(['email' => 'buyer@example.com']);

        try {
            DB::transaction(function () use ($user) {
                $this->placeOrder($user);
                throw new RuntimeException('stock moved');
            });
        } catch (RuntimeException) {
        }

        Mail::assertNothingSent();
        $this->assertSame(0, DB::table('order_notifications')->count());
    }

    public function test_partly_cancelled_approval_says_so(): void
    {
        $order = $this->placeOrder();
        $order->items()->update(['qty_cancelled' => 1]);
        $order->update(['approval_status' => 'approved']);

        $mail = new OrderMail($order->fresh()->load('items'), OrderMail::APPROVED);
        $this->assertSame('Order '.$order->number.' partly confirmed', $mail->envelope()->subject);
        $this->assertStringContainsString('could not be fulfilled', $mail->render());
    }

    public function test_customer_text_cannot_inject_markdown_links_into_staff_alert(): void
    {
        $order = $this->placeOrder(null, ['shipping_name' => '[Verify payment](https://evil.test)']);

        $html = (new OrderMail($order->fresh()->load(['items', 'user']), OrderMail::STAFF_NEW_ORDER))->render();

        $this->assertStringNotContainsString('href="https://evil.test"', $html);
        $this->assertStringContainsString('[Verify payment](https://evil.test)', html_entity_decode($html));
    }
}
