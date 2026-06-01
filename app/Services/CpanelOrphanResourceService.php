<?php

namespace App\Services;

use Exception;
use GuzzleHttp\Client as Guzzle;
use Illuminate\Support\Facades\DB;
use Throwable;

class CpanelOrphanResourceService
{
    private const TYPE_DOMAIN = 'domain';
    private const TYPE_DATABASE = 'database';
    private const TYPE_DATABASE_USER = 'database_user';
    private const BATCH_CONFIRMATION = 'REMOVER ORFAOS';

    public function scan(): array
    {
        $errors = [];

        $serverResources = [
            self::TYPE_DOMAIN => $this->safeCollect(fn () => $this->cpanelDomains(), $errors, 'Domínios'),
            self::TYPE_DATABASE => $this->safeCollect(fn () => $this->cpanelDatabases(), $errors, 'Bancos'),
            self::TYPE_DATABASE_USER => $this->safeCollect(fn () => $this->cpanelDatabaseUsers(), $errors, 'Usuários MySQL'),
        ];

        $expectedResources = $this->centralExpectedResources();

        $orphans = [
            self::TYPE_DOMAIN => $this->domainOrphans($serverResources[self::TYPE_DOMAIN], $expectedResources[self::TYPE_DOMAIN]),
            self::TYPE_DATABASE => $this->databaseOrphans($serverResources[self::TYPE_DATABASE], $expectedResources[self::TYPE_DATABASE]),
            self::TYPE_DATABASE_USER => $this->databaseUserOrphans($serverResources[self::TYPE_DATABASE_USER], $expectedResources[self::TYPE_DATABASE_USER]),
        ];

        return [
            'errors' => $errors,
            'orphans' => $orphans,
            'summary' => [
                'domains' => count($orphans[self::TYPE_DOMAIN]),
                'databases' => count($orphans[self::TYPE_DATABASE]),
                'database_users' => count($orphans[self::TYPE_DATABASE_USER]),
                'total' => count($orphans[self::TYPE_DOMAIN]) + count($orphans[self::TYPE_DATABASE]) + count($orphans[self::TYPE_DATABASE_USER]),
            ],
            'labels' => $this->resourceLabels(),
            'batch_confirmation' => self::BATCH_CONFIRMATION,
        ];
    }

    public function removeOne(string $type, string $name): array
    {
        if (!isset($this->resourceLabels()[$type])) {
            return $this->removalResult($type, $name, 'ignored', 'Tipo de recurso inválido.');
        }

        if (!$this->isSafeToRemove($type, $name)) {
            return $this->removalResult($type, $name, 'ignored', 'Recurso fora do padrão seguro de remoção.');
        }

        if (!$this->isStillOrphan($type, $name)) {
            return $this->removalResult($type, $name, 'ignored', 'Recurso passou a existir na Central e não foi removido.');
        }

        try {
            $response = match ($type) {
                self::TYPE_DOMAIN => $this->deleteDomain($name),
                self::TYPE_DATABASE => $this->deleteDatabase($name),
                self::TYPE_DATABASE_USER => $this->deleteDatabaseUser($name),
                default => null,
            };

            if (!$this->cpanelResponseSucceeded($response ?? [])) {
                return $this->removalResult($type, $name, 'failed', $this->extractCpanelError($response ?? []));
            }

            return $this->removalResult($type, $name, 'removed', 'Recurso removido no cPanel.');
        } catch (Throwable $throwable) {
            return $this->removalResult($type, $name, 'failed', $throwable->getMessage());
        }
    }

    public function removeBatch(string $confirmation): array
    {
        if ($confirmation !== self::BATCH_CONFIRMATION) {
            return [
                'success' => false,
                'message' => 'Confirmação inválida. Nenhum recurso foi removido.',
                'items' => [],
            ];
        }

        $scan = $this->scan();
        $items = [];

        foreach ($scan['orphans'] as $type => $resources) {
            foreach ($resources as $resource) {
                $items[] = $this->removeOne($type, $resource['name']);
            }
        }

        return [
            'success' => true,
            'message' => 'Remoção em lote processada.',
            'items' => $items,
        ];
    }

    protected function cpanelDomains(): array
    {
        $response = $this->requestCpanelApi('GET', '/execute/DomainInfo/domains_data');
        $items = $this->getCpanelDataItems($response);
        $domains = $this->flattenDomainItems($items);

        if (!empty($domains)) {
            return $domains;
        }

        $fallbackResponse = $this->requestCpanelApi('GET', '/execute/DomainInfo/list_domains');
        $fallbackItems = $this->getCpanelDataItems($fallbackResponse);

        return $this->flattenListDomainsItems($fallbackItems);
    }

