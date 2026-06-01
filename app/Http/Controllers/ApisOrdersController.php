<?php

namespace App\Http\Controllers;

use App\Models\TenantPlanItem;
use App\Models\AdditionalStorage;
use App\Models\AdditionalUser;
use App\Models\Module;
use App\Models\ModulePricingTier;
use App\Models\Order;
use App\Models\Package;
use App\Models\TenantPlanItemConfiguration;
use App\Services\OrderService;
use App\Services\PagarMeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ApisOrdersController extends Controller
{
    protected $orderService;

    public function __construct(OrderService $orderService)
    {
        $this->orderService = $orderService;
    }


    /**
     * Retorna o pedido em rascunho mais recente do cliente (se existir).
     * Obs.: Geralmente é o pedido em andamento.
     */
    public function draft(Request $request)
    {
        // Obtem dados
        $data = $request->all();

        // Obtem cliente
        $tenant = $data['tenant'];

        /**
         * Obtém o plano em andamento do tenant.
         */
        $plan = $this->orderService->getPlanInProgress($tenant);

        // Busca o pedido em andamento
        $order = $this->orderService->getOrderInProgress($tenant, $plan);

        // Monta os itens com origem (pacote) para o resumo no front.
        $items = $plan->items()
            ->with(['item:id,name,pricing_type', 'sourcePackage:id,name', 'configurations'])
            ->get()
            ->map(function (TenantPlanItem $planItem) {
                $moduleName = $planItem->module_name ?? $planItem->item?->name;
                $moduleBillingType = $planItem->item?->pricing_type ?? $planItem->billing_type;
                $usageConfig = $planItem->configurations->where('key', 'usage')->first();
                $usageLimit = $planItem->usage_limit;

                if ($usageLimit === null && $usageConfig) {
                    $usageLimit = $usageConfig->value;
                }

                return [
                    'id' => $planItem->item_id,
                    'name' => $moduleName,
                    'billing_type' => $moduleBillingType,
                    'package_id' => $planItem->package_id,
                    'package_name' => $planItem->sourcePackage?->name,
                    'item_type' => $planItem->item_type,
                    'base_price' => $planItem->base_price,
                    'applied_price' => $planItem->applied_price,
                    'discount_amount' => $planItem->discount_amount,
                    'discount_percent' => $planItem->discount_percent,
                    'pricing_source' => $planItem->pricing_source,
                    'module_pricing_tier_id' => $planItem->module_pricing_tier_id,
                    'usage_limit' => $usageLimit,
                ];
            })
            ->values();

        // Calcula subtotal e desconto aplicado
        $subtotalAmount = (float) $plan->items()->sum('applied_price');
        $discountAmount = (float) ($order->coupon_discount_amount ?? 0);

        // Responde com o rascunho e os itens formatados
        return response()->json([
            'order_id'          => $order->id,
            'status'            => $order->status,
            'current_step'      => $order->current_step,
            'amount'            => $subtotalAmount,
            'discount_amount'   => $discountAmount,
            'total_amount'      => $order->total_amount,
            'currency'          => $order->currency,
            'items'             => $items,
        ], 200);
    }

    /**
     * Retorna as opções de uso (tiers) para módulos do pedido.
     */
    public function usageOptions(Request $request)
    {

        // Extrai dados e cliente já anexado pelo middleware
        $data = $request->all();
        $tenant = $data['tenant'];
        $orderId = $data['order_id'] ?? null;

        /**
         * Regra: prioriza o order_id recebido do front.
         * Se não vier, usa o único rascunho do tenant.
         */
        $order = null;

        if ($orderId) {
            $order = Order::where('tenant_id', $tenant->id)
                ->where('id', $orderId)
                ->where('status', 'draft')
                ->first();
        }

        if (!$order) {
            $order = Order::where('tenant_id', $tenant->id)
                ->where('status', 'draft')
                ->orderByDesc('id')
                ->first();
        }

        if (!$order || !$order->plan) {
            return response()->json([
                'order_id' => null,
                'modules' => [],
                'users_limit' => 0,
                'storage_limit_gb' => 0,
                'base_users_limit' => 0,
                'base_storage_limit_gb' => 0,
                'additional_users' => [],
                'additional_storages' => [],
            ], 200);
        }

        /**
         * Garante materialização dos adicionais já no carregamento da etapa de uso.
         * Assim o resumo lateral reflete usuários/armazenamento sem exigir alteração manual no select.
         */
        $this->syncAdditionalItemsFromCurrentLimits($order);

        // Inicia lista de módulos que exigem seleção de uso
        $usageModules = [];

        // Percorre itens do pedido e filtra apenas módulos com cobrança por uso
        foreach ($order->plan->items as $module) {
            if (!$module->item) {
                continue;
            }

            if ($module->item->pricing_type != 'Preço Por Uso') {
                continue;
            }

            // Carrega as faixas (tiers) do módulo ordenadas por limite
            $tiers = ModulePricingTier::where('module_id', $module->item->id)
                ->orderBy('usage_limit')
                ->get()
                ->map(function ($tier) {
                    return [
                        'usage_limit' => $tier->usage_limit,
                        'price' => (float) $tier->price,
                    ];
                })
                ->toArray();

            // Obtém uso já escolhido para o item (configuração ou snapshot)
            $usageConfig = null;
            $configItem = $module->configurations->where('key', 'usage')->first();

            if ($configItem) {
                $usageConfig = $configItem->value;
            } elseif (is_array($module->item->pricing_model_snapshot ?? null) && isset($module->item->pricing_model_snapshot['usage'])) {
                $usageConfig = $module->item->pricing_model_snapshot['usage'];
            }

            $usageModules[] = [
                'module_id' => $module->item->id,
                'module_name' => $module->item->name,
                'usage_card_title' => $module->item->usage_card_title ?: $module->item->name,
                'usage_card_subtitle' => $module->item->usage_card_subtitle ?: 'Escolha a faixa de uso ideal para o seu momento.',
                'tiers' => $tiers,
                'selected_usage' => $usageConfig,
            ];
        }

        $plan = $order->plan;
        $currentUsersLimit = (int) ($plan->users_limit ?? 0);
        $currentStorageBytes = (int) ($plan->size_storage ?? 0);
        $currentStorageGb = (int) floor($currentStorageBytes / (1024 * 1024 * 1024));
        $activePlan = $tenant->plans()
            ->where('status', true)
            ->where('progress', 'completed')
            ->orderByDesc('id')
            ->first();
        $baseUsersLimit = (int) ($activePlan?->users_limit ?? 0);
        $baseStorageGb = (int) floor(((int) ($activePlan?->size_storage ?? 0)) / (1024 * 1024 * 1024));

        $additionalUsers = AdditionalUser::query()
            ->where('status', true)
            ->orderBy('quantity')
            ->get(['quantity', 'price'])
            ->map(function (AdditionalUser $item) {
                return [
                    'quantity' => (int) $item->quantity,
                    'price' => (float) $item->price,
                ];
            })
            ->values()
            ->all();

        $additionalStorages = AdditionalStorage::query()
            ->where('status', true)
            ->orderBy('quantity')
            ->get(['quantity', 'price'])
            ->map(function (AdditionalStorage $item) {
                return [
                    'quantity' => (int) $item->quantity,
                    'price' => (float) $item->price,
                ];
            })
            ->values()
            ->all();

        return response()->json([
            'order_id' => $order->id,
            'modules' => $usageModules,
            'users_limit' => $currentUsersLimit,
            'storage_limit_gb' => $currentStorageGb,
            'base_users_limit' => $baseUsersLimit,
            'base_storage_limit_gb' => $baseStorageGb,
            'additional_users' => $additionalUsers,
            'additional_storages' => $additionalStorages,
        ], 200);
    }

    /**
     * Sincroniza itens adicionais com os limites atuais do plano em rascunho.
     */
    private function syncAdditionalItemsFromCurrentLimits(Order $order): void
    {
        $plan = $order->plan;

        if (!$plan) {
            return;
        }

        $usersLimit = intval($plan->users_limit ?? 0);
        $storageLimitGb = intval(floor((intval($plan->size_storage ?? 0)) / (1024 * 1024 * 1024)));

        if ($usersLimit > 0) {
            $this->upsertAdditionalUsersItem($plan, $usersLimit);
        }

        if ($storageLimitGb > 0) {
            $this->upsertAdditionalStorageItem($plan, $storageLimitGb);
        }

        $this->orderService->recalculateOrderTotals($order);
    }

    public function details(Request $request, $id)
    {
        // Recebe dados
        $data = $request->all();

        // Obtém dados do cliente
        $tenant = $data['tenant'];

        // Busca o pedido do cliente
        $order = Order::where('tenant_id', $tenant->id)->where('id', $id)->first();

        // Formata o pedido
        $orderJson['id'] = $order->id;
        $orderJson['date_created'] = $order->created_at;
        $orderJson['date_paid'] = $order->paid_at;
        $orderJson['amount'] = $order->total_amount;
        $orderJson['method'] = $order->provider_method;
        $orderJson['status'] = $order->status;
        $orderJson['packageName'] = $order->plan->name;

        // Caso não encontre a conta do cliente
        if (!$tenant) {
            return response()->json('Pedido não encontrado', 404);
        }

        // Obtém transações do pedido
        $transactions = $order->transactions;

        // Insere o pedido formatado
        $transactionsJson = [];

        // Formata dados Json
        foreach ($transactions as $transaction) {
            $buy['id'] = $transaction->id;
            $buy['amount'] = $transaction->amount;
            $buy['method'] = $transaction->provider_method;
            $buy['gateway'] = $transaction->gateway ? $transaction->gateway->name : null;
            $buy['date_created'] = $transaction->created_at;
            $buy['status'] = $transaction->status;

            $transactionsJson[] = $buy;
        }

        // Insere as transações no pedido
        $orderJson['transactions'] = $transactionsJson;

        return response()->json($orderJson, 200);
    }

    /**
     * Retorna snapshot canônico do catálogo da etapa de módulos.
     * Fonte de verdade:
     * - owned_*: plano contratado ativo/concluído.
     * - draft_*: plano em rascunho em edição.
     */
    public function modulesCatalog(Request $request)
    {
        $data = $request->all();
        $tenant = $data['tenant'];

        $draftPlan = $this->orderService->getPlanInProgress($tenant);
        $snapshot = $this->buildModulesCatalogSnapshot($tenant, $draftPlan);

        return response()->json($snapshot, 200);
    }

    /**
     * Cria um pedido em rascunho (intenção de compra) com base nos módulos desejados.
     */
    public function update(Request $request)
    {
        $startedAt = microtime(true);
        $requestId = (string) Str::uuid();

        // Obtém dados
        $data = $request->all();

        // Extrai cliente
        $tenant = $data['tenant'];

        /**
         * Obtém o plano em andamento do tenant.
         */
        $plan = $this->orderService->getPlanInProgress($tenant);

        // Busca o pedido em andamento
        $order = $this->orderService->getOrderInProgress($tenant, $plan);

        $beforeSnapshot = $this->buildModulesCatalogSnapshot($tenant, $plan);

        // Realiza ação desejada
        $action = match ($data['action']) {
            'change_package' => $this->changePackage($order, (int) ($data['value'] ?? 0)),
            'change_module' => $this->toggleModule($order, $data['value']),
            'change_modules_bulk' => $this->changeModulesBulk($order, $data['value'] ?? []),
            'usage' => $this->updateUsage($order, $data['value'] ?? []),
            'limits' => $this->updateLimits($order, $data['value'] ?? []),
            'step' => $this->updateStep($order, $data['value']),
            default => null,
        };

        $afterSnapshot = $this->buildModulesCatalogSnapshot($tenant, $plan->fresh());
        $durationMs = (int) round((microtime(true) - $startedAt) * 1000);

        Log::info('orders.update.snapshot', [
            'request_id' => $requestId,
            'tenant_id' => $tenant->id,
            'action' => $data['action'] ?? null,
            'duration_ms' => $durationMs,
            'before' => [
                'owned_module_ids' => $beforeSnapshot['owned_module_ids'] ?? [],
                'draft_module_ids' => $beforeSnapshot['draft_module_ids'] ?? [],
                'selected_package_ids' => $beforeSnapshot['selected_package_ids'] ?? [],
            ],
            'after' => [
                'owned_module_ids' => $afterSnapshot['owned_module_ids'] ?? [],
                'draft_module_ids' => $afterSnapshot['draft_module_ids'] ?? [],
                'selected_package_ids' => $afterSnapshot['selected_package_ids'] ?? [],
            ],
        ]);

        return response()->json([
            'success' => true,
            'request_id' => $requestId,
            'message' => $action['message'] ?? 'Pedido atualizado com sucesso.',
            'action' => $action['action'] ?? null,
            'snapshot' => $afterSnapshot,
        ]);
    }

    private function buildModulesCatalogSnapshot($tenant, $draftPlan = null): array
    {
        $activePlan = $tenant->plans()
            ->where('status', true)
            ->where('progress', 'completed')
            ->orderByDesc('id')
            ->with('items')
            ->first();

        $draftPlan = $draftPlan ?: $this->orderService->getPlanInProgress($tenant);
        $draftPlan->loadMissing('items');

        $ownedModuleIds = collect($activePlan?->items ?? [])
            ->pluck('item_id')
            ->filter()
            ->map(fn ($itemId) => (int) $itemId)
            ->unique()
            ->values()
            ->all();

        $draftModuleIds = collect($draftPlan->items ?? [])
            ->pluck('item_id')
            ->filter()
            ->map(fn ($itemId) => (int) $itemId)
            ->unique()
            ->values()
            ->all();

        $selectedPackageIds = collect($draftPlan->items ?? [])
            ->pluck('package_id')
            ->filter()
            ->map(fn ($packageId) => (int) $packageId)
            ->unique()
            ->values()
            ->all();

        $modulesCatalog = Module::with(['category', 'pricingTiers', 'benefits', 'resources'])
            ->where('status', true)
            ->where('is_native', false)
            ->get();

        $packagesCatalog = Package::with(['modules', 'benefits'])
            ->where('status', true)
            ->orderBy('order')
            ->get();

        $selectedPackageModuleIds = [];
        foreach ($packagesCatalog as $packageCatalog) {
            if (!in_array((int) $packageCatalog->id, $selectedPackageIds, true)) {
                continue;
            }

            foreach ($packageCatalog->modules as $packageModule) {
                if ((int) ($packageModule->is_native ?? false) === 1) {
                    continue;
                }

                $selectedPackageModuleIds[] = (int) $packageModule->id;
            }
        }
        $selectedPackageModuleIds = collect($selectedPackageModuleIds)->unique()->values()->all();

        $modules = [];
        foreach ($modulesCatalog as $module) {
            $pricingValues = 0;
            if ($module->pricing_type === 'Preço Por Uso') {
                $pricingValues = $module->pricingTiers
                    ->sortBy('usage_limit')
                    ->values()
                    ->map(function ($tier) {
                        return [
                            'usage_limit' => $tier->usage_limit,
                            'price' => (float) $tier->price,
                        ];
                    })
                    ->toArray();
            } else {
                $pricingValues = (float) $module->value;
            }

            $isSelected = in_array((int) $module->id, $draftModuleIds, true);

            $modules[] = [
                'id' => $module->id,
                'name' => $module->name,
                'description' => $module->description,
                'price_label' => is_array($pricingValues) ? 'Sob consulta' : 'R$ ' . number_format((float) $pricingValues, 2, ',', '.'),
                'price_suffix' => is_array($pricingValues) ? '' : '/mês',
                'is_selected' => $isSelected,
                'is_owned' => in_array((int) $module->id, $ownedModuleIds, true),
                'is_selected_by_package' => in_array((int) $module->id, $selectedPackageModuleIds, true),
                'benefits' => $module->benefits->map(function ($benefit) {
                    $labelColor = strtolower($benefit->label_color ?? 'primary');
                    $allowedColors = ['success', 'primary', 'info', 'warning'];
                    if (!in_array($labelColor, $allowedColors, true)) {
                        $labelColor = 'primary';
                    }

                    $iconValue = $benefit->icon ?? '';
                    if ($iconValue === '') {
                        $iconClass = 'fa-solid fa-circle-check';
                    } elseif (str_contains($iconValue, 'fa-')) {
                        $iconClass = $iconValue;
                    } else {
                        $iconClass = 'fa-solid fa-' . ltrim($iconValue, '-');
                    }

                    return [
                        'icon' => $benefit->icon,
                        'icon_class' => $iconClass,
                        'title' => $benefit->title,
                        'label' => $benefit->label,
                        'label_color' => $benefit->label_color,
                        'label_color_class' => 'text-' . $labelColor,
                    ];
                })->values()->toArray(),
            ];
        }

        $packages = [];
        foreach ($packagesCatalog as $package) {
            $nonNativeModules = $package->modules->filter(function ($module) {
                return !(bool) ($module->is_native ?? false);
            })->values();

            $moduleIds = $nonNativeModules->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->values()
                ->all();

            $isOwned = !empty($moduleIds) && count(array_diff($moduleIds, $ownedModuleIds)) === 0;
            $isSelected = !empty($moduleIds) && count(array_diff($moduleIds, $draftModuleIds)) === 0;

            $packagePrice = (float) $package->modules->sum(function ($module) {
                return (float) ($module->pivot->price ?? $module->value ?? 0);
            });

            $packages[] = [
                'id' => $package->id,
                'module_ids' => $moduleIds,
                'name' => $package->name,
                'description' => $package->description,
                'is_popular' => (bool) ($package->popular ?? false),
                'is_owned' => $isOwned,
                'is_selected' => $isSelected,
                'button_class' => $isSelected ? 'btn-light' : 'btn-primary',
                'price_label' => 'R$ ' . number_format($packagePrice, 2, ',', '.'),
                'benefits' => $package->benefits->map(function ($benefit) {
                    $labelColor = strtolower($benefit->label_color ?? 'primary');
                    $allowedColors = ['success', 'primary', 'info', 'warning'];
                    if (!in_array($labelColor, $allowedColors, true)) {
                        $labelColor = 'primary';
                    }

                    $iconValue = $benefit->icon ?? '';
                    if ($iconValue === '') {
                        $iconClass = 'fa-solid fa-circle-check';
                    } elseif (str_contains($iconValue, 'fa-')) {
                        $iconClass = $iconValue;
                    } else {
                        $iconClass = 'fa-solid fa-' . ltrim($iconValue, '-');
                    }

                    return [
                        'icon' => $benefit->icon,
                        'icon_class' => $iconClass,
                        'title' => $benefit->title,
                        'label' => $benefit->label,
                        'label_color' => $benefit->label_color,
                        'label_color_class' => 'text-' . $labelColor,
                    ];
                })->values()->toArray(),
                'included_modules' => $nonNativeModules->map(function ($module) use ($draftModuleIds) {
                    $moduleId = (int) $module->id;
                    return [
                        'id' => $moduleId,
                        'name' => $module->name,
                        'is_already_enabled' => in_array($moduleId, $draftModuleIds, true),
                    ];
                })->values()->toArray(),
                'included_resources' => collect(preg_split('/\r\n|\r|\n/', $package->resources_list ?? '') ?: [])
                    ->filter()
                    ->values()
                    ->all(),
            ];
        }

        return [
            'owned_module_ids' => $ownedModuleIds,
            'draft_module_ids' => $draftModuleIds,
            'selected_package_ids' => $selectedPackageIds,
            'catalog' => [
                'modules' => $modules,
                'packages' => $packages,
            ],
        ];
    }

    /**
     * Atualiza o passo do pedido
     */
    private function updateStep($order, $step)
    {
        // Atualiza a etapa
        $order->current_step = $step;
        $order->save();

        return [
            'message' => 'Passo atualizado com sucesso',
            'order' => $order
        ];
    }

    /**
     * Aplica um pacote base no rascunho atual.
     * Substitui os módulos do rascunho pelos módulos do pacote selecionado.
     */
    private function changePackage($order, int $selectedPackageId): array
    {
        if ($selectedPackageId <= 0) {
            return [
                'message' => 'Pacote inválido.',
                'action' => 'invalid_package',
            ];
        }

        $selectedPackage = Package::with('modules')
            ->where('status', true)
            ->find($selectedPackageId);

        if (!$selectedPackage) {
            return [
                'message' => 'Pacote não encontrado.',
                'action' => 'package_not_found',
            ];
        }

        $draftPlan = $order->plan;

        if (!$draftPlan) {
            return [
                'message' => 'Pacote em progresso não encontrado.',
                'action' => 'draft_not_found',
            ];
        }

        $existingItemIds = $draftPlan->items()->pluck('id');
        if ($existingItemIds->isNotEmpty()) {
            TenantPlanItemConfiguration::whereIn('item_id', $existingItemIds)->delete();
        }

        $draftPlan->items()->delete();

        foreach ($selectedPackage->modules as $module) {
            $tierId = (int) ($module->pivot->module_pricing_tier_id ?? 0);
            $pricingContext = $this->resolvePricingContext(
                module: $module,
                packageId: $selectedPackage->id,
                requestedTierId: $tierId
            );

            $createdItem = $this->createCanonicalPlanItem(
                planId: $draftPlan->id,
                module: $module,
                packageId: $selectedPackage->id,
                pricingSource: 'package',
                pricingContext: $pricingContext
            );

            $this->persistUsageConfiguration(
                planItemId: $createdItem->id,
                usageLimit: $pricingContext['usage_limit'],
                basePrice: $pricingContext['base_price'],
                description: 'Configuração padrão do pacote aplicada automaticamente.'
            );
        }

        $this->orderService->recalculateOrderTotals($order);

        return [
            'message' => 'Pacote aplicado com sucesso.',
            'action' => 'changed',
        ];
    }

    /**
     * Adiciona ou remove o módulo
     */
    private function toggleModule($order, $moduleId)
    {
        $module = Module::find($moduleId);
        $plan = $order->plan;
        $existingItem = $plan?->items()->where('item_id', $moduleId)->first();

        if ($existingItem) {
            $existingItem->delete();
            $this->orderService->recalculateOrderTotals($order);

            return [
                'message' => 'Módulo removido com sucesso.',
                'action' => 'removed',
            ];
        }

        $pricingContext = $this->resolvePricingContext(module: $module);
        $createdItem = $this->createCanonicalPlanItem(
            planId: $plan->id,
            module: $module,
            packageId: null,
            pricingSource: 'manual',
            pricingContext: $pricingContext
        );
        $this->persistUsageConfiguration(
            planItemId: $createdItem->id,
            usageLimit: $pricingContext['usage_limit'],
            basePrice: $pricingContext['base_price'],
            description: 'Configuração inicial de uso no módulo avulso.'
        );

        $this->orderService->recalculateOrderTotals($order);

        return [
            'message' => 'Módulo adicionado com sucesso.',
            'action' => 'added',
        ];
    }

    private function changeModulesBulk($order, $value): array
    {
        if (!is_array($value)) {
            return [
                'message' => 'Payload de módulos inválido.',
                'action' => 'invalid',
            ];
        }

        $modulesToAdd = collect($value['add'] ?? [])
            ->map(function ($entry) {
                if (is_array($entry)) {
                    return [
                        'module_id' => isset($entry['module_id']) ? (int) $entry['module_id'] : 0,
                        'package_id' => isset($entry['package_id']) ? (int) $entry['package_id'] : 0,
                        'tier_id' => isset($entry['tier_id']) ? (int) $entry['tier_id'] : 0,
                    ];
                }

                return [
                    'module_id' => (int) $entry,
                    'package_id' => 0,
                    'tier_id' => 0,
                ];
            })
            ->filter(fn ($entry) => $entry['module_id'] > 0)
            ->unique(fn ($entry) => $entry['module_id'] . '_' . $entry['package_id'] . '_' . $entry['tier_id'])
            ->values();

        $modulesToRemove = collect($value['remove'] ?? [])
            ->map(fn ($moduleId) => (int) $moduleId)
            ->filter(fn ($moduleId) => $moduleId > 0)
            ->unique()
            ->values();

        $modulesToPromote = collect($value['promote'] ?? [])
            ->map(function ($entry) {
                if (!is_array($entry)) {
                    return null;
                }

                return [
                    'module_id' => isset($entry['module_id']) ? (int) $entry['module_id'] : 0,
                    'package_id' => isset($entry['package_id']) ? (int) $entry['package_id'] : 0,
                    'tier_id' => isset($entry['tier_id']) ? (int) $entry['tier_id'] : 0,
                ];
            })
            ->filter(fn ($entry) => is_array($entry) && $entry['module_id'] > 0 && $entry['package_id'] > 0)
            ->unique(fn ($entry) => $entry['module_id'] . '_' . $entry['package_id'] . '_' . $entry['tier_id'])
            ->values();

        if ($modulesToAdd->isEmpty() && $modulesToRemove->isEmpty() && $modulesToPromote->isEmpty()) {
            return [
                'message' => 'Nenhuma alteração de módulo para aplicar.',
                'action' => 'noop',
            ];
        }

        $plan = $order->plan;
        if (!$plan) {
            return [
                'message' => 'Plano em progresso não encontrado.',
                'action' => 'not_found',
            ];
        }

        foreach ($modulesToRemove as $moduleId) {
            $existingItem = $plan->items()->where('item_id', $moduleId)->first();
            if (!$existingItem) {
                continue;
            }

            TenantPlanItemConfiguration::where('item_id', $existingItem->id)->delete();
            $existingItem->delete();
        }

        foreach ($modulesToPromote as $entry) {
            $moduleId = $entry['module_id'];
            $packageId = $entry['package_id'];
            $requestedTierId = $entry['tier_id'];

            $module = Module::find($moduleId);
            if (!$module) {
                continue;
            }

            $existingItem = $plan->items()->where('item_id', $moduleId)->orderBy('id')->first();
            if (!$existingItem) {
                continue;
            }

            if (!Package::where('status', true)->where('id', $packageId)->exists()) {
                continue;
            }

            $pricingContext = $this->resolvePricingContext(
                module: $module,
                packageId: $packageId,
                requestedTierId: $requestedTierId > 0 ? $requestedTierId : null
            );

            $canonicalPricing = $this->buildCanonicalPricingValues(
                $pricingContext['base_price'],
                $pricingContext['applied_price']
            );

            $existingItem->update([
                'package_id' => $packageId,
                'item_type' => 'module',
                'module_name' => $module->name,
                'base_price' => $pricingContext['base_price'],
                'applied_price' => $pricingContext['applied_price'],
                'discount_amount' => $canonicalPricing['discount_amount'],
                'discount_percent' => $canonicalPricing['discount_percent'],
                'pricing_source' => 'package',
                'module_pricing_tier_id' => $pricingContext['module_pricing_tier_id'],
                'usage_limit' => $pricingContext['usage_limit'],
                'billing_type' => $module->pricing_type,
                'payload' => json_encode($module),
            ]);

            if ($pricingContext['usage_limit'] === null) {
                TenantPlanItemConfiguration::where('item_id', $existingItem->id)
                    ->where('key', 'usage')
                    ->delete();
            } else {
                $this->persistUsageConfiguration(
                    planItemId: $existingItem->id,
                    usageLimit: $pricingContext['usage_limit'],
                    basePrice: $pricingContext['base_price'],
                    description: 'Configuração de uso aplicada no item.'
                );
            }

            $duplicates = $plan->items()
                ->where('item_id', $moduleId)
                ->where('id', '!=', $existingItem->id)
                ->get();

            foreach ($duplicates as $duplicateItem) {
                TenantPlanItemConfiguration::where('item_id', $duplicateItem->id)->delete();
                $duplicateItem->delete();
            }
        }

        foreach ($modulesToAdd as $entry) {
            $moduleId = $entry['module_id'];
            $packageId = $entry['package_id'];
            $requestedTierId = $entry['tier_id'];
            $existingItem = $plan->items()->where('item_id', $moduleId)->first();
            $module = Module::find($moduleId);
            if (!$module) {
                continue;
            }

            $resolvedPackageId = null;
            if ($packageId > 0 && Package::where('status', true)->where('id', $packageId)->exists()) {
                $resolvedPackageId = $packageId;
            }

            $pricingContext = $this->resolvePricingContext(
                module: $module,
                packageId: $resolvedPackageId,
                requestedTierId: $requestedTierId > 0 ? $requestedTierId : null
            );

            /**
             * Se o módulo já existe como avulso, converte para item de pacote
             * para evitar duplicidade visual entre "Módulos Avulsos" e "Pacote".
             */
            if ($existingItem) {
                $canonicalPricing = $this->buildCanonicalPricingValues(
                    $pricingContext['base_price'],
                    $pricingContext['applied_price']
                );

                $existingItem->update([
                    'package_id' => $resolvedPackageId,
                    'item_type' => 'module',
                    'module_name' => $module->name,
                    'base_price' => $pricingContext['base_price'],
                    'applied_price' => $pricingContext['applied_price'],
                    'discount_amount' => $canonicalPricing['discount_amount'],
                    'discount_percent' => $canonicalPricing['discount_percent'],
                    'pricing_source' => $resolvedPackageId ? 'package' : 'manual',
                    'module_pricing_tier_id' => $pricingContext['module_pricing_tier_id'],
                    'usage_limit' => $pricingContext['usage_limit'],
                    'billing_type' => $module->pricing_type,
                    'payload' => json_encode($module),
                ]);

                if ($pricingContext['usage_limit'] === null) {
                    TenantPlanItemConfiguration::where('item_id', $existingItem->id)
                        ->where('key', 'usage')
                        ->delete();
                } else {
                    $this->persistUsageConfiguration(
                        planItemId: $existingItem->id,
                        usageLimit: $pricingContext['usage_limit'],
                        basePrice: $pricingContext['base_price'],
                        description: 'Configuração de uso aplicada no item.'
                    );
                }

                continue;
            }

            $createdItem = $this->createCanonicalPlanItem(
                planId: $plan->id,
                module: $module,
                packageId: $resolvedPackageId,
                pricingSource: $resolvedPackageId ? 'package' : 'manual',
                pricingContext: $pricingContext
            );

            $this->persistUsageConfiguration(
                planItemId: $createdItem->id,
                usageLimit: $pricingContext['usage_limit'],
                basePrice: $pricingContext['base_price'],
                description: 'Configuração de uso aplicada no item.'
            );
        }

        $this->orderService->recalculateOrderTotals($order);

        return [
            'message' => 'Módulos atualizados com sucesso.',
            'action' => 'bulk_updated',
        ];
    }

    /**
     * Salva a configuração de uso do módulo no pedido em andamento.
     */
    private function updateUsage($order, $value): array
    {
        // Extrai identificadores do payload enviado pelo front.
        $moduleId = is_array($value) ? ($value['module_id'] ?? null) : null;
        $usageLimit = is_array($value) ? ($value['usage'] ?? null) : null;

        // Valida dados mínimos obrigatórios para processar o uso.
        if (!$moduleId || !$usageLimit) {
            return [
                'message' => 'Parâmetros de uso inválidos.',
                'action' => 'invalid',
            ];
        }

        $orderItem = $order->plan->items()->where('item_id', $moduleId)->first();

        // Interrompe quando o módulo não existe no pedido atual.
        if (!$orderItem) {
            return [
                'message' => 'Módulo não encontrado no pedido.',
                'action' => 'not_found',
            ];
        }

        // Busca a faixa de preço do módulo com base no limite escolhido.
        $pricingTier = ModulePricingTier::where('module_id', $moduleId)
            ->where('usage_limit', $usageLimit)
            ->first();

        // Garante que o limite selecionado pertence a uma faixa válida.
        if (!$pricingTier) {
            return [
                'message' => 'Faixa de uso inválida para este módulo.',
                'action' => 'invalid_tier',
            ];
        }

        $basePrice = (float) $pricingTier->price;
        $appliedPrice = $basePrice;

        if ($orderItem->pricing_source === 'package' && $orderItem->package_id) {
            $package = Package::with('modules')
                ->where('status', true)
                ->find($orderItem->package_id);

            $moduleInPackage = $package?->modules->firstWhere('id', $moduleId);
            if ($moduleInPackage) {
                $appliedPrice = (float) ($moduleInPackage->pivot->price ?? $basePrice);
            }
        }

        $canonicalPricing = $this->buildCanonicalPricingValues($basePrice, $appliedPrice);

        $orderItem->base_price = $basePrice;
        $orderItem->applied_price = $appliedPrice;
        $orderItem->discount_amount = $canonicalPricing['discount_amount'];
        $orderItem->discount_percent = $canonicalPricing['discount_percent'];
        $orderItem->module_pricing_tier_id = $pricingTier->id;
        $orderItem->usage_limit = $pricingTier->usage_limit;
        $orderItem->save();

        $this->persistUsageConfiguration(
            planItemId: $orderItem->id,
            usageLimit: $pricingTier->usage_limit,
            basePrice: $basePrice,
            description: 'Configuração de uso atualizada no item.'
        );

        // Recalcula os totais do pedido após alteração de uso.
        $this->orderService->recalculateOrderTotals($order);

        // Retorna status de sucesso para o front.
        return [
            'message' => 'Uso do módulo atualizado com sucesso.',
            'action' => 'updated',
        ];
    }

    /**
     * Resolve valores canônicos de preço do item com base no módulo, pacote e tier.
     */
    private function resolvePricingContext($module, $packageId = null, $requestedTierId = null): array
    {
        $basePrice = (float) $module->value;
        $appliedPrice = $basePrice;
        $resolvedTierId = null;
        $usageLimit = null;
        $packageModule = null;

        if ($packageId) {
            $package = Package::with('modules')->where('status', true)->find($packageId);
            if ($package) {
                $packageModule = $package->modules->firstWhere('id', $module->id);
                if ($packageModule) {
                    $appliedPrice = (float) ($packageModule->pivot->price ?? $basePrice);
                }
            }
        }

        if (($module->pricing_type ?? 'Preço Fixo') === 'Preço Por Uso') {
            if ($requestedTierId) {
                $tier = ModulePricingTier::where('id', $requestedTierId)->where('module_id', $module->id)->first();
            } elseif ($packageModule) {
                $tierId = (int) ($packageModule->pivot->module_pricing_tier_id ?? 0);
                $tier = $tierId > 0
                    ? ModulePricingTier::where('id', $tierId)->where('module_id', $module->id)->first()
                    : null;
            } else {
                $tier = ModulePricingTier::where('module_id', $module->id)->orderBy('usage_limit')->first();
            }

            if ($tier) {
                $resolvedTierId = $tier->id;
                $usageLimit = $tier->usage_limit;
                $basePrice = (float) $tier->price;

                if (!$packageId) {
                    $appliedPrice = $basePrice;
                }
            }
        }

        return [
            'base_price' => $basePrice,
            'applied_price' => $appliedPrice,
            'module_pricing_tier_id' => $resolvedTierId,
            'usage_limit' => $usageLimit,
        ];
    }

    /**
     * Cria item de plano com contrato canônico de precificação.
     */
    private function createCanonicalPlanItem($planId, $module, $packageId, $pricingSource, array $pricingContext): TenantPlanItem
    {
        $canonicalPricing = $this->buildCanonicalPricingValues(
            $pricingContext['base_price'],
            $pricingContext['applied_price']
        );

        return TenantPlanItem::create([
            'plan_id'      => $planId,
            'package_id'   => $packageId,
            'item_id'      => $module->id,
            'item_type'    => 'module',
            'module_name'  => $module->name,
            'base_price' => $pricingContext['base_price'],
            'applied_price' => $pricingContext['applied_price'],
            'discount_amount' => $canonicalPricing['discount_amount'],
            'discount_percent' => $canonicalPricing['discount_percent'],
            'pricing_source' => $pricingSource,
            'module_pricing_tier_id' => $pricingContext['module_pricing_tier_id'],
            'usage_limit' => $pricingContext['usage_limit'],
            'billing_type' => $module->pricing_type,
            'payload'      => json_encode($module),
        ]);
    }

    /**
     * Persiste configuração de uso do item somente quando houver faixa válida.
     */
    private function persistUsageConfiguration($planItemId, $usageLimit, $basePrice, $description): void
    {
        if ($usageLimit === null) {
            return;
        }

        TenantPlanItemConfiguration::updateOrCreate(
            [
                'item_id' => $planItemId,
                'key' => 'usage',
            ],
            [
                'value' => (string) $usageLimit,
                'value_type' => 'integer',
                'derived_pricing_effect' => [
                    'usage_limit' => (int) $usageLimit,
                    'price' => (float) $basePrice,
                ],
                'description' => $description,
            ]
        );
    }

    private function buildCanonicalPricingValues($basePrice, $appliedPrice): array
    {
        $basePriceValue = floatval($basePrice);
        $appliedPriceValue = floatval($appliedPrice);

        $discountAmount = 0.0;
        $discountPercent = 0.0;

        if ($basePriceValue > 0 && $appliedPriceValue < $basePriceValue) {
            $discountAmount = round($basePriceValue - $appliedPriceValue, 2);
            $discountPercent = round((($basePriceValue - $appliedPriceValue) / $basePriceValue) * 100, 3);
        }

        return [
            'discount_amount' => $discountAmount,
            'discount_percent' => $discountPercent,
        ];
    }

    /**
     * Atualiza limites adicionais de usuários e armazenamento do plano em rascunho.
     */
    private function updateLimits($order, $value): array
    {
        if (!is_array($value)) {
            return [
                'message' => 'Parâmetros de limite inválidos.',
                'action' => 'invalid',
            ];
        }

        $plan = $order->plan;
        if (!$plan) {
            return [
                'message' => 'Plano em progresso não encontrado.',
                'action' => 'not_found',
            ];
        }

        $usersLimit = isset($value['users_limit']) ? (int) $value['users_limit'] : null;
        $storageLimitGb = isset($value['storage_limit_gb']) ? (int) $value['storage_limit_gb'] : null;

        if ($usersLimit !== null && $usersLimit > 0) {
            $plan->users_limit = $usersLimit;
            $this->upsertAdditionalUsersItem($plan, $usersLimit);
        }

        if ($storageLimitGb !== null && $storageLimitGb > 0) {
            $plan->size_storage = $storageLimitGb * 1024 * 1024 * 1024;
            $this->upsertAdditionalStorageItem($plan, $storageLimitGb);
        }

        $plan->save();
        $this->orderService->recalculateOrderTotals($order);

        return [
            'message' => 'Limites atualizados com sucesso.',
            'action' => 'updated',
        ];
    }

    /**
     * Materializa item de usuários adicionais a partir da faixa total selecionada.
     */
    private function upsertAdditionalUsersItem($plan, int $usersLimit): void
    {
        $additional = AdditionalUser::where('status', true)
            ->where('quantity', $usersLimit)
            ->first();

        $existing = $plan->items()->where('item_type', 'additional_user')->first();

        if (!$additional) {
            if ($existing) {
                $existing->delete();
            }
            return;
        }

        $price = (float) $additional->price;

        if (!$existing) {
            TenantPlanItem::create([
                'plan_id' => $plan->id,
                'package_id' => null,
                'item_id' => null,
                'item_type' => 'additional_user',
                'module_name' => 'Usuários permitidos: ' . $usersLimit,
                'base_price' => $price,
                'applied_price' => $price,
                'discount_amount' => 0,
                'discount_percent' => 0,
                'pricing_source' => 'manual',
                'module_pricing_tier_id' => null,
                'usage_limit' => $usersLimit,
                'billing_type' => 'Adicional',
                'payload' => json_encode([
                    'quantity' => $usersLimit,
                    'price' => $price,
                ]),
            ]);
            return;
        }

        $existing->update([
            'module_name' => 'Usuários permitidos: ' . $usersLimit,
            'base_price' => $price,
            'applied_price' => $price,
            'discount_amount' => 0,
            'discount_percent' => 0,
            'pricing_source' => 'manual',
            'usage_limit' => $usersLimit,
            'billing_type' => 'Adicional',
            'payload' => json_encode([
                'quantity' => $usersLimit,
                'price' => $price,
            ]),
        ]);
    }

    /**
     * Materializa item de armazenamento adicional a partir da faixa total selecionada.
     */
    private function upsertAdditionalStorageItem($plan, int $storageLimitGb): void
    {
        $additional = AdditionalStorage::where('status', true)
            ->where('quantity', $storageLimitGb)
            ->first();

        $existing = $plan->items()->where('item_type', 'additional_storage')->first();

        if (!$additional) {
            if ($existing) {
                $existing->delete();
            }
            return;
        }

        $price = (float) $additional->price;

        if (!$existing) {
            TenantPlanItem::create([
                'plan_id' => $plan->id,
                'package_id' => null,
                'item_id' => null,
                'item_type' => 'additional_storage',
                'module_name' => 'Armazenamento: ' . $storageLimitGb . ' GB',
                'base_price' => $price,
                'applied_price' => $price,
                'discount_amount' => 0,
                'discount_percent' => 0,
                'pricing_source' => 'manual',
                'module_pricing_tier_id' => null,
                'usage_limit' => $storageLimitGb,
                'billing_type' => 'Adicional',
                'payload' => json_encode([
                    'quantity' => $storageLimitGb,
                    'price' => $price,
                ]),
            ]);
            return;
        }

        $existing->update([
            'module_name' => 'Armazenamento: ' . $storageLimitGb . ' GB',
            'base_price' => $price,
            'applied_price' => $price,
            'discount_amount' => 0,
            'discount_percent' => 0,
            'pricing_source' => 'manual',
            'usage_limit' => $storageLimitGb,
            'billing_type' => 'Adicional',
            'payload' => json_encode([
                'quantity' => $storageLimitGb,
                'price' => $price,
            ]),
        ]);
    }

    public function cancel(Request $request)
    {

        // Obtém os dados enviados no formulário
        $data = $request->all();

        // Verifica se veio o id do pedido
        if (isset($data['tenant'])) {

            // Encontra o pedido do cliente
            $order = $data['tenant']->lastOrder();

            // Se ele existir atualiza para cancelado
            if ($order) {

                // Inicia o serviço da pagarme
                $pagarme = new PagarMeService();

                // Cancela a assinatura
                $response = $pagarme->cancelSubscription($order->subscription->provider_subscription_id);

                // Se a assinatura foi cancelada
                if ((isset($response['status']) && $response['status'] == 'canceled') || $response['message'] == 'This subscription is canceled.') {

                    $order->update([
                        'status' => 'canceled'
                    ]);

                    $order->subscription->update([
                        'status' => 'canceled'
                    ]);

                    // Retorna o cliente atualizado
                    return response()->json([
                        'message' => 'Assinatura cancelada com sucesso'
                    ], 200);
                }
            }
        }

        // Retorna o cliente atualizado
        return response()->json([
            'message' => 'Ocorreu um erro ao cancelar a assinatura. Tente novamente mais tarde.'
        ], 500);
    }
}
