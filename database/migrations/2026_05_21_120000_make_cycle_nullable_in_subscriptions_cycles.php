<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (!Schema::hasTable('subscriptions_cycles')) {
            return;
        }

        Schema::table('subscriptions_cycles', function (Blueprint $table) {
            $table->string('cycle')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (!Schema::hasTable('subscriptions_cycles')) {
            return;
        }

        Schema::table('subscriptions_cycles', function (Blueprint $table) {
            $table->string('cycle')->nullable(false)->change();
        });
    }
};
