<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const DEFAULT_TRIAL_DAYS = 30;

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (!Schema::hasTable('tenants_plans') || Schema::hasColumn('tenants_plans', 'trial_days')) {
            return;
        }

        Schema::table('tenants_plans', function (Blueprint $table) {
            /**
             * Período operacional liberado para planos de teste inicial.
             * Planos antigos recebem 30 dias para manter a vigência já praticada.
             */
            $table->unsignedSmallInteger('trial_days')
                ->default(self::DEFAULT_TRIAL_DAYS)
                ->after('size_storage');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (!Schema::hasTable('tenants_plans') || !Schema::hasColumn('tenants_plans', 'trial_days')) {
            return;
        }

        Schema::table('tenants_plans', function (Blueprint $table) {
            $table->dropColumn('trial_days');
        });
    }
};
