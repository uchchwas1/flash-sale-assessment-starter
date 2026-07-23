<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->constrained('items')->restrictOnDelete();
            $table->string('user_id', 100)->collation('utf8mb4_bin');
            $table->timestamps();

            // One purchase per user per item — Unique
            $table->unique(['item_id', 'user_id'], 'uniq_item_user');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
