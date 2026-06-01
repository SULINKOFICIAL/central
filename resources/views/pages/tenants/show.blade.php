@extends('layouts.app')

@section('title', $client->name)

@section('content')
    <div class="card mb-4">
        <div class="card-body py-7">
            <div class="d-flex flex-wrap align-items-center justify-content-between gap-6">
                <div class="d-flex align-items-center gap-6">
                    <div class="symbol symbol-75px">
                        @if ($client->logo)
                            <img src="{{ asset('storage/tenants/' . $client->id . '/logo.png') }}" alt="Logo do Cliente" class="object-fit-contain">
                        @else
                            <img src="{{ asset('assets/media/images/logo_dark.webp') }}" alt="Logo do Cliente" class="object-fit-contain">
                        @endif
                    </div>
                    <div class="d-flex flex-column">
                        <h1 class="mb-1 fw-bolder text-gray-800 text-uppercase fs-2">{{ $client->name }}</h1>
                        <div class="text-gray-500 fw-semibold fs-7 mb-2">
                            {{ $client->responsible_name ?? $client->name }} · {{ $client->email ?? ($client->domains->first()->domain ?? '-') }}
                        </div>
                        <div class="d-flex flex-wrap align-items-center gap-2">
                            <span class="badge rounded-pill badge-light-dark text-gray-700">
                                <i class="fa-solid fa-link me-2 fs-9"></i>Plano #{{ $currentPlanId ?? 'N/A' }}
                            </span>
                            <span class="badge rounded-pill badge-light-success">
                                <i class="fa-solid fa-rotate text-success me-2 fs-9"></i>Renova em {{ $client->renovation() ?? 0 }} dias
                            </span>
                            <span class="badge rounded-pill badge-light-secondary text-gray-600">
                                <i class="fa-solid fa-calendar-check me-2 fs-8"></i>{{ $periodStart }} até {{ $periodEnd }}
                            </span>
                        </div>
                    </div>
                </div>
                <div class="d-flex align-items-center gap-2">
                    <button type="button" class="btn btn-light-primary btn-sm" id="btn-open-plan-manual-modal">
                        <i class="fa-solid fa-list-check me-1"></i>Atualizar Plano do Cliente
                    </button>
                    <button type="button" class="btn btn-warning btn-sm btn-sync-manual-plan @if (!in_array($client->plan?->tenant_sync_status, ['pending', 'failed'], true)) d-none @endif" id="btn-sync-manual-plan-page">
                        <i class="fa-solid fa-rotate me-1"></i>Sincronizar novo plano
                    </button>
                    <a href="{{ route('tenants.edit', $client->id) }}" class="btn btn-light btn-sm">
                        <i class="fa-solid fa-gear me-1"></i>Configurações
                    </a>
                </div>
            </div>

            @if (!$actualPlan['name'])
                <div class="alert alert-danger mt-5 mb-0">
                    <i class="fa-solid fa-triangle-exclamation me-2"></i>Nenhum plano ativo.
                </div>
            @endif

            <div id="manual-plan-sync-page-message" class="alert mt-5 mb-0 @if ($client->plan?->tenant_sync_status === 'failed') alert-danger @else alert-warning @endif @if (!in_array($client->plan?->tenant_sync_status, ['pending', 'failed'], true)) d-none @endif">
                @if ($client->plan?->tenant_sync_status === 'failed')
                    <i class="fa-solid fa-triangle-exclamation me-2"></i>Falha ao sincronizar o novo plano. Revise o retorno e tente novamente.
                @else
                    <i class="fa-solid fa-circle-info me-2"></i>Existe uma alteração de plano salva internamente e ainda pendente de sincronização com o tenant.
                @endif
            </div>
        </div>
    </div>

    <div class="row g-4 mb-4">
        <div class="col-12 col-md-6 col-xl-3">
            <div class="card h-100">
                <div class="card-body">
                    <div class="d-flex align-items-center justify-content-between mb-2">
                        <span class="text-gray-500 fs-8 fw-semibold text-uppercase">Valor do Pacote</span>
                        <i class="fa-solid fa-money-bill-wave"></i>
                    </div>
                    <div class="fs-2 fw-bolder text-gray-800">R$ {{ number_format($client->current_value, 2, ',', '.') }}</div>
                    <div class="text-gray-500 fs-7">{{ $actualPlan['name'] ?: 'Sem plano' }}</div>
                </div>
            </div>
        </div>
        <div class="col-12 col-md-6 col-xl-3">
            <div class="card h-100">
                <div class="card-body">
                    <div class="d-flex align-items-center justify-content-between mb-2">
                        <span class="text-gray-500 fs-8 fw-semibold text-uppercase">Usuário</span>
                        <i class="fa-solid fa-users"></i>
                    </div>
                    <div class="fs-2 fw-bolder text-gray-800">{{ $usersLimit }}</div>
                    <div class="text-gray-500 fs-7">Limite no plano atual</div>
                </div>
            </div>
        </div>
        <div class="col-12 col-md-6 col-xl-3">
            <div class="card h-100">
                <div class="card-body">
                    <div class="d-flex align-items-center justify-content-between mb-2">
                        <span class="text-gray-500 fs-8 fw-semibold text-uppercase">Armazenamento</span>
                        <i class="fa-solid fa-hard-drive"></i>
                    </div>
                    <div class="fs-2 fw-bolder text-gray-800">{{ $storageLimitGb }} GB</div>
                    <div class="text-gray-500 fs-7">Limite no plano atual</div>
                    <div class="progress h-7px">
                        <div class="progress-bar bg-gray-200 w-75" role="progressbar" aria-valuenow="75" aria-valuemin="0" aria-valuemax="100"></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-12 col-md-6 col-xl-3">
            <div class="card h-100">
                <div class="card-body">
                    <div class="d-flex align-items-center justify-content-between mb-2">
                        <span class="text-gray-500 fs-8 fw-semibold text-uppercase">Domínios</span>
                        <i class="fa-solid fa-globe"></i>
                    </div>
                    <div class="fs-2 fw-bolder text-gray-800">{{ $client->domains->count() }}</div>
                    <div class="d-flex flex-wrap gap-1 mt-2">
                        @forelse ($client->domains as $domain)
                            <span class="badge badge-light text-gray-600">{{ $domain->domain }}</span>
                        @empty
                            <span class="badge badge-light text-gray-600">Sem domínio</span>
                        @endforelse
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="d-flex flex-wrap gap-2 mb-5">
        <button class="btn btn-sm btn-light-primary btn-sections active" data-show="resources">
            <i class="fa-solid fa-list-check me-1"></i>Plano Atual
        </button>
        <button class="btn btn-sm btn-light btn-sections" data-show="cards">
            <i class="fa-regular fa-credit-card me-1"></i>Cartões
        </button>
        <button class="btn btn-sm btn-light btn-sections" data-show="signatures">
            <i class="fa-solid fa-file-signature me-1"></i>Histórico de Planos
        </button>
        <button class="btn btn-sm btn-light btn-sections" data-show="orders">
            <i class="fa-solid fa-clock-rotate-left me-1"></i>Histórico de Compras
        </button>
        <button class="btn btn-sm btn-light btn-sections" data-show="api-data" id="btn-api-data">
            <i class="fa-solid fa-wave-square me-1"></i>Dados em Tempo Real
        </button>
    </div>

    <div class="row g-4">
        <div class="col-12">
            <div class="divs-sections div-resources">
                @include('pages.tenants._resources')
            </div>
            <div class="divs-sections div-cards" style="display: none;">
                @include('pages.tenants._cards')
            </div>
            <div class="divs-sections div-orders" style="display: none;">
                @include('pages.tenants._orders')
            </div>
            <div class="divs-sections div-signatures" style="display: none;">
                @include('pages.tenants._signatures')
            </div>
            <div class="divs-sections div-api-data" style="display: none;">
                <div id="api-data-container">
                    <div class="d-flex align-items-center gap-3 text-muted">
                        <span class="spinner-border spinner-border-sm d-none" id="api-data-loading" role="status" aria-hidden="true"></span>
                        <span>Clique em <b>Dados em Tempo Real</b> para consultar a API desta instalação.</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

