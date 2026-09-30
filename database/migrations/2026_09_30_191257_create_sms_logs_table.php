<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sms_logs', function (Blueprint $table) {
            $table->id();
            $table->string('to', 32);
            $table->text('body');
            $table->string('status', 16)->default('queued'); // queued | sent | failed
            $table->text('provider_response')->nullable();
            $table->foreignId('transfer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('event', 40);
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index('transfer_id');
            $table->index('event');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sms_logs');
    }
};
