<?php

namespace Tests\Feature;

use App\Modules\Address\Models\Address;
use App\Modules\Auth\Models\User;
use App\Modules\Cart\Models\CartItem;
use App\Modules\Catalog\Models\Product;
use App\Modules\Order\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OrderPlacementTest extends TestCase
{
    use RefreshDatabase;

    public function test_cod_order_snapshots_part_no_and_charges_no_shipping(): void
    {
        $user = User::factory()->create();
        $address = Address::query()->create([
            'user_id' => $user->id, 'name' => 'Home', 'phone' => '919876543210', 'line1' => '1 Street',
            'city' => 'Pune', 'state' => 'Maharashtra', 'pincode' => '411001', 'type' => 'home', 'is_default' => true,
        ]);
        $product = Product::factory()->create(['published' => true, 'approved' => true]);
        $variant = $product->defaultVariant;
        $variant->update(['part_no' => 'MZ11018', 'unit_price' => 500, 'current_stock' => 10]);
        $taxId = DB::table('taxes')->insertGetId(['name' => 'GST', 'rate' => 18]);
        DB::table('product_taxes')->insert(['product_id' => $product->id, 'tax_id' => $taxId, 'tax' => 18]);
        CartItem::query()->create(['user_id' => $user->id, 'product_id' => $product->id, 'variant_id' => $variant->id, 'qty' => 2]);

        $res = $this->actingAs($user, 'sanctum')->postJson('/api/v1/orders', [
            'address_id' => $address->id,
            'payment_method' => 'cod',
        ]);

        $res->assertCreated();
        $order = Order::query()->with('items')->firstOrFail();
        $this->assertSame('MZ11018', $order->items->first()->part_no);
        $this->assertSame('18.00', (string) $order->items->first()->gst_rate);
        // Below the old ₹2,999 free-shipping threshold: shipping is still 0.
        $this->assertSame('0.00', (string) $order->shipping_fee);
        $this->assertSame((string) $order->subtotal, (string) $order->grand_total);
    }
}