@section('modals')
@parent
<div class="modal fade" tabindex="-1" id="modal-plan-manual-update">
    <div class="modal-dialog modal-dialog-centered modal-xl">
        <div class="modal-content">
            <form id="form-plan-manual-update">
                @csrf
                @method('PUT')
                <div class="modal-header">
                    <h3 class="modal-title">Atualização Manual do Plano</h3>
                    <div class="btn btn-icon btn-sm btn-active-light-primary ms-2" data-bs-dismiss="modal" aria-label="Close">
                        <i class="fa-solid fa-xmark"></i>
                    </div>
                </div>
                <div class="modal-body">

                    <div class="row g-4 mb-4">
                        <div class="col-12 col-md-4">
                            <label class="form-label fw-semibold required">Início</label>
                            <input type="date" class="form-control form-control-solid" name="start_date" required>
                        </div>
                        <div class="col-12 col-md-4">
                            <label class="form-label fw-semibold required">Fim</label>
                            <input type="date" class="form-control form-control-solid" name="end_date" required>
                        </div>
                        <div class="col-12 col-md-2">
                            <label class="form-label fw-semibold required">Usuários</label>
                            <input type="number" min="0" class="form-control form-control-solid" name="users_limit" required>
                        </div>
                        <div class="col-12 col-md-2">
                            <label class="form-label fw-semibold required">Armazenamento (GB)</label>
                            <input type="number" min="0" step="0.01" class="form-control form-control-solid" name="storage_limit_gb" required>
                        </div>
                    </div>

                    <div class="mb-4">
                        <div class="d-flex align-items-center justify-content-between mb-3">
                            <label class="form-label fw-semibold required mb-0">Módulos habilitados</label>
                            <span class="badge badge-light-primary" id="manual-modules-counter">0 selecionados</span>
                        </div>
                        <div class="row g-3" id="manual-modules-list">
                            <div class="col-12">
                                <div class="text-muted">Carregando módulos...</div>
                            </div>
                        </div>
                    </div>

                    <div class="mb-2">
                        <label class="form-label fw-semibold required">Justificativa da alteração manual</label>
                        <textarea class="form-control form-control-solid" name="manual_change_reason" rows="4" minlength="10" maxlength="2000" required placeholder="Descreva o motivo da alteração manual..."></textarea>
                    </div>

                    <div id="manual-plan-errors" class="alert alert-danger d-none mb-0"></div>
                    <div id="manual-plan-info" class="alert alert-warning d-none mb-0"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancelar</button>
                    <button type="button" class="btn btn-warning d-none btn-sync-manual-plan" id="btn-sync-manual-plan-modal">
                        <i class="fa-solid fa-rotate me-1"></i>Sincronizar novo plano
                    </button>
                    <button type="submit" class="btn btn-primary" id="btn-submit-manual-plan">Salvar alteração</button>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection

