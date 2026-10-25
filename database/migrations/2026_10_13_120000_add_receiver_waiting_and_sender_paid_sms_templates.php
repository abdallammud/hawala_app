<?php

use Database\Seeders\SmsTemplatesSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Existing installs only run migrations, not seeders: add the two new
 * templates (receiver_waiting, sender_paid). Inactive until an admin turns
 * them on; the seeder only creates what is missing.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('sms_templates')) {
            return;
        }

        (new SmsTemplatesSeeder)->run();
    }

    public function down(): void
    {
        //
    }
};
