<?php

namespace App\Services;

use App\Models\City;
use App\Models\Tenant;
use App\Models\TenantProvisioning;
use Exception;
use GuzzleHttp\Client as Guzzle;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Net\SSH2;
use RuntimeException;

class CpanelProvisioningService
{
    private string $cpanelUrl;
    private string $cpanelUser;
    private string $cpanelPass;
    private string $cpanelPrefix;

    public function __construct(private TenantConfigurationSyncService $syncService)
    {
        /**
         * Mantém as credenciais em propriedades para que todas as etapas
         * usem exatamente a mesma conta cPanel durante o provisionamento.
         */
        $this->cpanelUrl = env('CPANEL_URL') ?? '';
        $this->cpanelUser = env('CPANEL_USER') ?? '';
        $this->cpanelPass = env('CPANEL_PASS') ?? '';
        $this->cpanelPrefix = env('CPANEL_PREFIX') ?? '';
    }

    /**
     * Executa o fluxo de provisionamento da próxima etapa do cliente.
     */
    public function runProvisioning(Tenant|int $clientInput): array
    {
        $tenant = $this->resolveTenant($clientInput);
        $provisioning = $this->getTenantProvisioningOrFail($tenant);

        /**
         * O campo install é a fonte de verdade do checkpoint.
         * Cada chamada AJAX executa apenas a próxima etapa pendente.
         */
        if ($provisioning->install === TenantProvisioning::STEP_SUBDOMAIN) {
            return $this->handleSubdomainStep($tenant, $provisioning);
        }

        /**
         * Banco só roda depois do subdomínio ser considerado criado
         * ou já existente no cPanel.
         */
        if ($provisioning->install === TenantProvisioning::STEP_DATABASE) {
            return $this->handleDatabaseStep($tenant, $provisioning);
        }

        /**
         * Usuário e token dependem do banco do tenant já existir
         * e aceitar conexão com as credenciais provisionadas.
         */
        if ($provisioning->install === TenantProvisioning::STEP_USER_TOKEN) {
            return $this->handleUserTokenStep($tenant, $provisioning);
        }

        /**
         * Módulos ficam por último porque usam HTTP contra o tenant
         * e dependem do domínio, banco, token e usuário inicial.
         */
        if ($provisioning->install === TenantProvisioning::STEP_MODULES) {
            return $this->handleModulesStep($tenant, $provisioning);
        }

        /**
         * Qualquer estado posterior às etapas conhecidas é tratado como fim.
         * Isso preserva o fluxo atual sem tentar repetir etapas concluídas.
         */
        $provisioning->install = TenantProvisioning::STEP_COMPLETED;
        $provisioning->save();

        return [
            'url' => $this->getTenantPrimaryDomainOrFail($tenant),
            'message' => 'Conta criada com sucesso',
            'step' => TenantProvisioning::STEP_COMPLETED,
        ];
    }

    /**
     * Cria o subdomínio do cliente manualmente.
     */
    public function createSubdomainForTenant(int $clientId): array
    {
        $tenant = $this->resolveTenant($clientId);
        $domain = $this->getTenantPrimaryDomainOrFail($tenant);

        return $this->createSubdomain($domain);
    }

    /**
     * Clona o banco template para o cliente manualmente.
     */
    public function cloneDatabaseForTenant(int $clientId): array
    {
        $tenant = $this->resolveTenant($clientId);

        return $this->cloneDatabase($tenant);
    }

    /**
     * Insere usuário inicial e token no banco do cliente manualmente.
     */
    public function addTokenAndUserForTenant(int $clientId): array
    {
        $tenant = $this->resolveTenant($clientId);
        $this->insertTokenAndUser($tenant);

        return [
            'message' => 'Usuário e token inseridos com sucesso',
            'step' => TenantProvisioning::STEP_MODULES,
        ];
    }

    private function handleSubdomainStep(Tenant $tenant, TenantProvisioning $provisioning): array
    {
        $domain = $this->getTenantPrimaryDomainOrFail($tenant);

        Log::info('Criando subdomínio do cliente', ['tenant_id' => $tenant->id, 'domain' => $domain]);
        $this->createSubdomain($domain);

        /**
         * A etapa avança quando o subdomínio foi criado ou quando já existia.
         * createSubdomain() só retorna nesses dois cenários seguros.
         */
        $provisioning->install = TenantProvisioning::STEP_DATABASE;
        $provisioning->save();

        return [
            'message' => 'Subdomínio criado com sucesso',
            'step' => TenantProvisioning::STEP_DATABASE,
        ];
    }

