<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row: the Zoho Books refresh token from the OAuth connect flow, encrypted by the model cast.
        Schema::create('zoho_books_tokens', function (Blueprint $table): void {
            $table->id();
            $table->text('refresh_token');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('zoho_books_tokens');
    }
};
