<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ledger_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('type', 30);
            $table->string('normal_side', 10);
            $table->nullableMorphs('owner');
            $table->string('name');
            $table->bigInteger('balance_cents')->default(0);
            $table->timestamps();

            $table->unique(['type', 'owner_type', 'owner_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_accounts');
    }
};
