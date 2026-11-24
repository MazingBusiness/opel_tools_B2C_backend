<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table): void {
            $table->string('part_no')->nullable()->after('variant_id')->index();
        });

        // Backfill from the variant; part_no is the raw B2B id and is never overridden.
        DB::table('order_items')
            ->whereNull('part_no')
            ->whereNotNull('variant_id')
            ->orderBy('id')
            ->chunkById(500, function ($items): void {
                $parts = DB::table('product_variants')
                    ->whereIn('id', $items->pluck('variant_id')->unique())
                    ->pluck('part_no', 'id');
                foreach ($items as $item) {
                    $part = $parts[$item->variant_id] ?? null;
                    if ($part !== null && $part !== '') {
                        DB::table('order_items')->where('id', $item->id)->update(['part_no' => $part]);
                    }
                }
            });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table): void {
            $table->dropIndex(['part_no']);
            $table->dropColumn('part_no');
        });
    }
};
