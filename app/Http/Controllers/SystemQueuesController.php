<?php

namespace App\Http\Controllers;

use App\Services\CentralSupervisorService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class SystemQueuesController extends Controller
{
    /**
     * Injeta o serviço responsável por consultar e reiniciar Supervisor.
     */
    public function __construct(
        private readonly CentralSupervisorService $centralSupervisorService,
    ) {
    }

    /**
     * Exibe o status atual das filas da Central no Supervisor.
     */
    public function index(): View
    {
        return view('pages.system.central-queues', [
            'supervisorStatus' => $this->centralSupervisorService->status(),
        ]);
    }

    /**
     * Reinicia as filas da Central e retorna para a tela de consulta.
     */
    public function restart(): RedirectResponse
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
}
