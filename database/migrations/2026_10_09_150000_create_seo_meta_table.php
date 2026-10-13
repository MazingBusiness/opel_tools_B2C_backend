<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seo_meta', function (Blueprint $table): void {
            $table->id();
            // page | product | category_group | category
            $table->string('target_type', 32);
            // page key (e.g. "home", "about") or numeric id as string
            $table->string('target_key', 191);
            $table->string('seo_title', 255)->nullable();
            $table->text('meta_description')->nullable();
            $table->string('h1', 255)->nullable();
            $table->longText('content')->nullable(); // sanitized HTML
            $table->string('primary_keyword', 255)->nullable();
            $table->json('secondary_keywords')->nullable();
            $table->string('canonical_url', 2048)->nullable();
            $table->string('robots', 16)->nullable(); // index | noindex | null (default index)
            $table->string('og_title', 255)->nullable();
            $table->text('og_description')->nullable();
            $table->string('og_image', 2048)->nullable();
            $table->longText('schema_jsonld')->nullable(); // validated JSON
            $table->json('faq')->nullable();
            $table->json('related_product_ids')->nullable();
            $table->json('image_alts')->nullable();
            $table->timestamps();

            $table->unique(['target_type', 'target_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seo_meta');
    }
};
