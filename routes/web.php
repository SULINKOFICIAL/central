<?php
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\TenantController;
use App\Http\Controllers\TenantManualPlanController;
use App\Http\Controllers\TenantDomainController;
use App\Http\Controllers\TenantDomainProcessingController;
use App\Http\Controllers\TenantIntegrationController;
use App\Http\Controllers\TenantInstallController;
use App\Http\Controllers\TenantLocationController;
use App\Http\Controllers\TenantsActionsController;
use App\Http\Controllers\CpanelController;
use App\Http\Controllers\DeveloperController;
use App\Http\Controllers\ErrorMiCoreController;
use App\Http\Controllers\GroupController;
use App\Http\Controllers\IntegrationSuggestionController;
use App\Http\Controllers\MetaApiController;
use App\Http\Controllers\MetaApiOnboardingController;
use App\Http\Controllers\PackageController;
use App\Http\Controllers\ResourceController;
use App\Http\Controllers\ModuleController;
use App\Http\Controllers\ModuleBulkController;
use App\Http\Controllers\ModuleCategoryController;
use App\Http\Controllers\NewsCategoryController;
use App\Http\Controllers\NewsController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\CouponController;
use App\Http\Controllers\AdditionalUserController;
use App\Http\Controllers\AdditionalStorageController;
use App\Http\Controllers\AccountSettingsController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\PagarMeController;
use App\Http\Controllers\PagHiperController;
use App\Http\Controllers\SubscriptionsController;
use App\Http\Controllers\SystemCoreUpdateController;
use App\Http\Controllers\SystemMailSettingsController;
use App\Http\Controllers\SystemProvisioningController;
use App\Http\Controllers\SystemQueuesController;
use App\Http\Controllers\SystemSubscriptionsSyncController;
use App\Http\Controllers\SystemWhatsAppSettingsController;
use App\Http\Controllers\TicketController;
use App\Http\Controllers\TaskDispatchHistoryController;
use App\Http\Controllers\TaskDispatchHistoryProcessingController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\WhatsAppApiController;
use App\Http\Controllers\LogsApiController;
use App\Http\Controllers\TenantProcessingController;
use App\Http\Controllers\TenantIntegrationProcessingController;
use App\Http\Controllers\CouponProcessingController;
use App\Http\Controllers\LogsApiProcessingController;
use App\Http\Controllers\ModuleCategoryProcessingController;
use App\Http\Controllers\NewsCategoryProcessingController;
use App\Http\Controllers\NewsProcessingController;
use App\Http\Controllers\OrderProcessingController;
use App\Http\Controllers\ResourceProcessingController;
use App\Http\Controllers\SqsErrorController;
use App\Http\Controllers\SuggestionProcessingController;
use App\Http\Controllers\TicketProcessingController;
use App\Http\Controllers\TikTokApiController;
use App\Http\Controllers\UserProcessingController;

/**
 * Paínel de administração.
 */