    private function handleDatabaseStep(Tenant $tenant, TenantProvisioning $provisioning): array
    {
        Log::info('Clonando banco template para cliente', [
            'tenant_id' => $tenant->id,
            'database' => $provisioning->table,
        ]);

        $this->cloneDatabase($tenant);

        /**
         * cloneDatabase() também garante usuário MySQL e privilégios.
         * Se qualquer parte ficar ambígua, ela lança exceção e não avançamos.
         */
        $provisioning->install = TenantProvisioning::STEP_USER_TOKEN;
        $provisioning->save();

        return [
            'message' => 'Banco de dados clonado com sucesso',
            'step' => TenantProvisioning::STEP_USER_TOKEN,
        ];
    }

    private function handleUserTokenStep(Tenant $tenant, TenantProvisioning $provisioning): array
    {
        Log::info('Inserindo usuário e token no banco do cliente', [
            'tenant_id' => $tenant->id,
            'database' => $provisioning->table,
        ]);

        $this->insertTokenAndUser($tenant);

        /**
         * A etapa é idempotente: se usuário, colaborador, loja e configs
         * já existirem, os registros são reaproveitados ou atualizados.
         */
        $provisioning->install = TenantProvisioning::STEP_MODULES;
        $provisioning->save();

        return [
            'message' => 'Usuário e token inseridos com sucesso',
            'step' => TenantProvisioning::STEP_MODULES,
        ];
    }

    private function handleModulesStep(Tenant $tenant, TenantProvisioning $provisioning): array
    {
        Log::info('Configurando módulos do cliente', [
            'tenant_id' => $tenant->id,
            'database' => $provisioning->table,
        ]);

        $syncResult = $this->configureModulesForTenant($tenant);

        /**
         * Não avançamos quando a sincronização remota falha.
         * Isso permite clicar novamente e repetir apenas a etapa modules.
         */
        if (!($syncResult['success'] ?? false)) {
            throw new RuntimeException('Falha ao configurar módulos do tenant. A etapa será mantida para nova tentativa.');
        }

        /**
         * A próxima chamada do fluxo transforma finalizing em completed,
         * mantendo o comportamento visual já esperado pela tela.
         */
        $provisioning->install = TenantProvisioning::STEP_FINALIZING;
        $provisioning->save();

        return [
            'message' => 'Módulos configurados com sucesso',
            'step' => TenantProvisioning::STEP_FINALIZING,
        ];
    }

    private function resolveTenant(Tenant|int $clientInput): Tenant
    {
        /**
         * Aceita tanto model já carregado quanto id vindo do controller.
         * Em ambos os casos precisamos de provisioning e domains para instalar.
         */
        $tenant = $clientInput instanceof Tenant
            ? $clientInput->loadMissing('provisioning', 'domains')
            : Tenant::with(['provisioning', 'domains'])->find($clientInput);

        /**
         * Sem tenant central não há como descobrir domínio, banco ou plano.
         */
        if (!$tenant) {
            throw new RuntimeException('Tenante não encontrado para provisionamento.');
        }

        return $tenant;
    }

    private function getTenantProvisioningOrFail(Tenant $tenant): TenantProvisioning
    {
        $provisioning = $tenant->provisioning;

        /**
         * O provisioning guarda banco, usuário MySQL, senha e etapa atual.
         * Criar infraestrutura sem esse registro quebraria a rastreabilidade.
         */
        if (!$provisioning) {
            throw new RuntimeException("Provisioning não encontrado para o cliente {$tenant->id}.");
        }

        return $provisioning;
    }

    private function getTenantPrimaryDomainOrFail(Tenant $tenant): string
    {
        $domain = $tenant->domains[0]->domain ?? null;

        /**
         * A integração com cPanel e os POSTs para o tenant usam sempre
         * o primeiro domínio carregado no relacionamento.
         */
        if (!$domain) {
            throw new RuntimeException("Domínio principal não encontrado para o cliente {$tenant->id}.");
        }

        return $domain;
    }

