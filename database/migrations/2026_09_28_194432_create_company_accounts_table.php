<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('location', 20); // europe | somalia | other
            $table->boolean('is_active')->default(true);
            $table->foreignId('ledger_account_id')->nullable()->constrained('ledger_accounts')->restrictOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_accounts');
    }
};
