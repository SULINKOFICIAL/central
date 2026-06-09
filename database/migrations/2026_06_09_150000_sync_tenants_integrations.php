<?php

use App\Services\IntegrationSyncService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        /**
         * Backfill único: empurra as integrações já existentes em cada tenant
         * para o gateway externo. Não altera schema — apenas sincroniza dados.
         */
        $summary = app(IntegrationSyncService::class)->syncAllTenants();

        /**
         * Registra o resumo da varredura para auditoria pós-deploy.
         */
        Log::info('migration.sync_tenants_integrations', $summary);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        /**
         * Sincronização não é reversível: o envio ao gateway externo já ocorreu.
         */
    }
};
