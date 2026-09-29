<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transfers', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->uuid('idempotency_key')->unique();

            $table->foreignId('sending_agent_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();

            $table->string('sender_name');
            $table->string('sender_phone');
            $table->string('receiver_name');
            $table->string('receiver_phone');
            $table->foreignId('receiver_account_type_id')->nullable()->constrained('account_types')->restrictOnDelete();
            $table->string('receiver_account_number')->nullable();

            // What the customer is sending, in their currency.
            $table->char('original_currency', 3);
            $table->bigInteger('original_amount_cents');
            $table->decimal('market_rate', 18, 8);
            $table->integer('margin_bp');
            $table->decimal('customer_rate', 18, 8);

            $table->bigInteger('amount_cents');          // USD the receiver gets
            $table->bigInteger('fee_cents');
            $table->bigInteger('fx_income_cents');
            $table->bigInteger('total_debit_cents');     // amount + fee + fx_income
            $table->bigInteger('collect_original_cents'); // total to collect, in their currency

            $table->bigInteger('sending_share_cents');
            $table->bigInteger('payout_share_cents')->nullable();
            $table->bigInteger('company_share_cents')->nullable();
            $table->json('fee_snapshot');
            $table->json('commission_snapshot');

            $table->string('status', 20);
            $table->boolean('is_large')->default(false);

            $table->foreignId('claimed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('claim_expires_at')->nullable();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->restrictOnDelete();

            $table->foreignId('paid_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('payout_account_id')->nullable()->constrained('payout_accounts')->restrictOnDelete();
            $table->string('payout_reference')->nullable();
            $table->timestamp('paid_at')->nullable();

            $table->boolean('is_uncollected')->default(false);
            $table->bigInteger('collected_cents')->default(0);
            $table->timestamp('fully_collected_at')->nullable();

            $table->foreignId('cancelled_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancel_reason')->nullable();
            $table->boolean('fee_refunded')->nullable();

            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['status', 'branch_id']);
            $table->index(['sending_agent_id', 'created_at']);
            $table->index('receiver_phone');
            $table->index('claim_expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transfers');
    }
};
