@extends('layouts.app')

@section('title', 'Painel')

@section('content')
<div class="card">
    <div class="card-body">
        <div class="d-flex flex-stack flex-wrap mb-5">
            <div class="d-flex align-items-center position-relative my-1 mb-2 mb-md-0">
                <i class="ki-duotone ki-magnifier fs-1 position-absolute ms-6"><span class="path1"></span><span class="path2"></span></i>
                <input type="text" data-kt-docs-table-filter="search" class="form-control form-control-solid w-250px ps-15" placeholder="Procurar tenants">
            </div>
            <div class="d-flex justify-content-end" data-kt-docs-table-toolbar="base">
                <a href="{{ route('tenants.domains.index') }}" class="btn btn-light me-3">
                    <i class="fa-solid fa-globe me-1"></i> Domínios
                </a>
                <button type="button" class="btn btn-light-primary me-3" data-kt-menu-trigger="click" data-kt-menu-placement="bottom-end">
                    <i class="ki-duotone ki-filter fs-2"><span class="path1"></span><span class="path2"></span></i>        Filtrar
                </button>
                <div class="menu menu-sub menu-sub-dropdown w-300px w-md-325px" data-kt-menu="true">
                    <form action="" id="tenants-filters">
                        <div class="px-7 py-5">
                            <div class="fs-4 text-gray-900 fw-bold">Filtros</div>
                        </div>
                        <div class="separator border-gray-200"></div>
                        <div class="px-7 py-5">
                            <div class="mb-10">
                                <label class="form-label fs-5 fw-semibold mb-3">Tipo de Instalação:</label>
                                <div class="d-flex flex-column flex-wrap fw-semibold" data-kt-docs-table-filter="payment_type">
                                    <label class="form-check form-check-sm form-check-custom form-check-solid mb-3 me-5">
                                        <input class="form-check-input" type="radio" name="payment_type" value="all" checked="checked">
                                        <span class="form-check-label text-gray-600">
                                            Todos
                                        </span>
                                    </label>

                                    <label class="form-check form-check-sm form-check-custom form-check-solid mb-3 me-5">
                                        <input class="form-check-input" type="radio" name="payment_type" value="shared">
                                        <span class="form-check-label text-gray-600">
                                            Compartilhada
                                        </span>
                                    </label>

                                    <label class="form-check form-check-sm form-check-custom form-check-solid mb-3">
                                        <input class="form-check-input" type="radio" name="payment_type" value="dedicated">
                                        <span class="form-check-label text-gray-600">
                                            Dedicada
                                        </span>
                                    </label>
                                </div>
                            </div>
                            <div class="mb-10">
                                <label class="form-label fs-5 fw-semibold mb-3">Status:</label>
                                <div class="d-flex flex-column flex-wrap fw-semibold" data-kt-docs-table-filter="status_filter">

                                    <label class="form-check form-check-sm form-check-custom form-check-solid mb-3 me-5">
                                        <input class="form-check-input" type="radio" name="status_filter" value="1" checked>
                                        <span class="form-check-label text-gray-600">
                                            Ativos
                                        </span>
                                    </label>

                                    <label class="form-check form-check-sm form-check-custom form-check-solid mb-3">
                                        <input class="form-check-input" type="radio" name="status_filter" value="0">
                                        <span class="form-check-label text-gray-600">
                                            Inativos
                                        </span>
                                    </label>
                                </div>
                            </div>
                            <div class="d-flex justify-content-end">
                                <button type="reset" class="btn btn-light btn-active-light-primary me-2" data-kt-menu-dismiss="true" data-kt-docs-table-filter="reset">Reset</button>
                                <button type="submit" class="btn btn-primary" data-kt-menu-dismiss="true" data-kt-docs-table-filter="filter">Aplicar</button>
                            </div>
                        </div>
                    </form>
                </div>
                <a href="{{ route('tenants.create') }}" type="button" class="btn btn-primary" data-bs-toggle="tooltip" data-bs-original-title="Coming Soon" data-kt-initialized="1">
                    <i class="ki-duotone ki-plus fs-2"></i> Criar Sistema
                </a>
            </div>
        </div>
        <table id="datatables-tenants" class="table table-striped table-row-bordered gy-2 gs-7 align-middle">
            <thead class="rounded">
                <tr class="fw-bold fs-6 text-gray-700 px-7">
                    <th class="">ID</th>
                    <th class="">Nome do Cliente</th>
                    <th class="">Tipo de Instalação</th>
                    <th class="">Expira em</th>
                    <th class="text-center px-0">DB</th>
                    <th class="text-center px-0">GIT</th>
                    <th class="text-center px-0">SP</th>
                    <th class="text-center px-0">JS</th>
                    <th class="text-center px-0">Status</th>
                    <th class="text-end pe-12 w-100px">Ações</th>
                </tr>
            </thead>
            <tbody></tbody>
        </table>
    </div>
