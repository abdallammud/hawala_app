<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('from_company_account_id')->constrained('company_accounts')->restrictOnDelete();
            $table->foreignId('to_company_account_id')->constrained('company_accounts')->restrictOnDelete();
            $table->bigInteger('sent_cents');
            $table->bigInteger('received_cents');
            $table->bigInteger('charges_cents');
            $table->string('reference')->nullable();
            $table->date('transferred_at');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->restrictOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_transfers');
    }
};
