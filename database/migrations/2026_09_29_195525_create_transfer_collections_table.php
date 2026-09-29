<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transfer_collections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transfer_id')->constrained('transfers')->restrictOnDelete();
            $table->bigInteger('amount_cents'); // USD
            $table->char('currency', 3)->default('USD');
            $table->bigInteger('original_amount_cents')->nullable();
            $table->decimal('rate', 18, 8)->nullable();
            $table->date('received_at');
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index('transfer_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transfer_collections');
    }
};
