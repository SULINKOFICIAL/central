<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Tenant extends Model
{
    protected $table = 'tenants';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'type_installation',
        'name',
        'email',
        'pagarme_customer_id',
        'whatsapp',
        'company',
        'cnpj',
        'cpf',
        'company_profile',
        'company_zip_code',
        'company_state_id',
        'company_city_id',
        'company_address',
        'company_neighborhood',
        'company_number',
        'company_complement',
        'tips_whatsapp',
        'tips_email',
        'has_coupon',
        'coupon_code',
        'document_type',
        'onboarding_current_step',
        'onboarding_started_at',
        'onboarding_completed_at',
        'logo',
        'token',
        'status',
        'filed_by',
        'created_by',
        'updated_by',
    ];

    // Dados de provisionamento técnico do cliente
    public function provisioning(): HasOne
    {
        return $this->hasOne(TenantProvisioning::class, 'tenant_id', 'id');
    }

    /**
     * Roteamento usado pelos aplicativos MiCore.
     */
    public function appRoute(): HasOne
    {
        return $this->hasOne(TenantAppRoute::class, 'tenant_id', 'id');
    }

    // Status técnico de atualização do cliente
    public function runtimeStatus(): HasOne
    {
        return $this->hasOne(TenantRuntimeStatus::class, 'tenant_id', 'id');
    }

    // Planos do cliente
    public function plans(): HasMany
    {
        return $this->hasMany(TenantPlan::class, 'tenant_id', 'id');
    }

    // Plano atual do cliente
    public function plan(): HasOne
    {
        return $this->hasOne(TenantPlan::class, 'tenant_id', 'id')
            ->where('status', true)
            ->where('progress', 'completed');
    }

    // Domínios do cliente
    public function domains(): HasMany
    {
        return $this->hasMany(TenantDomain::class, 'tenant_id', 'id');
    }

    /**
     * Estado da loja inicial do tenant.
     */
    public function companyState(): BelongsTo
    {
        return $this->belongsTo(State::class, 'company_state_id', 'id');
    }

    /**
     * Cidade da loja inicial do tenant.
     */
    public function companyCity(): BelongsTo
    {
        return $this->belongsTo(City::class, 'company_city_id', 'id');
    }

    // Objetivos principais selecionados no onboarding
    public function mainGoals(): HasMany
    {
        return $this->hasMany(TenantMainGoal::class, 'tenant_id', 'id');
    }

    // Módulos habilitados para o cliente
    public function modules(): BelongsToMany
    {
        return $this->belongsToMany(Module::class, 'tenants_modules', 'tenant_id', 'module_id');
    }

    // Compras realizadas pelo cliente
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class, 'tenant_id', 'id');
    }

    // Compras realizadas pelo cliente
    public function cards(): HasMany
    {
        return $this->hasMany(TenantCard::class, 'tenant_id', 'id');
    }

    // Assinaturas realizadas pelo cliente
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class, 'tenant_id', 'id');
    }

    // Retorna a última compra realizada pelo cliente
    public function lastOrder()
    {
        return $this->orders()->latest('created_at')->first();
    }

    // Retorna em quantos dias deve ser feita a próxima renovação
    public function renovation()
    {
        // Obtém o último ciclo de assinatura do cliente
        $latestCycle = SubscriptionCycle::query()
            ->whereHas('subscription', function ($query) {
                $query->where('tenant_id', $this->id);
            })
            ->whereNotNull('end_date')
            ->orderByDesc('end_date')
            ->first();

        // Caso não encontre
        if (!$latestCycle) {
            return null;
        }

        // Obtém data de expiração
        $now = Carbon::now();
        return round($now->diffInDays($latestCycle->end_date, false));
    }

    // Retorna em quantos dias deve ser feita a próxima renovação
    public function lastSubscription()
    {
        return $this->subscriptions()->latest('id')->first();
    }

    /**
     * Centraliza a visão atual da assinatura do tenant.
     */
    public function actualSubscription(): array
    {

        /**
         * Carrega o contexto da assinatura
         */
        $this->loadMissing([
            'plan.items.item',
            'subscriptions.cycles',
        ]);

        /**
         * Obtém o último ciclo registrado do cliente
         */
        $tenantPlan = TenantPlan::where('tenant_id', $this->id)
                                    ->where('progress', 'completed')
                                    ->orderByDesc('id')
                                    ->with(['subscription.cycles', 'items.item'])
                                    ->first();

        if (!$tenantPlan) {
            return [
                'name' => null,
                'users' => 0,
                'storage' => 0,
                'cycle' => [
                    'hasActiveCycle' => false,
                    'start' => null,
                    'end' => null,
                ],
                'modules' => [],
            ];
        }

        /**
         * Obtém o último ciclo registrado do cliente
         */
        $activeCycle = null;
        if ($tenantPlan->subscription) {
            $activeCycle = $tenantPlan->subscription->cycles()
                ->where('start_date', '<=', now())
                ->where('end_date', '>=', now())
                ->orderByDesc('end_date')
                ->first();
        }

        /**
         * Se encontrou o ciclo
         */
        if($activeCycle){
            $cycle = [
                'hasActiveCycle'    => true,
                'start'             => $activeCycle->start_date->format('d/m/Y H:i:s'),
                'end'               => $activeCycle->end_date->format('d/m/Y H:i:s'),
            ];
        } else {
            $cycle = [
                'hasActiveCycle'    => false,
                'start'             => null,
                'end'               => null,
            ];
        }

        /**
         * Se encontrou itens, formata os módulos.
         */
        if ($tenantPlan->items->count()) {

            /**
             * Formata os módulos
             */
            $modules = [];
            foreach ($tenantPlan->items as $item) {
                if (!$item->item) {
                    continue;
                }

                $modules[] = [
                    'name' => $item->item->name,
                    'slug' => $item->item->slug,
                ];
            }
        } else {

            /**
             * Caso não tenha itens, retorna array vazio.
             */
            $modules = [];

        }

        return [
            'name'              => $tenantPlan->name,
            'users'             => $tenantPlan->users_limit,
            'storage'           => $tenantPlan->size_storage,
            'cycle'             => $cycle,
            'modules'           => $modules,
        ];
    }
}
