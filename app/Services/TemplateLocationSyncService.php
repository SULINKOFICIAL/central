<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use RuntimeException;

class TemplateLocationSyncService
{
    private const CONNECTION = 'mysql_template_locations';

    /**
     * Sincroniza estados e cidades do banco template para a Central.
     */
    public function sync(): array
    {
        $this->connectTemplateDatabase();

        $result = $this->syncFromConnection(self::CONNECTION);

        DB::disconnect(self::CONNECTION);

        return $result;
    }

    /**
     * Sincroniza a partir de uma conexão já configurada.
     */
    public function syncFromConnection(string $connection): array
    {
        $states = DB::connection($connection)
            ->table('states')
            ->select([
                'id',
                'country_id',
                'name',
                'acronym',
                'code',
                'cuf',
                'status',
                'filed_by',
                'created_by',
                'updated_by',
                'created_at',
                'updated_at',
            ])
            ->orderBy('id')
            ->get()
            ->map(function ($state) {
                return [
                    'id' => $state->id,
                    'country_id' => $state->country_id,
                    'name' => $state->name,
                    'acronym' => $state->acronym,
                    'code' => $state->code,
                    'cuf' => $state->cuf,
                    'status' => $state->status,
                    'filed_by' => $state->filed_by,
                    'created_by' => $state->created_by,
                    'updated_by' => $state->updated_by,
                    'created_at' => $state->created_at,
                    'updated_at' => $state->updated_at,
                ];
            })
            ->all();

        $cities = DB::connection($connection)
            ->table('cities')
            ->select([
                'id',
                'state_id',
                'name',
                'code_ibge',
                'status',
                'filed_by',
                'created_by',
                'updated_by',
                'created_at',
                'updated_at',
            ])
            ->orderBy('id')
            ->get()
            ->map(function ($city) {
                return [
                    'id' => $city->id,
                    'state_id' => $city->state_id,
                    'name' => $city->name,
                    'code_ibge' => $city->code_ibge,
                    'status' => $city->status,
                    'filed_by' => $city->filed_by,
                    'created_by' => $city->created_by,
                    'updated_by' => $city->updated_by,
                    'created_at' => $city->created_at,
                    'updated_at' => $city->updated_at,
                ];
            })
            ->all();

        if (!empty($states)) {
            DB::table('states')->upsert(
                $states,
                ['id'],
                ['country_id', 'name', 'acronym', 'code', 'cuf', 'status', 'filed_by', 'created_by', 'updated_by', 'created_at', 'updated_at']
            );
        }

        if (!empty($cities)) {
            DB::table('cities')->upsert(
                $cities,
                ['id'],
                ['state_id', 'name', 'code_ibge', 'status', 'filed_by', 'created_by', 'updated_by', 'created_at', 'updated_at']
            );
        }

        return [
            'states' => count($states),
            'cities' => count($cities),
        ];
    }

    /**
     * Monta conexão temporária para ler o banco template do cPanel.
     */
    private function connectTemplateDatabase(): void
    {
        $prefix = env('CPANEL_PREFIX') ?? '';
        $host = env('WHM_IP') ?? '';
        $user = env('CPANEL_USER') ?? '';
        $password = env('CPANEL_PASS') ?? '';

        if ($prefix === '' || $host === '' || $user === '') {
            throw new RuntimeException('CPANEL_PREFIX, WHM_IP e CPANEL_USER precisam estar preenchidos para sincronizar localidades.');
        }

        config([
            'database.connections.' . self::CONNECTION => [
                'driver' => 'mysql',
                'host' => $host,
                'database' => $prefix . '_template',
                'username' => $user,
                'password' => $password,
                'charset' => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
                'prefix' => '',
                'strict' => false,
            ],
        ]);

        DB::purge(self::CONNECTION);
        DB::disconnect(self::CONNECTION);
        DB::reconnect(self::CONNECTION);
    }
}
