<?php

use App\Models\Tenant;
use App\Models\TenantAppRoute;
use App\Models\TenantDomain;
use App\Models\TenantProvisioning;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;

beforeEach(function () {
    config(['services.central.token' => 'central-test-token']);
});

it('returns local database route by tenant id', function () {
    $operator = User::factory()->create();

    $tenant = Tenant::create([
        'name'       => 'Cliente App Local',
        'domain'     => 'cliente-app-local.test',
        'created_by' => $operator->id,
    ]);

    TenantAppRoute::create([
        'tenant_id' => $tenant->id,
        'mode'      => TenantAppRoute::MODE_LOCAL_DATABASE,
        'status'    => true,
    ]);

    TenantProvisioning::create([
        'tenant_id'      => $tenant->id,
        'table'          => 'tenant_app',
        'table_user'     => 'tenant_user',
        'table_password' => 'tenant_password',
        'install'        => TenantProvisioning::STEP_COMPLETED,
    ]);

    $response = $this->withToken('central-test-token')
        ->getJson('/api/central/app/tenant-resolution?tenant_id=' . $tenant->id);

    $response->assertOk()
        ->assertJson([
            'tenant_id' => $tenant->id,
            'mode'      => TenantAppRoute::MODE_LOCAL_DATABASE,
            'database'  => [
                'name'     => 'tenant_app',
                'user'     => 'tenant_user',
                'password' => 'tenant_password',
            ],
        ]);
});

it('returns remote api route by tenant id', function () {
    $operator = User::factory()->create();

    $tenant = Tenant::create([
        'name'       => 'Cliente App Remoto',
        'domain'     => 'cliente-app-remoto.test',
        'created_by' => $operator->id,
    ]);

    TenantAppRoute::create([
        'tenant_id'            => $tenant->id,
        'mode'                 => TenantAppRoute::MODE_REMOTE_API,
        'remote_base_url'      => 'https://cliente.com.br/api/app',
        'remote_service_token' => Crypt::encryptString('service-token'),
        'status'               => true,
    ]);

    $response = $this->withToken('central-test-token')
        ->getJson('/api/central/app/tenant-resolution?tenant_id=' . $tenant->id);

    $response->assertOk()
        ->assertJson([
            'tenant_id' => $tenant->id,
            'mode'      => TenantAppRoute::MODE_REMOTE_API,
            'api'       => [
                'base_url'      => 'https://cliente.com.br/api/app',
                'service_token' => 'service-token',
            ],
        ]);
});

it('builds remote api url from active tenant domain', function () {
    $operator = User::factory()->create();

    $tenant = Tenant::create([
        'name'       => 'Cliente App Remoto Dominio',
        'domain'     => 'cliente-app-remoto-dominio.test',
        'created_by' => $operator->id,
    ]);

    TenantDomain::create([
        'tenant_id' => $tenant->id,
        'domain'    => 'sulink.com.br',
        'status'    => true,
    ]);

    TenantAppRoute::create([
        'tenant_id'            => $tenant->id,
        'mode'                 => TenantAppRoute::MODE_REMOTE_API,
        'remote_service_token' => Crypt::encryptString('service-token'),
        'status'               => true,
    ]);

    $response = $this->withToken('central-test-token')
        ->getJson('/api/central/app/tenant-resolution?tenant_id=' . $tenant->id);

    $response->assertOk()
        ->assertJson([
            'tenant_id' => $tenant->id,
            'mode'      => TenantAppRoute::MODE_REMOTE_API,
            'api'       => [
                'base_url'      => 'https://sulink.com.br/api/app',
                'service_token' => 'service-token',
            ],
        ]);
});

it('blocks disabled app route', function () {
    $operator = User::factory()->create();

    $tenant = Tenant::create([
        'name'       => 'Cliente App Bloqueado',
        'domain'     => 'cliente-app-bloqueado.test',
        'created_by' => $operator->id,
    ]);

    TenantAppRoute::create([
        'tenant_id' => $tenant->id,
        'mode'      => TenantAppRoute::MODE_DISABLED,
        'status'    => true,
    ]);

    $response = $this->withToken('central-test-token')
        ->getJson('/api/central/app/tenant-resolution?tenant_id=' . $tenant->id);

    $response->assertForbidden()
        ->assertJson([
            'error' => 'Tenant bloqueado para aplicativos.',
        ]);
});

it('returns not found when tenant does not exist', function () {
    $response = $this->withToken('central-test-token')
        ->getJson('/api/central/app/tenant-resolution?tenant_id=999999');

    $response->assertNotFound()
        ->assertJson([
            'error' => 'Tenant não encontrado.',
        ]);
});
