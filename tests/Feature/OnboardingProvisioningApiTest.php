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
        'company_city_state' => 'Florianopolis/SC',
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
        'pricing_source' => 'trial_30_days',
        'applied_price' => 0,
    ]);

    $this->assertDatabaseHas('tenants_plans_items', [
        'plan_id' => $planId,
        'item_id' => 2,
        'package_id' => null,
        'pricing_source' => 'trial_30_days',
        'applied_price' => 0,
    ]);

    expect(DB::table('subscriptions')->where('tenant_id', $tenant->id)->count())->toBe(0);
    expect(DB::table('orders')->where('tenant_id', $tenant->id)->count())->toBe(0);
});
