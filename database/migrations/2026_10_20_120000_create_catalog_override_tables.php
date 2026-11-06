<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Admin catalog overrides, stored apart from the B2B base rows.
 *
 * One table per entity, one row per B2B row (unique FK column, no FK
 * constraint so a catalog reload can never cascade-delete overrides).
 * Every override column is nullable: NULL = "no override, use B2B";
 * any non-null value (including "" or []) is a deliberate override.
 * `version` guards admin saves (optimistic concurrency, same as seo_meta).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('category_group_overrides', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('category_group_id')->unique();
            $table->string('name')->nullable();
            $table->boolean('hidden')->nullable()->index();
            $table->integer('sort_order')->nullable();
            $this->meta($table);
        });

        Schema::create('category_overrides', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('category_id')->unique();
            $table->string('name')->nullable();
            $table->string('banner', 2048)->nullable();
            $table->boolean('hidden')->nullable()->index();
            $table->integer('sort_order')->nullable();
            $this->meta($table);
        });

        Schema::create('product_overrides', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_id')->unique();
            $table->string('name')->nullable();
            $table->text('description')->nullable();
            $table->string('thumbnail_img', 2048)->nullable();
            $table->json('photos')->nullable();
            $table->string('tags')->nullable();
            $table->string('hsncode')->nullable();
            $table->string('unit')->nullable();
            $table->unsignedInteger('min_qty')->nullable();
            $table->unsignedInteger('piece_per_carton')->nullable();
            $table->boolean('hidden')->nullable()->index();
            $table->integer('sort_order')->nullable();
            $this->meta($table);
        });

        Schema::create('product_variant_overrides', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_variant_id')->unique();
            $table->string('label')->nullable();
            $table->string('thumbnail_img', 2048)->nullable();
            $table->decimal('unit_price', 12, 2)->nullable();
            $table->decimal('list_price', 12, 2)->nullable();
            $table->decimal('mrp', 12, 2)->nullable();
            $table->decimal('carton_price', 12, 2)->nullable();
            // Stock CAP: effective stock = min(B2B stock, this). Never raises stock.
            $table->unsignedInteger('current_stock')->nullable();
            $table->unsignedInteger('min_qty')->nullable();
            $table->string('hsncode')->nullable();
            $this->meta($table);
        });
    }

    private function meta(Blueprint $table): void
    {
        $table->unsignedInteger('version')->default(1);
        $table->unsignedBigInteger('updated_by')->nullable();
        $table->timestamps();
    }

    public function down(): void
    {
        Schema::dropIfExists('product_variant_overrides');
        Schema::dropIfExists('product_overrides');
        Schema::dropIfExists('category_overrides');
        Schema::dropIfExists('category_group_overrides');
    }
};
