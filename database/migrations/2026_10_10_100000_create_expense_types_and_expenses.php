<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The customer can add more; these four are just where they start.
        Schema::create('expense_types', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });

        foreach (['Staff Payroll', 'Office Rent', 'Bills (Internet & airtime)', 'Running Cost'] as $i => $name) {
            DB::table('expense_types')->insert([
                'name' => $name, 'is_active' => true, 'sort' => $i, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('expense_type_id')->constrained('expense_types')->restrictOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->restrictOnDelete();
            $table->foreignId('company_account_id')->constrained('company_accounts')->restrictOnDelete();

            // All stored amounts are USD; a non-USD entry keeps what was typed.
            $table->bigInteger('amount_cents');
            $table->string('original_currency', 3)->default('USD');
            $table->bigInteger('original_amount_cents');
            $table->decimal('rate', 18, 8)->nullable();

            $table->date('expense_date');
            $table->string('payee')->nullable();
            $table->string('reference')->nullable();
            $table->text('description')->nullable();

            $table->string('status', 20)->default('posted');
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->restrictOnDelete();
            $table->foreignId('void_journal_entry_id')->nullable()->constrained('journal_entries')->restrictOnDelete();
            $table->foreignId('voided_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->string('void_reason', 500)->nullable();

            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->uuid('idempotency_key')->unique();
            $table->timestamps();

            $table->index('expense_date');
            $table->index(['branch_id', 'status']);
            $table->index('expense_type_id');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expenses');
        Schema::dropIfExists('expense_types');
    }
};