    protected function cpanelDatabases(): array
    {
        $response = $this->requestCpanelApi('GET', '/execute/Mysql/list_databases');
        $items = $this->getCpanelDataItems($response);
        $databases = [];

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $name = $item['database'] ?? ($item['name'] ?? null);

            if (!$name) {
                continue;
            }

            $databases[] = [
                'name' => $name,
                'source' => 'Mysql/list_databases',
                'details' => $item,
            ];
        }

        return $databases;
    }

    protected function cpanelDatabaseUsers(): array
    {
        $response = $this->requestCpanelApi('GET', '/execute/Mysql/list_users');
        $items = $this->getCpanelDataItems($response);
        $users = [];

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $name = $item['user'] ?? ($item['name'] ?? null);

            if (!$name) {
                continue;
            }

            $users[] = [
                'name' => $name,
                'source' => 'Mysql/list_users',
                'details' => $item,
            ];
        }

        return $users;
    }

    protected function centralExpectedResources(): array
    {
        return [
            self::TYPE_DOMAIN => DB::table('tenants_domains')
                ->whereNotNull('domain')
                ->pluck('domain')
                ->filter()
                ->values()
                ->all(),
            self::TYPE_DATABASE => DB::table('tenants_provisionings')
                ->whereNotNull('table')
                ->pluck('table')
                ->filter()
                ->values()
                ->all(),
            self::TYPE_DATABASE_USER => DB::table('tenants_provisionings')
                ->whereNotNull('table_user')
                ->pluck('table_user')
                ->filter()
                ->values()
                ->all(),
        ];
    }

    protected function requestCpanelApi(string $method, string $endpoint, ?array $query = null): array
    {
        $cpanelUrl = env('CPANEL_URL') ?? '';
        $cpanelUser = env('CPANEL_USER') ?? '';
        $cpanelPass = env('CPANEL_PASS') ?? '';

        if ($cpanelUrl === '' || $cpanelUser === '' || $cpanelPass === '') {
            throw new Exception('CPANEL_URL, CPANEL_USER ou CPANEL_PASS não estão preenchidos.');
        }

        $guzzle = new Guzzle([
            'timeout' => 30,
            'connect_timeout' => 10,
        ]);

        $options = [
            'auth' => [$cpanelUser, $cpanelPass],
        ];

        if ($query !== null) {
            $options['query'] = $query;
        }

        $response = $guzzle->request($method, $cpanelUrl . $endpoint, $options);
        $payload = json_decode($response->getBody()->getContents(), true);

        if (!is_array($payload)) {
            throw new Exception('Resposta do cPanel não retornou JSON válido.');
        }

        return $payload;
    }

    protected function deleteDomain(string $name): array
    {
        return $this->requestCpanelApi('GET', '/execute/SubDomain/delsubdomain', [
            'domain' => $name,
        ]);
    }

    protected function deleteDatabase(string $name): array
    {
        return $this->requestCpanelApi('GET', '/execute/Mysql/delete_database', [
            'name' => $name,
        ]);
    }

    protected function deleteDatabaseUser(string $name): array
    {
        return $this->requestCpanelApi('GET', '/execute/Mysql/delete_user', [
            'name' => $name,
        ]);
    }

    private function safeCollect(callable $collector, array &$errors, string $label): array
    {
        try {
            return $collector();
        } catch (Throwable $throwable) {
            $errors[] = [
                'label' => $label,
                'message' => $throwable->getMessage(),
            ];

            return [];
        }
    }

    private function domainOrphans(array $serverDomains, array $expectedDomains): array
    {
        $orphans = [];

        foreach ($serverDomains as $domain) {
            $name = $domain['name'] ?? null;

            if (!$name || in_array($name, $expectedDomains, true)) {
                continue;
            }

            $orphans[] = $this->orphanResource(
                $name,
                $domain['source'] ?? 'cPanel',
                'Existe no cPanel, mas não está cadastrado em tenants_domains.',
                $this->isSafeToRemove(self::TYPE_DOMAIN, $name)
                    ? 'Compatível com remoção controlada.'
                    : 'Fora do padrão seguro; remoção bloqueada.'
            );
        }

        return $orphans;
    }

    private function databaseOrphans(array $serverDatabases, array $expectedDatabases): array
    {
        $orphans = [];
        $templateDatabase = $this->cpanelPrefix() . '_template';

        foreach ($serverDatabases as $database) {
            $name = $database['name'] ?? null;

            if (!$name || $name === $templateDatabase) {
                continue;
            }

            if (!$this->startsWithCpanelPrefix($name) || in_array($name, $expectedDatabases, true)) {
                continue;
            }

            $orphans[] = $this->orphanResource(
                $name,
                $database['source'] ?? 'cPanel',
                'Banco com prefixo da conta não existe em tenants_provisionings.table.',
                'Removível após revalidação.'
            );
        }

        return $orphans;
    }

    private function databaseUserOrphans(array $serverUsers, array $expectedUsers): array
    {
        $orphans = [];

        foreach ($serverUsers as $user) {
            $name = $user['name'] ?? null;

            if (!$name || !$this->startsWithCpanelPrefix($name) || in_array($name, $expectedUsers, true)) {
                continue;
            }

            $orphans[] = $this->orphanResource(
                $name,
                $user['source'] ?? 'cPanel',
                'Usuário MySQL com prefixo da conta não existe em tenants_provisionings.table_user.',
                'Removível após revalidação.'
            );
        }

        return $orphans;
    }

    private function orphanResource(string $name, string $source, string $reason, string $risk): array
    {
        return [
            'name' => $name,
            'source' => $source,
            'reason' => $reason,
            'risk' => $risk,
        ];
    }

    private function isStillOrphan(string $type, string $name): bool
    {
        $expectedResources = $this->centralExpectedResources();

        return !in_array($name, $expectedResources[$type] ?? [], true);
    }

    private function isSafeToRemove(string $type, string $name): bool
    {
        if ($type === self::TYPE_DOMAIN) {
            return $name !== 'micore.com.br' && str_ends_with($name, '.micore.com.br');
        }

        if ($type === self::TYPE_DATABASE) {
            return $this->startsWithCpanelPrefix($name) && $name !== $this->cpanelPrefix() . '_template';
        }

        if ($type === self::TYPE_DATABASE_USER) {
            return $this->startsWithCpanelPrefix($name);
        }

        return false;
    }

    private function startsWithCpanelPrefix(string $name): bool
    {
        $prefix = $this->cpanelPrefix();

        if ($prefix === '') {
            return false;
        }

        return str_starts_with($name, $prefix . '_');
    }

    private function cpanelPrefix(): string
    {
        return env('CPANEL_PREFIX') ?? '';
    }

    private function resourceLabels(): array
    {
        return [
            self::TYPE_DOMAIN => 'Domínios',
            self::TYPE_DATABASE => 'Bancos',
            self::TYPE_DATABASE_USER => 'Usuários MySQL',
        ];
    }

    private function removalResult(string $type, string $name, string $status, string $message): array
    {
        return [
            'type' => $type,
            'label' => $this->resourceLabels()[$type] ?? $type,
            'name' => $name,
            'status' => $status,
            'message' => $message,
        ];
    }

    private function getCpanelDataItems(array $response): array
    {
        if (isset($response['data']) && is_array($response['data'])) {
            return $response['data'];
        }

        if (
            isset($response['result'])
            && is_array($response['result'])
            && isset($response['result']['data'])
            && is_array($response['result']['data'])
        ) {
            return $response['result']['data'];
        }

        return [];
    }

    private function flattenDomainItems(array $items): array
    {
        $domains = [];

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $domain = $item['domain'] ?? null;

            if (!$domain) {
                continue;
            }

            $domains[] = [
                'name' => $domain,
                'source' => 'DomainInfo/domains_data',
                'details' => $item,
            ];
        }

        return $domains;
    }

    private function flattenListDomainsItems(array $items): array
    {
        $domains = [];

        foreach ($items as $item) {
            if (is_array($item)) {
                foreach ($item as $domain) {
                    if (is_string($domain)) {
                        $domains[] = [
                            'name' => $domain,
                            'source' => 'DomainInfo/list_domains',
                            'details' => [],
                        ];
                    }
                }

                continue;
            }

            if (is_string($item)) {
                $domains[] = [
                    'name' => $item,
                    'source' => 'DomainInfo/list_domains',
                    'details' => [],
                ];
            }
        }

        return $domains;
    }

    private function cpanelResponseSucceeded(array $response): bool
    {
        $status = $response['status'] ?? null;

        if ($status === 1 || $status === '1') {
            return true;
        }

        if (isset($response['result']) && is_array($response['result'])) {
            $resultStatus = $response['result']['status'] ?? null;

            if ($resultStatus === 1 || $resultStatus === '1') {
                return true;
            }
        }

        return false;
    }

    private function extractCpanelError(array $response): string
    {
        $errors = $response['errors'] ?? null;

        if (is_array($errors) && !empty($errors)) {
            return implode(' | ', $errors);
        }

        if (isset($response['result']) && is_array($response['result'])) {
            $resultErrors = $response['result']['errors'] ?? null;

            if (is_array($resultErrors) && !empty($resultErrors)) {
                return implode(' | ', $resultErrors);
            }
        }

        return 'cPanel não confirmou sucesso.';
    }
}
