<?php

use App\Models\Tenant;
use App\Models\TenantProvisioning;
use App\Models\User;

beforeEach(function () {
    config(['services.central.token' => 'central-test-token']);
});

it('returns tenant database credentials by tenant id', function () {
    $operator = User::factory()->create();

    $tenant = Tenant::create([
        'name'       => 'Cliente App',
        'domain'     => 'cliente-app.test',
        'created_by' => $operator->id,
    ]);

    TenantProvisioning::create([
        'tenant_id'      => $tenant->id,
        'table'          => 'tenant_app',
        'table_user'     => 'tenant_user',
        'table_password' => 'tenant_password',
        'install'        => TenantProvisioning::STEP_COMPLETED,
    ]);

    $response = $this->withToken('central-test-token')
        ->getJson('/api/central/banco-por-tenant?tenant_id=' . $tenant->id);

    $response->assertOk()
        ->assertJson([
            'tenant'      => $tenant->id,
            'db_name'     => 'tenant_app',
            'db_user'     => 'tenant_user',
            'db_password' => 'tenant_password',
        ]);
});

it('returns not found when tenant does not exist', function () {
    $response = $this->withToken('central-test-token')
        ->getJson('/api/central/banco-por-tenant?tenant_id=999999');

    $response->assertNotFound()
        ->assertJson([
            'error' => 'Tenant não encontrado.',
        ]);
});

it('returns not found when tenant has no provisioning', function () {
    $operator = User::factory()->create();

    $tenant = Tenant::create([
        'name'       => 'Cliente Sem Provisionamento',
        'domain'     => 'cliente-sem-provisionamento.test',
        'created_by' => $operator->id,
    ]);

    $response = $this->withToken('central-test-token')
        ->getJson('/api/central/banco-por-tenant?tenant_id=' . $tenant->id);

    $response->assertNotFound()
        ->assertJson([
            'error' => 'Tenant sem provisioning vinculado.',
        ]);
});
