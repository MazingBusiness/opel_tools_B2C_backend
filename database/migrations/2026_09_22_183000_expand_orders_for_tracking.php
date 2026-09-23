<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->string('b2b_order_id')->nullable()->after('paid_at');
            $table->string('handoff_status', 32)->nullable()->after('b2b_order_id'); // null|pending|sent|failed
            $table->json('timeline')->nullable()->after('handoff_status');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn(['b2b_order_id', 'handoff_status', 'timeline']);
        });
    }
};
