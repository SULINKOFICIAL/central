<?php

namespace App\Services;

use Symfony\Component\Process\Process;
use Throwable;

class CentralSupervisorService
{
    /**
     * Caminhos fixos do Supervisor no servidor da Central.
     * O serviço usa esses valores tanto para consulta quanto para restart.
     */
    private const SUPERVISOR_CONFIG = '/etc/supervisord.conf';
    private const SUPERVISOR_BINARY = '/bin/supervisorctl';

    /**
     * Consulta o status atual dos processos gerenciados pelo Supervisor.
     * Além do retorno bruto, monta uma lista estruturada para exibir na tela.
     */
    public function status(): array
    {
        $result = $this->runSupervisorCommand([
            'status',
        ]);

        $result['processes'] = $this->parseStatusOutput($result['output']);

        return $result;
    }

    /**
     * Reinicia os processos configurados no Supervisor.
     * Esse método é usado pelo botão manual da tela de filas da Central.
     */
    public function restart(): array
    {
        return $this->runSupervisorCommand([
            'restart',
            'all',
        ]);
    }

    /**
     * Expõe o comando de restart para ser reutilizado no fluxo de atualização da Central.
     * Assim a rotina de deploy e a tela manual usam exatamente o mesmo comando.
     */
    public function restartCommand(): array
    {
        return $this->buildSupervisorCommand([
            'restart',
            'all',
        ]);
    }

    /**
     * Executa um comando do Supervisor e normaliza sucesso, saída e erro.
     * A exceção é capturada para a interface conseguir mostrar a causa operacional.
     */
    private function runSupervisorCommand(array $arguments): array
    {
        try {
            $process = new Process(
                $this->buildSupervisorCommand($arguments),
                base_path()
            );

            $process->setTimeout(120);
            $process->run();

            return [
                'success' => $process->isSuccessful(),
                'exit_code' => $process->getExitCode(),
                'output' => $process->getOutput(),
                'error' => $process->getErrorOutput(),
            ];
        } catch (Throwable $exception) {
            return [
                'success' => false,
                'exit_code' => null,
                'output' => '',
                'error' => $exception->getMessage(),
            ];
        }
    }

    /**
     * Monta o comando base do Supervisor com o usuário operacional deploy.
     * Os argumentos finais definem a ação específica, como status ou restart.
     */
    private function buildSupervisorCommand(array $arguments): array
    {
        return array_merge([
            'sudo',
            '-u',
            'deploy',
            self::SUPERVISOR_BINARY,
            '-c',
            self::SUPERVISOR_CONFIG,
        ], $arguments);
    }

    /**
     * Converte a saída textual do supervisorctl status em linhas estruturadas.
     * O detalhe preserva pid, uptime ou mensagem de falha retornada pelo Supervisor.
     */
    private function parseStatusOutput(string $output): array
    {
        $processes = [];
        $lines = preg_split('/\R/', $output);

        foreach ($lines as $line) {
            if ($line === '') {
                continue;
            }

            if (!preg_match('/^(\S+)\s+(\S+)\s*(.*)$/', $line, $matches)) {
                continue;
            }

            $processes[] = [
                'name' => $matches[1],
                'status' => $matches[2],
                'detail' => $matches[3] ?? '',
            ];
        }

        return $processes;
    }
}
