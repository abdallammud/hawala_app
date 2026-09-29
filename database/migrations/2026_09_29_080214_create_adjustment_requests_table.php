<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('adjustment_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('debit_ledger_account_id')->constrained('ledger_accounts')->restrictOnDelete();
            $table->foreignId('credit_ledger_account_id')->constrained('ledger_accounts')->restrictOnDelete();
            $table->bigInteger('amount_cents');
            $table->text('reason');
            $table->string('status', 10)->default('pending');
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->restrictOnDelete();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('adjustment_requests');
    }
};