Route::middleware(['auth'])->group(function () {

    Route::get('/', [DashboardController::class, 'index'])->name('index');
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('/dashboard/sistemas-por-dia', [DashboardController::class, 'dailySystemsByMonth'])->name('dashboard.daily.systems');

    /**
     * Rotas para configurações da conta do usuário autenticado.
     */
    Route::prefix('conta')->name('account.')->group(function () {
        /**
         * Rotas para edição dos dados de configuração da conta.
         */
        Route::prefix('configuracoes')->name('settings.')->group(function () {
            Route::get('/', [AccountSettingsController::class, 'edit'])->name('edit');
            Route::put('/', [AccountSettingsController::class, 'update'])->name('update');
        });
    });

    /**
     * Rotas para configurações sistêmicas da central.
     */
    Route::prefix('configuracoes')->name('system.settings.')->group(function () {
        
        /**
         * Rotas para configuração e teste de SMTP.
         */
        Route::prefix('sistema/smtp')->name('mail.')->group(function () {
            Route::get('/',              [SystemMailSettingsController::class, 'edit'])->name('edit');
            Route::put('/',              [SystemMailSettingsController::class, 'update'])->name('update');
            Route::post('/testar-email', [SystemMailSettingsController::class, 'sendTest'])->name('test');
            Route::get('/preview-email', [SystemMailSettingsController::class, 'preview'])->name('preview');
        });

        /**
         * Rotas para configuração e teste de WhatsApp.
         */
        Route::prefix('sistema/whatsapp')->name('whatsapp.')->group(function () {
            Route::get('/',         [SystemWhatsAppSettingsController::class, 'edit'])->name('edit');
            Route::put('/',         [SystemWhatsAppSettingsController::class, 'update'])->name('update');
            Route::post('/testar',  [SystemWhatsAppSettingsController::class, 'sendTest'])->name('test');
        });

        /**
         * Rotas para sincronização em massa de planos.
         */
        Route::prefix('sistema/assinaturas')->name('subscriptions.sync.')->group(function () {
            Route::get('/',             [SystemSubscriptionsSyncController::class, 'edit'])->name('edit');
            Route::post('/sincronizar', [SystemSubscriptionsSyncController::class, 'sync'])->name('run');
        });

        /**
         * Rotas para diagnóstico do provisionamento de tenants.
         */
        Route::prefix('sistema/provisionamento')->name('provisioning.')->group(function () {
            Route::get('/integridade',       [SystemProvisioningController::class, 'integrity'])->name('integrity');
            Route::get('/consultar-dominio', [SystemProvisioningController::class, 'domainLookup'])->name('domain.lookup');
            Route::get('/recursos-orfaos',   [SystemProvisioningController::class, 'orphanResources'])->name('orphans');
            Route::post('/recursos-orfaos/remover', [SystemProvisioningController::class, 'removeOrphanResource'])->name('orphans.remove');
            Route::post('/recursos-orfaos/remover-lote', [SystemProvisioningController::class, 'removeOrphanResourcesBatch'])->name('orphans.remove-batch');
        });

        /**
         * Rotas para consultar e reiniciar as filas da própria Central.
         */
        Route::get('/sistema/filas-central', [SystemQueuesController::class, 'index'])->name('central.queues');
        Route::post('/sistema/filas-central/reiniciar', [SystemQueuesController::class, 'restart'])->name('central.queues.restart');

        /**
         * Rota para atualizar a própria central core_business.
         */
        Route::get('/sistema/central/atualizar', [SystemCoreUpdateController::class, 'run'])->name('core.update');
    });

    /**
     * Rotas para gerenciamento dos clientes.
     */
    Route::prefix('instalacoes')->group(function () {
        Route::name('tenants.')->group(function () {
            Route::get('/',                                      [TenantController::class, 'index'])->name('index');
            Route::get('/processar',                             [TenantProcessingController::class, 'process'])->name('process');
            Route::get('/adicionar',                             [TenantController::class, 'create'])->name('create');
            Route::get('/consultar-dominio',                     [TenantController::class, 'domainAvailability'])->name('domain.availability');
            Route::get('/cidades',                               [TenantLocationController::class, 'cities'])->name('locations.cities');
            Route::post('/adicionar',                            [TenantController::class, 'store'])->name('store');
            Route::get('/visualizar/{id}',                       [TenantController::class, 'show'])->name('show');
            Route::get('/visualizar/dados-api/{id}',             [TenantController::class, 'apiData'])->name('api.data');
            Route::get('/visualizar/plano-manual-dados/{id}',    [TenantManualPlanController::class, 'editData'])->name('plan.manual.edit-data');
            Route::put('/visualizar/plano-manual-aplicar/{id}',  [TenantManualPlanController::class, 'apply'])->name('plan.manual.apply');
            Route::post('/visualizar/plano-manual-sincronizar/{id}', [TenantManualPlanController::class, 'sync'])->name('plan.manual.sync');
            Route::get('/editar/{id}',                           [TenantController::class, 'edit'])->name('edit');
            Route::put('/editar/{id}',                           [TenantController::class, 'update'])->name('update');
            Route::get('/desabilitar/{id}',                      [TenantController::class, 'destroy'])->name('destroy');
            Route::get('/dominios',                              [TenantDomainController::class, 'index'])->name('domains.index');
            Route::get('/dominios/processar',                    [TenantDomainProcessingController::class, 'process'])->name('domains.process');
            Route::post('/dominios/adicionar',                   [TenantDomainController::class, 'store'])->name('domains.store');
            

            /**
             * Rotas para instalação de clientes.
             */
            Route::name('install.')->group(function () {
                /**
                 * Rotas para etapas da instalação dos clientes.
                 */
                Route::prefix('instalacao')->group(function () {
                    Route::get('/{id}', [TenantInstallController::class, 'index'])->name('index');

                    /**
                     * Rotas para automações da API do cPanel.
                     */
                    Route::prefix('cpanel')->group(function () {
                        Route::get('/gerar/{id}',           [CpanelController::class, 'make'])->name('make');
                        Route::get('/subdominio/{id}',      [CpanelController::class, 'clientMakeDomain'])->name('subdomain');
                        Route::get('/clonar-banco/{id}',    [CpanelController::class, 'clientMakeDatabase'])->name('clone');
                        Route::get('/insere-token/{id}',    [CpanelController::class, 'clientAddTokenAndUser'])->name('token');
                    });
                });
            });

        });
    });

    /**
     * Rotas para gerenciamento das notícias.
     */
    Route::prefix('noticias')->group(function () {
        Route::name('news.')->group(function () {
            Route::get('/',                 [NewsController::class, 'index'])->name('index');
            Route::get('/processar',        [NewsProcessingController::class, 'process'])->name('process');
            Route::get('/adicionar',        [NewsController::class, 'create'])->name('create');
            Route::post('/adicionar',       [NewsController::class, 'store'])->name('store');
            Route::get('/visualizar/{id}',  [NewsController::class, 'show'])->name('show');
            Route::get('/editar/{id}',      [NewsController::class, 'edit'])->name('edit');
            Route::put('/editar/{id}',      [NewsController::class, 'update'])->name('update');
            Route::get('/desabilitar/{id}', [NewsController::class, 'destroy'])->name('destroy');

            /**
             * Rotas para gerenciamento das categorias de notícias.
             */
            Route::prefix('categorias')->group(function () {
                Route::name('categories.')->group(function () {
                    Route::get('/',                 [NewsCategoryController::class, 'index'])->name('index');
                    Route::get('/processar',        [NewsCategoryProcessingController::class, 'process'])->name('process');
                    Route::get('/adicionar',        [NewsCategoryController::class, 'create'])->name('create');
                    Route::post('/adicionar',       [NewsCategoryController::class, 'store'])->name('store');
                    Route::get('/editar/{id}',      [NewsCategoryController::class, 'edit'])->name('edit');
                    Route::put('/editar/{id}',      [NewsCategoryController::class, 'update'])->name('update');
                    Route::get('/desabilitar/{id}', [NewsCategoryController::class, 'destroy'])->name('destroy');
                });
            });

        });
    });

    /**
     * Rotas para gerenciamento dos pacotes.
     */
    Route::prefix('pacotes')->group(function () {
        Route::name('packages.')->group(function () {
            Route::get('/',                 [PackageController::class, 'index'])->name('index');
            Route::get('/adicionar',        [PackageController::class, 'create'])->name('create');
            Route::post('/adicionar',       [PackageController::class, 'store'])->name('store');
            Route::get('/editar/{id}',      [PackageController::class, 'edit'])->name('edit');
            Route::put('/editar/{id}',      [PackageController::class, 'update'])->name('update');
            Route::post('/ordem/{id}',      [PackageController::class, 'updateOrder'])->name('order.update');
            Route::get('/desabilitar/{id}', [PackageController::class, 'destroy'])->name('destroy');
        });
    });

    /**
     * Rotas para gerenciamento dos módulos.
     */
    Route::prefix('modulos')->group(function () {
        Route::name('modules.')->group(function () {
            Route::get('/',                         [ModuleController::class, 'index'])->name('index');
            Route::get('/importacao-em-massa',      [ModuleBulkController::class, 'bulkPage'])->name('bulk.page');
            Route::get('/modelo-importacao-json',   [ModuleBulkController::class, 'bulkTemplate'])->name('bulk.template');
            Route::post('/importacao-json',         [ModuleBulkController::class, 'bulkImport'])->name('bulk.import');
            Route::get('/atualizar-precos',         [ModuleController::class, 'editPrices'])->name('prices.edit');
            Route::put('/atualizar-precos',         [ModuleController::class, 'updatePrices'])->name('prices.update');
            Route::get('/adicionar',                [ModuleController::class, 'create'])->name('create');
            Route::post('/adicionar',               [ModuleController::class, 'store'])->name('store');
            Route::get('/editar/{id}',              [ModuleController::class, 'edit'])->name('edit');
            Route::put('/editar/{id}',              [ModuleController::class, 'update'])->name('update');
            Route::get('/desabilitar/{id}',         [ModuleController::class, 'destroy'])->name('destroy');

            /**
             * Rotas para gerenciamento das categorias de módulos.
             */
            Route::prefix('categorias')->group(function () {
                Route::name('categories.')->group(function () {
                    Route::get('/',                 [ModuleCategoryController::class, 'index'])->name('index');
                    Route::get('/processar',        [ModuleCategoryProcessingController::class, 'process'])->name('process');
                    Route::get('/adicionar',        [ModuleCategoryController::class, 'create'])->name('create');
                    Route::post('/adicionar',       [ModuleCategoryController::class, 'store'])->name('store');
                    Route::get('/editar/{id}',      [ModuleCategoryController::class, 'edit'])->name('edit');
                    Route::put('/editar/{id}',      [ModuleCategoryController::class, 'update'])->name('update');
                    Route::get('/desabilitar/{id}', [ModuleCategoryController::class, 'destroy'])->name('destroy');
                });
            });
        });
    });

    /**
     * Rotas para gerenciamento dos grupos.
     */
    Route::prefix('grupos')->group(function () {
        Route::name('groups.')->group(function () {
            Route::get('/',                 [GroupController::class, 'index'])->name('index');
            Route::get('/adicionar',        [GroupController::class, 'create'])->name('create');
            Route::post('/adicionar',       [GroupController::class, 'store'])->name('store');
            Route::get('/editar/{id}',      [GroupController::class, 'edit'])->name('edit');
            Route::put('/editar/{id}',      [GroupController::class, 'update'])->name('update');
            Route::get('/desabilitar/{id}', [GroupController::class, 'destroy'])->name('destroy');
        });
    });

    /**
     * Rotas para gerenciamento dos recursos.
     */
    Route::prefix('recursos')->group(function () {
        Route::name('resources.')->group(function () {
            Route::get('/',                 [ResourceController::class, 'index'])->name('index');
            Route::get('/processar',        [ResourceProcessingController::class, 'process'])->name('process');
            Route::get('/adicionar',        [ResourceController::class, 'create'])->name('create');
            Route::post('/adicionar',       [ResourceController::class, 'store'])->name('store');
            Route::get('/editar/{id}',      [ResourceController::class, 'edit'])->name('edit');
            Route::put('/editar/{id}',      [ResourceController::class, 'update'])->name('update');
            Route::get('/desabilitar/{id}', [ResourceController::class, 'destroy'])->name('destroy');
        });
    });

    /**
     * Rotas para gerenciamento dos pedidos.
     */
    Route::prefix('pedidos')->group(function () {
        Route::name('orders.')->group(function () {
            Route::get('/',                                     [OrderController::class, 'index'])->name('index');
            Route::get('/processar',                            [OrderProcessingController::class, 'process'])->name('process');
            Route::get('/visualizar/{id}',                      [OrderController::class, 'show'])->name('show');
            Route::get('/reprocessar-assinatura-pagarme/{id}',  [OrderController::class, 'reprocessSubscription'])->name('reprocess.subscription');
            Route::get('/cancelar-em-andamento',                [OrderController::class, 'cancelDrafts'])->name('cancel.drafts');
        });
    });

    /**
     * Rotas para gerenciamento dos cupons.
     */
    Route::prefix('cupons')->group(function () {
        Route::name('coupons.')->group(function () {
            Route::get('/',                 [CouponController::class, 'index'])->name('index');
            Route::get('/processar',        [CouponProcessingController::class, 'process'])->name('process');
            Route::get('/adicionar',        [CouponController::class, 'create'])->name('create');
            Route::post('/adicionar',       [CouponController::class, 'store'])->name('store');
            Route::get('/editar/{id}',      [CouponController::class, 'edit'])->name('edit');
            Route::put('/editar/{id}',      [CouponController::class, 'update'])->name('update');
            Route::get('/desabilitar/{id}', [CouponController::class, 'destroy'])->name('destroy');
        });
    });

    /**
     * Rotas para gerenciamento de usuário adicional.
     */
    Route::prefix('usuarios-adicionais')->group(function () {
        Route::name('additional.users.')->group(function () {
            Route::get('/',                 [AdditionalUserController::class, 'index'])->name('index');
            Route::get('/adicionar',        [AdditionalUserController::class, 'create'])->name('create');
            Route::post('/adicionar',       [AdditionalUserController::class, 'store'])->name('store');
            Route::get('/editar/{id}',      [AdditionalUserController::class, 'edit'])->name('edit');
            Route::put('/editar/{id}',      [AdditionalUserController::class, 'update'])->name('update');
            Route::get('/desabilitar/{id}', [AdditionalUserController::class, 'destroy'])->name('destroy');
        });
    });

    /**
     * Rotas para gerenciamento de armazenamento adicional.
     */
    Route::prefix('armazenamentos-adicionais')->group(function () {
        Route::name('additional.storages.')->group(function () {
            Route::get('/',                 [AdditionalStorageController::class, 'index'])->name('index');
            Route::get('/adicionar',        [AdditionalStorageController::class, 'create'])->name('create');
            Route::post('/adicionar',       [AdditionalStorageController::class, 'store'])->name('store');
            Route::get('/editar/{id}',      [AdditionalStorageController::class, 'edit'])->name('edit');
            Route::put('/editar/{id}',      [AdditionalStorageController::class, 'update'])->name('update');
            Route::get('/desabilitar/{id}', [AdditionalStorageController::class, 'destroy'])->name('destroy');
        });
    });

    /**
     * Rotas para gerenciamento dos tickets.
     */
    Route::prefix('tickets')->group(function () {
        Route::name('tickets.')->group(function () {
            Route::get('/',                 [TicketController::class, 'index'])->name('index');
            Route::get('/processar',        [TicketProcessingController::class, 'process'])->name('process');
            Route::get('/visualizar/{id}',  [TicketController::class, 'show'])->name('show');
            Route::post('/responder/{id}',  [TicketController::class, 'reply'])->name('reply');
            Route::post('/finalizar/{id}',  [TicketController::class, 'finish'])->name('finish');
            Route::put('/editar/{id}',      [TicketController::class, 'update'])->name('update');
        });
    });

    /**
     * Rotas para gerenciamento das sugestões.
     */
    Route::prefix('sugestoes')->group(function () {
        Route::name('suggestions.')->group(function () {
            Route::get('/',                 [IntegrationSuggestionController::class, 'index'])->name('index');
            Route::get('/processar',        [SuggestionProcessingController::class, 'process'])->name('process');
            Route::put('/editar/{id}',      [IntegrationSuggestionController::class, 'update'])->name('update');
        });
    });

    /**
     * Rotas para gerenciamento das integrações dos clientes.
     */
    Route::prefix('integracoes-tenants')->group(function () {
        Route::name('tenants.integrations.')->group(function () {
            Route::get('/',                    [TenantIntegrationController::class, 'index'])->name('index');
            Route::get('/processar',           [TenantIntegrationProcessingController::class, 'process'])->name('process');
            Route::get('/debugar-token/{id?}', [TenantIntegrationController::class, 'debugToken'])->name('debug.token');
        });
    });

    /**
     * Rotas para visualização dos erros do sistema.
     */
    Route::prefix('errors')->group(function () {
        Route::name('errors.')->group(function () {
            Route::get('/',                 [ErrorMiCoreController::class, 'index'])->name('index');
            Route::get('/aplicacao',        [ErrorMiCoreController::class, 'application'])->name('application');
            Route::get('/visualizar',       [ErrorMiCoreController::class, 'show'])->name('show');
            Route::get('/arquivar-log',     [ErrorMiCoreController::class, 'archiveLaravelLog'])->name('archive.log');
        });
    });

    /**
     * Rotas para visualização dos logs de APIs.
     */
    Route::prefix('logs/apis')->group(function () {
        Route::name('logs.apis.')->group(function () {
            Route::get('/',                 [LogsApiController::class, 'index'])->name('index');
            Route::get('/relatorio',        [LogsApiController::class, 'relatoryGraphic'])->name('relatory.graphic');
            Route::get('/processar',        [LogsApiProcessingController::class, 'process'])->name('process');
            Route::get('/visualizar/{id}',  [LogsApiController::class, 'show'])->name('show');
        });
    });

    /**
     * Rotas para histórico de tarefas disparadas.
     */
    Route::prefix('historico-tarefas')->group(function () {
        Route::name('task.history.')->group(function () {
            Route::get('/', [TaskDispatchHistoryController::class, 'index'])->name('index');
            Route::get('/processar', [TaskDispatchHistoryProcessingController::class, 'process'])->name('process');
            Route::get('/visualizar/{id}', [TaskDispatchHistoryController::class, 'show'])->name('show');
        });
    });

    /**
     * Rotas para gerenciamento dos usuários.
     */
    Route::prefix('usuarios')->group(function () {
        Route::name('users.')->group(function () {
            Route::get('/',                 [UserController::class, 'index'])->name('index');
            Route::get('/processar',        [UserProcessingController::class, 'process'])->name('process');
            Route::get('/adicionar',        [UserController::class, 'create'])->name('create');
            Route::post('/adicionar',       [UserController::class, 'store'])->name('store');
            Route::get('/editar/{id}',      [UserController::class, 'edit'])->name('edit');
            Route::put('/editar/{id}',      [UserController::class, 'update'])->name('update');
            Route::get('/desabilitar/{id}', [UserController::class, 'destroy'])->name('destroy');
        });
    });


    /**
     * Rotas para comandos nos sistemas miCores.
     */
    Route::prefix('assinaturas')->group(function () {
        Route::name('subscriptions.')->group(function () {
            Route::get('/', [SubscriptionsController::class, 'index'])->name('index');
        });
    });

    /**
     * Rotas para obter informações ou alterar miCores.
     */
    Route::prefix('sistemas')->group(function () {
        Route::name('systems.')->group(function () {
            Route::get('/acessar-recursos',                  [TenantsActionsController::class, 'getResources'])->name('get.resources');
            Route::get('/resetar-plano/{id}',                [TenantsActionsController::class, 'resetPlanManual'])->name('reset.plan');
            Route::get('/atualizar-banco/{id}',              [TenantsActionsController::class, 'updateDatabaseManual'])->name('update.database');
            Route::get('/atualizar-git/{id}',                [TenantsActionsController::class, 'updateGitManual'])->name('update.git');
            Route::get('/reiniciar-filas/{id}',              [TenantsActionsController::class, 'updateSupervisorManual'])->name('update.supervisor');
            Route::get('/buildar-js/{id}',                    [TenantsActionsController::class, 'runNpmBuildManual'])->name('update.javascript');
            Route::get('/atualizar-em-massa',                [TenantsActionsController::class, 'updateAllDatabase'])->name('update.all.db');
            Route::get('/atualizar-sistemas',                [TenantsActionsController::class, 'updateAllSystems'])->name('update.all.systems');
            Route::get('/disparar-jobs-agendados',           [TenantsActionsController::class, 'runScheduledNow'])->name('run.scheduled.now');
            Route::get('/disparar-jobs-agendados/{id}',      [TenantsActionsController::class, 'runScheduledNow'])->name('run.scheduled.now.tenant');
            Route::get('/atualizar-tokens-integracoes/{id}', [TenantsActionsController::class, 'runIntegrationsNow'])->name('run.integrations.now');
        });
    });

    /**
     * Rotas relacionadas aos comandos do desenvolvedor.
     */
    Route::prefix('desenvolvedores')->group(function () {
        Route::name('developer.')->group(function () {
            Route::get('/', [DeveloperController::class, 'index'])->name('index');
        });
    });

});

