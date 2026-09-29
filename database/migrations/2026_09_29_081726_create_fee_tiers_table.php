<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fee_tiers', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('from_cents');
            $table->bigInteger('to_cents')->nullable(); // null = no upper limit
            $table->integer('rate_bp');
            $table->bigInteger('flat_cents')->default(0);
            $table->bigInteger('cap_cents')->nullable();
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fee_tiers');
    }
};
