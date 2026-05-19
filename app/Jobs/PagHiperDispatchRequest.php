<?php

namespace App\Jobs;

use App\DTOs\PagHiper\PagHiperDTO;

use App\Services\{
    PagHiperResponseService,
    TenantService
};

use App\Services\Payments\{
    ActivePlanService,
    PagHiperPayloadService,
    SubscriptionService
};

class PagHiperDispatchRequest extends InicializerJob
{
    /**
     * Variáveis Globais para a PagHiper
     */
    protected array $data;
    protected PagHiperDTO $pagHiperDTO;

    /**
     * Variaveis de Serviço
     */
    protected PagHiperResponseService $pagHiperResponseService;
    protected TenantService           $tenantService;
    protected SubscriptionService     $subscriptionService;
    protected ActivePlanService       $activePlanService;
    protected PagHiperPayloadService  $pagHiperPayloadService;

    /**
     * Inicializa serviços do pipeline e normaliza o payload recebido do webhook.
     */
    public function __construct(array $data)
    {
        $this->data = $data;

        // Inicia os serviços necessários
        $this->pagHiperResponseService = app(PagHiperResponseService::class);
        $this->tenantService           = app(TenantService::class);
        $this->subscriptionService     = app(SubscriptionService::class);
        $this->activePlanService       = app(ActivePlanService::class);
        $this->pagHiperPayloadService  = app(PagHiperPayloadService::class);

        // Processa e normaliza os dados do webhook
        $this->pagHiperDTO = $this->pagHiperResponseService->process($data);

    }

    /**
     * Roteia o tipo de evento normalizado para a rotina correspondente.
     */
    protected function process(): mixed
    {
        return match ($this->pagHiperDTO->type) {
            'pix',
            'boleto' => $this->payments($this->pagHiperDTO),
            default  => null,
        };
    }

    /**
     * Processa eventos de pagamento resolvendo o contexto completo antes de persistir.
     */
    private function payments(PagHiperDTO $pagHiperDTO): void
    {
        // Resolve o tenant a partir da transação externa recebida no evento
        $tenant = $this->tenantService->findTenantByTransaction('paghiper', $pagHiperDTO->transactionId);

        // Verifica se já existe um plano ativo cadastrado
        $plan = $this->activePlanService->findActivePlan($tenant->id);

        // Busca ou cria assinatura apenas quando o PIX foi efetivamente pago
        $subscription = $pagHiperDTO->status === 'paid' ? $this->subscriptionService->findOrCreateForPagHiper($tenant->id, $plan->id, $pagHiperDTO->transactionId, $pagHiperDTO->type) : null;

        // Monta e persiste o pagamento
        $this->pagHiperPayloadService->create($pagHiperDTO, $tenant, $subscription, $plan, $this->data);
    }
}
