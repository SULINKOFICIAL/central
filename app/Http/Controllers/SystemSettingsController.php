<?php

namespace App\Http\Controllers;

use App\Mail\SimpleEmailMailable;
use App\Models\Tenant;
use App\Services\CpanelDomainLookupService;
use App\Services\CentralSupervisorService;
use App\Services\CoreBusinessUpdateService;
use App\Services\EmailService;
use App\Services\MailSettingsService;
use App\Services\ProvisioningIntegrityService;
use App\Services\SystemProblemNotificationService;
use App\Services\TenantConfigurationSyncService;
use App\Services\WhatsAppSettingsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

class SystemSettingsController extends Controller
{
    /**
     * Injeta os serviços usados para salvar configuração e testar envios.
     * Isso mantém o controller fino e alinhado ao padrão do projeto.
     */
    public function __construct(
        private readonly MailSettingsService $mailSettingsService,
        private readonly WhatsAppSettingsService $whatsAppSettingsService,
        private readonly EmailService $emailService,
        private readonly SystemProblemNotificationService $systemProblemNotificationService,
        private readonly TenantConfigurationSyncService $tenantConfigurationSyncService,
        private readonly ProvisioningIntegrityService $provisioningIntegrityService,
        private readonly CpanelDomainLookupService $cpanelDomainLookupService,
        private readonly CoreBusinessUpdateService $coreBusinessUpdateService,
        private readonly CentralSupervisorService $centralSupervisorService,
    ) {
    }

    /**
     * Exibe a página de configuração de SMTP do sistema.
     * A view recebe apenas os dados de e-mail e teste relacionados a esse domínio.
     */
    public function editMail(): View
    {
        return view('pages.system.settings-mail', [
            'mailSettings' => $this->mailSettingsService->getFormData(),
        ]);
    }

    /**
     * Exibe a página de configuração de WhatsApp do sistema.
     * A view recebe apenas os dados da integração Meta e do teste de template.
     */
    public function editWhatsApp(): View
    {
        $whatsAppSettings = $this->whatsAppSettingsService->getFormData();

        return view('pages.system.settings-whatsapp', [
            'whatsAppSettings' => $whatsAppSettings,
            'missingWhatsAppSettings' => $this->whatsAppSettingsService->missingMetaConfigurationLabels($whatsAppSettings),
        ]);
    }

    /**
     * Exibe a tela de sincronização em massa de planos para os tenants.
     */
    public function editSubscriptionsSync(): View
    {
        return view('pages.system.settings-subscriptions-sync', [
            'tenantsCount' => Tenant::query()->count(),
        ]);
    }

    /**
     * Exibe o diagnóstico dos requisitos para provisionar novos tenants.
     */
    public function provisioningIntegrity(): View
    {
        return view('pages.system.settings-provisioning-integrity', [
            'integrity' => $this->provisioningIntegrityService->check(),
        ]);
    }

    /**
     * Consulta se um domínio existe na conta cPanel configurada.
     */
    public function cpanelDomainLookup(Request $request): View
    {
        $lookup = null;
        $domain = $request->input('domain');

        if ($domain !== null && $domain !== '') {
            $data = $request->validate([
                'domain' => ['required', 'string', 'max:255'],
            ]);

            $lookup = $this->cpanelDomainLookupService->find($data['domain']);
        }

        return view('pages.system.settings-cpanel-domain-lookup', [
            'domain' => $domain,
            'lookup' => $lookup,
        ]);
    }

    /**
     * Exibe o status atual das filas da Central no Supervisor.
     */
    public function centralQueues(): View
    {
        return view('pages.system.central-queues', [
            'supervisorStatus' => $this->centralSupervisorService->status(),
        ]);
    }

    /**
     * Reinicia as filas da Central e retorna para a tela de consulta.
     */
    public function restartCentralQueues(): RedirectResponse
    {
        $result = $this->centralSupervisorService->restart();

        if (!($result['success'] ?? false)) {
            return redirect()
                ->route('system.settings.central.queues')
                ->with('error', 'Falha ao reiniciar filas da Central: ' . ($result['error'] ?: $result['output'] ?: 'erro desconhecido'));
        }

        return redirect()
            ->route('system.settings.central.queues')
            ->with('message', 'Filas da Central reiniciadas com sucesso.');
    }

