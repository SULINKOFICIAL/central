<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Models\TenantDomain;
use App\Models\TenantProvisioning;
use App\Models\City;
use App\Models\Module;
use App\Models\Package;
use App\Models\State;
use App\Services\GuzzleService;
use App\Services\TenantInitialTrialPlanService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use GuzzleHttp\Client as Guzzle;
use Illuminate\Support\Str;

class TenantController extends Controller
{
    private $repository;

    public function __construct(
        Tenant $content,
        private readonly TenantInitialTrialPlanService $tenantInitialTrialPlanService
    )
    {
        $this->repository = $content;
    }

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        // Retorna a página. Os dados são carregados por AJAX (DataTables server-side).
        return view('pages.tenants.index');

    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        $selectedStateId = old('company_state_id');
        $cities = collect();

        if (!empty($selectedStateId)) {
            $cities = City::where('state_id', $selectedStateId)
                ->where('status', 1)
                ->orderBy('name')
                ->get();
        }

        /**
         * Retorna a página com o catálogo local sincronizado do MiCore.
         */
        return view('pages.tenants.create', [
            'states' => State::where('status', 1)->orderBy('name')->get(),
            'cities' => $cities,
        ]);

    }

    public function domainAvailability(Request $request)
    {
        $validated = $request->validate([
            'domain' => ['required', 'string', 'max:255'],
        ]);

        $domain = $this->normalizeTenantDomain($validated['domain']);

        if (empty($domain)) {
            return response()->json([
                'available' => false,
                'domain' => null,
                'full_domain' => null,
                'suggestions' => [],
            ]);
        }

        $fullDomain = $domain . '.micore.com.br';
        $available = !TenantDomain::where('domain', $fullDomain)->exists();

        return response()->json([
            'available' => $available,
            'domain' => $domain,
            'full_domain' => $fullDomain,
            'suggestions' => $available ? [] : $this->domainSuggestions($domain),
        ]);
    }

    private function normalizeTenantDomain(string $domain): string
    {
        $domain = preg_replace('/^www\./', '', strtolower($domain));
        $domain = str_replace('.micore.com.br', '', $domain);
        $domain = cleanString($domain);
        $domain = str_replace(' ', '-', $domain);
        $domain = preg_replace('/[^a-z0-9-]/', '', $domain);
        $domain = preg_replace('/-+/', '-', $domain);
        $domain = preg_replace('/^-|-$/', '', $domain);

        return $domain;
    }

    private function domainSuggestions(string $domain): array
    {
        $suggestions = [];
        $counter = 1;

        while (count($suggestions) < 3 && $counter <= 20) {
            $suggestion = $domain . '-' . $counter;
            $fullSuggestion = $suggestion . '.micore.com.br';

            if (!TenantDomain::where('domain', $fullSuggestion)->exists()) {
                $suggestions[] = [
                    'domain' => $suggestion,
                    'full_domain' => $fullSuggestion,
                ];
            }

            $counter++;
        }

        return $suggestions;
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        /**
         * Dados mínimos para criar o tenant e depois materializar
         * a loja inicial no banco isolado do cliente.
         */
        $validated = $request->validate([
            'name'                       => ['required', 'string', 'max:255'],
            'company'                    => ['required', 'string', 'max:255'],
            'email'                      => ['required', 'email', 'max:255'],
            'whatsapp'                   => ['required', 'string', 'max:20'],
            'domain'                     => ['required', 'string', 'max:255'],
            'document_type'              => ['required', 'in:cnpj,cpf'],
            'document_number'            => ['required', 'string', 'max:20'],
            'company_zip_code'           => ['required', 'string', 'size:8'],
            'company_state_id'           => ['required', 'integer', 'exists:states,id'],
            'company_city_id'            => ['required', 'integer', 'exists:cities,id'],
            'company_neighborhood'       => ['required', 'string', 'max:255'],
            'company_address'            => ['required', 'string', 'max:255'],
            'company_number'             => ['required', 'string', 'max:20'],
            'company_complement'         => ['nullable', 'string', 'max:255'],
            'trial_days'                 => ['nullable', 'integer', 'min:1', 'max:365'],
            'user.name'                  => ['required', 'string', 'max:255'],
            'user.email'                 => ['required', 'email', 'max:255'],
            'user.password'              => ['required', 'string', 'max:255'],
        ]);

        $trialDays = $validated['trial_days'] ?? TenantInitialTrialPlanService::DEFAULT_TRIAL_DAYS;
        $documentNumber = onlyNumbers($validated['document_number']);
        $zipCode = onlyNumbers($validated['company_zip_code']);
        $whatsapp = onlyNumbers($validated['whatsapp']);

        if (empty($documentNumber)) {
            return redirect()
                ->back()
                ->withErrors(['document_number' => 'Informe um documento válido.'])
                ->withInput();
        }

        if (strlen($zipCode) !== 8) {
            return redirect()
                ->back()
                ->withErrors(['company_zip_code' => 'CEP deve conter 8 dígitos.'])
                ->withInput();
        }

        if (empty($whatsapp)) {
            return redirect()
                ->back()
                ->withErrors(['whatsapp' => 'Informe o WhatsApp da conta.'])
                ->withInput();
        }

        if (!City::where('id', $validated['company_city_id'])->where('state_id', $validated['company_state_id'])->exists()) {
            return redirect()
                ->back()
                ->withErrors(['company_city_id' => 'Selecione uma cidade válida para o estado escolhido.'])
                ->withInput();
        }

        /**
         * A loja inicial do tenant usa esse documento como chave.
         * Por isso bloqueamos CPF/CNPJ incompletos antes do provisionamento.
         */
        if ($validated['document_type'] === 'cpf' && strlen($documentNumber) !== 11) {
            return redirect()
                ->back()
                ->withErrors(['document_number' => 'CPF deve conter 11 dígitos.'])
                ->withInput();
        }

        if ($validated['document_type'] === 'cnpj' && strlen($documentNumber) !== 14) {
            return redirect()
                ->back()
                ->withErrors(['document_number' => 'CNPJ deve conter 14 dígitos.'])
                ->withInput();
        }

        if (Tenant::where('email', $validated['email'])->exists()) {
            return redirect()
                ->back()
                ->withErrors(['email' => 'Já existe uma conta com este email.'])
                ->withInput();
        }

        if (Tenant::where($validated['document_type'], $documentNumber)->exists()) {
            return redirect()
                ->back()
                ->withErrors(['document_number' => 'Já existe uma conta com este documento.'])
                ->withInput();
        }

        /**
         * Monta o payload persistido já com os campos normalizados.
         */
        $data = $request->all();
        $data['company_zip_code'] = $zipCode;
        $data['whatsapp'] = $whatsapp;
        $data['cnpj'] = null;
        $data['cpf'] = null;

        if ($validated['document_type'] === 'cnpj') {
            $data['cnpj'] = $documentNumber;
        }

        if ($validated['document_type'] === 'cpf') {
            $data['cpf'] = $documentNumber;
        }

        /**
         * Autor administrativo padrão usado pelo fluxo atual da Central.
         */
        $data['created_by'] = 1;

        /**
         * Gera um domínio permitido.
         */
        $data['domain'] = verifyIfAllow($data['domain']);

        /**
         * Gera um nome de tabela permitido.
         */
        $domainClean = str_replace('-', '_', $data['domain']);

        /**
         * Insere prefixo do miCore no nome do banco.
         */
        $data['table'] = env('CPANEL_PREFIX') . '_' . $domainClean;

        /**
         * Insere prefixo do miCore no usuário do banco.
         */
        $data['table_user'] = env('CPANEL_PREFIX') . '_' . $domainClean;

        /**
         * Gera senha do usuário MySQL do tenant.
         */
        $data['table_password'] = Str::random(12);

        /**
         * Gera token para API.
         */
        $data['token'] = hash('sha256', $data['domain'] . microtime(true));

        /**
         * Gera usuário inicial.
         */
        $data['first_user'] = [
            'name'       => $data['user']['name'],
            'email'      => $data['user']['email'],
            'password'   => $data['user']['password'],
            'short_name' => generateShortName($data['user']['name']),
        ];

        $provisioningData = [
            'table' => $data['table'],
            'table_user' => $data['table_user'],
            'table_password' => $data['table_password'],
            'first_user' => $data['first_user'],
            'install' => TenantProvisioning::STEP_SUBDOMAIN,
        ];

        unset($data['table'], $data['table_user'], $data['table_password'], $data['first_user'], $data['user'], $data['trial_days'], $data['document_number']);

        /**
         * Insere o tenant e seus dados técnicos iniciais.
         */
        $created = $this->repository->create($data);
        $created->provisioning()->create($provisioningData);
        $created->runtimeStatus()->create();

        /**
         * Novas contas começam com teste operacional sem histórico financeiro.
         */
        $this->tenantInitialTrialPlanService->ensureForTenant($created, $trialDays);

        /**
         * Registra o domínio do cliente.
         */
        TenantDomain::create([
            'tenant_id'     => $created->id,
            'auto_generate' => true,
            'domain'        => $data['domain'] . '.micore.com.br',
            'description'   => 'Domínio cadastrado ao criar a conta do cliente',
            'status'        => true,
        ]);

        /**
         * Retorna a página.
         */
        return redirect()
                ->route('tenants.install.index', $created->id)
                ->with('message', 'Tenante <b>'. $created->name . '</b> adicionado com sucesso.');

    }

    /**
     * Display the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function show($id)
    {
        $tenant   = $this->repository->find($id);
        $tenant->loadMissing([
            'plan.items.item.category',
            'plan.items.item.resources',
            'plans.orders',
            'plans.subscription.cycles',
        ]);

        $packages = Package::where('status', true)->get();

        /**
         * Fonte da seção de recursos:
         * apenas módulos vinculados ao plano atual do tenant.
         */
        $planItems = $tenant->plan?->items ?? collect();

        $planModules = collect($planItems)
            ->map(fn ($planItem) => $planItem->item)
            ->filter()
            ->unique('id')
            ->values();

        $modules       = $planModules;

        $actualPlan    = $tenant->actualSubscription();
        $usersLimit    = $actualPlan['users'];
        $storageLimitGb = number_format($actualPlan['storage'] / 1073741824, 2, ',', '.');
        $periodStart   = $actualPlan['cycle']['start'];
        $periodEnd     = $actualPlan['cycle']['end'];
        $enabledModules = collect($actualPlan['modules'])
            ->map(fn ($module) => $module['name'])
            ->values()
            ->all();
        $enabledModulesTotal = collect($enabledModules)->count();
        $totalModulesCount = Module::where('status', true)
            ->count();
        $currentPlanId = $tenant->plan?->id;
        $plansHistory = $tenant->plans
            ->sortByDesc('created_at')
            ->values();

        return view('pages.tenants.show')->with([
            'client'             => $tenant,
            'modules'            => $modules,
            'packages'           => $packages,
            'actualPlan'         => $actualPlan,
            'usersLimit'         => $usersLimit,
            'storageLimitGb'     => $storageLimitGb,
            'periodStart'        => $periodStart,
            'periodEnd'          => $periodEnd,
            'enabledModules'     => $enabledModules,
            'enabledModulesTotal' => $enabledModulesTotal,
            'totalModulesCount'  => $totalModulesCount,
            'currentPlanId'      => $currentPlanId,
            'plansHistory'       => $plansHistory,
        ]);

    }

    /**
     * Busca dados em tempo real via API do tenant para renderização on-demand na tela.
     */
    public function apiData($id)
    {
        $tenant        = $this->repository->findOrFail($id);
        $guzzleService = new GuzzleService();
        $actualPlan    = $tenant->actualSubscription();
        $enabledModules = $actualPlan['modules'];

        $apiVerifyStatus = $guzzleService->request('GET', 'sistema/status', $tenant);
        if (isset($apiVerifyStatus['error'])) {
            $html = view('pages.tenants._api_data', [
                'apiError'       => true,
                'errorMessage'   => $apiVerifyStatus['message'],
                'enabledModules' => $enabledModules,
                'allowSubscription' => [],
                'totalUsers'     => 0,
                'limitUsers'     => 0,
                'totalStorageGB' => 0,
                'limitStorageGB' => 0,
            ])->render();

            return response()->json([
                'success' => false,
                'html'    => $html,
            ]);
        }

        $apiGetSubscription = $guzzleService->request('GET', 'sistema/assinatura', $tenant);
        $apiGetUsers        = $guzzleService->request('GET', 'sistema/usuarios', $tenant);
        $apiGetStorage      = $guzzleService->request('GET', 'sistema/armazenamento', $tenant);

        $allowSubscription = json_decode($apiGetSubscription['data'], true)['subscription'];
        $totalUsers        = json_decode($apiGetUsers['data'], true)['users'];
        $limitUsers        = json_decode($apiGetUsers['data'], true)['limit'];
        $totalStorage      = json_decode($apiGetStorage['data'], true)['used_storage'];
        $limitStorage      = json_decode($apiGetStorage['data'], true)['allow_storage'];

        $html = view('pages.tenants._api_data', [
            'apiError'          => false,
            'errorMessage'      => null,
            'enabledModules'    => $enabledModules,
            'allowSubscription' => $allowSubscription,
            'totalUsers'        => $totalUsers,
            'limitUsers'        => $limitUsers,
            'totalStorageGB'    => round($totalStorage / (1024 * 1024 * 1024), 2),
            'limitStorageGB'    => round($limitStorage / (1024 * 1024 * 1024), 2),
        ])->render();

        return response()->json([
            'success' => true,
            'html'    => $html,
        ]);
    }

    /**
     * Realiza uma solicitação Guzzle com autenticação Bearer
     *
     * @param string $method Método HTTP (get, post, etc)
     * @param string $url URL para a solicitação
     * @param object $tenant Objeto cliente contendo informações do cliente
     * @param array|null $data Dados opcionais para incluir na requisição
     * @return array Resposta da API
     */
    public function guzzle($method, $url, $tenant, $data = null)
    {
        try {
            // Instancia o Guzzle
            $guzzle = new Guzzle();

            // Inicializa os parâmetros da requisição
            $options = [
                'headers' => [
                    'Authorization' => 'Bearer ' . config('services.central.token'),
                ]
            ];

            // Se houver dados, adiciona ao corpo da requisição
            if ($data !== null) {
                $options['json'] = $data;
            }

            // Realiza a solicitação
            $response = $guzzle->$method("{$tenant->domains[0]->domain}/api/$url", $options);

            // Obtém o corpo da resposta
            $response = $response->getBody()->getContents();

            // Decodifica o JSON
            $response = json_decode($response, true);
            
            // Retorna a resposta
            return $response;

        } catch (\Exception $e) {
            return [
                'error' => true,
                'message' => $e->getMessage(),
            ];
        }
    }


    /**
     * Show the form for editing the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function edit($id)
    {
        $content = $this->repository
            ->with(['provisioning', 'domains'])
            ->find($id);

        if (!$content) {
            return redirect()->back();
        }

        $provisioning = $content->provisioning;
        $provisioningStep = $provisioning?->install;
        $provisioningCompleted = $provisioning?->installAtLeast(TenantProvisioning::STEP_COMPLETED) ?? false;

        $provisioningLabels = [
            TenantProvisioning::STEP_SUBDOMAIN => [
                'label' => 'Aguardando subdomínio',
                'class' => 'badge-light-warning text-warning',
                'description' => 'Cria ou confirma o domínio técnico que vai apontar para o MiCore do cliente.',
                'next' => 'Criar subdomínio no cPanel',
            ],
            TenantProvisioning::STEP_DATABASE => [
                'label' => 'Aguardando banco',
                'class' => 'badge-light-warning text-warning',
                'description' => 'Cria o banco do tenant, clona o template e concede acesso ao usuário MySQL.',
                'next' => 'Clonar banco modelo',
            ],
            TenantProvisioning::STEP_USER_TOKEN => [
                'label' => 'Aguardando usuário inicial',
                'class' => 'badge-light-warning text-warning',
                'description' => 'Insere o usuário administrador, colaborador, loja matriz e token da Central no banco do tenant.',
                'next' => 'Inserir usuário e token',
            ],
            TenantProvisioning::STEP_MODULES => [
                'label' => 'Aguardando módulos',
                'class' => 'badge-light-warning text-warning',
                'description' => 'Sincroniza módulos, recursos, vigência e limites do plano atual para o tenant.',
                'next' => 'Sincronizar módulos do plano',
            ],
            TenantProvisioning::STEP_FINALIZING => [
                'label' => 'Finalizando',
                'class' => 'badge-light-primary text-primary',
                'description' => 'Consolida a instalação e marca o provisionamento como concluído.',
                'next' => 'Finalizar instalação',
            ],
            TenantProvisioning::STEP_COMPLETED => [
                'label' => 'Concluído',
                'class' => 'badge-light-success text-success',
                'description' => 'A instalação operacional do MiCore já foi concluída.',
                'next' => 'Nenhuma etapa pendente',
            ],
        ];

        $provisioningStatus = $provisioningLabels[$provisioningStep] ?? [
            'label' => 'Sem dados técnicos',
            'class' => 'badge-light-danger text-danger',
            'description' => 'Este cliente não possui registro técnico de provisionamento para iniciar a instalação.',
            'next' => 'Provisionamento indisponível',
        ];

        return view('pages.tenants.edit')->with([
            'content' => $content,
            'provisioning' => $provisioning,
            'provisioningStatus' => $provisioningStatus,
            'provisioningCompleted' => $provisioningCompleted,
            'primaryDomain' => $content->domains->first()?->domain,
        ]);
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $id)
    {
        // Verifica se existe
        if(!$content = $this->repository->find($id)) return redirect()->back();

        // Obtém dados
        $data = $request->all();

        // Autor
        $data['updated_by'] = Auth::id();

        // Atualiza dados
        $content->update($data);

        // Salva logo
        if(isset($data['fileLogo'])) $this->saveLogo($content, $data['fileLogo']);

        // Retorna a página
        return redirect()
                ->route('tenants.index')
                ->with('message', 'Tenante <b>'. $request->name . '</b> atualizado com sucesso.');

    }

    /**
     * Salva a logo do cliente, caso enviada.
     *
     * @param  \Illuminate\Http\UploadedFile|null  $logo
     * @param  \App\Models\Tenant  $tenant
     * @param  string  $filename
     * @return void
     */
    public function saveLogo($tenant, $logo = null, $filename = 'logo.png')
    {
        if ($logo && $logo->isValid()) {
            $logo->storeAs("clientes/{$tenant->id}", $filename, 'public');
            $tenant->logo = true;
            $tenant->save();
        }
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {

        // Obtém dados
        $content = $this->repository->findOrFail($id);

        // Atualiza status
        if($content->status == 1){
            $this->repository->where('id', $id)->update(['status' => false, 'filed_by' => Auth::id()]);
            $message = 'desabilitado';
            $status = false;
        } else {
            $this->repository->where('id', $id)->update(['status' => true]);
            $message = 'habilitado';
            $status = true;
        }

        if ($this->request->ajax()) {
            return response()->json([
                'message' => 'Tenante <b>'. $content->name . '</b> '. $message .' com sucesso.',
                'status' => $status
            ]);
        }

        // Retorna a página
        return redirect()
                ->route('tenants.index')
                ->with('message', 'Tenante <b>'. $content->name . '</b> '. $message .' com sucesso.');

    }

}
