<?php

namespace App\Services;

use App\Models\SystemSetting;
use Illuminate\Support\Facades\Crypt;
use Throwable;

class WhatsAppSettingsService
{
    /**
     * Mantém a lista central das chaves de configuração do WhatsApp.
     * Isso separa o domínio da Meta do restante das configurações de e-mail.
     */
    private const WHATS_APP_KEYS = [
        'notification_phones',
        'template_name',
        'template_language',
        'owner_account_id',
        'access_token',
    ];

    /**
     * Retorna a configuração efetiva do WhatsApp salva no sistema.
     * Quando uma chave não existir, usa um valor padrão mínimo para o fluxo.
     */
    public function getSettings(): array
    {
        $storedSettings = SystemSetting::query()
            ->whereIn('key', $this->keysWithPrefix())
            ->get()
            ->keyBy('key');

        return [
            'notification_phones' => $this->resolveValue($storedSettings, 'notification_phones', ''),
            'template_name' => $this->resolveValue($storedSettings, 'template_name', 'alerta_problema_sistema'),
            'template_language' => $this->resolveValue($storedSettings, 'template_language', 'pt_BR'),
            'owner_account_id' => $this->resolveValue($storedSettings, 'owner_account_id', ''),
            'access_token' => $this->resolveValue($storedSettings, 'access_token', ''),
        ];
    }

    /**
     * Prepara os dados da interface com a configuração salva do WhatsApp.
     * Isso mantém a tela desacoplada da forma como os dados são persistidos.
     */
    public function getFormData(): array
    {
        return $this->getSettings();
    }

    /**
     * Informa quais campos obrigatórios ainda impedem disparos pela Meta.
     */
    public function missingMetaConfigurationLabels(array $settings = []): array
    {
        if (empty($settings)) {
            $settings = $this->getSettings();
        }

        $requiredFields = [
            'template_name' => 'Nome do template',
            'template_language' => 'Idioma',
            'owner_account_id' => 'Conta dona',
            'access_token' => 'Token de acesso da Meta',
        ];

        $missingFields = [];

        foreach ($requiredFields as $key => $label) {
            if (empty($settings[$key])) {
                $missingFields[] = $label;
            }
        }

        return $missingFields;
    }

    /**
     * Persiste as configurações do WhatsApp informadas na tela administrativa.
     * O token é criptografado antes do armazenamento para manter o padrão do sistema.
     */
    public function save(array $data): void
    {
        $currentSettings = $this->getSettings();

        foreach (self::WHATS_APP_KEYS as $key) {
            if ($key === 'access_token' && empty($data['access_token'])) {
                if (! empty($currentSettings['access_token'])) {
                    continue;
                }
            }

            $value = $data[$key] ?? null;
            $isEncrypted = $key === 'access_token' && filled($value);

            if ($isEncrypted) {
                $value = Crypt::encryptString($value);
            }

            SystemSetting::updateOrCreate(
                ['key' => $this->prefix($key)],
                [
                    'value' => $value,
                    'is_encrypted' => $isEncrypted,
                ]
            );
        }
    }

    /**
     * Devolve os telefones de notificação em formato de lista simples.
     * Isso facilita reaproveitar o campo salvo nos disparos automáticos.
     */
    public function getNotificationPhones(): array
    {
        $notificationPhones = $this->getSettings()['notification_phones'] ?? '';

        if (! is_string($notificationPhones)) {
            $notificationPhones = '';
        }

        $items = preg_split('/[\s,;]+/', $notificationPhones, -1, PREG_SPLIT_NO_EMPTY);

        return array_values(array_unique($items));
    }

    /**
     * Gera as chaves completas salvas no banco para cada opção do WhatsApp.
     * Isso centraliza o prefixo e evita colisão com configurações de e-mail.
     */
    private function keysWithPrefix(): array
    {
        return array_map(fn (string $key) => $this->prefix($key), self::WHATS_APP_KEYS);
    }

    /**
     * Padroniza o nome de armazenamento das configurações do WhatsApp.
     * O prefixo separa esse domínio do restante das configurações globais.
     */
    private function prefix(string $key): string
    {
        return "whatsapp.meta.{$key}";
    }

    /**
     * Resolve um valor salvo no banco com suporte a criptografia.
     * Em caso de falha na leitura, retorna o valor padrão para não quebrar a tela.
     */
    private function resolveValue($storedSettings, string $key, mixed $default = null): mixed
    {
        $setting = $storedSettings->get($this->prefix($key));

        if (! $setting || $setting->value === null) {
            return $default;
        }

        if (! $setting->is_encrypted) {
            return $setting->value;
        }

        try {
            return Crypt::decryptString($setting->value);
        } catch (Throwable) {
            return $default;
        }
    }
}
