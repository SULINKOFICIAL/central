<div class="card mb-4" id="tenant-enabled-modules-card">
    <div class="card-header border-bottom border-gray-200 py-5">
        <div class="d-flex flex-wrap align-items-center justify-content-between w-100 gap-3">
            <div>
                <h3 class="card-title fw-bolder text-gray-800 mb-1">Módulos habilitados</h3>
                <div class="text-gray-600 fs-6">
                    <span class="fw-bolder" id="tenant-enabled-modules-counter">{{ $enabledModulesTotal }}</span> de {{ $totalModulesCount }} módulos ativos no sistema
                </div>
            </div>
        </div>
    </div>

    <div class="card-body">
        @if ($modules->isNotEmpty())
            <div class="row g-3">
                @foreach ($modules as $module)
                    <div class="col-12 col-md-6 col-xl-4 col-xxl-3">
                        <div class="rounded px-3 py-2 h-100 bg-light d-flex align-items-center">
                            <span class="symbol symbol-30px me-3">
                                <span class="symbol-label bg-light-success text-success">
                                    <i class="fa-solid fa-check text-success fs-6"></i>
                                </span>
                            </span>
                            <span class="fw-semibold text-gray-800">{{ $module->name }}</span>
                        </div>
                    </div>
                @endforeach
            </div>
        @else
            <div class="rounded-3 border border-dashed border-warning bg-light-warning p-6 mb-0">
                <div class="d-flex align-items-center mb-2">
                    <span class="symbol symbol-35px me-3">
                        <span class="symbol-label bg-warning">
                            <i class="fa-solid fa-circle-info text-white fs-6"></i>
                        </span>
                    </span>
                    <span class="fw-bolder text-gray-800 fs-6">Nenhum módulo habilitado</span>
                </div>
                <p class="text-gray-700 fs-7 mb-0">
                    Este cliente está sem módulos ativos no plano atual. Use o botão <b>Atualizar Plano do Cliente</b> para liberar os módulos desejados.
                </p>
            </div>
        @endif
    </div>
</div>

@if (app()->environment(['local', 'testing']))
    <div class="card mb-4">
        <div class="card-header border-bottom border-gray-200 py-5">
            <div class="d-flex flex-wrap align-items-center justify-content-between w-100 gap-3">
                <div>
                    <h3 class="card-title fw-bolder text-gray-800 mb-1">Manutenção técnica</h3>
                    <div class="text-gray-600 fs-6">
                        Ações destrutivas para reset operacional em ambiente local ou de testes.
                    </div>
                </div>
                <a href="{{ route('systems.reset.plan', $client->id) }}"
                   class="btn btn-sm btn-light-danger"
                   onclick="return confirm('Tem certeza que deseja resetar plano, assinaturas e pedidos desta instalação?');">
                    <i class="fa-solid fa-box-open me-1"></i>Resetar Plano e Histórico
                </a>
            </div>
        </div>
        <div class="card-body">
            <div class="alert alert-light-danger mb-0">
                Esta ação remove planos, assinaturas, ciclos, pedidos e transações do cliente no core_business.
            </div>
        </div>
    </div>
@endif
