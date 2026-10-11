<?php

use App\Support\NumberSequence;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Expenses start Pending and need a second person to approve them.
 *
 *  - number           EX-0001 voucher number
 *  - decided_by/at    who approved or rejected, and when; decision_reason is optional
 *  - attachment_*     optional scan or photo of the receipt
 *  - status           posted → approved, voided → rejected
 *
 * The ledger now moves at approval, so `journal_entry_id` is set only while an
 * expense is approved. The old void columns are folded into decision_reason.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->string('number', 20)->nullable()->unique()->after('id');
            $table->foreignId('decided_by')->nullable()->after('status')->constrained('users')->restrictOnDelete();
            $table->timestamp('decided_at')->nullable()->after('decided_by');
            $table->string('decision_reason', 500)->nullable()->after('decided_at');
            $table->string('attachment_path')->nullable();
            $table->string('attachment_name')->nullable();
        });

        $this->convertExistingRows();

        Schema::table('expenses', function (Blueprint $table) {
            $table->dropConstrainedForeignId('void_journal_entry_id');
            $table->dropConstrainedForeignId('voided_by');
            $table->dropColumn(['voided_at', 'void_reason']);
        });

        // Belt and braces for maker ≠ checker: even a bug in the app cannot
        // store an expense approved by the person who created it. SQLite
        // (the test database) cannot add a constraint after the fact.
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE expenses ADD CONSTRAINT expenses_maker_checker CHECK (decided_by IS NULL OR decided_by <> created_by)');
        }
    }

    public function down(): void
    {
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE expenses DROP CONSTRAINT expenses_maker_checker');
        }

        Schema::table('expenses', function (Blueprint $table) {
            $table->foreignId('void_journal_entry_id')->nullable()->constrained('journal_entries')->restrictOnDelete();
            $table->foreignId('voided_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->string('void_reason', 500)->nullable();
        });

        DB::table('expenses')->where('status', 'approved')->update(['status' => 'posted']);
        DB::table('expenses')->where('status', 'rejected')->update(['status' => 'voided']);

        Schema::table('expenses', function (Blueprint $table) {
            $table->dropConstrainedForeignId('decided_by');
            $table->dropColumn(['number', 'decided_at', 'decision_reason', 'attachment_path', 'attachment_name']);
        });
    }

    private function convertExistingRows(): void
    {
        $names = DB::table('users')->pluck('name', 'id');
        $last = 0;

        foreach (DB::table('expenses')->orderBy('id')->get() as $row) {
            $update = ['number' => NumberSequence::format('EX', ++$last)];

            if ($row->status === 'voided') {
                // Paid, then reversed: net effect nil, so it is Rejected and holds no posting.
                $who = $row->voided_by ? ($names[$row->voided_by] ?? null) : null;
                $update += [
                    'status' => 'rejected',
                    'decided_at' => $row->voided_at,
                    'decision_reason' => mb_substr(trim('Voided before approvals existed'.($who ? " (by {$who})" : '').': '.($row->void_reason ?? '')), 0, 500),
                    'journal_entry_id' => null,
                ];
            } else {
                $update += [
                    'status' => 'approved',
                    'decided_at' => $row->created_at,
                    'decision_reason' => 'Paid before approvals existed',
                ];
            }

            DB::table('expenses')->where('id', $row->id)->update($update);
        }

        if ($last > 0) {
            DB::table('number_sequences')->updateOrInsert(
                ['name' => 'expense'],
                ['last_value' => $last, 'created_at' => now(), 'updated_at' => now()],
            );
        }
    }
};