    private function insertTokenAndUser(Tenant $tenant): void
    {
        $provisioning = $this->getTenantProvisioningOrFail($tenant);

        $tenantDatabase = [
            'name' => $provisioning->table,
            'user' => $provisioning->table_user,
            'password' => $provisioning->table_password,
        ];

        $firstUser = $provisioning->first_user;

        /**
         * A partir daqui todos os writes acontecem dentro do banco do tenant,
         * não no banco central do core_business.
         */
        $this->connectTenantDatabase($tenantDatabase);

        /**
         * O e-mail é a chave natural do usuário inicial.
         * Se a etapa for rodada de novo, reaproveitamos esse usuário.
         */
        $user = DB::connection('mysql_cliente')
            ->table('users')
            ->where('email', $firstUser['email'])
            ->first();

        /**
         * Só insere usuário quando ele ainda não existe.
         * Isso evita violar unique de email/name em reprocessos.
         */
        if (!$user) {
            DB::connection('mysql_cliente')->table('users')->insert([
                'name' => $firstUser['short_name'],
                'password' => Hash::make($firstUser['password']),
                'full_name' => $firstUser['name'],
                'email' => $firstUser['email'],
                'role_id' => 1,
                'created_by' => 1,
            ]);

            /**
             * Recarrega para obter o id real gerado pelo banco.
             * O collaborator precisa desse id, não de um valor fixo.
             */
            $user = DB::connection('mysql_cliente')
                ->table('users')
                ->where('email', $firstUser['email'])
                ->first();
        }

        /**
         * Se o usuário não apareceu depois do insert, a etapa ficou
         * inconsistente e deve parar antes de criar registros dependentes.
         */
        if (!$user) {
            throw new RuntimeException('Usuário inicial não foi localizado após inserção.');
        }

        /**
         * Collaborator é derivado do usuário. updateOrInsert evita duplicidade
         * e permite reexecutar a etapa depois de uma falha parcial.
         */
        DB::connection('mysql_cliente')
            ->table('collaborators')
            ->updateOrInsert(
                ['user_id' => $user->id],
                [
                    'user_id' => $user->id,
                    'created_by' => 1,
                ]
            );

        $storePayload = $this->buildTenantStorePayload($tenant);

        /**
         * A loja inicial é o bootstrap operacional do tenant.
         * O documento do cadastro central vira a chave estável no banco do tenant.
         */
        DB::connection('mysql_cliente')
            ->table('stores')
            ->updateOrInsert(
                ['cnpj' => $storePayload['cnpj']],
                $storePayload
            );

        $sizeStorage = $tenant->plan?->size_storage ?? 0;

        /**
         * Essas configs ligam o banco local à Central.
         * Atualizar por option_name evita duplicar configs em reprocessos.
         */
        $this->updateCentralConfig('tenant', $tenant->id);
        $this->updateCentralConfig('token', $tenant->token);
        $this->updateCentralConfig('s3StorageAllow', $sizeStorage);
    }

