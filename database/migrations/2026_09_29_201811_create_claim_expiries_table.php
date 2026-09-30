<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BUILD.md §12's "Cancellations & time-outs" report needs claim expiries
 * per payout agent. The transfer row only keeps its *current* state, so
 * expiries are recorded here as they happen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('claim_expiries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transfer_id')->constrained('transfers')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete(); // who held the claim
            $table->timestamp('expired_at');
            $table->timestamps();

            $table->index(['user_id', 'expired_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('claim_expiries');
    }
};
