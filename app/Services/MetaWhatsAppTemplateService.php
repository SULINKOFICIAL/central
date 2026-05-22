<?php

namespace App\Services;

use Illuminate\Support\Str;

class MetaWhatsAppTemplateService
{
    /**
     * Centraliza a chamada da API de template do WhatsApp via Meta.
     * Isso evita espalhar payload e endpoint do Graph API pelo sistema.
     */
    public function __construct(
        private readonly RequestService $requestService,
        private readonly WhatsAppSettingsService $whatsAppSettingsService,
    ) {
    }

    /**
     * Dispara um template para um número usando a integração Meta/WhatsApp informada.
     * O método recebe componentes opcionais para permitir reuso com templates diferentes.
     */
    public function sendTemplate(
        string $phoneNumberId,
        string $accessToken,
        string $phoneNumber,
        string $templateName,
        string $languageCode = 'pt_BR',
        array $components = [],
    ): array {
        // Usa o phone number id informado para montar o endpoint correto.
        $url = "https://graph.facebook.com/v20.0/{$phoneNumberId}/messages";

        // Mantém o payload mínimo e compatível com a API oficial de templates da Meta.
        $payload = [
            'messaging_product' => 'whatsapp',
            'to' => onlyNumbers($phoneNumber),
            'type' => 'template',
            'template' => [
                'name' => $templateName,
                'language' => [
                    'code' => $languageCode,
                ],
            ],
        ];

        // Só envia componentes quando o template realmente precisar de parâmetros.
        if (! empty($components)) {
            $payload['template']['components'] = $components;
        }

        return $this->requestService->request(
            'POST',
            $url,
            [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                    'Content-Type' => 'application/json',
                ],
                'json' => $payload,
            ]
        );
    }

    /**
     * Busca os números vinculados à conta dona e devolve o primeiro phone number id disponível.
     * Isso permite testar o envio apenas com WABA ID e token manual informados na configuração.
     */
    public function getPhoneNumberIdFromOwnerAccount(string $ownerAccountId, string $accessToken): ?string
    {
        $response = $this->requestPhoneNumbers($ownerAccountId, $accessToken);

        if (($response['status'] ?? 500) >= 400) {
            return null;
        }

        return $response['data']['data'][0]['id'] ?? null;
    }

    /**
     * Envia o template de problema para os telefones de notificação configurados.
     * A resolução de configuração fica isolada no serviço de WhatsApp.
     */
    public function sendSystemProblemAlertToConfiguredPhones(array $incident): array
    {
        return $this->sendSystemProblemAlert($incident, null);
    }

    /**
     * Envia o template de problema para um telefone específico de teste.
     */
    public function sendSystemProblemAlertTest(string $phoneNumber, string $systemName, string $description, string $eventDate): array
    {
        return $this->sendSystemProblemAlert([
            'system_name' => $systemName,
            'description' => $description,
            'event_date' => $eventDate,
        ], [$phoneNumber]);
    }

    /**
     * Centraliza a consulta dos números vinculados à conta dona da Meta.
     * Isso evita duplicação do endpoint quando o serviço precisar resolver o emissor.
     */
    private function requestPhoneNumbers(string $ownerAccountId, string $accessToken): array
    {
        return $this->requestService->request(
            'GET',
            "https://graph.facebook.com/v20.0/{$ownerAccountId}/phone_numbers",
            [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                ],
            ]
        );
    }

    /**
     * Executa o envio do alerta de problema usando configuração atual da integração Meta.
     */
    private function sendSystemProblemAlert(array $incident, ?array $targetPhones = null): array
    {
        $settings = $this->whatsAppSettingsService->getSettings();
        $notificationPhones = $targetPhones ?? $this->whatsAppSettingsService->getNotificationPhones();

        if (empty($notificationPhones)) {
            return [
                'success' => false,
                'skipped' => true,
                'reason' => 'Nenhum telefone de notificação configurado.',
            ];
        }

        /**
         * Retorna os campos pendentes para a tela não exibir erro genérico.
         */
        $missingSettings = $this->whatsAppSettingsService->missingMetaConfigurationLabels($settings);

        if (! empty($missingSettings)) {
            return [
                'success' => false,
                'skipped' => true,
                'reason' => 'Configuração de WhatsApp incompleta: ' . implode(', ', $missingSettings) . '.',
            ];
        }

        $phoneNumberId = $this->getPhoneNumberIdFromOwnerAccount(
            $settings['owner_account_id'],
            $settings['access_token'],
        );

        if (! $phoneNumberId) {
            return [
                'success' => false,
                'skipped' => false,
                'error' => 'Não foi possível localizar um phone_number_id válido para a conta configurada.',
            ];
        }

        $normalizedIncident = $this->normalizeProblemIncident($incident);
        $components = $this->buildSystemProblemTemplateComponents($normalizedIncident);
        $results = [];
        $successCount = 0;

        foreach ($notificationPhones as $notificationPhone) {
            $result = $this->sendTemplate(
                $phoneNumberId,
                $settings['access_token'],
                $notificationPhone,
                $settings['template_name'],
                $settings['template_language'] ?: 'pt_BR',
                $components,
            );

            $results[] = [
                'phone' => $notificationPhone,
                'result' => $result,
            ];

            if (($result['status'] ?? 500) < 400) {
                $successCount++;
            }
        }

        return [
            'success' => $successCount > 0,
            'skipped' => false,
            'total' => count($notificationPhones),
            'success_count' => $successCount,
            'error_count' => count($notificationPhones) - $successCount,
            'results' => $results,
        ];
    }

    /**
     * Padroniza os dados mínimos usados no template de problema.
     */
    private function normalizeProblemIncident(array $incident): array
    {
        $systemName = $incident['system_name'] ?? config('app.name', 'MiCore');
        $description = $incident['description'] ?? $incident['message'] ?? 'Problema não especificado.';
        $eventDate = $incident['event_date'] ?? now()->format('d/m/Y H:i:s');

        if (! is_string($systemName)) {
            $systemName = config('app.name', 'MiCore');
        }

        if (! is_string($description)) {
            $description = 'Problema não especificado.';
        }

        if (! is_string($eventDate)) {
            $eventDate = now()->format('d/m/Y H:i:s');
        }

        return array_merge($incident, [
            'system_name' => $systemName,
            'description' => $description,
            'event_date' => $eventDate,
        ]);
    }

    /**
     * Monta os parâmetros do template de alerta no padrão esperado pela Meta.
     */
    private function buildSystemProblemTemplateComponents(array $incident): array
    {
        return [
            [
                'type' => 'header',
                'parameters' => [
                    [
                        'type' => 'text',
                        'text' => Str::limit($incident['system_name'], 60, ''),
                    ],
                ],
            ],
            [
                'type' => 'body',
                'parameters' => [
                    [
                        'type' => 'text',
                        'text' => Str::limit($incident['system_name'], 60, ''),
                    ],
                    [
                        'type' => 'text',
                        'text' => Str::limit($incident['description'], 500, ''),
                    ],
                    [
                        'type' => 'text',
                        'text' => Str::limit($incident['event_date'], 50, ''),
                    ],
                ],
            ],
        ];
    }
}