    private function buildTenantStorePayload(Tenant $tenant): array
    {
        $company = $tenant->company;
        $document = $tenant->cnpj ?: $tenant->cpf;

        if (empty($company)) {
            throw new RuntimeException("Razão social não informada para o cliente {$tenant->id}.");
        }

        if (empty($document)) {
            throw new RuntimeException("Documento não informado para o cliente {$tenant->id}.");
        }

        if (empty($tenant->email)) {
            throw new RuntimeException("Email da conta não informado para o cliente {$tenant->id}.");
        }

        if (empty($tenant->company_address)) {
            throw new RuntimeException("Endereço da conta não informado para o cliente {$tenant->id}.");
        }

        if (empty($tenant->company_number)) {
            throw new RuntimeException("Número do endereço não informado para o cliente {$tenant->id}.");
        }

        if (empty($tenant->company_neighborhood)) {
            throw new RuntimeException("Bairro da conta não informado para o cliente {$tenant->id}.");
        }

        if (empty($tenant->company_zip_code)) {
            throw new RuntimeException("CEP da conta não informado para o cliente {$tenant->id}.");
        }

        if (empty($tenant->company_state_id)) {
            throw new RuntimeException("Estado da conta não informado para o cliente {$tenant->id}.");
        }

        if (empty($tenant->company_city_id)) {
            throw new RuntimeException("Cidade da conta não informada para o cliente {$tenant->id}.");
        }

        if (!City::where('id', $tenant->company_city_id)->where('state_id', $tenant->company_state_id)->exists()) {
            throw new RuntimeException("Cidade inválida para o estado informado no cliente {$tenant->id}.");
        }

        $document = onlyNumbers($document);
        $phone = onlyNumbers($tenant->whatsapp);
        $zipCode = onlyNumbers($tenant->company_zip_code);

        if (empty($document)) {
            throw new RuntimeException("Documento inválido para o cliente {$tenant->id}.");
        }

        if (empty($phone)) {
            throw new RuntimeException("WhatsApp da conta não informado para o cliente {$tenant->id}.");
        }

        if (strlen($zipCode) !== 8) {
            throw new RuntimeException("CEP inválido para o cliente {$tenant->id}.");
        }

        $site = $tenant->domains()->where('status', true)->value('domain');

        if (empty($site)) {
            throw new RuntimeException("Domínio ativo não informado para o cliente {$tenant->id}.");
        }

        /**
         * Estado e cidade usam os mesmos IDs sincronizados do template MiCore.
         * Isso evita gravar null em tenants cujo schema exige localização.
         */
        return [
            'public'        => 1,
            'company'       => $company,
            'abbreviation'  => generateShortName($company),
            'cnpj'          => $document,
            'name_fantasy'  => $company,
            'address'       => $tenant->company_address,
            'number'        => $tenant->company_number,
            'complement'    => $tenant->company_complement,
            'neighborhood'  => $tenant->company_neighborhood,
            'zip'           => $zipCode,
            'email'         => $tenant->email,
            'site'          => $site,
            'phone1'        => $phone,
            'status'        => 1,
            'country_id'    => 26,
            'state_id'      => $tenant->company_state_id,
            'city_id'       => $tenant->company_city_id,
            'created_by'    => 1,
        ];
    }

    private function updateCentralConfig(string $optionName, mixed $optionValue): void
    {
        /**
         * central_configs não possui índice único no schema antigo,
         * então a idempotência precisa ser garantida pela busca lógica.
         */
        DB::connection('mysql_cliente')
            ->table('central_configs')
            ->updateOrInsert(
                ['option_name' => $optionName],
                [
                    'option_name' => $optionName,
                    'option_value' => $optionValue,
                ]
            );
    }

    private function configureModulesForTenant(Tenant $tenant): array
    {
        $trialDays = $tenant->plan?->trial_days ?? TenantInitialTrialPlanService::DEFAULT_TRIAL_DAYS;

        /**
         * Na finalização do provisionamento, aplica no tenant remoto
         * o pacote consolidado inicial de módulos, vigência e limites.
         * A vigência usa o período escolhido na Central ao criar a conta.
         */
        return $this->syncService->syncFromCurrentPlan(
            $tenant,
            source: 'provisioning',
            operatorId: null,
            reason: 'Provisionamento inicial do tenant',
            startDate: now()->toDateString(),
            endDate: now()->addDays($trialDays)->toDateString(),
        );
    }

    private function connectTenantDatabase(array $tenantDatabase): void
    {
        /**
         * A conexão mysql_cliente é montada dinamicamente com os dados
         * recém-provisionados para escrever no banco isolado do tenant.
         */
        config([
            'database.connections.mysql_cliente' => [
                'driver' => 'mysql',
                'host' => env('WHM_IP'),
                'database' => $tenantDatabase['name'],
                'username' => $tenantDatabase['user'],
                'password' => $tenantDatabase['password'],
                'charset' => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
                'prefix' => '',
                'strict' => false,
            ],
        ]);

        /**
         * Remove qualquer conexão anterior para evitar reaproveitar
         * credenciais de outro tenant no mesmo processo PHP.
         */
        DB::purge('mysql_cliente');
        DB::disconnect('mysql_cliente');
        DB::reconnect('mysql_cliente');
    }

