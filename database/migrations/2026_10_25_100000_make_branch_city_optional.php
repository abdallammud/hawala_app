<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** The branch name is what matters; the city is only extra detail. */
    public function up(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->string('city')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->string('city')->nullable(false)->default('')->change();
        });
    }
};
