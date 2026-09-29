<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('balance_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete(); // beneficiary
            $table->string('kind', 30);
            $table->bigInteger('amount_cents'); // USD
            $table->char('original_currency', 3)->default('USD');
            $table->bigInteger('original_amount_cents')->nullable();
            $table->decimal('rate', 18, 8)->nullable();
            $table->foreignId('company_account_id')->nullable()->constrained('company_accounts')->restrictOnDelete();
            $table->string('status', 10)->default('pending');
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('note')->nullable();
            $table->string('reference')->nullable();
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->restrictOnDelete();
            $table->timestamps();

            $table->index(['user_id', 'kind', 'status']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('balance_requests');
    }
};
