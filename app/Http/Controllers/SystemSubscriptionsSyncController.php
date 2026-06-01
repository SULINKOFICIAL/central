<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Services\TenantConfigurationSyncService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class SystemSubscriptionsSyncController extends Controller
{
    /**
     * Injeta o serviço que replica a configuração atual do plano para os tenants.
     */
    public function __construct(
        private readonly TenantConfigurationSyncService $tenantConfigurationSyncService,
    ) {
    }

    /**
     * Exibe a tela de sincronização em massa de planos para os tenants.
     */
    public function edit(): View
    {
        return view('pages.system.settings-subscriptions-sync', [
            'tenantsCount' => Tenant::count(),
        ]);
    }

    /**
     * Sincroniza em massa os planos atuais para todos os tenants.
     */
    public function sync(): RedirectResponse
    {
        $periodStartDate = '2026-01-01';
        $periodEndDate = '2026-12-31';
        $syncedCount = 0;
        $failedTenants = [];

        $tenants = Tenant::orderBy('id')->get();

        foreach ($tenants as $tenant) {
            $syncResult = $this->tenantConfigurationSyncService->syncFromCurrentPlan(
                $tenant,
                source: 'manual_admin',
                operatorId: 1,
                reason: 'Sincronização em massa manual após rebuild global',
                startDate: $periodStartDate,
                endDate: $periodEndDate,
            );

            if (!($syncResult['success'] ?? false)) {
                $failedTenants[] = $tenant->id;
                continue;
            }

            $syncedCount++;
        }

        if (!empty($failedTenants)) {
            return redirect()
                ->route('system.settings.subscriptions.sync.edit')
                ->with('error', 'Sincronização concluída parcialmente. Sucesso em ' . $syncedCount . ' tenant(s). Falha em: #' . implode(', #', $failedTenants) . '.');
        }

        return redirect()
            ->route('system.settings.subscriptions.sync.edit')
            ->with('message', 'Sincronização concluída com sucesso para ' . $syncedCount . ' tenant(s).');
    }
}
