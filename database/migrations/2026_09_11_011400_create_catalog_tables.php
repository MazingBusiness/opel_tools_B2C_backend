<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('category_groups', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->foreignId('parent_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->foreignId('category_group_id')->constrained('category_groups')->cascadeOnDelete();
            $table->string('banner')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('brands', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('logo')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('taxes', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->decimal('rate', 8, 2);
            $table->timestamps();
        });

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('hsncode')->nullable();
            $table->foreignId('group_id')->constrained('category_groups')->restrictOnDelete();
            $table->foreignId('category_id')->constrained('categories')->restrictOnDelete();
            $table->foreignId('brand_id')->constrained('brands')->restrictOnDelete();
            $table->string('thumbnail_img')->nullable();
            $table->json('photos')->nullable();
            $table->text('description')->nullable();
            $table->string('tags')->nullable();
            $table->boolean('published')->default(false);
            $table->boolean('approved')->default(true);
            $table->string('unit')->default('pc');
            $table->unsignedInteger('min_qty')->default(1);
            $table->unsignedInteger('piece_per_carton')->default(1);
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->index(['published', 'approved']);
            $table->index('group_id');
            $table->index('category_id');
            $table->index('brand_id');
        });

        Schema::create('product_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->string('part_no')->unique();
            $table->string('part_no_normalized');
            $table->json('options')->nullable();
            $table->string('label')->nullable();
            $table->string('thumbnail_img')->nullable();
            $table->decimal('unit_price', 12, 2);
            $table->decimal('list_price', 12, 2)->nullable();
            $table->decimal('mrp', 12, 2)->nullable();
            $table->decimal('carton_price', 12, 2)->nullable();
            $table->unsignedInteger('current_stock')->default(0);
            $table->unsignedInteger('min_qty')->default(1);
            $table->string('hsncode')->nullable();
            $table->boolean('is_default')->default(false);
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->index('part_no_normalized');
            $table->index(['product_id', 'is_default']);
        });

        Schema::create('product_taxes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('tax_id')->constrained('taxes')->cascadeOnDelete();
            $table->decimal('tax', 8, 2);
            $table->string('tax_type')->default('percent');
            $table->timestamps();

            $table->unique(['product_id', 'tax_id']);
        });

        Schema::create('product_warehouses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_variant_id')->constrained('product_variants')->cascadeOnDelete();
            $table->string('warehouse_code');
            $table->unsignedInteger('qty')->default(0);
            $table->decimal('price', 12, 2)->nullable();
            $table->timestamps();

            $table->unique(['product_variant_id', 'warehouse_code']);
        });

        if (Schema::getConnection()->getDriverName() === 'mysql') {
            Schema::table('products', function (Blueprint $table) {
                $table->fullText('name');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('product_warehouses');
        Schema::dropIfExists('product_taxes');
        Schema::dropIfExists('product_variants');
        Schema::dropIfExists('products');
        Schema::dropIfExists('taxes');
        Schema::dropIfExists('brands');
        Schema::dropIfExists('categories');
        Schema::dropIfExists('category_groups');
    }
};
