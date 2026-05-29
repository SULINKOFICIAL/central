<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * Backfill operacional das rotas usadas pelo gateway app.micore.com.br.
 *
 * O comando cria `tenant_app_routes` para tenants antigos que já existiam
 * antes da App API centralizada, sem sobrescrever rotas manuais por padrão.
 */
class SyncAppTenantRoutes extends Command
{
    protected $signature = 'tenants:sync-app-routes
        {--tenant-id= : Sincroniza apenas um tenant específico}
        {--force : Atualiza rotas existentes}
        {--dry-run : Mostra o que seria feito sem gravar no banco}';

    protected $description = 'Cria rotas de aplicativo para tenants antigos da Central.';

    /**
     * Cria ou atualiza rotas de app conforme o tipo de instalação do tenant.
     */
    public function handle(): int
    {
        /**
         * Flags definem escopo, sobrescrita e simulação da sincronização.
         */
        $tenantId = $this->option('tenant-id');
        $force    = $this->option('force') === true;
        $dryRun   = $this->option('dry-run') === true;

        $created = 0;
        $updated = 0;
        $skipped = 0;

        /**
         * Processa tenants antigos para garantir rota de app no registry.
         */
        $tenants = DB::table('tenants')
            ->select(['id', 'name', 'type_installation', 'token'])
            ->when($tenantId, function ($query) use ($tenantId) {
                $query->where('id', $tenantId);
            })
            ->orderBy('id')
            ->get();

        foreach ($tenants as $tenant) {
            $existingRoute = DB::table('tenant_app_routes')
                ->where('tenant_id', $tenant->id)
                ->first();

            /**
             * Evita sobrescrever configuração manual sem --force.
             */
            if ($existingRoute && !$force) {
                $skipped++;
                $this->line('Ignorado tenant ' . $tenant->id . ': rota já existe.');
                continue;
            }

            $routeData = $this->routeDataForTenant($tenant);

            /**
             * Tenants dedicated precisam de token técnico para usar remote_api.
             */
            if (!$routeData) {
                $skipped++;
                $this->warn('Ignorado tenant ' . $tenant->id . ': token técnico ausente.');
                continue;
            }

            if ($dryRun) {
                $skipped++;
                $this->line('Dry-run tenant ' . $tenant->id . ': ' . $routeData['mode']);
                continue;
            }

            DB::table('tenant_app_routes')->updateOrInsert(
                [
                    'tenant_id' => $tenant->id,
                ],
                $routeData
            );

            if ($existingRoute) {
                $updated++;
                $this->line('Atualizada rota do tenant ' . $tenant->id . ': ' . $routeData['mode']);
                continue;
            }

            $created++;
            $this->line('Criada rota do tenant ' . $tenant->id . ': ' . $routeData['mode']);
        }

        $this->info('Sincronização de rotas do app concluída.');
        $this->line('Criadas: ' . $created);
        $this->line('Atualizadas: ' . $updated);
        $this->line('Ignoradas: ' . $skipped);

        return self::SUCCESS;
    }

    /**
     * Define o modo padrão da rota a partir do tipo de instalação do tenant.
     */
    private function routeDataForTenant($tenant): ?array
    {
        /**
         * O timestamp é compartilhado para manter created_at e updated_at coerentes.
         */
        $now = now();

        /**
         * Dedicated é atendido por API remota usando domínio ativo como fallback.
         */
        if ($tenant->type_installation === 'dedicated') {
            if (!$tenant->token) {
                return null;
            }

            return [
                'mode'                 => 'remote_api',
                'remote_base_url'      => null,
                'remote_service_token' => Crypt::encryptString($tenant->token),
                'status'               => true,
                'updated_at'           => $now,
                'created_at'           => $now,
            ];
        }

        /**
         * Shared/SaaS usa banco local resolvido pelo provisioning da Central.
         */
        return [
            'mode'                 => 'local_database',
            'remote_base_url'      => null,
            'remote_service_token' => null,
            'status'               => true,
            'updated_at'           => $now,
            'created_at'           => $now,
        ];
    }
}
