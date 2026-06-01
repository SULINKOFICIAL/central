<?php

namespace App\Services;

use App\Models\Module;
use App\Models\Tenant;
use App\Models\TenantPlan;
use App\Models\TenantPlanItem;
use Illuminate\Support\Str;

class TenantInitialTrialPlanService
{
    public const DEFAULT_TRIAL_DAYS = 30;

    private const STORAGE_BYTES = 5 * 1024 * 1024 * 1024;

    /**
     * Cria o plano inicial de teste para novas contas sem gerar histórico financeiro.
     * O teste libera todos os módulos ativos, 5GB de armazenamento e nenhum usuário adicional
     * pelo período definido na criação do cliente.
     */
    public function ensureForTenant(Tenant $tenant, int $trialDays = self::DEFAULT_TRIAL_DAYS): void
    {
        if ($trialDays < 1) {
            $trialDays = self::DEFAULT_TRIAL_DAYS;
        }

        $tenant->unsetRelation('plan');
        $tenant->loadMissing('plan');

        if ($tenant->plan) {
            return;
        }

        $plan = TenantPlan::create([
            'tenant_id' => $tenant->id,
            'name' => $this->planName($trialDays),
            'value' => 0,
            'users_limit' => 0,
            'size_storage' => self::STORAGE_BYTES,
            'trial_days' => $trialDays,
            'progress' => 'completed',
            'status' => true,
            'tenant_sync_status' => 'pending',
            'tenant_sync_request_id' => Str::uuid()->toString(),
            'created_by' => 1,
        ]);

        $modules = Module::where('status', true)
            ->orderBy('name')
            ->get();

        foreach ($modules as $module) {
            $basePrice = $module->value ?? 0;
            $discountPercent = $basePrice > 0 ? 100 : 0;

            TenantPlanItem::create([
                'plan_id' => $plan->id,
                'package_id' => null,
                'item_id' => $module->id,
                'item_type' => 'module',
                'module_name' => $module->name,
                'base_price' => $basePrice,
                'applied_price' => 0,
                'discount_amount' => $basePrice,
                'discount_percent' => $discountPercent,
                'pricing_source' => 'initial_trial',
                'billing_type' => $module->pricing_type,
                'payload' => $module->toJson(),
            ]);
        }

        $tenant->unsetRelation('plan');
    }

    /**
     * Mantém o nome administrativo alinhado ao período liberado no plano.
     */
    private function planName(int $trialDays): string
    {
        return 'Teste ' . $trialDays . ' dias';
    }
}
