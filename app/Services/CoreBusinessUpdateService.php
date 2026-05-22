<?php

namespace App\Services;

use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Process\Process;
use Throwable;

class CoreBusinessUpdateService
{
    public function __construct(
        private readonly CentralSupervisorService $centralSupervisorService
    ) {
    }

    /**
     * Executa a rotina de atualização da central core_business.
     */
    public function run(): array
    {
        $steps = $this->steps();
        $results = [];

        foreach ($steps as $step) {
            $result = $this->runStep($step);
            $results[] = $result;

            /**
             * Interrompe no primeiro erro para evitar continuar com código
             * ou banco em estados parcialmente incompatíveis.
             */
            if (!$result['success']) {
                return [
                    'success' => false,
                    'message' => 'Falha ao atualizar a central na etapa: ' . $result['label'] . '.',
                    'results' => $results,
                ];
            }
        }

        return [
            'success' => true,
            'message' => 'Central core_business atualizada com sucesso.',
            'results' => $results,
        ];
    }

    /**
     * Define a ordem operacional necessária para publicar a versão atual.
     */
    private function steps(): array
    {
        return [
            [
                'label' => 'Atualizar código',
                'command' => ['git', 'pull', '--ff-only'],
                'timeout' => 300,
            ],
            [
                'label' => 'Rodar migrations',
                'artisan' => [
                    'command' => 'migrate',
                    'parameters' => [
                        '--force' => true,
                    ],
                ],
                'timeout' => 600,
            ],
            [
                'label' => 'Limpar cache da aplicação',
                'artisan' => [
                    'command' => 'optimize:clear',
                    'parameters' => [],
                ],
                'timeout' => 300,
            ],
            [
                'label' => 'Reiniciar filas',
                'command' => $this->centralSupervisorService->restartCommand(),
                'timeout' => 120,
            ],
        ];
    }

    /**
     * Executa uma etapa isolada e normaliza saída, erro e código de retorno.
     */
    private function runStep(array $step): array
    {
        try {
            /**
             * Comandos Artisan rodam pelo próprio Laravel, igual ao fluxo dos tenants.
             * Isso evita chamar php-fpm por engano quando PHP_BINARY vem do FPM.
             */
            if (!empty($step['artisan'])) {
                return $this->runArtisanStep($step);
            }

            $process = new Process($step['command'], base_path());
            $process->setTimeout($step['timeout']);
            $process->run();

            return [
                'label' => $step['label'],
                'success' => $process->isSuccessful(),
                'exit_code' => $process->getExitCode(),
                'output' => $this->normalizeOutput($process->getOutput()),
                'error' => $this->normalizeOutput($process->getErrorOutput()),
            ];
        } catch (Throwable $exception) {
            return [
                'label' => $step['label'],
                'success' => false,
                'exit_code' => null,
                'output' => '',
                'error' => $exception->getMessage(),
            ];
        }
    }

    /**
     * Executa uma etapa Artisan sem depender do binário PHP do servidor.
     */
    private function runArtisanStep(array $step): array
    {
        $exitCode = Artisan::call(
            $step['artisan']['command'],
            $step['artisan']['parameters']
        );

        return [
            'label' => $step['label'],
            'success' => $exitCode === 0,
            'exit_code' => $exitCode,
            'output' => $this->normalizeOutput(Artisan::output()),
            'error' => '',
        ];
    }

    /**
     * Limita a saída exibida no flash para não estourar a sessão.
     */
    private function normalizeOutput(string $output): string
    {
        $normalizedOutput = preg_replace('/\s+/', ' ', $output);

        if (mb_strlen($normalizedOutput) <= 500) {
            return $normalizedOutput;
        }

        return mb_substr($normalizedOutput, 0, 500) . '...';
    }
}
