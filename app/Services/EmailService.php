<?php

namespace App\Services;

use App\Mail\SimpleEmailMailable;
use App\Models\EmailDispatchLog;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Mail;
use Throwable;

class EmailService
{
    /**
     * Injeta o serviço que aplica a configuração SMTP dinâmica antes do envio.
     * Isso desacopla o disparo de e-mail da origem da configuração.
     */
    public function __construct(
        private readonly MailSettingsService $mailSettingsService,
    ) {
    }

    /**
     * Envia um único e-mail para string simples ou array de destinatário.
     * O método reaplica o SMTP atual antes de montar o envio.
     */
    public function send(string|array $recipient, string $subject, array $data = [], string $template = 'emails.simple'): array
    {
        // Recarrega o mailer para usar a última configuração salva no sistema.
        $this->mailSettingsService->apply();

        return $this->sendToRecipient(
            $this->normalizeRecipient($recipient),
            $subject,
            $data,
            $template,
        );
    }

    /**
     * Envia o mesmo conteúdo para múltiplos destinatários.
     * O retorno consolida totais de sucesso e erro por destinatário.
     */
    public function sendMany(array $recipients, string $subject, array $data = [], string $template = 'emails.simple'): array
    {
        // Aplica a configuração uma vez antes do lote para manter consistência.
        $this->mailSettingsService->apply();

        $results = [];
        $successCount = 0;
        $errorCount = 0;

        foreach ($recipients as $recipient) {
            // Cada item é normalizado para manter o mesmo contrato do envio único.
            $result = $this->sendToRecipient(
                $this->normalizeRecipient($recipient),
                $subject,
                $data,
                $template,
            );

            $results[] = $result;

            if ($result['success']) {
                $successCount++;
                continue;
            }

            $errorCount++;
        }

        return [
            'success' => $errorCount === 0,
            'total' => count($results),
            'success_count' => $successCount,
            'error_count' => $errorCount,
            'results' => $results,
        ];
    }

    /**
     * Envia alerta de problema do sistema para os e-mails de notificação configurados.
     * O próprio serviço resolve os destinatários e o corpo padrão do incidente.
     */
    public function sendSystemProblemAlert(array $incident): array
    {
        $emails = $this->mailSettingsService->getNotificationEmails();

        if (empty($emails)) {
            return [
                'success' => false,
                'skipped' => true,
                'reason' => 'Nenhum e-mail de notificação configurado.',
            ];
        }

        $normalizedIncident = $this->normalizeProblemIncident($incident);
        $result = $this->sendMany(
            $emails,
            '[ALERTA] Problema no sistema ' . $normalizedIncident['system_name'],
            [
                'message_body' => $this->buildSystemProblemEmailBody($normalizedIncident),
                'cta_label' => 'Acessar Central',
                'cta_url' => config('app.url'),
            ]
        );

        return [
            'success' => (bool) ($result['success'] ?? false),
            'skipped' => false,
            'result' => $result,
        ];
    }

    /**
     * Executa o envio real para um destinatário e registra o resultado no banco.
     * O log persiste tanto casos de sucesso quanto de falha para auditoria simples.
     */
    private function sendToRecipient(array $recipient, string $subject, array $data, string $template): array
    {
        // Injeta o nome do destinatário no payload base para uso no template.
        $payload = array_merge($data, [
            'recipient_name' => $recipient['name'],
        ]);

        try {
            // Usa o mailable simples para manter o template Blade reutilizável.
            Mail::to($recipient['email'])->send(
                new SimpleEmailMailable($subject, $payload, $template)
            );

            // Registra o envio bem-sucedido para consulta posterior.
            $log = EmailDispatchLog::create([
                'recipient_email' => $recipient['email'],
                'recipient_name' => $recipient['name'],
                'subject' => $subject,
                'template' => $template,
                'status' => 'success',
                'payload' => $payload,
                'sent_at' => now(),
            ]);

            $this->mailSettingsService->clearTestFailure();

            return [
                'success' => true,
                'recipient_email' => $recipient['email'],
                'log_id' => $log->id,
            ];
        } catch (Throwable $exception) {
            // Registra a falha usando a mesma estrutura para simplificar rastreabilidade.
            $log = EmailDispatchLog::create([
                'recipient_email' => $recipient['email'],
                'recipient_name' => $recipient['name'],
                'subject' => $subject,
                'template' => $template,
                'status' => 'error',
                'error_message' => $exception->getMessage(),
                'payload' => $payload,
            ]);

            $this->mailSettingsService->recordTestFailure(
                'Falha ao enviar e-mail: ' . $exception->getMessage()
            );

            return [
                'success' => false,
                'recipient_email' => $recipient['email'],
                'error' => $exception->getMessage(),
                'log_id' => $log->id,
            ];
        }
    }

    /**
     * Normaliza o destinatário para um formato único com e-mail e nome.
     * Isso permite que a API pública aceite string simples ou array estruturado.
     */
    private function normalizeRecipient(string|array $recipient): array
    {
        if (is_string($recipient)) {
            return [
                'email' => $recipient,
                'name' => null,
            ];
        }

        return [
            'email' => $recipient['email'],
            'name' => $recipient['name'] ?? null,
        ];
    }

    /**
     * Padroniza os dados mínimos do incidente para o alerta por e-mail.
     */
    private function normalizeProblemIncident(array $incident): array
    {
        return array_merge($incident, [
            'system_name' => (string) ($incident['system_name'] ?? config('app.name', 'MiCore')),
            'description' => (string) ($incident['description'] ?? $incident['message'] ?? 'Problema não especificado.'),
            'event_date' => (string) ($incident['event_date'] ?? now()->format('d/m/Y H:i:s')),
            'status_code' => (int) ($incident['status_code'] ?? 500),
            'url' => (string) ($incident['url'] ?? ''),
            'ip_address' => (string) ($incident['ip_address'] ?? ''),
            'stack_trace' => (string) ($incident['stack_trace'] ?? ''),
        ]);
    }

    /**
     * Gera o corpo textual padrão para alertas de problema do sistema.
     */
    private function buildSystemProblemEmailBody(array $incident): string
    {
        $lines = [
            'Foi detectado um problema em um sistema integrado.',
            '',
            'Sistema: ' . $incident['system_name'],
            'Descrição: ' . $incident['description'],
            'Data/Hora: ' . $incident['event_date'],
            'Status HTTP: ' . $incident['status_code'],
            'URL: ' . ($incident['url'] ?: '-'),
            'IP: ' . ($incident['ip_address'] ?: '-'),
        ];

        if (! empty($incident['stack_trace'])) {
            $lines[] = '';
            $lines[] = 'Stack trace (resumo):';
            $lines[] = Str::limit($incident['stack_trace'], 1500);
        }

        return implode("\n", $lines);
    }
}