</div>
@endsection

@section('modals')
<div class="modal fade" tabindex="-1" id="modal_tenant_status_error">
    <div class="modal-dialog modal-xl modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Erro da ação</h3>
                <div class="btn btn-icon btn-sm btn-active-light-primary ms-2" data-bs-dismiss="modal" aria-label="Close">
                    <i class="fa-solid fa-xmark"></i>
                </div>
            </div>
            <div class="modal-body">
                <pre id="tenant-status-error-content" class="bg-light text-gray-800 rounded p-5 mb-0" style="white-space: pre-wrap; word-break: break-word; max-height: 65vh; overflow: auto;"></pre>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" tabindex="-1" id="modal_tenant_run_task">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form id="form-tenant-run-task" method="GET" action="">
                <div class="modal-header">
                    <h3 class="modal-title">Executar Tarefa</h3>
                    <div class="btn btn-icon btn-sm btn-active-light-primary ms-2" data-bs-dismiss="modal" aria-label="Close">
                        <i class="fa-solid fa-xmark"></i>
                    </div>
                </div>
                <div class="modal-body">
                    <div class="text-gray-700 mb-5">
                        Escolha qual tarefa deseja executar para o tenant
                        <span class="fw-bold" id="selected-tenant-name">-</span>.
                    </div>

                    <div class="mb-4">
                        <label for="scheduled-job-select" class="form-label fw-semibold">Tarefa</label>
                        <select
                            name="job"
                            id="scheduled-job-select"
                            class="form-select form-select-solid"
                            data-control="select2"
                            data-dropdown-parent="#modal_tenant_run_task"
                            data-placeholder="Selecione uma tarefa"
                        >
                            <option value="all">Todas as tarefas</option>
                            <option value="finish_calls_24h">Finalizar chamadas com 24h</option>
                            <option value="finish_order_access">Finalizar acessos de pedidos</option>
                            <option value="update_s3_metrics">Atualizar métricas do S3</option>
                            <option value="archive_finished_tasks">Arquivar tarefas concluídas</option>
                            <option value="import_cfop_table">Importar CFOPs</option>
                            <option value="import_ncm_table">Importar NCMs</option>
                            <option value="test_log">Registrar log de teste</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary">Executar</button>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection

