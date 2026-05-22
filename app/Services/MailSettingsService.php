<?php

namespace App\Services;

use App\Models\SystemSetting;
use Illuminate\Support\Facades\Crypt;
use Throwable;

class MailSettingsService
{
    /**
     * Mantém a lista central das chaves SMTP persistidas no banco.
     * Isso evita divergência entre leitura, escrita e aplicação dinâmica.
     */
    private const SMTP_KEYS = [
        'mailer',
        'host',
        'port',
        'username',
        'password',
        'encryption',
        'from_address',
        'from_name',
        'notification_emails',
    ];

    private const LAST_TEST_ERROR_KEY = 'mail.smtp.last_test_error';

    /**
     * Retorna a configuração SMTP efetiva do sistema.
     * Quando uma chave não existir no banco, usa o valor padrão do config/env.
     */
    public function getSettings(): array
    {
        // Carrega todas as chaves SMTP em uma única consulta para reduzir acoplamento.
        $storedSettings = SystemSetting::query()
            ->whereIn('key', $this->keysWithPrefix())
            ->get()
            ->keyBy('key');

        return [
            'mailer' => $this->resolveValue($storedSettings, 'mailer', config('mail.default', 'smtp')),
            'host' => $this->resolveValue($storedSettings, 'host', config('mail.mailers.smtp.host')),
            'port' => $this->resolveValue($storedSettings, 'port', config('mail.mailers.smtp.port')),
            'username' => $this->resolveValue($storedSettings, 'username', config('mail.mailers.smtp.username')),
            'password' => $this->resolveValue($storedSettings, 'password', config('mail.mailers.smtp.password')),
            'encryption' => $this->resolveValue($storedSettings, 'encryption', config('mail.mailers.smtp.encryption')),
            'from_address' => $this->resolveValue($storedSettings, 'from_address', config('mail.from.address')),
            'from_name' => $this->resolveValue($storedSettings, 'from_name', config('mail.from.name')),
            'notification_emails' => $this->resolveValue($storedSettings, 'notification_emails', ''),
        ];
    }

    /**
     * Prepara os dados para o formulário de edição.
     * A senha não volta preenchida por segurança, mas informamos se ela já existe.
     */
    public function getFormData(): array
    {
        $settings = $this->getSettings();

        // Nunca devolve a senha descriptografada para a tela.
        $settings['password'] = '';
        $settings['hasPassword'] = $this->hasStoredPassword();
        $settings['last_test_error'] = $this->getLastTestError();

        return $settings;
    }

    /**
     * Persiste o último erro do teste SMTP para exibição fixa na tela.
     */
    public function recordTestFailure(string $message): void
    {
        SystemSetting::updateOrCreate(
            ['key' => self::LAST_TEST_ERROR_KEY],
            [
                'value' => $message,
                'is_encrypted' => false,
            ]
        );
    }

    /**
     * Limpa o erro persistente quando o teste SMTP volta a funcionar.
     */
    public function clearTestFailure(): void
    {
        SystemSetting::query()
            ->where('key', self::LAST_TEST_ERROR_KEY)
            ->delete();
    }

    /**
     * Persiste as configurações SMTP informadas na interface.
     * A senha é criptografada e mantida quando o campo vier vazio.
     */
    public function save(array $data): void
    {
        $currentSettings = $this->getSettings();

        foreach (self::SMTP_KEYS as $key) {
            // Mantém a senha atual quando o usuário salva sem reenviar esse campo.
            if ($key === 'password' && empty($data['password'])) {
                if (! empty($currentSettings['password'])) {
                    continue;
                }
            }

            $value = $data[$key] ?? null;
            $isEncrypted = $key === 'password' && filled($value);

            if ($isEncrypted) {
                $value = Crypt::encryptString($value);
            }

            // Usa chave estável para permitir update incremental de cada opção SMTP.
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
     * Aplica a configuração SMTP em tempo de execução.
     * Isso permite usar os valores salvos sem depender de editar o .env.
     */
    public function apply(): void
    {
        $settings = $this->getSettings();

        // Sobrescreve apenas as chaves de mail necessárias para o envio atual.
        config([
            'mail.default' => $settings['mailer'] ?: 'smtp',
            'mail.mailers.smtp.transport' => 'smtp',
            'mail.mailers.smtp.host' => $settings['host'],
            'mail.mailers.smtp.port' => $settings['port'],
            'mail.mailers.smtp.username' => $settings['username'],
            'mail.mailers.smtp.password' => $settings['password'],
            'mail.mailers.smtp.encryption' => $settings['encryption'],
            'mail.from.address' => $settings['from_address'],
            'mail.from.name' => $settings['from_name'],
        ]);

        try {
            // Limpa instâncias anteriores para forçar o Laravel a recriar o mailer.
            app('mail.manager')->forgetMailers();
        } catch (Throwable) {
            //
        }
    }

    /**
     * Devolve os e-mails de notificação em formato de lista simples.
     * Isso facilita reaproveitar o campo salvo em outros fluxos do sistema.
     */
    public function getNotificationEmails(): array
    {
        $notificationEmails = $this->getSettings()['notification_emails'] ?? '';

        // Aceita lista por vírgula, ponto e vírgula ou quebra de linha.
        $items = preg_split('/[\s,;]+/', (string) $notificationEmails, -1, PREG_SPLIT_NO_EMPTY);

        return array_values(array_unique($items));
    }

    /**
     * Gera as chaves completas salvas no banco para cada opção SMTP.
     * Isso centraliza o prefixo e evita duplicação de strings.
     */
    private function keysWithPrefix(): array
    {
        return array_map(fn (string $key) => $this->prefix($key), self::SMTP_KEYS);
    }

    /**
     * Padroniza o nome de armazenamento das configurações SMTP.
     * O prefixo separa essas chaves de futuras configurações globais.
     */
    private function prefix(string $key): string
    {
        return "mail.smtp.{$key}";
    }

    /**
     * Resolve um valor salvo no banco com suporte a criptografia.
     * Em caso de dado inválido, retorna o valor padrão para evitar quebra do fluxo.
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
            // A descriptografia fica isolada para proteger o restante da resolução.
            return Crypt::decryptString($setting->value);
        } catch (Throwable) {
            return $default;
        }
    }

    /**
     * Indica se já existe uma senha SMTP salva.
     * Isso é usado pela interface para orientar o preenchimento do campo.
     */
    private function hasStoredPassword(): bool
    {
        return SystemSetting::query()
            ->where('key', $this->prefix('password'))
            ->whereNotNull('value')
            ->exists();
    }

    /**
     * Recupera o último erro do teste SMTP quando ainda não foi solucionado.
     */
    public function getLastTestError(): ?string
    {
        $setting = SystemSetting::query()
            ->where('key', self::LAST_TEST_ERROR_KEY)
            ->first();

        return $setting?->value;
    }

}
