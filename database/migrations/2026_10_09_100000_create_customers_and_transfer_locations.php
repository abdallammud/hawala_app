<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A customer is a person who sends money. One row per phone number,
        // company-wide, so the same person at two agents is still one person.
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('phone', 32)->unique(); // digits only, see Customer::normalizePhone()
            $table->string('display_phone', 32)->nullable(); // as the agent typed it, for showing back
            $table->string('country', 100)->nullable();
            $table->string('city', 100)->nullable();
            $table->string('province', 100)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('name');
        });

        Schema::table('transfers', function (Blueprint $table) {
            $table->foreignId('customer_id')->nullable()->after('sending_agent_id')
                ->constrained('customers')->nullOnDelete();

            foreach (['sender', 'receiver'] as $side) {
                $table->string("{$side}_country", 100)->nullable()->after("{$side}_phone");
                $table->string("{$side}_city", 100)->nullable()->after("{$side}_country");
                $table->string("{$side}_province", 100)->nullable()->after("{$side}_city");
            }
        });

        $this->backfillCustomers();
    }

    public function down(): void
    {
        Schema::table('transfers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('customer_id');
            $table->dropColumn([
                'sender_country', 'sender_city', 'sender_province',
                'receiver_country', 'receiver_city', 'receiver_province',
            ]);
        });

        Schema::dropIfExists('customers');
    }

    /** Existing transfers become customers, one per sender phone. */
    private function backfillCustomers(): void
    {
        $byPhone = [];

        DB::table('transfers')->orderBy('id')->select(['id', 'sender_name', 'sender_phone', 'sending_agent_id', 'created_at'])
            ->each(function ($t) use (&$byPhone) {
                $phone = preg_replace('/\D+/', '', (string) $t->sender_phone);
                $phone = preg_replace('/^00/', '', $phone);

                if ($phone === '') {
                    return;
                }

                if (! isset($byPhone[$phone])) {
                    $byPhone[$phone] = DB::table('customers')->insertGetId([
                        'name' => $t->sender_name,
                        'phone' => $phone,
                        'display_phone' => $t->sender_phone,
                        'created_by' => $t->sending_agent_id,
                        'created_at' => $t->created_at,
                        'updated_at' => $t->created_at,
                    ]);
                }

                DB::table('transfers')->where('id', $t->id)->update(['customer_id' => $byPhone[$phone]]);
            });
    }
};