@section('custom-footer')
<script>
    /**
     * Seleciona a tabela
     */
    const table = $('#datatables-tenants');

    /**
     * Configurações da tabela
     */
    const dataTableOptions = {
        serverSide: true,
        processing: true,
        ajax: {
            url: '{{ route("tenants.process") }}',
            data: function (data) {
                data.searchBy       = data.search.value;
                data.order_by       = data.columns[data.order[0].column].data;
                data.per_page       = data.length;
                data.payment_type   = $('input[name="payment_type"]:checked').val();
                data.status_filter  = $('input[name="status_filter"]:checked').val();
            },
        },
        buttons: false,
        searching: true,
        order: [[2, 'asc']],
        pageLength: 25,
        columns: [
            { targets: 0, data: "id" },
            { targets: 1, data: "name" },
            { targets: 2, data: "type" },
            { targets: 3, data: "expires_at" },
            { targets: 4, data: "bank" },
            { targets: 5, data: "git" },
            { targets: 6, data: "sp" },
            { targets: 7, data: "js" },
            { targets: 8, data: "status" },
            { targets: 9, data: "actions", orderable: false },
        ],
        language: {
            "search": "Pesquisar:",
            "zeroRecords": "Ops, não encontramos nenhum resultado :(",
            "info": "Mostrando _START_ até _END_ de _TOTAL_ registros",
            "infoEmpty": "Nenhum registro disponível",
            "infoFiltered": "(Filtrando _MAX_ registros)",
            "processing": "Carregando dados...",
            "paginate": {
                "previous": "Anterior",
                "next": "Próximo",
                "first": '<i class="fa-solid fa-angles-left text-gray-300 text-hover-primary cursor-pointer"></i>',
                "last": '<i class="fa-solid fa-angles-right text-gray-300 text-hover-primary cursor-pointer"></i>',
            }
        },
        columnDefs: [
            {   
                targets: 4,
                className: 'text-center',
            },
            {   
                targets: 5,
                className: 'text-center',
            },
            {   
                targets: 6,
                className: 'text-center',
            },
            {   
                targets: 7,
                className: 'text-center',
            },
            {   
                targets: 8,
                className: 'text-center',
            },
            {   
                targets: 9,
                className: 'text-end',
            },
        ],
        pagingType: 'simple_numbers',
        drawCallback: function () {
            /**
             * Inicializa os menus após cada renderização da tabela
             */
            KTMenu.createInstances();
        },
    };

    /**
     * Renderiza tabela
     */
    const dataTable = table.DataTable(dataTableOptions);
    let tenantsAutoReloadIntervalId = null;
    let tenantsAutoReloadTimeoutId = null;

    /**
     * Encerra qualquer ciclo de auto-reload ativo da listagem.
     */
    function stopTenantsAutoReloadCycle() {
        /**
         * Limpa intervalo periódico, quando existente.
         */
        if (tenantsAutoReloadIntervalId !== null) {
            clearInterval(tenantsAutoReloadIntervalId);
            tenantsAutoReloadIntervalId = null;
        }

        /**
         * Limpa timeout finalizador, quando existente.
         */
        if (tenantsAutoReloadTimeoutId !== null) {
            clearTimeout(tenantsAutoReloadTimeoutId);
            tenantsAutoReloadTimeoutId = null;
        }
    }

    /**
     * Inicia ciclo de recarga automática:
     * - recarrega imediatamente
     * - recarrega a cada 3 segundos
     * - encerra após 30 segundos
     */
    function startTenantsAutoReloadCycle() {
        stopTenantsAutoReloadCycle();
        dataTable.ajax.reload(null, false);

        tenantsAutoReloadIntervalId = setInterval(function () {
            dataTable.ajax.reload(null, false);
        }, 3000);

        tenantsAutoReloadTimeoutId = setTimeout(function () {
            stopTenantsAutoReloadCycle();
        }, 30000);
    }

    /**
     * Escuta início de atualização global para ligar auto-reload local.
     */
    window.addEventListener('systems:update-started', function () {
        startTenantsAutoReloadCycle();
    });

    /**
     * Garante limpeza de timers ao sair da página.
     */
    window.addEventListener('beforeunload', function () {
        stopTenantsAutoReloadCycle();
    });

    /**
     * Inicializa select2 do modal de tarefas.
     */
    $('#scheduled-job-select').select2({
        dropdownParent: $('#modal_tenant_run_task'),
        width: '100%',
    });

    /**
     * Busca pelo campo no topo
     */
    $('[data-kt-docs-table-filter="search"]').on('keyup', function () {
        dataTable.search($(this).val()).draw();
    });

    /**
     * Filtra a tabela
     */
    $('#tenants-filters').on('submit', function(e) {
        e.preventDefault();
        dataTable.ajax.reload();
    });

    /**
     * Reseta filtros do menu
     */
    $('#tenants-filters').on('reset', function() {
        setTimeout(() => dataTable.ajax.reload(), 0);
    });

    /**
     * Abre o modal para escolher qual tarefa executar no tenant.
     */
    $(document).on('click', '.js-tenant-run-task-modal', function (e) {
        e.preventDefault();

        const trigger = $(this);
        const tenantName = trigger.data('tenant-name') || 'tenant';
        const actionUrl = trigger.attr('href');

        /**
         * Atualiza o formulário do modal com o tenant selecionado.
         */
        $('#selected-tenant-name').text(tenantName);
        $('#form-tenant-run-task').attr('action', actionUrl);
        $('#scheduled-job-select').val('all').trigger('change');

        $('#modal_tenant_run_task').modal('show');
    });

    function buildTenantStatusIcon(isSuccess, title, isProcessing) {
        const iconClass = isSuccess ? 'fa-circle-check text-success' : 'fa-circle-xmark text-danger';
        const processingClass = isProcessing ? 'tenant-status-processing' : '';
        const safeTitle = escapeTenantStatusTitle(title);
        const errorAttributes = statusErrorAttributes(isSuccess, isProcessing, safeTitle);

        return `<i class="fa-solid ${iconClass} ${processingClass}${errorAttributes.className}" data-bs-toggle="tooltip" data-bs-placement="top" title="${safeTitle}"${errorAttributes.content}></i>`;
    }

    function escapeTenantStatusTitle(title) {
        /**
         * O erro real pode vir com aspas, tags ou saída de terminal.
         * Converter para texto antes de montar o HTML preserva o tooltip.
         */
        return $('<div>').text(title || 'Erro desconhecido').html();
    }

    function statusErrorAttributes(isSuccess, isProcessing, safeTitle) {
        /**
         * Apenas erro final abre modal; sucesso e processamento mantêm só tooltip.
         */
        if (isSuccess || isProcessing) {
            return {
                className: '',
                content: '',
            };
        }

        return {
            className: ' cursor-pointer js-tenant-error-detail',
            content: ` data-error-content="${safeTitle}"`,
        };
    }

    $(document).on('click', '.js-tenant-error-detail', function () {
        const errorContent = $(this).attr('data-error-content') || $(this).attr('title') || 'Erro desconhecido';

        $('#tenant-status-error-content').text(errorContent);

        const modalElement = document.getElementById('modal_tenant_status_error');
        const modal = bootstrap.Modal.getOrCreateInstance(modalElement);
        modal.show();
    });

    function ajaxErrorContent(xhr) {
        /**
         * Prioriza o corpo bruto para permitir ver exatamente o retorno da central.
         */
        if (xhr.responseText) {
            return xhr.responseText;
        }

        if (xhr.responseJSON && xhr.responseJSON.message) {
            return xhr.responseJSON.message;
        }

        return 'Erro desconhecido';
    }

    function openCentralErrorTab(title, content) {
        const tab = window.open('', '_blank');

        /**
         * Se o navegador bloquear popup, usa o modal local como fallback legível.
         */
        if (!tab) {
            $('#tenant-status-error-content').text(content);
            const modalElement = document.getElementById('modal_tenant_status_error');
            const modal = bootstrap.Modal.getOrCreateInstance(modalElement);
            modal.show();
            return;
        }

        tab.document.write('<!doctype html><html lang="pt-BR"><head><title>Erro da Central</title><style>body{font-family:Arial,sans-serif;margin:24px;background:#f5f5f5;color:#1f2937}pre{white-space:pre-wrap;word-break:break-word;background:#fff;border:1px solid #ddd;border-radius:8px;padding:16px}</style></head><body><h1></h1><pre></pre></body></html>');
        tab.document.close();
        tab.document.querySelector('h1').textContent = title;
        tab.document.querySelector('pre').textContent = content;
    }

    function columnIndexByActionType(actionType) {
        /**
         * Mapeia cada ação para a coluna visual correspondente.
         * A tabela começa em ID, por isso DB/GIT/SP/JS ficam nas posições 4/5/6/7.
         */
        if (actionType === 'db') {
            return 4;
        }

        if (actionType === 'git') {
            return 5;
        }

        if (actionType === 'sp') {
            return 6;
        }

        if (actionType === 'js') {
            return 7;
        }

        return null;
    }

    function tooltipTitleForAction(actionType, isSuccess, errorMessage) {

        if (actionType === 'db') {
            return isSuccess ? 'Banco de dados atualizado' : errorMessage;
        }

        if (actionType === 'git') {
            return isSuccess ? 'Git atualizado' : errorMessage;
        }

        if (actionType === 'sp') {
            return isSuccess ? 'Supervisor atualizado' : errorMessage;
        }

        if (actionType === 'js') {
            return isSuccess ? 'Javascript atualizado' : errorMessage;
        }

        return errorMessage;
    }

    function refreshBootstrapTooltips() {
        /**
         * Sem bootstrap carregado não há tooltips para reinstanciar.
         */
        if (!window.bootstrap || !bootstrap.Tooltip) {
            return;
        }

        document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(function (element) {
            bootstrap.Tooltip.getOrCreateInstance(element);
        });
    }

    function updateTenantStatusCell(rowElement, actionType, isSuccess, title, isProcessing) {
        /**
         * Sem linha de tabela resolvida não existe célula para atualizar.
         */
        if (!rowElement || rowElement.length === 0) {
            return;
        }

        const columnIndex = columnIndexByActionType(actionType);

        /**
         * Ignora tipos de ação sem mapeamento de coluna.
         */
        if (columnIndex === null) {
            return;
        }

        const cell = rowElement.find('td').eq(columnIndex);
        cell.html(buildTenantStatusIcon(isSuccess, title, isProcessing));
    }

    function findRowElementByTenantId(tenantId) {
        /**
         * Sem tenant id não consegue localizar a linha alvo.
         */
        if (!tenantId) {
            return $();
        }

        let foundRow = $();

        dataTable.rows({ page: 'current' }).every(function () {
            const rowData = this.data();

            if (String(rowData.id) === String(tenantId)) {
                foundRow = $(this.node());
            }
        });

        return foundRow;
    }

    function isGroupedSharedAction(actionType, installationType) {
        /**
         * Banco continua individual porque depende do contexto de cada tenant.
         */
        if (installationType !== 'shared') {
            return false;
        }

        return actionType === 'git' || actionType === 'sp' || actionType === 'js';
    }

    function findVisibleSharedRowElements() {
        const rows = [];

        dataTable.rows({ page: 'current' }).every(function () {
            const rowElement = $(this.node());
            const hasSharedAction = rowElement.find('.js-tenant-action-confirm[data-tenant-installation-type="shared"]').length > 0;

            /**
             * Só atualiza linhas compartilhadas que estão visíveis na página atual.
             */
            if (hasSharedAction) {
                rows.push(this.node());
            }
        });

        return $(rows);
    }

    function updateTenantRowsStatusCell(rowElements, actionType, isSuccess, title, isProcessing) {
        rowElements.each(function () {
            updateTenantStatusCell($(this), actionType, isSuccess, title, isProcessing);
        });
    }

    function updateTenantRowsActionFromSnapshot(rowElements, actionType, status) {
        /**
         * Sem snapshot de status não existe payload para refletir na linha.
         */
        if (!status) {
            return;
        }

        const successByAction = {
            db: status.db_last_version === 1,
            git: status.git_last_version === 1,
            sp: status.sp_last_version === 1,
            js: status.js_last_version === 1,
        };

        const errorByAction = {
            db: status.db_error,
            git: status.git_error,
            sp: status.sp_error,
            js: status.js_error,
        };

        const isSuccess = successByAction[actionType] === true;
        const title = tooltipTitleForAction(actionType, isSuccess, errorByAction[actionType]);

        updateTenantRowsStatusCell(rowElements, actionType, isSuccess, title, false);
    }

    function applyStatusSnapshotToRow(rowElement, status) {
        /**
         * Sem snapshot de status não existe payload para refletir na linha.
         */
        if (!status) {
            return;
        }

        const dbSuccess = status.db_last_version === 1;
        const gitSuccess = status.git_last_version === 1;
        const spSuccess = status.sp_last_version === 1;
        const jsSuccess = status.js_last_version === 1;

        updateTenantStatusCell(rowElement, 'db', dbSuccess, tooltipTitleForAction('db', dbSuccess, status.db_error), false);
        updateTenantStatusCell(rowElement, 'git', gitSuccess, tooltipTitleForAction('git', gitSuccess, status.git_error), false);
        updateTenantStatusCell(rowElement, 'sp', spSuccess, tooltipTitleForAction('sp', spSuccess, status.sp_error), false);
        updateTenantStatusCell(rowElement, 'js', jsSuccess, tooltipTitleForAction('js', jsSuccess, status.js_error), false);
    }

    /**
     * Executa ações do menu do tenant via AJAX.
     */
    $(document).on('click', '.js-tenant-action-confirm', function (e) {
        e.preventDefault();

        const trigger = $(this);
        const tenantName = trigger.data('tenant-name');
        const actionLabel = trigger.data('action-label');
        const actionType = trigger.data('action-type');
        const installationType = trigger.data('tenant-installation-type');
        const shouldProceed = window.confirm(`Deseja mesmo ${actionLabel} no tenant ${tenantName}?`);

        /**
         * Usuário cancelou a confirmação, fluxo interrompido.
         */
        if (!shouldProceed) {
            return;
        }

        const url = trigger.attr('href');
        let rowElement = trigger.closest('tr');
        const tenantId = trigger.data('tenant-id');

        /**
         * Fallback para localizar linha quando o elemento clicado está fora do
         * contexto direto do <tr> por causa do menu dropdown.
         */
        if (!rowElement || rowElement.length === 0 || rowElement.find('td').length === 0) {
            rowElement = findRowElementByTenantId(tenantId);
        }
        const processingTitle = 'Processando ação...';
        const shouldUpdateSharedRows = isGroupedSharedAction(actionType, installationType);
        const targetRows = shouldUpdateSharedRows ? findVisibleSharedRowElements() : rowElement;

        updateTenantRowsStatusCell(targetRows, actionType, false, processingTitle, true);
        refreshBootstrapTooltips();

        $.ajax({
            url: url,
            method: 'GET',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json',
            },
            success: function (response) {
                toastr.success(response.message);

                if (response.shared_status_updated && response.shared_action_type === actionType) {
                    updateTenantRowsActionFromSnapshot(targetRows, actionType, response.status);
                    refreshBootstrapTooltips();
                    return;
                }

                applyStatusSnapshotToRow(rowElement, response.status);
                refreshBootstrapTooltips();
            },
            error: function (xhr) {
                const message = ajaxErrorContent(xhr);

                updateTenantRowsStatusCell(targetRows, actionType, false, message, false);
                refreshBootstrapTooltips();
                openCentralErrorTab('Erro ao executar ação no tenant', message);
            }
        });
    });

    /**
     * Ativa/desativa tenant via AJAX na listagem
     */
    $(document).on('click', '.js-tenant-toggle-status', function (e) {
        e.preventDefault();

        const trigger = $(this);
        const tenantName = trigger.data('tenant-name');
        const actionLabel = trigger.data('action-label');
        const shouldProceed = window.confirm(`Deseja mesmo ${actionLabel} no tenant ${tenantName}?`);

        /**
         * Usuário cancelou a confirmação, fluxo interrompido.
         */
        if (!shouldProceed) {
            return;
        }

        const url = trigger.attr('href');

        $.ajax({
            url: url,
            method: 'GET',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
            },
            success: function (response) {
                toastr.success(response.message);
                dataTable.ajax.reload(null, false);
            },
            error: function (xhr) {
                openCentralErrorTab('Erro ao alterar status do tenant', ajaxErrorContent(xhr));
            }
        });
    });

</script>
@endsection