    private function createSubdomain(string $domain): array
    {
        /**
         * Primeira barreira idempotente: se o domínio já existe,
         * não chamamos addsubdomain novamente.
         */
        if ($this->cpanelDomainExists($domain)) {
            return [
                'message' => "Subdomínio {$domain} já existe no cPanel.",
                'domain' => $domain,
                'idempotent' => true,
            ];
        }

        $documentRoot = '/home/' . $this->cpanelPrefix . '/public_html';

        /**
         * Subdomínios de tenants apontam para o mesmo public_html.
         * A aplicação escolhe o banco depois pelo domínio da request.
         */
        $response = $this->requestCpanelApi('GET', "{$this->cpanelUrl}/execute/SubDomain/addsubdomain", [
            'domain' => $domain,
            'rootdomain' => 'micore.com.br',
            'dir' => $documentRoot,
        ]);

        /**
         * Quando o cPanel retorna erro, ainda pode ser um caso idempotente:
         * timeout ou recurso já criado por uma tentativa anterior.
         */
        if (!$this->cpanelResponseSucceeded($response)) {
            /**
             * A API pode responder erro quando o recurso já existe.
             * Antes de falhar, conferimos o estado real no cPanel.
             */
            if ($this->cpanelDomainExists($domain)) {
                return [
                    'message' => "Subdomínio {$domain} já existia no cPanel.",
                    'domain' => $domain,
                    'idempotent' => true,
                ];
            }

            /**
             * Se o domínio não aparece após o erro, não temos estado seguro
             * para avançar para banco de dados.
             */
            throw new RuntimeException('Falha ao criar subdomínio no cPanel: ' . $this->extractCpanelError($response));
        }

        return $response;
    }

    /**
     * @throws Exception
     */
    private function cloneDatabase(Tenant $tenant): array
    {
        $provisioning = $this->getTenantProvisioningOrFail($tenant);

        $database = [
            'name' => $provisioning->table,
            'user' => $provisioning->table_user,
            'password' => $provisioning->table_password,
        ];

        $templateDatabase = $this->cpanelPrefix . '_template';

        /**
         * Antes de criar, olhamos o estado real no cPanel.
         * Isso permite repetir a etapa sem bater em "database exists".
         */
        $databaseExists = $this->cpanelDatabaseExists($database['name']);

        /**
         * Só chama create_database quando a conta ainda não lista o banco.
         */
        if (!$databaseExists) {
            $createDatabaseResponse = $this->requestCpanelApi('GET', "{$this->cpanelUrl}/execute/Mysql/create_database", [
                'name' => $database['name'],
            ]);

            /**
             * Se a API falhar, conferimos de novo. Pode ter criado o banco
             * antes de devolver erro para a aplicação.
             */
            if (!$this->cpanelResponseSucceeded($createDatabaseResponse) && !$this->cpanelDatabaseExists($database['name'])) {
                throw new RuntimeException('Falha ao criar banco no cPanel: ' . $this->extractCpanelError($createDatabaseResponse));
            }
        }

        /**
         * A importação do template é feita por SSH porque o fluxo atual
         * usa mysqldump direto no servidor cPanel.
         */
        $ssh = $this->connectSsh();

        /**
         * Só importamos o template em banco vazio.
         * Banco com tabelas é tratado como estado ambíguo e bloqueante.
         */
        if (!$this->databaseIsEmpty($ssh, $database['name'])) {
            /**
             * Banco com conteúdo pode ser uma instalação parcial ou um tenant real.
             * O provisionamento não remove nem sobrescreve dados automaticamente.
             */
            throw new RuntimeException("Banco {$database['name']} já possui conteúdo. Revise manualmente antes de continuar o provisionamento.");
        }

        /**
         * Neste ponto o banco existe e está vazio, então a clonagem
         * não sobrescreve dados de uma instalação anterior.
         */
        $this->cloneTemplateDatabase($ssh, $templateDatabase, $database['name']);

        /**
         * Usuário MySQL é criado apenas se ainda não existir na conta cPanel.
         */
        if (!$this->cpanelDatabaseUserExists($database['user'])) {
            $createUserResponse = $this->requestCpanelApi('GET', "{$this->cpanelUrl}/execute/Mysql/create_user", [
                'name' => $database['user'],
                'password' => $database['password'],
            ]);

            /**
             * Mesmo padrão idempotente: em caso de erro, confirmamos
             * se o usuário passou a existir antes de bloquear.
             */
            if (!$this->cpanelResponseSucceeded($createUserResponse) && !$this->cpanelDatabaseUserExists($database['user'])) {
                throw new RuntimeException('Falha ao criar usuário do banco no cPanel: ' . $this->extractCpanelError($createUserResponse));
            }
        }

        /**
         * Conceder privilégios é seguro para repetir e corrige casos
         * em que usuário já existia sem acesso ao banco novo.
         */
        $privilegesResponse = $this->requestCpanelApi('GET', "{$this->cpanelUrl}/execute/Mysql/set_privileges_on_database", [
            'user' => $database['user'],
            'database' => $database['name'],
            'privileges' => 'ALL PRIVILEGES',
        ]);

        /**
         * Sem privilégios, a próxima etapa não consegue conectar no tenant.
         */
        if (!$this->cpanelResponseSucceeded($privilegesResponse)) {
            throw new RuntimeException('Falha ao conceder privilégios no banco: ' . $this->extractCpanelError($privilegesResponse));
        }

        return [
            'message' => "Banco de dados {$database['name']} clonado com sucesso!",
            'usuario' => $database['user'],
            'password' => $database['password'],
        ];
    }

