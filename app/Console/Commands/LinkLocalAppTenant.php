<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Comando local para vincular um tenant da Central a um banco MiCore de desenvolvimento.
 *
 * Ele evita Tinker/manual SQL ao preparar testes do app, criando ou atualizando o tenant,
 * o provisioning técnico e a rota `local_database` usada pelo gateway `app.micore.com.br`.
 */
class LinkLocalAppTenant extends Command
{
    protected $signature = 'tenants:link-local-app
        {--tenant-id= : ID da empresa que o app usará no login}
        {--name= : Nome do tenant local}
        {--database= : Banco local que será usado pelo tenant}
        {--db-user= : Usuário MySQL do banco do tenant}
        {--db-password= : Senha MySQL do banco do tenant}
        {--email= : E-mail de referência que deve existir no banco do tenant}
        {--operator-email=local-app@micore.test : Usuário técnico criado na Central para created_by}';

    protected $description = 'Cria ou atualiza um vínculo local entre tenant da Central e banco MiCore para testes do app.';

    /**
     * Cria ou atualiza o tenant local usado pelo aplicativo em desenvolvimento.
     */
    public function handle(): int
    {
        /**
         * Coleta todas as opções antes de alterar qualquer registro.
         */
        $tenantId       = $this->requiredOption('tenant-id');
        $tenantName     = $this->requiredOption('name');
        $tenantDatabase = $this->requiredOption('database');
        $tenantDbUser   = $this->requiredOption('db-user');
        $tenantDbPass   = $this->option('db-password') ?? '';
        $tenantEmail    = $this->option('email');
        $operatorEmail  = $this->option('operator-email') ?: 'local-app@micore.test';

        try {
            /**
             * Valida ambiente, banco e usuário final antes de criar o vínculo.
             */
            $this->assertSafeEnvironment();
            $this->assertDatabaseExists($tenantDatabase);
            $this->assertTenantUserExists($tenantDatabase, $tenantEmail);

            $operatorId = $this->ensureCentralOperator($operatorEmail);
            $this->linkTenant($tenantId, $tenantName, $tenantDatabase, $tenantDbUser, $tenantDbPass, $tenantEmail, $operatorId);
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Vínculo local do app criado ou atualizado com sucesso.');
        $this->line('ID da empresa: ' . $tenantId);
        $this->line('Banco do tenant: ' . $tenantDatabase);

        if ($tenantEmail) {
            $this->line('E-mail de referência: ' . $tenantEmail);
        }

        return self::SUCCESS;
    }

    /**
     * Bloqueia uso em produção porque o comando cria vínculos manuais de desenvolvimento.
     */
    private function assertSafeEnvironment(): void
    {
        /**
         * Produção nunca deve receber vínculos manuais de desenvolvimento.
         */
        if (app()->environment('production')) {
            throw new RuntimeException('Este comando só deve ser usado em ambiente local ou de desenvolvimento.');
        }
    }

    /**
     * Obtém uma option obrigatória e falha com mensagem clara quando vier vazia.
     */
    private function requiredOption(string $name)
    {
        /**
         * Options obrigatórias precisam vir preenchidas no comando.
         */
        $value = $this->option($name);

        if ($value === null || $value === '') {
            throw new RuntimeException('Informe --' . $name . '.');
        }

        return $value;
    }

    /**
     * Garante que o banco local informado existe no MySQL usado pela Central.
     */
    private function assertDatabaseExists($database): void
    {
        /**
         * SHOW DATABASES confirma se o banco do tenant existe localmente.
         */
        $databases = DB::select('SHOW DATABASES');

        foreach ($databases as $row) {
            if ($row->Database === $database) {
                return;
            }
        }

        throw new RuntimeException('Banco local não encontrado: ' . $database . '.');
    }

    /**
     * Confere o usuário final no banco do tenant quando um e-mail de referência for informado.
     */
    private function assertTenantUserExists($database, $email): void
    {
        /**
         * Sem e-mail de referência, o comando não valida usuário final.
         */
        if (!$email) {
            return;
        }

        $originalDatabase = config('database.connections.mysql.database');

        try {
            /**
             * Troca temporariamente para o banco do tenant para consultar users.
             */
            Config::set('database.connections.mysql.database', $database);
            DB::purge('mysql');
            DB::reconnect('mysql');

            if (!DB::getSchemaBuilder()->hasTable('users')) {
                throw new RuntimeException('O banco ' . $database . ' não possui tabela users.');
            }

            $exists = DB::table('users')
                ->where('email', $email)
                ->exists();
        } finally {
            Config::set('database.connections.mysql.database', $originalDatabase);
            DB::purge('mysql');
            DB::reconnect('mysql');
        }

        if (!$exists) {
            throw new RuntimeException('E-mail não encontrado no banco do tenant: ' . $email . '.');
        }
    }

    /**
     * Garante um usuário técnico na Central para preencher o created_by do tenant local.
     */
    private function ensureCentralOperator($operatorEmail)
    {
        /**
         * Reusa operador técnico quando ele já existe na Central.
         */
        $operator = DB::table('users')
            ->where('email', $operatorEmail)
            ->first();

        if ($operator) {
            return $operator->id;
        }

        /**
         * Cria operador técnico mínimo para registros criados pelo comando.
         */
        DB::table('users')->insert([
            'name'       => 'Operador Local App',
            'email'      => $operatorEmail,
            'password'   => Hash::make(Str::random(32)),
            'status'     => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return DB::table('users')
            ->where('email', $operatorEmail)
            ->value('id');
    }

    /**
     * Cria ou atualiza o tenant e seu provisioning local em uma transação única.
     */
    private function linkTenant($tenantId, $tenantName, $tenantDatabase, $tenantDbUser, $tenantDbPass, $tenantEmail, $operatorId): void
    {
        /**
         * A transação mantém tenant, provisioning e rota app sincronizados.
         */
        DB::transaction(function () use ($tenantId, $tenantName, $tenantDatabase, $tenantDbUser, $tenantDbPass, $tenantEmail, $operatorId) {
            $now = now();

            DB::table('tenants')->updateOrInsert(
                [
                    'id' => $tenantId,
                ],
                [
                    'type_installation' => 'shared',
                    'name'              => $tenantName,
                    'company'           => $tenantName,
                    'email'             => $tenantEmail,
                    'status'            => true,
                    'created_by'        => $operatorId,
                    'updated_at'        => $now,
                    'created_at'        => $now,
                ]
            );

            DB::table('tenants_provisionings')->updateOrInsert(
                [
                    'tenant_id' => $tenantId,
                ],
                [
                    'table'          => $tenantDatabase,
                    'table_user'     => $tenantDbUser,
                    'table_password' => $tenantDbPass,
                    'first_user'     => $this->firstUserPayload($tenantEmail),
                    'install'        => 'completed',
                    'updated_at'     => $now,
                    'created_at'     => $now,
                ]
            );

            DB::table('tenant_app_routes')->updateOrInsert(
                [
                    'tenant_id' => $tenantId,
                ],
                [
                    'mode'                 => 'local_database',
                    'remote_base_url'      => null,
                    'remote_service_token' => null,
                    'status'               => true,
                    'updated_at'           => $now,
                    'created_at'           => $now,
                ]
            );
        });
    }

    /**
     * Mantém um payload informativo para identificar qual usuário local foi usado no vínculo.
     */
    private function firstUserPayload($tenantEmail)
    {
        /**
         * Payload informativo é opcional e só existe quando há e-mail.
         */
        if (!$tenantEmail) {
            return null;
        }

        return json_encode([
            'email' => $tenantEmail,
        ]);
    }
}
