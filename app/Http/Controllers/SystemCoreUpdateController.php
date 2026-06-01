<?php

namespace App\Http\Controllers;

use App\Services\CoreBusinessUpdateService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class SystemCoreUpdateController extends Controller
{
    /**
     * Injeta o serviço que atualiza a própria central core_business.
     */
    public function __construct(
        private readonly CoreBusinessUpdateService $coreBusinessUpdateService,
    ) {
    }

    /**
     * Atualiza a própria central core_business a partir do menu de configuração.
     */
    public function run(): RedirectResponse|View
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