    private function requestCpanelApi(string $method, string $url, ?array $query = null): array
    {
        $tenant = new Guzzle();

        /**
         * O cPanel atual usa HTTP Basic com o usuário/senha da conta.
         */
        $options = [
            'auth' => [$this->cpanelUser, $this->cpanelPass],
        ];

        /**
         * Endpoints UAPI recebem parâmetros via query string.
         */
        if ($query !== null) {
            $options['query'] = $query;
        }

        /**
         * Deixamos exceções HTTP subirem. Falha de transporte não é estado
         * idempotente seguro para avançar o provisionamento.
         */
        $response = $tenant->request($method, $url, $options);

        return json_decode($response->getBody()->getContents(), true);
    }

    private function cpanelDomainExists(string $domain): bool
    {
        /**
         * DomainInfo/list_domains retorna grupos de domínios em arrays.
         * Por isso o loop aceita item array e item string.
         */
        $response = $this->requestCpanelApi('GET', "{$this->cpanelUrl}/execute/DomainInfo/list_domains");
        $items = $this->getCpanelDataItems($response);

        foreach ($items as $item) {
            /**
             * Formato observado: cada grupo pode vir como lista simples
             * de domínios, sem chave associativa.
             */
            if (is_array($item) && in_array($domain, $item, true)) {
                return true;
            }

            /**
             * Mantém suporte a respostas achatadas do cPanel.
             */
            if ($item === $domain) {
                return true;
            }
        }

        return false;
    }

    private function cpanelDatabaseExists(string $database): bool
    {
        /**
         * A lista de bancos é a fonte segura para decidir se podemos
         * chamar create_database ou tratar como reprocesso.
         */
        $response = $this->requestCpanelApi('GET', "{$this->cpanelUrl}/execute/Mysql/list_databases");
        $items = $this->getCpanelDataItems($response);

        foreach ($items as $item) {
            /**
             * Ignora formatos inesperados em vez de quebrar o loop inteiro.
             */
            if (!is_array($item)) {
                continue;
            }

            /**
             * Formato UAPI observado no ambiente atual.
             */
            if (($item['database'] ?? null) === $database) {
                return true;
            }

            /**
             * Formato alternativo aceito por segurança entre versões.
             */
            if (($item['name'] ?? null) === $database) {
                return true;
            }
        }

        return false;
    }

    private function cpanelDatabaseUserExists(string $user): bool
    {
        /**
         * Usuários MySQL também são globais dentro da conta cPanel.
         * Consultar antes evita erro quando a etapa é repetida.
         */
        $response = $this->requestCpanelApi('GET', "{$this->cpanelUrl}/execute/Mysql/list_users");
        $items = $this->getCpanelDataItems($response);

        foreach ($items as $item) {
            /**
             * A resposta esperada é associativa: user, shortuser, databases.
             */
            if (!is_array($item)) {
                continue;
            }

            /**
             * Compara o usuário completo, incluindo prefixo da conta cPanel.
             */
            if (($item['user'] ?? null) === $user) {
                return true;
            }
        }

        return false;
    }

    private function connectSsh(): SSH2
    {
        /**
         * A conexão SSH usa a mesma conta cPanel que executa mysqldump.
         */
        $ssh = new SSH2(env('WHM_IP'));
        $keyPath = storage_path('keys/id_rsa');
        $privateKey = PublicKeyLoader::loadPrivateKey(file_get_contents($keyPath), env('SSH_PASSPHRASE'));

        /**
         * Sem login SSH não existe caminho seguro para clonar o template.
         */
        if (!$ssh->login($this->cpanelUser, $privateKey)) {
            throw new Exception('Falha na autenticação SSH com chave privada');
        }

        return $ssh;
    }

