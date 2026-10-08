<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Every user now has a home branch, so branches also exist in
        // Europe. Only payout points are offered as a transfer destination.
        Schema::table('branches', function (Blueprint $table) {
            $table->boolean('is_payout_point')->default(true)->after('city');
        });

        // The sending agent's own branch, frozen when the transfer is made.
        // `branch_id` stays the destination (payout) branch.
        Schema::table('transfers', function (Blueprint $table) {
            $table->foreignId('origin_branch_id')->nullable()->after('branch_id')
                ->constrained('branches')->nullOnDelete();
            $table->index('origin_branch_id');
        });
    }

    public function down(): void
    {
        Schema::table('transfers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('origin_branch_id');
        });

        Schema::table('branches', function (Blueprint $table) {
            $table->dropColumn('is_payout_point');
        });
    }
};