@section('custom-footer')
<script>
    $(document).ready(function(){
        /**
         * Define se os dados via API já foram carregados uma vez.
         */
        let hasLoadedApiData = false;
        const manualPlanModal = $('#modal-plan-manual-update');
        const manualPlanForm = $('#form-plan-manual-update');
        const manualModulesList = $('#manual-modules-list');
        const manualModulesCounter = $('#manual-modules-counter');
        const manualErrors = $('#manual-plan-errors');
        const manualInfo = $('#manual-plan-info');
        const manualSubmitButton = $('#btn-submit-manual-plan');
        const manualSyncButtons = $('.btn-sync-manual-plan');
        const manualPageSyncMessage = $('#manual-plan-sync-page-message');
        let isLoadingManualPlan = false;

        function refreshManualModulesCounter() {
            const selectedCount = manualModulesList.find('input[name="modules[]"]:checked').length;
            manualModulesCounter.text(selectedCount + ' selecionados');
        }

        function renderManualModules(modules) {
            if (!Array.isArray(modules) || modules.length === 0) {
                manualModulesList.html(
                    '<div class="col-12"><div class="alert alert-light-warning mb-0">Nenhum módulo ativo encontrado na central.</div></div>'
                );
                refreshManualModulesCounter();
                return;
            }

            const html = modules.map(function(module) {
                const checked = module.enabled ? 'checked' : '';
                return `
                    <div class="col-12 col-md-6 col-xl-4">
                        <label class="d-flex align-items-center p-3 rounded bg-light cursor-pointer mb-0">
                            <input type="checkbox" class="form-check-input me-3" name="modules[]" value="${module.id}" ${checked}>
                            <span class="fw-semibold text-gray-800">${module.name}</span>
                        </label>
                    </div>
                `;
            }).join('');

            manualModulesList.html(html);
            refreshManualModulesCounter();
        }

        function showManualPlanError(message) {
            manualInfo.addClass('d-none').text('');
            manualErrors.removeClass('d-none').text(message);
        }

        function clearManualPlanError() {
            manualErrors.addClass('d-none').text('');
        }

        function showManualPlanInfo(message) {
            manualErrors.addClass('d-none').text('');
            manualInfo.removeClass('d-none').text(message);
        }

        function showManualPlanSyncPending(message) {
            showManualPlanInfo(message);
            manualSubmitButton.addClass('d-none');
            $('#btn-sync-manual-plan-modal').removeClass('d-none');
            manualSyncButtons.removeClass('d-none');
            manualPageSyncMessage
                .removeClass('d-none alert-danger')
                .addClass('alert-warning')
                .html('<i class="fa-solid fa-circle-info me-2"></i>' + message);
        }

        function showManualPlanSyncFailure(message) {
            showManualPlanError(message);
            manualSyncButtons.removeClass('d-none');
            manualPageSyncMessage
                .removeClass('d-none alert-warning')
                .addClass('alert-danger')
                .html('<i class="fa-solid fa-triangle-exclamation me-2"></i>' + message);
        }

        $(document).on('change', '#manual-modules-list input[name="modules[]"]', function () {
            refreshManualModulesCounter();
        });

        $(document).on('click', '#btn-open-plan-manual-modal', function() {
            if (isLoadingManualPlan) {
                return;
            }

            isLoadingManualPlan = true;
            clearManualPlanError();
            manualInfo.addClass('d-none').text('');
            manualModulesList.html('<div class="col-12"><div class="text-muted">Carregando módulos...</div></div>');
            manualPlanForm.trigger('reset');
            manualSubmitButton.removeClass('d-none').prop('disabled', true);
            $('#btn-sync-manual-plan-modal').addClass('d-none');
            manualPlanModal.modal('show');

            $.ajax({
                type: 'GET',
                url: "{{ route('tenants.plan.manual.edit-data', $client->id) }}",
                success: function(response) {
                    if (!response.success) {
                        showManualPlanError(response.message || 'Não foi possível carregar dados do plano para edição.');
                        return;
                    }

                    const data = response.data || {};
                    manualPlanForm.find('input[name="start_date"]').val(data.start_date || '');
                    manualPlanForm.find('input[name="end_date"]').val(data.end_date || '');
                    manualPlanForm.find('input[name="users_limit"]').val(data.users_limit ?? 0);
                    manualPlanForm.find('input[name="storage_limit_gb"]').val(data.storage_limit_gb ?? 0);
                    renderManualModules(data.modules || []);
                },
                error: function() {
                    showManualPlanError('Não foi possível carregar dados do plano para edição.');
                },
                complete: function() {
                    isLoadingManualPlan = false;
                    manualSubmitButton.prop('disabled', false);
                }
            });
        });

        manualPlanForm.on('submit', function(e) {
            e.preventDefault();
            clearManualPlanError();
            manualSubmitButton.prop('disabled', true);

            $.ajax({
                type: 'POST',
                url: "{{ route('tenants.plan.manual.apply', $client->id) }}",
                data: manualPlanForm.serialize() + '&_method=PUT',
                success: function(response) {
                    if (!response.success) {
                        showManualPlanError(response.message || 'Não foi possível aplicar a atualização manual.');
                        return;
                    }

                    showManualPlanSyncPending(response.message || 'Plano salvo internamente. Sincronize o novo plano para aplicar no tenant.');
                },
                error: function(xhr) {
                    let message = 'Não foi possível aplicar a atualização manual.';
                    if (xhr.responseJSON && xhr.responseJSON.message) {
                        message = xhr.responseJSON.message;
                    }
                    showManualPlanError(message);
                },
                complete: function() {
                    manualSubmitButton.prop('disabled', false);
                }
            });
        });

        $(document).on('click', '.btn-sync-manual-plan', function() {
            clearManualPlanError();
            manualSyncButtons.prop('disabled', true);

            const syncData = manualPlanForm.serializeArray().filter(function(item) {
                return item.name !== '_method';
            });

            $.ajax({
                type: 'POST',
                url: "{{ route('tenants.plan.manual.sync', $client->id) }}",
                data: $.param(syncData),
                success: function(response) {
                    if (!response.success) {
                        showManualPlanSyncFailure(response.message || 'Não foi possível sincronizar o novo plano.');
                        return;
                    }

                    manualPlanModal.modal('hide');
                    window.location.reload();
                },
                error: function(xhr) {
                    let message = 'Não foi possível sincronizar o novo plano.';
                    if (xhr.responseJSON && xhr.responseJSON.message) {
                        message = xhr.responseJSON.message;
                    }
                    showManualPlanSyncFailure(message);
                },
                complete: function() {
                    manualSyncButtons.prop('disabled', false);
                }
            });
        });

        $(document).on('click', '.btn-sections', function(){
            $('.btn-sections').removeClass('btn-light-primary active').addClass('btn-light');
            $(this).removeClass('btn-light').addClass('btn-light-primary active');

            var section = $(this).data('show');
            
            $('.divs-sections').hide();

            $('.div-' + section).show();

        });

        /**
         * Carrega os dados em tempo real da instalação via API apenas sob demanda.
         * Após o primeiro carregamento com sucesso, reutiliza o HTML já renderizado.
         */
        $(document).on('click', '#btn-api-data', function() {
            if (hasLoadedApiData) {
                return;
            }

            const $loading = $('#api-data-loading');
            const $container = $('#api-data-container');

            $loading.removeClass('d-none');

            $.ajax({
                type: 'GET',
                url: "{{ route('tenants.api.data', $client->id) }}",
                success: function(response) {
                    $container.html(response.html);
                    hasLoadedApiData = true;
                },
                error: function() {
                    $container.html(
                        '<div class="alert alert-danger mb-0">Não foi possível consultar a API desta instalação agora.</div>'
                    );
                },
                complete: function() {
                    $loading.addClass('d-none');
                }
            });
        });
    });
</script>
@endsection
