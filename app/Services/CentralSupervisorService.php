<?php

namespace App\Services;

use Symfony\Component\Process\Process;
use Throwable;

class CentralSupervisorService
{
    private const SUPERVISOR_CONFIG = '/etc/supervisord.conf';
    private const SUPERVISOR_BINARY = '/bin/supervisorctl';
    private const SUPERVISOR_USER = 'deploy';
    private const WORKER_TARGET = 'central-worker:*';

    public function status(): array
    {
        $result = $this->runSupervisorCommand([
            'status',
            self::WORKER_TARGET,
        ]);

        $result['processes'] = $this->parseStatusOutput($result['output']);

        return $result;
    }

    public function restart(): array
    {
        return $this->runSupervisorCommand([
            'restart',
            self::WORKER_TARGET,
        ]);
    }

    public function restartCommand(): array
    {
        return $this->buildSupervisorCommand([
            'restart',
            self::WORKER_TARGET,
        ]);
    }

    private function runSupervisorCommand(array $arguments): array
    {
        try {
            $process = new Process($this->buildSupervisorCommand($arguments), base_path());
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

    private function buildSupervisorCommand(array $arguments): array
    {
        return array_merge([
            'sudo',
            '-u',
            self::SUPERVISOR_USER,
            self::SUPERVISOR_BINARY,
            '-c',
            self::SUPERVISOR_CONFIG,
        ], $arguments);
    }

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
