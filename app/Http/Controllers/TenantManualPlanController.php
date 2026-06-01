<?php

namespace App\Http\Controllers;

use App\Models\Module;
use App\Models\Subscription;
use App\Models\SubscriptionCycle;
use App\Models\Tenant;
use App\Models\TenantPlan;
use App\Models\TenantPlanItem;
use App\Services\TenantConfigurationSyncService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TenantManualPlanController extends Controller
{
    public function __construct(private TenantConfigurationSyncService $syncService)
    {
    }

    /**
     * Carrega dados para edição manual administrativa do plano.
     * Fonte de verdade: core_business (plano e ciclo locais).
     */
    public function editData($id)
    {
        $tenant = Tenant::findOrFail($id);

        $tenant->loadMissing(['plan.items', 'subscriptions.cycles']);

        $enabledModuleIds = collect($tenant->plan?->items ?? [])->pluck('item_id')->filter()->unique()->values();

        $localModules = Module::where('status', true)
            ->orderBy('name')
            ->get(['id', 'name', 'slug', 'value']);

        $formattedModules = $localModules->map(function (Module $module) use ($enabledModuleIds) {
            return [
                'id'      => $module->id,
                'name'    => $module->name,
                'slug'    => $module->slug,
                'value'   => $module->value,
                'enabled' => $enabledModuleIds->contains($module->id),
            ];
        })->values();

        $latestSubscription = $tenant->subscriptions()->latest('id')->first();
        $latestCycle = $latestSubscription?->cycles()->latest('id')->first();

        $storageLimitBytes = $tenant->plan?->size_storage ?? 0;

        return response()->json([
            'success' => true,
            'message' => 'Dados carregados com sucesso.',
            'data' => [
                'modules'           => $formattedModules,
                'start_date'        => $latestCycle?->start_date ? Carbon::parse($latestCycle->start_date)->format('Y-m-d') : null,
                'end_date'          => $latestCycle?->end_date ? Carbon::parse($latestCycle->end_date)->format('Y-m-d') : null,
                'users_limit'       => $tenant->plan?->users_limit ?? 0,
                'storage_limit_gb'  => round($storageLimitBytes / (1024 * 1024 * 1024), 2),
            ],
        ]);
    }

    /**
     * Aplica atualização manual administrativa do plano sem cobrança.
     * Regra: ajuste operacional imediato para administradores.
     */
    public function apply(Request $request, $id)
    {
        $tenant = Tenant::findOrFail($id);

        $data = $request->all();

        $selectedModuleIds = collect($data['modules'] ?? [])->unique()->values();
        $selectedModules   = Module::whereIn('id', $selectedModuleIds)->get();

        $storageLimitGb    = $data['storage_limit_gb'] ?? 0;
        $storageLimitBytes = round($storageLimitGb * 1024 * 1024 * 1024);
        $planValue         = $selectedModules->sum('value');
        $syncRequestId     = Str::uuid()->toString();

        $activePlan = DB::transaction(function () use ($tenant, $selectedModules, $data, $storageLimitBytes, $planValue, $syncRequestId) {
            $activePlan = $tenant->plan ?: TenantPlan::create([
                'tenant_id'    => $tenant->id,
                'name'         => 'Plano Manual Admin',
                'value'        => 0,
                'users_limit'  => 0,
                'size_storage' => 0,
                'progress'     => 'completed',
                'status'       => true,
                'tenant_sync_status' => 'pending',
                'tenant_sync_request_id' => $syncRequestId,
                'created_by'   => Auth::id(),
            ]);

            $activePlan->update([
                'name'         => $activePlan->name ?: 'Plano Manual Admin',
                'value'        => $planValue,
                'users_limit'  => $data['users_limit'] ?? 0,
                'size_storage' => $storageLimitBytes,
                'progress'     => 'completed',
                'status'       => true,
                'tenant_sync_status' => 'pending',
                'tenant_sync_request_id' => $syncRequestId,
                'tenant_synced_at' => null,
                'tenant_sync_response' => null,
                'tenant_sync_error' => null,
                'updated_by'   => Auth::id(),
            ]);

            $activePlan->items()->delete();

            foreach ($selectedModules as $module) {
                $basePrice = $module->value;
                TenantPlanItem::create([
                    'plan_id'      => $activePlan->id,
                    'package_id'   => null,
                    'item_id'      => $module->id,
                    'item_type'    => 'module',
                    'module_name'  => $module->name,
                    'base_price'   => $basePrice,
                    'applied_price' => $basePrice,
                    'discount_amount' => 0,
                    'discount_percent' => 0,
                    'pricing_source' => 'manual_admin',
                    'billing_type' => $module->pricing_type,
                    'payload'      => $module->toJson(),
                ]);
            }

            $subscription = Subscription::where('tenant_id', $tenant->id)
                ->where('plan_id', $activePlan->id)
                ->latest('id')
                ->first();

            if (!$subscription) {
                /**
                 * Assinatura administrativa não passa pelo provedor de pagamento.
                 * Mesmo assim, a tabela exige o intervalo usado pelos fluxos pagos.
                 */
                $subscription = Subscription::create([
                    'tenant_id'                 => $tenant->id,
                    'plan_id'                   => $activePlan->id,
                    'provider'                  => 'pagarme',
                    'provider_subscription_id'  => 'manual-admin-' . $tenant->id,
                    'interval'                  => 'year',
                    'payment_method'            => 'manual_admin',
                    'currency'                  => 'BRL',
                    'installments'              => 1,
                    'status'                    => 'active',
                ]);
            } else {
                /**
                 * Garante que assinaturas antigas incompletas também fiquem válidas
                 * antes de gerar um novo ciclo manual.
                 */
                $subscription->update([
                    'provider'       => 'pagarme',
                    'plan_id'        => $activePlan->id,
                    'interval'       => $subscription->interval ?: 'year',
                    'payment_method' => $subscription->payment_method ?: 'manual_admin',
                    'currency'       => $subscription->currency ?: 'BRL',
                    'installments'   => $subscription->installments ?: 1,
                    'status'         => 'active',
                ]);
            }

            $existingCycle = SubscriptionCycle::where('subscription_id', $subscription->id)
                ->where('status', 'billed')
                ->latest('id')
                ->first();

            $cyclePayload = [
                'subscription_id' => $subscription->id,
                'provider' => 'pagarme',
                'provider_cycle_id' => $existingCycle?->provider_cycle_id ?: 'manual-cycle-' . $subscription->id,
                'start_date' => Carbon::parse($data['start_date'] ?? now())->startOfDay(),
                'end_date' => Carbon::parse($data['end_date'] ?? now())->endOfDay(),
                'status' => 'billed',
                'cycle' => $existingCycle?->cycle ?: 1,
                'billing_at' => Carbon::parse($data['start_date'] ?? now())->startOfDay(),
                'next_billing_at' => Carbon::parse($data['end_date'] ?? now())->endOfDay(),
            ];

            if ($existingCycle) {
                $existingCycle->update($cyclePayload);
            } else {
                SubscriptionCycle::create($cyclePayload);
            }

            return $activePlan;
        });

        return response()->json([
            'success' => true,
            'message' => 'Plano salvo internamente. Sincronize o novo plano para aplicar no tenant.',
            'requires_sync' => true,
            'sync_status' => $activePlan->tenant_sync_status,
            'sync_request_id' => $activePlan->tenant_sync_request_id,
        ]);
    }

    /**
     * Sincroniza o plano local pendente com o tenant remoto.
     */
    public function sync(Request $request, $id)
    {
        $tenant = Tenant::findOrFail($id);
        $data   = $request->all();

        $tenant->loadMissing(['plan']);
        $activePlan = $tenant->plan;

        if (!$activePlan) {
            return response()->json([
                'success' => false,
                'message' => 'Nenhum plano ativo encontrado para sincronizar.',
            ], 404);
        }

        $requestId = $activePlan->tenant_sync_request_id ?: Str::uuid()->toString();

        if (!$activePlan->tenant_sync_request_id) {
            $activePlan->update([
                'tenant_sync_status' => 'pending',
                'tenant_sync_request_id' => $requestId,
                'tenant_sync_error' => null,
            ]);
        }

        $syncResults = $this->syncService->syncFromCurrentPlan(
            $tenant,
            source: 'manual_admin',
            operatorId: Auth::id(),
            reason: $data['manual_change_reason'] ?? null,
            requestId: $requestId,
            startDate: $data['start_date'] ?? null,
            endDate: $data['end_date'] ?? null,
        );

        if (!empty($syncResults['success'])) {
            $activePlan->update([
                'tenant_sync_status' => 'synced',
                'tenant_synced_at' => now(),
                'tenant_sync_response' => json_encode($syncResults, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'tenant_sync_error' => null,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Novo plano sincronizado com sucesso.',
                'sync' => $syncResults,
            ]);
        }

        $activePlan->update([
            'tenant_sync_status' => 'failed',
            'tenant_sync_response' => json_encode($syncResults, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'tenant_sync_error' => $syncResults['message'] ?? 'Falha ao sincronizar o novo plano.',
        ]);

        return response()->json([
            'success' => false,
            'message' => 'Não foi possível sincronizar o novo plano.',
            'sync' => $syncResults,
        ], 422);
    }
}
