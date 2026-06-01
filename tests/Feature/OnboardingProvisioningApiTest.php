<?php

use App\Models\Tenant;
use App\Models\TenantProvisioning;
use App\Services\CpanelProvisioningService;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    config(['services.central.token' => 'central-test-token']);
    config(['app.debug' => false]);
    config(['app.env' => 'testing']);
    config(['CPANEL_PREFIX' => 'micorecom']);

    DB::table('states')->insert([
        'id' => 16,
        'country_id' => 26,
        'name' => 'Paraná',
        'acronym' => 'PR',
        'code' => 41,
        'cuf' => 41,
        'status' => 1,
        'created_by' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('cities')->insert([
        'id' => 4179,
        'state_id' => 16,
        'name' => 'Curitiba',
        'code_ibge' => 4106902,
        'status' => 1,
        'created_by' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
});

it('finalizes onboarding through api using tenant domains and provisioning steps', function () {
    DB::table('modules')->insert([
        'id' => 1,
        'name' => 'Atendimento',
        'slug' => 'atendimento',
        'description' => 'Atendimento',
        'value' => 100,
        'pricing_type' => 'Preço Fixo',
        'status' => true,
        'created_by' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('modules')->insert([
        'id' => 2,
        'name' => 'Vendas',
        'slug' => 'vendas',
        'description' => 'Vendas',
        'value' => 200,
        'pricing_type' => 'Preço Fixo',
        'status' => true,
        'created_by' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('modules')->insert([
        'id' => 3,
        'name' => 'Arquivado',
        'slug' => 'arquivado',
        'description' => 'Arquivado',
        'value' => 300,
        'pricing_type' => 'Preço Fixo',
        'status' => false,
        'created_by' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    app()->instance(CpanelProvisioningService::class, new class extends CpanelProvisioningService {
        public function __construct()
        {
        }

        public function runProvisioning(Tenant|int $clientInput): array
        {
            $tenant = $clientInput instanceof Tenant
                ? $clientInput
                : Tenant::findOrFail($clientInput);

            $provisioning = $tenant->provisioning()->firstOrFail();

            $nextStep = match ($provisioning->install) {
                TenantProvisioning::STEP_SUBDOMAIN => TenantProvisioning::STEP_DATABASE,
                TenantProvisioning::STEP_DATABASE => TenantProvisioning::STEP_USER_TOKEN,
                TenantProvisioning::STEP_USER_TOKEN => TenantProvisioning::STEP_MODULES,
                TenantProvisioning::STEP_MODULES => TenantProvisioning::STEP_FINALIZING,
                default => TenantProvisioning::STEP_COMPLETED,
            };

            $provisioning->install = $nextStep;
            $provisioning->save();

            return [
                'message' => 'Etapa fake executada.',
                'step' => $nextStep,
            ];
        }
    });

    $payload = [
        'step' => 'address',
        'email' => 'teste.api.final@micore.com.br',
        'document_type' => 'cnpj',
        'cnpj' => '99000000000001',
        'name' => 'Teste API Final',
        'company' => 'Teste API Final',
        'whatsapp' => '48999999999',
        'password' => 'TesteApi123',
        'company_profile' => 'simples_nacional',
        'main_goals' => ['centralizar_atendimentos'],
        'company_zip_code' => '88000000',
        'company_state_id' => 16,
        'company_city_id' => 4179,
        'company_address' => 'Rua Teste',
        'company_neighborhood' => 'Centro',
        'company_number' => '100',
        'tips_whatsapp' => false,
        'tips_email' => false,
        'has_coupon' => false,
    ];

    $this->withToken('central-test-token')
        ->postJson('/api/central/onboarding/salvar-etapa', $payload)
        ->assertOk();

    $response = $this->withToken('central-test-token')
        ->postJson('/api/central/onboarding/finalizar', $payload);

    $response->assertOk()
        ->assertJsonPath('provisioning.step', TenantProvisioning::STEP_COMPLETED);

    $tenant = Tenant::where('cnpj', '99000000000001')->firstOrFail();

    expect($tenant->onboarding_completed_at)->not->toBeNull();
    expect($tenant->company_state_id)->toBe(16);
    expect($tenant->company_city_id)->toBe(4179);

    $this->assertDatabaseHas('tenants_domains', [
        'tenant_id' => $tenant->id,
        'domain' => 'teste-api-final.micore.com.br',
        'auto_generate' => true,
    ]);

    $this->assertDatabaseHas('tenants_provisionings', [
        'tenant_id' => $tenant->id,
        'table' => 'micorecom_teste_api_final',
        'table_user' => 'micorecom_teste_api_final',
        'install' => TenantProvisioning::STEP_COMPLETED,
    ]);

    $this->assertDatabaseHas('tenants_plans', [
        'tenant_id' => $tenant->id,
        'name' => 'Teste 30 dias',
        'value' => 0,
        'users_limit' => 0,
        'size_storage' => 5368709120,
        'trial_days' => 30,
        'progress' => 'completed',
        'status' => true,
    ]);

    $planId = DB::table('tenants_plans')
        ->where('tenant_id', $tenant->id)
        ->value('id');

    expect(DB::table('tenants_plans_items')->where('plan_id', $planId)->count())->toBe(2);

    $this->assertDatabaseHas('tenants_plans_items', [
        'plan_id' => $planId,
        'item_id' => 1,
        'package_id' => null,
        'pricing_source' => 'initial_trial',
        'applied_price' => 0,
    ]);

    $this->assertDatabaseHas('tenants_plans_items', [
        'plan_id' => $planId,
        'item_id' => 2,
        'package_id' => null,
        'pricing_source' => 'initial_trial',
        'applied_price' => 0,
    ]);

    expect(DB::table('subscriptions')->where('tenant_id', $tenant->id)->count())->toBe(0);
    expect(DB::table('orders')->where('tenant_id', $tenant->id)->count())->toBe(0);
});

it('retries finalization without recalculating provisioned domain and database', function () {
    DB::table('modules')->insert([
        'id' => 1,
        'name' => 'Atendimento',
        'slug' => 'atendimento',
        'description' => 'Atendimento',
        'value' => 100,
        'pricing_type' => 'Preço Fixo',
        'status' => true,
        'created_by' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $provisioningService = new class extends CpanelProvisioningService {
        private bool $failedUserToken = false;

        public function __construct()
        {
        }

        public function runProvisioning(Tenant|int $clientInput): array
        {
            $tenant = $clientInput instanceof Tenant
                ? $clientInput
                : Tenant::findOrFail($clientInput);

            $provisioning = $tenant->provisioning()->firstOrFail();

            if ($provisioning->install === TenantProvisioning::STEP_USER_TOKEN && !$this->failedUserToken) {
                $this->failedUserToken = true;

                throw new RuntimeException('Falha fake na etapa user_token.');
            }

            $nextStep = match ($provisioning->install) {
                TenantProvisioning::STEP_SUBDOMAIN => TenantProvisioning::STEP_DATABASE,
                TenantProvisioning::STEP_DATABASE => TenantProvisioning::STEP_USER_TOKEN,
                TenantProvisioning::STEP_USER_TOKEN => TenantProvisioning::STEP_MODULES,
                TenantProvisioning::STEP_MODULES => TenantProvisioning::STEP_FINALIZING,
                default => TenantProvisioning::STEP_COMPLETED,
            };

            $provisioning->install = $nextStep;
            $provisioning->save();

            return [
                'message' => 'Etapa fake executada.',
                'step' => $nextStep,
            ];
        }
    };

    app()->instance(CpanelProvisioningService::class, $provisioningService);

    $payload = [
        'step' => 'address',
        'email' => 'teste.api.retry@micore.com.br',
        'document_type' => 'cnpj',
        'cnpj' => '99000000000002',
        'name' => 'Teste API Retry',
        'company' => 'Teste API Retry',
        'whatsapp' => '48999999999',
        'password' => 'TesteApi123',
        'company_profile' => 'simples_nacional',
        'main_goals' => ['centralizar_atendimentos'],
        'company_zip_code' => '88000000',
        'company_state_id' => 16,
        'company_city_id' => 4179,
        'company_address' => 'Rua Teste',
        'company_neighborhood' => 'Centro',
        'company_number' => '100',
        'tips_whatsapp' => false,
        'tips_email' => false,
        'has_coupon' => false,
    ];

    $this->withToken('central-test-token')
        ->postJson('/api/central/onboarding/salvar-etapa', $payload)
        ->assertOk();

    $this->withToken('central-test-token')
        ->postJson('/api/central/onboarding/finalizar', $payload)
        ->assertStatus(422)
        ->assertJsonPath('provisioning.step', TenantProvisioning::STEP_USER_TOKEN);

    $tenant = Tenant::where('cnpj', '99000000000002')->firstOrFail();

    $this->assertDatabaseHas('tenants_domains', [
        'tenant_id' => $tenant->id,
        'domain' => 'teste-api-retry.micore.com.br',
        'auto_generate' => true,
    ]);

    $this->assertDatabaseHas('tenants_provisionings', [
        'tenant_id' => $tenant->id,
        'table' => 'micorecom_teste_api_retry',
        'table_user' => 'micorecom_teste_api_retry',
        'install' => TenantProvisioning::STEP_USER_TOKEN,
    ]);

    $retryPayload = $payload;
    $retryPayload['company'] = 'Teste API Retry Alterado';

    $this->withToken('central-test-token')
        ->postJson('/api/central/onboarding/finalizar', $retryPayload)
        ->assertOk()
        ->assertJsonPath('provisioning.step', TenantProvisioning::STEP_COMPLETED);

    $tenant->refresh();

    expect($tenant->onboarding_completed_at)->not->toBeNull();

    $this->assertDatabaseHas('tenants_domains', [
        'tenant_id' => $tenant->id,
        'domain' => 'teste-api-retry.micore.com.br',
        'auto_generate' => true,
    ]);

    $this->assertDatabaseMissing('tenants_domains', [
        'tenant_id' => $tenant->id,
        'domain' => 'teste-api-retry-alterado.micore.com.br',
    ]);

    $this->assertDatabaseHas('tenants_provisionings', [
        'tenant_id' => $tenant->id,
        'table' => 'micorecom_teste_api_retry',
        'table_user' => 'micorecom_teste_api_retry',
        'install' => TenantProvisioning::STEP_COMPLETED,
    ]);

    $this->assertDatabaseMissing('tenants_provisionings', [
        'tenant_id' => $tenant->id,
        'table' => 'micorecom_teste_api_retry_alterado',
    ]);
});

it('rejects onboarding address payload without state and city ids', function () {
    $payload = [
        'step' => 'address',
        'email' => 'teste.api.sem.localidade@micore.com.br',
        'document_type' => 'cnpj',
        'cnpj' => '99000000000004',
        'name' => 'Teste API Sem Localidade',
        'company' => 'Teste API Sem Localidade',
        'whatsapp' => '48999999999',
        'password' => 'TesteApi123',
        'company_profile' => 'simples_nacional',
        'main_goals' => ['centralizar_atendimentos'],
        'company_zip_code' => '88000000',
        'company_address' => 'Rua Teste',
        'company_neighborhood' => 'Centro',
        'company_number' => '100',
        'tips_whatsapp' => false,
        'tips_email' => false,
        'has_coupon' => false,
    ];

    $this->withToken('central-test-token')
        ->postJson('/api/central/onboarding/finalizar', $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['company_state_id', 'company_city_id']);
});
