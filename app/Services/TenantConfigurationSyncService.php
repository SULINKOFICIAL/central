<?php

namespace App\Services;

use App\Models\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Serviço único para sincronização consolidada de configuração do tenant.
 *
 * Contrato enviado ao tenant:
 * - effective_at: datetime ISO8601
 * - period.start_date, period.end_date (Y-m-d)
 * - limits.users, limits.storage_bytes
 * - modules: [{slug,status}]
 * - metadata: {source, operator_id, reason, request_id}
 */
class TenantConfigurationSyncService
{
    public function __construct(private GuzzleService $guzzleService)
    {
    }

    /**
     * Sincroniza o estado final do tenant (fonte: plano atual em core_business).
     */
    public function syncFromCurrentPlan(
        Tenant $tenant,
        string $source,
        ?int $operatorId = null,
        ?string $reason = null,
        ?string $requestId = null,
        ?string $startDate = null,
        ?string $endDate = null,
    ): array {
        /**
         * Recarrega o tenant para garantir estado mais recente do banco
         * antes de montar o payload consolidado.
         */
        $tenant = $tenant->fresh() ?? $tenant;

        /**
         * Carrega tudo que é necessário para montar o payload consolidado
         * sem consultas adicionais durante a transformação.
         */
        $tenant->loadMissing(['plan.items.item.category', 'plan.items.item.resources', 'subscriptions.cycles']);

        /**
         * Plano atual é a fonte de verdade da configuração.
         * Também usamos último ciclo para preencher período quando
         * a chamada não informar datas explícitas.
         */
        $plan = $tenant->plan;
        $lastSubscription = $tenant->subscriptions()->latest('id')->first();
        $lastCycle = $lastSubscription?->cycles()->latest('id')->first();
        $trialDays = $plan?->trial_days ?? TenantInitialTrialPlanService::DEFAULT_TRIAL_DAYS;

        /**
         * Datas efetivas da configuração.
         * Prioridade: parâmetro recebido > último ciclo > período definido no plano inicial.
         */
        $effectiveStartDate = $startDate
            ?: ($lastCycle?->start_date ? Carbon::parse($lastCycle->start_date)->format('Y-m-d') : now()->toDateString());

        $effectiveEndDate = $endDate
            ?: ($lastCycle?->end_date ? Carbon::parse($lastCycle->end_date)->format('Y-m-d') : now()->addDays($trialDays)->toDateString());

        /**
         * Converte os itens do plano em catálogo consolidado de módulos
         * para envio único ao tenant.
         */
        $moduleEntries = collect($plan?->items ?? [])
            ->map(fn ($item) => $item->item)
            ->filter()
            ->unique('slug')
            ->values()
            ->map(fn ($module) => [
                'slug'          => $module->slug,
                'name'          => $module->name,
                'category'      => $module->category?->name,
                'category_slug' => $module->category?->slug,
                'status'        => true,
                'resources'     => collect($module->resources ?? [])->pluck('name')->filter()->values()->all(),
            ])
            ->all();

        /**
         * Payload único do contrato de sincronização.
         */
        $payload = [
            'effective_at' => now()->toIso8601String(),
            'period'       => [
                'start_date' => $effectiveStartDate,
                'end_date'   => $effectiveEndDate,
            ],
            'limits'       => [
                'users'         => (int) ($plan?->users_limit ?? 0),
                'storage_bytes' => (int) ($plan?->size_storage ?? 0),
            ],
            'modules'      => $moduleEntries,
            'metadata' => [
                'source'      => $source,
                'operator_id' => $operatorId,
                'reason'      => $reason,
                'request_id' => $requestId ?: (string) Str::uuid(),
            ],
        ];

        /**
         * Mede a duração fim-a-fim da sincronização para rastreio operacional.
         */
        $startedAt = microtime(true);

        /**
         * Envia uma única requisição consolidada ao tenant.
         */
        $response = $this->guzzleService->request(
            'post',
            'sistema/reconfigurar-tenant',
            $tenant,
            $payload,
            ['timeout' => 20]
        );

        $durationMs = (int) round((microtime(true) - $startedAt) * 1000);

        /**
         * Auditoria operacional em log de aplicação.
         * Não depende de logs_apis.
         */
        Log::info('tenant.configuration.sync', [
            'tenant_id'    => $tenant->id,
            'request_id'   => $payload['metadata']['request_id'],
            'source'       => $source,
            'duration_ms' => $durationMs,
            'success'      => (bool) ($response['success'] ?? false),
            'status_code' => $response['status_code'] ?? null,
            'payload'      => [
                'effective_at'  => $payload['effective_at'],
                'period'        => $payload['period'],
                'limits'        => $payload['limits'],
                'modules_count' => count($payload['modules']),
                'metadata'      => $payload['metadata'],
            ],
            'response' => $response,
        ]);

        /**
         * Retorno padronizado para o chamador acompanhar sucesso,
         * request_id e detalhes da operação.
         */
        return [
            'success'     => (bool) ($response['success'] ?? false),
            'duration_ms' => $durationMs,
            'request_id'  => $payload['metadata']['request_id'],
            'payload'     => $payload,
            'response'    => $response,
        ];
    }
}
