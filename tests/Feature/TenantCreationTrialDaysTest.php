<?php

use App\Models\Module;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;

it('allows choosing the initial trial days when creating a tenant in central', function () {
    $operator = User::factory()->create();

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

    Module::create([
        'name' => 'Atendimento',
        'slug' => 'atendimento',
        'description' => 'Atendimento',
        'value' => 100,
        'pricing_type' => 'Preço Fixo',
        'status' => true,
        'created_by' => $operator->id,
    ]);

    $this->actingAs($operator)
        ->post(route('tenants.store'), [
            'name' => 'Cliente Trial Customizado',
            'company' => 'Cliente Trial Customizado LTDA',
            'email' => 'conta.trial@micore.com.br',
            'domain' => 'cliente-trial-customizado',
            'document_type' => 'cnpj',
            'document_number' => '99000000000003',
            'whatsapp' => '41999999999',
            'company_zip_code' => '88000000',
            'company_state_id' => 16,
            'company_city_id' => 4179,
            'company_neighborhood' => 'Centro',
            'company_address' => 'Rua Trial',
            'company_number' => '100',
            'company_complement' => 'Sala 1',
            'trial_days' => 45,
            'user' => [
                'name' => 'Cliente Trial',
                'email' => 'cliente.trial@micore.com.br',
                'password' => 'SenhaTrial123',
            ],
        ])
        ->assertRedirect();

    $tenant = Tenant::where('name', 'Cliente Trial Customizado')->firstOrFail();

    expect($tenant->company)->toBe('Cliente Trial Customizado LTDA');
    expect($tenant->email)->toBe('conta.trial@micore.com.br');
    expect($tenant->cnpj)->toBe('99000000000003');
    expect($tenant->company_zip_code)->toBe('88000000');
    expect($tenant->company_state_id)->toBe(16);
    expect($tenant->company_city_id)->toBe(4179);

    $this->assertDatabaseHas('tenants_plans', [
        'tenant_id' => $tenant->id,
        'name' => 'Teste 45 dias',
        'trial_days' => 45,
        'users_limit' => 0,
        'size_storage' => 5368709120,
        'progress' => 'completed',
        'status' => true,
    ]);

    $this->assertDatabaseHas('tenants_plans_items', [
        'item_id' => 1,
        'pricing_source' => 'initial_trial',
        'applied_price' => 0,
    ]);
});

it('shows thirty days as the default trial period in the tenant creation form', function () {
    $operator = User::factory()->create();

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

    $this->actingAs($operator)
        ->get(route('tenants.create'))
        ->assertOk()
        ->assertSee('name="trial_days"', false)
        ->assertSee('value="30"', false)
        ->assertSee('name="company"', false)
        ->assertSee('name="email"', false)
        ->assertSee('name="document_type"', false)
        ->assertSee('name="document_number"', false)
        ->assertSee('name="company_state_id"', false)
        ->assertSee('name="company_city_id"', false);
});
