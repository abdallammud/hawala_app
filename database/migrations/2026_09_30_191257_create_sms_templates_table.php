<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sms_templates', function (Blueprint $table) {
            $table->id();
            $table->string('event', 40);
            $table->text('body');

            // Who this event's message goes to. A template can target several.
            $table->boolean('to_sender')->default(false);
            $table->boolean('to_receiver')->default(false);
            $table->boolean('to_sending_agent')->default(false);
            $table->boolean('to_payout_agent')->default(false);

            $table->boolean('is_active')->default(false);
            $table->timestamps();

            // One template per event (BUILD.md §13 seeds exactly one each).
            $table->unique('event');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sms_templates');
    }
};
