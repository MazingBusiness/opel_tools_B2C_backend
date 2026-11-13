<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Single row (id 1): the admin-edited homepage layout. No row = default layout.
        Schema::create('home_layouts', function (Blueprint $table): void {
            $table->id();
            $table->json('sections');
            $table->unsignedInteger('version')->default(0);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('home_layouts');
    }
};