/**
 * Embedded Signup da Meta deve rodar somente no domínio fixo da central.
 */
Route::prefix('integracoes/meta')->name('meta.embedded.')->group(function () {
    Route::get('/onboarding', [MetaApiOnboardingController::class, 'embeddedOnboarding'])->name('onboarding');
}); 

/**
 * Rotas de callback para autorizações OAuth externas.
 */
Route::name('callbacks.')->prefix('callbacks')->group(function () {
    /**
     * Rotas de callback específicas da Meta.
     */
    Route::prefix('meta')->group(function () {
        /**
         * Rotas nomeadas para callbacks dos produtos da Meta.
         */
        Route::name('meta.')->group(function () {

            /**
             * Criamos rotas de callback separadas para WhatsApp e Instagram,
             * mesmo que ambas chamem a mesma função. Isso facilita o processo
             * de validação da Meta, pois cada produto possui um fluxo OAuth
             * distinto e uma URL de redirecionamento específica.
             *
             * Ao manter endpoints independentes, deixamos explícito para o
             * sistema da Meta e para o App Review que tratamos permissões
             * e integrações de cada produto de forma isolada.
             */
            Route::get('/whatsapp',   [MetaApiController::class, 'callback'])->name('whatsapp');
            Route::get('/instagram',  [MetaApiController::class, 'callback'])->name('instagram');

        });
    });

    /**
     * Rotas de callback específicas do TikTok.
     */
    Route::prefix('tiktok')->group(function () {
        /**
         * Rotas nomeadas para callbacks dos produtos do TikTok.
         */
        Route::name('tiktok.')->group(function () {

            /**
             * Criamos rotas de callback separadas para TikTok,
             * mesmo que ambas chamem a mesma função. Isso facilita o processo
             * de validação do TikTok, pois cada produto possui um fluxo OAuth
             * distinto e uma URL de redirecionamento específica.
             *
             * Ao manter endpoints independentes, deixamos explícito para o
             * sistema do TikTok e para o App Review que tratamos permissões
             * e integrações de cada produto de forma isolada.
             */
            Route::get('/',   [TikTokApiController::class, 'callback'])->name('tiktok');

        });
    });
});

/**
 * Rotas de webhooks para recebimento de notificações externas.
 */
Route::prefix('webhooks')->withoutMiddleware(['web'])->group(function () {

    Route::get('/meta',      [MetaApiController::class, 'authWebhooks']);
    Route::post('/meta',     [MetaApiController::class, 'return'])->name('meta');
    Route::post('/whatsapp', [WhatsAppApiController::class, 'return'])->name('whatsapp');

    Route::post('/pagarme',  [PagarMeController::class, 'return'])->name('pagarme');
    Route::post('/paghiper', [PagHiperController::class, 'return'])->name('paghiper');

    Route::get('/tiktok',    [TikTokApiController::class, 'return']);
    Route::post('/tiktok',   [TikTokApiController::class, 'return']);

    Route::post('/sqs',      [SqsErrorController::class, 'return']);
    
});

require __DIR__.'/auth.php';
