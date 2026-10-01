<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transfers', function (Blueprint $table) {
            // Which leg the agent typed: 'pay' (customer hands over X in their
            // currency) or 'receive' (receiver must get X USD). BUILD.md §7.
            $table->string('quote_mode', 10)->default('pay')->after('original_currency');

            // Extra collected on top of the computed amount, in the customer's
            // currency — the agent rounding €94.93 up to €95.00. Always 0
            // unless the agent holds transfers.round_collect.
            $table->bigInteger('round_up_original_cents')->default(0)->after('collect_original_cents');
        });

        // Rows created before two-way quoting were all typed in the customer's
        // currency, which is what 'pay' means. Their stored amounts are
        // untouched (money rule 8).
        DB::table('transfers')->update(['quote_mode' => 'pay']);
    }

    public function down(): void
    {
        Schema::table('transfers', function (Blueprint $table) {
            $table->dropColumn(['quote_mode', 'round_up_original_cents']);
        });
    }
};
