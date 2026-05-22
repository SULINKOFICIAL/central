<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class TenantUpdateNotificationService
{
    /**
     * Assunto fixo do alerta operacional para facilitar busca na caixa de entrada.
     */
    private const SUBJECT = '[ALERTA] Falha ao atualizar tenants na Central';

    /**
     * Centraliza as dependências para manter o controller focado no update.
     */
    public function __construct(
        private readonly MailSettingsService $mailSettingsService,
        private readonly EmailService $emailService,
    ) {
    }

    /**
     * Envia um único e-mail ao final do update em massa quando houver falhas.
     * O método nunca interrompe o processamento dos tenants: se não houver erro
     * coletado, destinatário configurado ou o envio falhar, apenas encerra ou loga.
     */
    public function notifyFailures(array $context, array $failures): void
    {
        /**
         * Sem falhas não existe alerta operacional para disparar.
         */
        if (empty($failures)) {
            return;
        }

        /**
         * Usa a mesma lista configurada em SMTP para manter uma fonte única.
         */
        $recipients = $this->mailSettingsService->getNotificationEmails();

        /**
         * A ausência de destinatários não pode quebrar o update dos tenants.
         */
        if (empty($recipients)) {
            Log::warning('Alerta de falha no update de tenants ignorado por falta de destinatários.', [
                'scope' => $context['scope'] ?? null,
                'actions' => $context['actions'] ?? [],
                'failures_count' => count($failures),
            ]);

            return;
        }

        /**
         * Envia pelo serviço padrão para manter logs em email_dispatch_logs.
         */
        $result = $this->emailService->sendMany(
            $recipients,
            self::SUBJECT,
            [
                'message_body' => $this->buildBody($context, $failures),
                'cta_label' => 'Ver tenants',
                'cta_url' => route('tenants.index'),
            ]
        );

        /**
         * Falhas do próprio alerta ficam registradas sem relançar exceção.
         */
        if (! ($result['success'] ?? false)) {
            Log::warning('Falha ao enviar alerta de update de tenants para um ou mais destinatários.', [
                'scope' => $context['scope'] ?? null,
                'actions' => $context['actions'] ?? [],
                'failures_count' => count($failures),
                'email_result' => $result,
            ]);
        }
    }

    /**
     * Monta o corpo textual usado pelo template simples de e-mail.
     * O resumo fica em texto puro para evitar criar um template específico agora.
     */
    private function buildBody(array $context, array $failures): string
    {
        /**
         * Converte as ações técnicas para nomes legíveis no cabeçalho do alerta.
         */
        $actions = $context['actions'] ?? [];
        $actionLabels = [];

        foreach ($actions as $action) {
            $actionLabels[] = $this->actionLabel($action);
        }

        /**
         * Cabeçalho do e-mail com os dados gerais da execução do update.
         */
        $lines = [
            'A rotina Atualizar Sistemas encontrou falhas em um ou mais tenants.',
            '',
            'Escopo usado: ' . $this->scopeLabel($context['scope'] ?? null),
            'Ações solicitadas: ' . implode(', ', $actionLabels),
            'Total de tenants avaliados: ' . ($context['total_tenants'] ?? 0),
            'Total de falhas: ' . count($failures),
            '',
            'Falhas encontradas:',
        ];

        /**
         * Cada falha representa uma ação que retornou erro para um tenant.
         */
        foreach ($failures as $failure) {
            $lines[] = '';
            $lines[] = '- Tenant: ' . ($failure['tenant_name'] ?? '-');
            $lines[] = '  ID: ' . ($failure['tenant_id'] ?? '-');
            $lines[] = '  Tipo de instalação: ' . $this->installationLabel($failure['installation_type'] ?? null);
            $lines[] = '  Domínio principal: ' . ($failure['domain'] ?? '-');
            $lines[] = '  Ação: ' . $this->actionLabel($failure['action'] ?? null);
            $lines[] = '  Erro: ' . ($failure['error'] ?? 'Erro desconhecido');

            /**
             * Shared executa uma vez no tenant base e replica o status visual.
             */
            if (! empty($failure['shared_replicated_count'])) {
                $lines[] = '  Status replicado para tenants compartilhados: ' . $failure['shared_replicated_count'];
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Traduz o escopo técnico do modal para texto de operação.
     */
    private function scopeLabel(?string $scope): string
    {
        $labels = [
            'all' => 'Todos',
            'individual' => 'Individual',
            'shared' => 'Compartilhado',
        ];

        return $labels[$scope] ?? ($scope ?: '-');
    }

    /**
     * Traduz a ação técnica para o mesmo vocabulário usado na interface.
     */
    private function actionLabel(?string $action): string
    {
        $labels = [
            'database' => 'Banco de dados',
            'git' => 'Git pull',
            'supervisor' => 'Reinício de filas',
            'npm_build' => 'Build de Javascript',
        ];

        return $labels[$action] ?? ($action ?: '-');
    }

    /**
     * Traduz o tipo técnico de instalação para o resumo do alerta.
     */
    private function installationLabel(?string $installationType): string
    {
        $labels = [
            'dedicated' => 'Dedicada',
            'shared' => 'Compartilhada',
        ];

        return $labels[$installationType] ?? ($installationType ?: '-');
    }
}
