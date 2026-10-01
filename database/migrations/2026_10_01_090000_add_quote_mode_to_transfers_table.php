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
        });

        // Rows created before two-way quoting were all typed in the customer's
        // currency, which is what 'pay' means. Their stored amounts are
        // untouched (money rule 8).
        DB::table('transfers')->update(['quote_mode' => 'pay']);
    }

    public function down(): void
    {
        Schema::table('transfers', function (Blueprint $table) {
            $table->dropColumn('quote_mode');
        });
    }
};
