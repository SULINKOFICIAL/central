<?php

namespace App\Http\Controllers;

use App\Services\SystemProblemNotificationService;
use App\Services\WhatsAppSettingsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SystemWhatsAppSettingsController extends Controller
{
    /**
     * Injeta somente os serviços necessários para configurar e testar WhatsApp.
     */
    public function __construct(
        private readonly WhatsAppSettingsService $whatsAppSettingsService,
        private readonly SystemProblemNotificationService $systemProblemNotificationService,
    ) {
    }

    /**
     * Exibe a página de configuração de WhatsApp do sistema.
     */
    public function edit(): View
    {
        $whatsAppSettings = $this->whatsAppSettingsService->getFormData();

        return view('pages.system.settings-whatsapp', [
            'whatsAppSettings' => $whatsAppSettings,
            'missingWhatsAppSettings' => $this->whatsAppSettingsService->missingMetaConfigurationLabels($whatsAppSettings),
        ]);
    }

    /**
     * Valida e persiste as configurações do WhatsApp informadas pelo usuário.
     */
    public function update(Request $request): RedirectResponse
    {
        /**
         * Mantém a validação concentrada na página de WhatsApp.
         */
        $whatsAppData = $request->validate([
            'notification_phones' => ['nullable', 'string'],
            'whatsapp_template_name' => ['required', 'string', 'max:255'],
            'whatsapp_template_language' => ['required', 'string', 'max:20'],
            'whatsapp_owner_account_id' => ['nullable', 'string', 'max:255'],
            'whatsapp_access_token' => ['nullable', 'string'],
        ]);

        /**
         * Persiste apenas as chaves do domínio WhatsApp para evitar acoplamento com SMTP.
         */
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
     * Dispara manualmente o template de alerta do WhatsApp para validar a integração.
     */
    public function sendTest(Request $request): RedirectResponse
    {
        /**
         * Valida a zona de testes sem misturar com o formulário principal de configuração.
         */
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
}
