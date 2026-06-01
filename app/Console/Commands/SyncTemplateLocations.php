<?php

namespace App\Console\Commands;

use App\Services\TemplateLocationSyncService;
use Illuminate\Console\Command;
use Throwable;

class SyncTemplateLocations extends Command
{
    protected $signature = 'locations:sync-from-template';

    protected $description = 'Sincroniza estados e cidades do banco template MiCore para a Central.';

    /**
     * Executa a sincronização de localidades usadas no cadastro de tenants.
     */
    public function handle(TemplateLocationSyncService $service): int
    {
        try {
            $result = $service->sync();
        } catch (Throwable $exception) {
            $this->error('Falha ao sincronizar localidades: ' . $exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Localidades sincronizadas com sucesso.');
        $this->line('Estados: ' . $result['states']);
        $this->line('Cidades: ' . $result['cities']);

        return self::SUCCESS;
    }
}
