<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('items', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->unsignedInteger('total_stock');
            $table->unsignedInteger('available_stock');
            $table->timestamps();
        });


        DB::statement('ALTER TABLE items ADD CONSTRAINT chk_available_non_negative CHECK (available_stock >= 0)');
        DB::statement('ALTER TABLE items ADD CONSTRAINT chk_available_lte_total CHECK (available_stock <= total_stock)');
    }

    public function down(): void
    {
        Schema::dropIfExists('items');
    }
};
