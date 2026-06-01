<?php

use App\Services\CpanelOrphanResourceService;

beforeEach(function () {
    putenv('CPANEL_PREFIX=micorecom');
    $_ENV['CPANEL_PREFIX'] = 'micorecom';
    $_SERVER['CPANEL_PREFIX'] = 'micorecom';
});

it('identifies orphan domains databases and database users', function () {
    $service = new class extends CpanelOrphanResourceService {
        protected function cpanelDomains(): array
        {
            return [
                ['name' => 'cliente-ok.micore.com.br', 'source' => 'fake', 'details' => []],
                ['name' => 'cliente-orfao.micore.com.br', 'source' => 'fake', 'details' => []],
            ];
        }

        protected function cpanelDatabases(): array
        {
            return [
                ['name' => 'micorecom_cliente_ok', 'source' => 'fake', 'details' => []],
                ['name' => 'micorecom_cliente_orfao', 'source' => 'fake', 'details' => []],
                ['name' => 'micorecom_template', 'source' => 'fake', 'details' => []],
                ['name' => 'outro_cliente_orfao', 'source' => 'fake', 'details' => []],
            ];
        }

        protected function cpanelDatabaseUsers(): array
        {
            return [
                ['name' => 'micorecom_cliente_ok', 'source' => 'fake', 'details' => []],
                ['name' => 'micorecom_cliente_orfao', 'source' => 'fake', 'details' => []],
            ];
        }

        protected function centralExpectedResources(): array
        {
            return [
                'domain' => ['cliente-ok.micore.com.br'],
                'database' => ['micorecom_cliente_ok'],
                'database_user' => ['micorecom_cliente_ok'],
            ];
        }
    };

    $scan = $service->scan();

    expect($scan['summary']['total'])->toBe(3);
    expect($scan['orphans']['domain'])->toHaveCount(1);
    expect($scan['orphans']['database'])->toHaveCount(1);
    expect($scan['orphans']['database_user'])->toHaveCount(1);
    expect($scan['orphans']['domain'][0]['name'])->toBe('cliente-orfao.micore.com.br');
    expect($scan['orphans']['database'][0]['name'])->toBe('micorecom_cliente_orfao');
    expect($scan['orphans']['database_user'][0]['name'])->toBe('micorecom_cliente_orfao');
});

it('blocks removal when the resource is no longer orphan', function () {
    $service = new class extends CpanelOrphanResourceService {
        protected function centralExpectedResources(): array
        {
            return [
                'domain' => ['cliente-vinculado.micore.com.br'],
                'database' => [],
                'database_user' => [],
            ];
        }

        protected function deleteDomain(string $name): array
        {
            return ['status' => 1];
        }
    };

    $result = $service->removeOne('domain', 'cliente-vinculado.micore.com.br');

    expect($result['status'])->toBe('ignored');
});

it('removes only after confirmation rules consider the resource still orphan', function () {
    $service = new class extends CpanelOrphanResourceService {
        protected function centralExpectedResources(): array
        {
            return [
                'domain' => [],
                'database' => ['micorecom_cliente_vinculado'],
                'database_user' => [],
            ];
        }

        protected function deleteDatabase(string $name): array
        {
            return ['status' => 1];
        }
    };

    $removed = $service->removeOne('database', 'micorecom_cliente_orfao');
    $ignored = $service->removeOne('database', 'micorecom_cliente_vinculado');

    expect($removed['status'])->toBe('removed');
    expect($ignored['status'])->toBe('ignored');
});

it('blocks batch removal with invalid confirmation', function () {
    $service = new class extends CpanelOrphanResourceService {
    };

    $result = $service->removeBatch('remover');

    expect($result['success'])->toBeFalse();
    expect($result['items'])->toBe([]);
});
