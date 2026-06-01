<?php

namespace App\Services;

use App\Models\Module;
use App\Models\Tenant;
use App\Models\TenantPlan;
use App\Models\TenantPlanItem;
use Illuminate\Support\Str;

class TenantInitialTrialPlanService
{
    private const PLAN_NAME = 'Teste 30 dias';
    private const STORAGE_BYTES = 5 * 1024 * 1024 * 1024;

    /**
     * Cria o plano inicial de teste para novas contas sem gerar histórico financeiro.
     * O teste libera todos os módulos ativos, 5GB de armazenamento e nenhum usuário adicional.
     */
    public function ensureForTenant(Tenant $tenant): void
    {
        $tenant->unsetRelation('plan');
        $tenant->loadMissing('plan');

        if ($tenant->plan) {
            return;
        }

        $plan = TenantPlan::create([
            'tenant_id' => $tenant->id,
            'name' => self::PLAN_NAME,
            'value' => 0,
            'users_limit' => 0,
            'size_storage' => self::STORAGE_BYTES,
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
                'pricing_source' => 'trial_30_days',
                'billing_type' => $module->pricing_type,
                'payload' => $module->toJson(),
            ]);
        }

        $tenant->unsetRelation('plan');
    }
}
