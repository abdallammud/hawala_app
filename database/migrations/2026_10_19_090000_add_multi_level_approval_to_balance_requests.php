<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Credit top-ups need two different approvers.
 *
 *  - balance_requests.approvals_required   snapshot taken when the request is created
 *  - balance_request_approvals             one row per approver; unique per (request, user)
 *
 * A credit top-up that is still pending when this runs is raised to two approvals.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('balance_requests', function (Blueprint $table) {
            $table->unsignedTinyInteger('approvals_required')->default(1)->after('status');
        });

        Schema::create('balance_request_approvals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('balance_request_id')->constrained('balance_requests')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('note', 500)->nullable();
            $table->timestamps();

            $table->unique(['balance_request_id', 'user_id']);
        });

        DB::table('balance_requests')
            ->where('kind', 'topup_credit')
            ->where('status', 'pending')
            ->update(['approvals_required' => 2]);
    }

    public function down(): void
    {
        Schema::dropIfExists('balance_request_approvals');

        Schema::table('balance_requests', function (Blueprint $table) {
            $table->dropColumn('approvals_required');
        });
    }
};
