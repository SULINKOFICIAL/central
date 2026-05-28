<?php

use App\Http\Controllers\ApisAccountController;
use App\Http\Controllers\ApisDiscoveryController;
use App\Http\Controllers\ApisOrdersController;
use App\Http\Controllers\ApisPaymentsController;
use App\Http\Controllers\ApisTicketsController;
use App\Http\Controllers\ApisDomainsController;
use App\Http\Controllers\ApisNewsController;
use App\Http\Controllers\ApisOnboardingController;
use App\Http\Controllers\ApisTokensController;
use App\Http\Controllers\ApisUtilityController;
use App\Http\Controllers\TenantsPlansController;
use App\Http\Controllers\MetaApiController;
use App\Http\Controllers\MetaApiOnboardingController;
use App\Http\Controllers\TikTokApiController;
use Illuminate\Support\Facades\Route;

/**
 * API para comunicação com os sistemas miCore
 */
Route::prefix('central')->middleware('auth.bearer')->group(function () {

    /**
     * API para comunicação com o WebSite micore.com.br
     */
    Route::post('/encontrar-cliente', [ApisDiscoveryController::class, 'findTenant']);
    Route::prefix('onboarding')->group(function () {
        Route::post('/verificar-identidade', [ApisOnboardingController::class, 'checkOnboardingIdentity']);
        Route::post('/salvar-etapa',         [ApisOnboardingController::class, 'saveOnboardingStep']);
        Route::post('/finalizar',            [ApisOnboardingController::class, 'finalizeOnboarding']);
    });

    /** Retorna as informações do banco de dados */
    Route::get('/meu-banco',                [ApisDiscoveryController::class, 'getDatabase']);
    Route::get('/banco-por-tenant',         [ApisDiscoveryController::class, 'getDatabaseByTenant']);
    Route::get('/app/tenant-resolution',    [ApisDiscoveryController::class, 'getAppTenantResolution']);
    Route::get('/modulos',                  [ApisUtilityController::class, 'modules']);
    Route::get('/pacotes',                  [ApisUtilityController::class, 'packages']);

    /** APIS que necessitam de um cliente */
    Route::middleware('attach.tenant')->group(function () {

        /** API que envia informações para os miCores */
        Route::get('/meu-pedido',         [ApisAccountController::class, 'order']);
        Route::get('/minhas-compras',     [ApisAccountController::class, 'orders']);
        Route::get('/compra/{id}',        [ApisAccountController::class, 'invoice']);
        Route::get('/cartoes',            [ApisAccountController::class, 'cards']);
        Route::get('/meu-plano',          [TenantsPlansController::class, 'plan']);
        Route::get('/meu-pacote',         [TenantsPlansController::class, 'plan']);
        Route::get('/token/{id}',         [ApisAccountController::class, 'token']);

        /**
         * API que gerencia os pedidos
         */
        Route::prefix('pedidos')->group(function () {
            Route::get('/compra/{id}',       [ApisOrdersController::class, 'details']);
            Route::get('/rascunho',          [ApisOrdersController::class, 'draft']);
            Route::get('/catalogo-modulos',  [ApisOrdersController::class, 'modulesCatalog']);
            Route::get('/uso',               [ApisOrdersController::class, 'usageOptions']);
            Route::post('/atualizar',        [ApisOrdersController::class, 'update']);
            Route::get('/cancelar',          [ApisOrdersController::class, 'cancel']);
        });

        /**
         * API que gerencia os pagamentos
         */
        Route::prefix('pagamento')->group(function () {
            Route::post('/pagar', [ApisPaymentsController::class, 'orderPayment']);
            Route::get('/status', [ApisPaymentsController::class, 'paymentStatus']);
        });

        /** API que recebe dados dos miCores */
        Route::get('/tickets',                 [ApisTicketsController::class, 'index']);
        Route::get('/tickets/{id}',            [ApisTicketsController::class, 'show']);
        Route::post('/tickets',                [ApisTicketsController::class, 'store']);
        Route::post('/tickets/{id}/anexos',    [ApisTicketsController::class, 'attach']);
        Route::post('/tickets/{id}/respostas', [ApisTicketsController::class, 'reply']);
        Route::post('/sugestoes',              [ApisUtilityController::class, 'suggestions']);
        Route::post('/error',                  [ApisUtilityController::class, 'notifyErrors']);

        /** API gerencia os domínios */
        Route::prefix('dominios')->group(function () {
            Route::get('/',                 [ApisDomainsController::class, 'index']);
            Route::post('/adicionar',       [ApisDomainsController::class, 'store']);
            Route::get('/editar/{id}',      [ApisDomainsController::class, 'edit']);
            Route::put('/editar/{id}',      [ApisDomainsController::class, 'update']);
            Route::delete('/remover/{id}',  [ApisDomainsController::class, 'destroy']);
        });

        /** API gerencia os domínios */
        Route::prefix('meta')->group(function () {
            Route::get('/auth/{type}/{host}', [MetaApiController::class, 'OAuth2']);
            Route::post('/onboarding/start',  [MetaApiOnboardingController::class, 'startOnboarding']);
            Route::post('/inscricao',         [MetaApiController::class, 'subscribed']);
            Route::post('/intercambio',       [MetaApiController::class, 'exchange']);
            Route::delete('/desinscricao',    [MetaApiController::class, 'unsubscribed']);
        });

        /** API gerencia as contas TikTok */
        Route::prefix('tiktok')->group(function () {
            Route::get('/auth/{host}', [TikTokApiController::class, 'OAuth2']);
        });

        /** API gerencia as notícias */
        Route::prefix('noticias')->group(function () {
            Route::get('/',                   [ApisNewsController::class, 'index']);
            Route::get('/detalhes/{id}',      [ApisNewsController::class, 'show']);
            Route::get('/nao-lidas/{id}',     [ApisNewsController::class, 'notRead']);
            Route::post('/marcar-lidas/{id}', [ApisNewsController::class, 'markRead']);
        });
    });
});

/**
 * Callback técnico do Embedded Signup da Meta.
 * Recebe payload bruto do frontend da central e valida no backend.
 */
Route::prefix('integracoes/meta')->group(function () {
    Route::post('/onboarding/callback', [MetaApiOnboardingController::class, 'embeddedOnboardingCallback']);
});