    private function databaseIsEmpty(SSH2 $ssh, string $database): bool
    {
        /**
         * Escapa aspas para usar o nome do banco dentro do SQL remoto.
         */
        $databaseName = str_replace("'", "''", $database);
        $command = 'mysql -u ' . escapeshellarg($this->cpanelUser)
            . ' -p' . escapeshellarg($this->cpanelPass)
            . ' -N -B -e '
            . escapeshellarg("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = '{$databaseName}'");

        $output = $ssh->exec($command);

        /**
         * Se o comando falhar, não presumimos banco vazio.
         * Avançar nesse caso poderia sobrescrever uma instalação existente.
         */
        if ($ssh->getExitStatus() !== 0) {
            throw new RuntimeException("Falha ao verificar se o banco {$database} está vazio.");
        }

        /**
         * A saída esperada é apenas um número por causa de -N -B.
         */
        $count = preg_replace('/[^0-9]/', '', $output);

        /**
         * Saída vazia também é tratada como vazia para tolerar variações
         * do cliente MySQL quando não encontra tabelas.
         */
        return $count === '' || $count === '0';
    }

    private function cloneTemplateDatabase(SSH2 $ssh, string $templateDatabase, string $targetDatabase): void
    {
        /**
         * escapeshellarg protege os argumentos do comando remoto.
         */
        $mysqlUser = escapeshellarg($this->cpanelUser);
        $mysqlPassword = escapeshellarg($this->cpanelPass);
        $template = escapeshellarg($templateDatabase);
        $target = escapeshellarg($targetDatabase);

        /**
         * O dump sai do banco template e entra direto no banco alvo vazio.
         */
        $cloneCommand = "mysqldump -u {$mysqlUser} -p{$mysqlPassword} {$template} | mysql -u {$mysqlUser} -p{$mysqlPassword} {$target}";
        $ssh->exec($cloneCommand);

        /**
         * Qualquer erro no pipe deixa a etapa database sem garantia.
         * Por isso bloqueamos e permitimos nova tentativa depois da análise.
         */
        if ($ssh->getExitStatus() !== 0) {
            throw new RuntimeException("Falha ao clonar o banco template {$templateDatabase} para {$targetDatabase}.");
        }
    }

    private function cpanelResponseSucceeded(array $response): bool
    {
        /**
         * Formato UAPI observado: status no topo.
         */
        $status = $response['status'] ?? null;

        if ($status === 1 || $status === '1') {
            return true;
        }

        $resultStatus = null;

        /**
         * Formato alternativo: status dentro de result.
         */
        if (isset($response['result']) && is_array($response['result'])) {
            $resultStatus = $response['result']['status'] ?? null;
        }

        /**
         * Alguns retornos podem serializar status como string.
         */
        if ($resultStatus === 1 || $resultStatus === '1') {
            return true;
        }

        return false;
    }

    private function getCpanelDataItems(array $response): array
    {
        /**
         * Formato UAPI usado pelos endpoints testados no ambiente atual.
         */
        if (isset($response['data']) && is_array($response['data'])) {
            return $response['data'];
        }

        /**
         * Formato alternativo usado por alguns endpoints/versões.
         */
        if (
            isset($response['result'])
            && is_array($response['result'])
            && isset($response['result']['data'])
            && is_array($response['result']['data'])
        ) {
            return $response['result']['data'];
        }

        /**
         * Sem dados conhecidos, o chamador trata como "não encontrado".
         */
        return [];
    }

    private function extractCpanelError(array $response): string
    {
        /**
         * Erros diretos são o formato mais comum da UAPI.
         */
        $errors = $response['errors'] ?? null;

        if (is_array($errors) && !empty($errors)) {
            return implode(' | ', $errors);
        }

        $resultErrors = null;

        /**
         * Alguns endpoints aninham erros em result.
         */
        if (isset($response['result']) && is_array($response['result'])) {
            $resultErrors = $response['result']['errors'] ?? null;
        }

        if (is_array($resultErrors) && !empty($resultErrors)) {
            return implode(' | ', $resultErrors);
        }

        $messages = $response['messages'] ?? null;

        /**
         * Quando não há errors, messages ainda pode trazer o motivo.
         */
        if (is_array($messages) && !empty($messages)) {
            return implode(' | ', $messages);
        }

        /**
         * Fallback neutro para não expor payload inteiro com dados sensíveis.
         */
        return 'Sem detalhe retornado pelo cPanel.';
    }
}