    /**
     * Valida e persiste as configurações SMTP informadas pelo usuário.
     * Após salvar, reaplica a configuração para uso imediato na mesma sessão.
     */
    public function updateMail(Request $request): \Illuminate\Http\RedirectResponse
    {
        // Mantém a validação concentrada no ponto de entrada da página SMTP.
        $mailData = $request->validate([
            'mailer' => ['required', 'string', 'max:20'],
            'host' => ['required', 'string', 'max:255'],
            'port' => ['required', 'integer', 'min:1', 'max:65535'],
            'username' => ['required', 'string', 'max:255'],
            'password' => ['nullable', 'string', 'max:255'],
            'encryption' => ['nullable', 'string', 'max:20'],
            'from_address' => ['required', 'email', 'max:255'],
            'from_name' => ['required', 'string', 'max:255'],
            'notification_emails' => ['nullable', 'string'],
        ]);

        // Salva e reaplica para evitar depender de novo request ou cache clear manual.
        $this->mailSettingsService->save($mailData);
        $this->mailSettingsService->apply();

        return redirect()
            ->route('system.settings.mail.edit')
            ->with('message', 'Configurações SMTP salvas com sucesso.');
    }

    /**
     * Valida e persiste as configurações do WhatsApp informadas pelo usuário.
     * Isso mantém a edição da Meta desacoplada da configuração de e-mail.
     */
    public function updateWhatsApp(Request $request): \Illuminate\Http\RedirectResponse
    {
        // Mantém a validação concentrada na página de WhatsApp.
        $whatsAppData = $request->validate([
            'notification_phones' => ['nullable', 'string'],
            'whatsapp_template_name' => ['required', 'string', 'max:255'],
            'whatsapp_template_language' => ['required', 'string', 'max:20'],
            'whatsapp_owner_account_id' => ['nullable', 'string', 'max:255'],
            'whatsapp_access_token' => ['nullable', 'string'],
        ]);

        // Persiste apenas as chaves do domínio WhatsApp para evitar acoplamento com SMTP.
        $this->whatsAppSettingsService->save([
            'notification_phones' => $whatsAppData['notification_phones'] ?? null,
            'template_name' => $whatsAppData['whatsapp_template_name'],
            'template_language' => $whatsAppData['whatsapp_template_language'],
            'owner_account_id' => $whatsAppData['whatsapp_owner_account_id'] ?? null,
            'access_token' => $whatsAppData['whatsapp_access_token'] ?? null,
        ]);

        return redirect()
            ->route('system.settings.whatsapp.edit')
            ->with('message', 'Configurações de WhatsApp salvas com sucesso.');
    }

    /**
     * Dispara um e-mail simples usando a configuração SMTP salva.
     * O retorno da operação é exibido na própria tela de configuração.
     */
    public function sendTest(Request $request): \Illuminate\Http\RedirectResponse
    {
        // Valida apenas os campos necessários para um teste manual de envio.
        $data = $request->validate([
            'test_email' => ['required', 'email', 'max:255'],
            'test_name' => ['nullable', 'string', 'max:255'],
        ]);

        // Garante que o teste use os últimos dados persistidos no sistema.
        $this->mailSettingsService->apply();

        $result = $this->emailService->send(
            [
                'email' => $data['test_email'],
                'name' => $data['test_name'] ?? null,
            ],
            'Teste de SMTP',
            [
                'message_body' => 'Este e-mail foi enviado a partir da tela de configuracoes do sistema.',
                'cta_label' => 'Acessar MiCore',
                'cta_url' => config('app.url'),
            ]
        );

        // Centraliza a mensagem de retorno na mesma rota da tela de edição.
        return redirect()
            ->route('system.settings.mail.edit')
            ->withInput($request->only(['test_email', 'test_name']))
            ->with($result['success'] ? 'message' : 'error', $result['success']
                ? 'E-mail de teste enviado com sucesso.'
                : 'Falha ao enviar e-mail de teste: ' . ($result['error'] ?? 'erro desconhecido'));
    }

