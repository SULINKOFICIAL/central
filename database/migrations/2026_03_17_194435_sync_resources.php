<?php

use App\Models\Module;
use App\Models\ModuleCategory;
use App\Models\Resource;
use App\Services\GuzzleService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $guzzleService = new GuzzleService();
        $categories = null;

        // Usa um cliente que realmente tenha domínio ativo para evitar null durante migrate.
        $client = DB::table('clients')
            ->join('clients_domains', 'clients_domains.client_id', '=', 'clients.id')
            ->select(['clients.id', 'clients_domains.domain'])
            ->first();

        if ($client) {
            $guzzleTenant = (object) [
                'domains' => collect([(object) ['domain' => $client->domain]]),
            ];

            // Realiza solicitação
            $response = $guzzleService->request('post', 'sistema/permissoes-recursos', $guzzleTenant, []);
            $decoded = json_decode($response['data'] ?? '[]', true);

            if (is_array($decoded) && !empty($decoded)) {
                $categories = $decoded;
            }
        }

        // Só sincroniza recursos/módulos quando houver payload válido da API.
        if (is_array($categories)) {
            DB::statement('SET FOREIGN_KEY_CHECKS=0;');

            Module::truncate();
            ModuleCategory::truncate();
            Resource::truncate();

            DB::statement('SET FOREIGN_KEY_CHECKS=1;');

            /**
             * Define o status de todos os registros como 0 antes da verificação.
             * Em seguida, verifica se a permissão recebida das rotas do Core 
             * corresponde a algum registro existente na tabela de Recursos.
             * 
             * Se houver correspondência, o status será atualizado para 1.
             * Se o status permanecer 0, significa que o nome da permissão recebida 
             * não corresponde a nenhum registro existente em Recursos.
             */
            Resource::where('status', true)->update([
                'status' => 0,
            ]);

            /**
             * Faz looping pelas categorias
             */
            foreach ($categories as $category) {

                /**
                 * Verifica se veio com pacote ou sem
                 */
                if($category['name'] != 'Sem Pacote') {

                    /**
                     * Cria a categoria no sistema
                     */
                    $moduleCategory = ModuleCategory::updateOrCreate([
                        'name' => $category['name'],
                    ], [
                        'status' => true,
                        'created_by' => 1
                    ]);

                    $categoryId = $moduleCategory->id;

                } else {
                    $categoryId = null;
                }
                
                // Faz looping entre modulos
                foreach ($category['modules'] as $key => $module) {

                    /**
                     * Cria os módulos
                     */
                    $modelModule = Module::updateOrCreate([
                        'slug' => $key,
                        'module_category_id' => $categoryId,
                    ], [
                        'name' => $module['name'],
                        'description' => $module['description'],
                        'created_by' => 1
                    ]);

                    // Faz looping entre os recursos
                    foreach ($module['resources'] as $resource) {

                        /**
                         * Busca um registro onde o campo 'name' seja igual a $permission.
                         * 
                         * Se o registro existir, atualiza o campo 'status' para true.
                         * Se o registro não existir, cria um novo com 'name' = $permission 
                         * e 'status' = true.
                         */
                        Resource::updateOrCreate(
                            [
                                'name' => $resource,
                                'module_id' => $modelModule->id,
                            ],
                            [
                                'status' => true,
                                'created_by' => 1
                            ]
                        );
                    }

                }
            }
        }

        // Obtem todos os clientes
        $clients = DB::table('clients')->select(['id'])->get();

        DB::statement('SET FOREIGN_KEY_CHECKS=0;');

        DB::table('orders')->truncate();
        DB::table('orders_transactions')->truncate();
        DB::table('subscriptions_cycles')->truncate();
        DB::table('subscriptions')->truncate();
        DB::table('clients_packages')->truncate();
        DB::table('clients_packages_items')->truncate();

        DB::statement('SET FOREIGN_KEY_CHECKS=1;');

        // Faz looping em cada cliente
        foreach ($clients as $client) {

            $packageId = DB::table('clients_packages')->insertGetId([
                'client_id' => $client->id,
                'name' => 'MIGRAÇÃO',
                'value' => 0,
                'status' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Pega todos os IDs de módulo
            $modules = Module::get();

            $packageItems = $modules->map(function($module) use ($packageId) {
                return [
                    'package_id' => $packageId,
                    'item_id' => $module->id,
                    'module_name' => $module->name,
                    'module_value' => $module->value,
                    'billing_type' => $module->pricing_type,
                    'payload' => json_encode($module),
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            })->toArray();

            // Inserção em massa
            DB::table('clients_packages_items')->insert($packageItems);

            $subscriptionId = DB::table('subscriptions')->insertGetId([
                'pagarme_subscription_id' => '1',
                'pagarme_card_id' => '1',
                'interval' => 'year',
                'payment_method' => 'liberado',
                'currency' => 'BRL',
                'installments' => 1,
                'status' => 'paid',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Cria um pedido
            $orderId = DB::table('orders')->insertGetId([
                'client_id' => $client->id,
                'package_id' => $packageId,
                'subscription_id' => $subscriptionId,
                'total_amount' => 0,
                'status' => 'Liberado',
                'type' => 'MIGRAÇÃO',
                'current_step' => 'Pagamento',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('subscriptions_cycles')->insert([
                'subscription_id' => $subscriptionId,
                'pagarme_cycle_id' => '1',
                'start_date' => now(),
                'end_date' => now()->addYear(),
                'status' => 'billed',
                'cycle' => 1,
                'billing_at' => now(),
                'next_billing_at' => now()->addYear(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('orders_transactions')->insert([
                'order_id' => $orderId,
                'subscription_id' => $subscriptionId,
                'pagarme_transaction_id' => '1',
                'amount' => 0,
                'status' => 'paid',
                'method' => 'liberado',
                'currency' => 'BRL',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

        }
        
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
    }
};
