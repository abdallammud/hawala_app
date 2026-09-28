<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('type', 20)->default('staff')->after('email');
            $table->foreignId('branch_id')->nullable()->after('type')->constrained()->restrictOnDelete();
            $table->string('send_commission_type', 10)->nullable()->after('branch_id');
            $table->integer('send_commission_value')->nullable()->after('send_commission_type');
            $table->string('payout_commission_type', 10)->nullable()->after('send_commission_value');
            $table->integer('payout_commission_value')->nullable()->after('payout_commission_type');
            $table->bigInteger('max_credit_cents')->nullable()->after('payout_commission_value');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('branch_id');
            $table->dropColumn([
                'type',
                'send_commission_type',
                'send_commission_value',
                'payout_commission_type',
                'payout_commission_value',
                'max_credit_cents',
            ]);
        });
    }
};