    /**
     * Renderiza em tela o mesmo HTML usado no template de e-mail.
     * Isso facilita validar estrutura, identidade visual e textos antes do envio.
     */
    public function preview(): Response
    {
        // Usa um payload de exemplo para mostrar o template em um estado realista.
        $html = (new SimpleEmailMailable(
            'Preview do template SMTP',
            [
                'recipient_name' => 'Usuário de teste',
                'message_body' => "Este preview mostra o HTML final renderizado pelo sistema.\n\nUse esta visualizacao para validar logo, cores, espacamento e hierarquia do conteudo antes de disparar e-mails reais.",
                'cta_label' => 'Acessar MiCore',
                'cta_url' => config('app.url'),
            ]
        ))->render();

        return response($html);
    }

    /**
     * Dispara manualmente o template de alerta do WhatsApp para validar a integração.
     * O método usa a configuração salva do template e os campos variáveis informados na tela.
     */
    public function sendWhatsAppTest(Request $request): \Illuminate\Http\RedirectResponse
    {
        // Valida a zona de testes sem misturar com o formulário principal de configuração.
        $data = $request->validate([
            'whatsapp_test_phone' => ['required', 'string', 'max:30'],
            'whatsapp_test_system_name' => ['required', 'string', 'max:60'],
            'whatsapp_test_description' => ['required', 'string', 'max:500'],
            'whatsapp_test_event_date' => ['required', 'string', 'max:50'],
        ]);

        $result = $this->systemProblemNotificationService->sendWhatsAppTest(
            $data['whatsapp_test_phone'],
            $data['whatsapp_test_system_name'],
            $data['whatsapp_test_description'],
            $data['whatsapp_test_event_date'],
        );

        return redirect()
            ->route('system.settings.whatsapp.edit')
            ->withInput($request->only([
                'whatsapp_test_phone',
                'whatsapp_test_system_name',
                'whatsapp_test_description',
                'whatsapp_test_event_date',
            ]))
            ->with(($result['success'] ?? false) ? 'message' : 'error', ($result['success'] ?? false)
                ? 'Template de WhatsApp enviado com sucesso.'
                : 'Falha ao enviar template de WhatsApp: ' . ($result['error'] ?? $result['reason'] ?? 'erro desconhecido'));
    }

    /**
     * Sincroniza em massa os planos atuais para todos os tenants.
     * Exige confirmação explícita na tela antes de executar.
     */
    public function syncSubscriptionsInBulk(): \Illuminate\Http\RedirectResponse
    {
        $periodStartDate = '2026-01-01';
        $periodEndDate = '2026-12-31';
        $syncedCount = 0;
        $failedTenants = [];

        $tenants = Tenant::query()->orderBy('id')->get();

        foreach ($tenants as $tenant) {
            $syncResult = $this->tenantConfigurationSyncService->syncFromCurrentPlan(
                $tenant,
                source: 'manual_admin',
                operatorId: 1,
                reason: 'Sincronização em massa manual após rebuild global',
                startDate: $periodStartDate,
                endDate: $periodEndDate,
            );

            if (!($syncResult['success'] ?? false)) {
                $failedTenants[] = $tenant->id;
                continue;
            }

            $syncedCount++;
        }

        if (!empty($failedTenants)) {
            return redirect()
                ->route('system.settings.subscriptions.sync.edit')
                ->with('error', 'Sincronização concluída parcialmente. Sucesso em ' . $syncedCount . ' tenant(s). Falha em: #' . implode(', #', $failedTenants) . '.');
        }

        return redirect()
            ->route('system.settings.subscriptions.sync.edit')
            ->with('message', 'Sincronização concluída com sucesso para ' . $syncedCount . ' tenant(s).');
    }

    /**
     * Atualiza a própria central core_business a partir do menu de configuração.
     */
    public function updateCoreBusiness(): RedirectResponse|View
    {
        $result = $this->coreBusinessUpdateService->run();

        /**
         * Falha operacional precisa abrir em tela própria para exibir saída bruta.
         */
        if (!$result['success']) {
            return view('pages.system.core-update-result', [
                'result' => $result,
            ]);
        }

        return redirect()
            ->route('dashboard')
            ->with('message', $result['message']);
    }
}
