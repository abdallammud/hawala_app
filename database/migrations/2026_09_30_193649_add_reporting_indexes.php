<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Indexes for the date-range filters the §12 reports and the dashboards run.
 *
 * The existing transfer indexes cover the operational queries (queue by
 * status + branch, an agent's own list). These cover the company-wide
 * reporting queries, which scan by date across every agent and so could not
 * use the leading column of `(sending_agent_id, created_at)`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transfers', function (Blueprint $table) {
            // TransfersReport, ProfitReport, dashboards: "today"/date range
            // across all agents.
            $table->index('created_at', 'transfers_created_at_index');

            // PayoutsByMethodReport and "paid today".
            $table->index('paid_at', 'transfers_paid_at_index');

            // CancellationsReport.
            $table->index('cancelled_at', 'transfers_cancelled_at_index');

            // UncollectedReport and the uncollected totals: both halves of
            // "still owed" are in the index, so it never touches the table
            // for the filter.
            $table->index(['is_uncollected', 'fully_collected_at'], 'transfers_uncollected_index');

            // LargeTransfersReport and "large this month".
            $table->index(['is_large', 'created_at'], 'transfers_large_index');
        });

        Schema::table('journal_lines', function (Blueprint $table) {
            // ProfitReport scans by date across accounts, so the existing
            // (ledger_account_id, created_at) index cannot serve it.
            $table->index('created_at', 'journal_lines_created_at_index');
        });

        Schema::table('claim_expiries', function (Blueprint $table) {
            // CancellationsReport and "claims timed out today" filter on the
            // date alone; the existing index leads with user_id.
            $table->index('expired_at', 'claim_expiries_expired_at_index');
        });
    }

    public function down(): void
    {
        Schema::table('transfers', function (Blueprint $table) {
            $table->dropIndex('transfers_created_at_index');
            $table->dropIndex('transfers_paid_at_index');
            $table->dropIndex('transfers_cancelled_at_index');
            $table->dropIndex('transfers_uncollected_index');
            $table->dropIndex('transfers_large_index');
        });

        Schema::table('journal_lines', function (Blueprint $table) {
            $table->dropIndex('journal_lines_created_at_index');
        });

        Schema::table('claim_expiries', function (Blueprint $table) {
            $table->dropIndex('claim_expiries_expired_at_index');
        });
    }
};
