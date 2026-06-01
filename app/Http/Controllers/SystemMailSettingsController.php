<?php

namespace App\Http\Controllers;

use App\Mail\SimpleEmailMailable;
use App\Services\EmailService;
use App\Services\MailSettingsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

class SystemMailSettingsController extends Controller
{
    /**
     * Injeta somente os serviços necessários para configurar e testar SMTP.
     */
    public function __construct(
        private readonly MailSettingsService $mailSettingsService,
        private readonly EmailService $emailService,
    ) {
    }

    /**
     * Exibe a página de configuração de SMTP do sistema.
     */
    public function edit(): View
    {
        return view('pages.system.settings-mail', [
            'mailSettings' => $this->mailSettingsService->getFormData(),
        ]);
    }

    /**
     * Valida e persiste as configurações SMTP informadas pelo usuário.
     */
    public function update(Request $request): RedirectResponse
    {
        /**
         * Mantém a validação concentrada no ponto de entrada da página SMTP.
         */
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

        /**
         * Salva e reaplica para evitar depender de novo request ou cache clear manual.
         */
        $this->mailSettingsService->save($mailData);
        $this->mailSettingsService->apply();

        return redirect()
            ->route('system.settings.mail.edit')
            ->with('message', 'Configurações SMTP salvas com sucesso.');
    }

    /**
     * Dispara um e-mail simples usando a configuração SMTP salva.
     */
    public function sendTest(Request $request): RedirectResponse
    {
        /**
         * Valida apenas os campos necessários para um teste manual de envio.
         */
        $data = $request->validate([
            'test_email' => ['required', 'email', 'max:255'],
            'test_name' => ['nullable', 'string', 'max:255'],
        ]);

        /**
         * Garante que o teste use os últimos dados persistidos no sistema.
         */
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

        /**
         * Centraliza a mensagem de retorno na mesma rota da tela de edição.
         */
        return redirect()
            ->route('system.settings.mail.edit')
            ->withInput($request->only(['test_email', 'test_name']))
            ->with($result['success'] ? 'message' : 'error', $result['success']
                ? 'E-mail de teste enviado com sucesso.'
                : 'Falha ao enviar e-mail de teste: ' . ($result['error'] ?? 'erro desconhecido'));
    }

    /**
     * Renderiza em tela o mesmo HTML usado no template de e-mail.
     */
    public function preview(): Response
    {
        /**
         * Usa um payload de exemplo para mostrar o template em um estado realista.
         */
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
}
