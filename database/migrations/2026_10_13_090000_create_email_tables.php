<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One editable subject + body per event. Recipients are fixed by the event.
        Schema::create('email_templates', function (Blueprint $table) {
            $table->id();
            $table->string('event', 40)->unique();
            $table->string('subject');
            $table->text('body');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Every email the system tried to send: what, to whom, and how it went.
        Schema::create('email_logs', function (Blueprint $table) {
            $table->id();
            $table->string('event', 40);
            $table->string('to_email');
            $table->string('to_name')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('subject');
            $table->text('body');
            $table->string('status', 12)->default('queued'); // queued | sent | failed | skipped
            $table->text('error')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->boolean('attach_receipt')->default(false);
            $table->foreignId('transfer_id')->nullable()->constrained('transfers')->nullOnDelete();
            $table->foreignId('expense_id')->nullable()->constrained('expenses')->nullOnDelete();
            $table->foreignId('balance_request_id')->nullable()->constrained('balance_requests')->nullOnDelete();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('event');
            $table->index('created_at');
        });

        // Switch off for accounts whose address is a placeholder or not read.
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('email_notifications')->default(true)->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('email_notifications');
        });

        Schema::dropIfExists('email_logs');
        Schema::dropIfExists('email_templates');
    }
};
