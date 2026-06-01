@extends('layouts.app')

@section('title', 'Recursos Órfãos cPanel')

@section('content')
<div class="row g-6">
    <div class="col-12">
        <div class="card">
            <div class="card-header border-0 pt-6">
                <div class="card-title">
                    <div>
                        <h3 class="fw-bold m-0">Recursos Órfãos cPanel</h3>
                        <div class="text-gray-600 fs-7 mt-1">
                            Compara cPanel com a Central e aponta domínios, bancos e usuários MySQL sem vínculo local.
                        </div>
                    </div>
                </div>
                <div class="card-toolbar">
                    <a href="{{ route('system.settings.provisioning.orphans') }}" class="btn btn-sm btn-light-primary">
                        <i class="fa-solid fa-rotate-right me-1"></i>
                        Verificar novamente
                    </a>
                </div>
            </div>
            <div class="card-body">
                @if (session('message'))
                    <div class="alert alert-success d-flex align-items-start p-5 mb-6">
                        <i class="fa-solid fa-circle-check fs-2 text-success me-4 mt-1"></i>
                        <div class="text-gray-700">{!! session('message') !!}</div>
                    </div>
                @endif

                @if (session('error'))
                    <div class="alert alert-danger d-flex align-items-start p-5 mb-6">
                        <i class="fa-solid fa-triangle-exclamation fs-2 text-danger me-4 mt-1"></i>
                        <div class="text-gray-700">{{ session('error') }}</div>
                    </div>
                @endif

                @if (!empty($diagnostic['errors']))
                    <div class="alert alert-warning d-flex align-items-start p-5 mb-6">
                        <i class="fa-solid fa-circle-exclamation fs-2 text-warning me-4 mt-1"></i>
                        <div>
                            <div class="fw-bold text-gray-800 mb-2">Algumas consultas falharam</div>
                            @foreach ($diagnostic['errors'] as $error)
                                <div class="text-gray-700">
                                    {{ $error['label'] }}: {{ $error['message'] }}
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif

                @if (!empty($removalResults))
                    <div class="table-responsive mb-8">
                        <table class="table align-middle table-row-dashed gy-3">
                            <thead>
                                <tr class="text-start text-gray-500 fw-bold fs-7 text-uppercase gs-0">
                                    <th>Resultado</th>
                                    <th>Tipo</th>
                                    <th>Recurso</th>
                                    <th>Mensagem</th>
                                </tr>
                            </thead>
                            <tbody class="fw-semibold text-gray-700">
                                @foreach ($removalResults as $result)
                                    <tr>
                                        <td>
                                            @if ($result['status'] === 'removed')
                                                <span class="badge badge-light-success">Removido</span>
                                            @elseif ($result['status'] === 'ignored')
                                                <span class="badge badge-light-warning">Ignorado</span>
                                            @else
                                                <span class="badge badge-light-danger">Falhou</span>
                                            @endif
                                        </td>
                                        <td>{{ $result['label'] }}</td>
                                        <td class="text-gray-900">{{ $result['name'] }}</td>
                                        <td>{{ $result['message'] }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif

                <div class="row g-4 mb-8">
                    <div class="col-12 col-md-3">
                        <div class="border border-dashed border-gray-300 rounded p-4">
                            <div class="text-gray-600 fs-7">Total</div>
                            <div class="fs-2 fw-bold text-gray-900">{{ $diagnostic['summary']['total'] }}</div>
                        </div>
                    </div>
                    <div class="col-12 col-md-3">
                        <div class="border border-dashed border-gray-300 rounded p-4">
                            <div class="text-gray-600 fs-7">Domínios</div>
                            <div class="fs-2 fw-bold text-gray-900">{{ $diagnostic['summary']['domains'] }}</div>
                        </div>
                    </div>
                    <div class="col-12 col-md-3">
                        <div class="border border-dashed border-gray-300 rounded p-4">
                            <div class="text-gray-600 fs-7">Bancos</div>
                            <div class="fs-2 fw-bold text-gray-900">{{ $diagnostic['summary']['databases'] }}</div>
                        </div>
                    </div>
                    <div class="col-12 col-md-3">
                        <div class="border border-dashed border-gray-300 rounded p-4">
                            <div class="text-gray-600 fs-7">Usuários MySQL</div>
                            <div class="fs-2 fw-bold text-gray-900">{{ $diagnostic['summary']['database_users'] }}</div>
                        </div>
                    </div>
                </div>

                @if ($diagnostic['summary']['total'] > 0)
                    <div class="alert alert-light-danger p-5 mb-8">
                        <div class="fw-bold text-gray-800 mb-2">Remoção em lote</div>
                        <div class="text-gray-700 mb-4">
                            A remoção revalida cada item antes de apagar. Digite <code>{{ $diagnostic['batch_confirmation'] }}</code> para processar todos os órfãos listados.
                        </div>
                        <form method="POST" action="{{ route('system.settings.provisioning.orphans.remove-batch') }}" class="d-flex flex-column flex-md-row gap-3">
                            @csrf
                            <input type="text" name="confirmation" class="form-control form-control-solid" placeholder="{{ $diagnostic['batch_confirmation'] }}" required>
                            <button type="submit" class="btn btn-danger">
                                <i class="fa-solid fa-trash-can me-1"></i>
                                Remover órfãos
                            </button>
                        </form>
                    </div>
                @endif

                @foreach ($diagnostic['orphans'] as $type => $resources)
                    <div class="mb-8">
                        <div class="d-flex align-items-center justify-content-between mb-3">
                            <h4 class="fw-bold text-gray-800 m-0">{{ $diagnostic['labels'][$type] }}</h4>
                            <span class="badge badge-light-primary">{{ count($resources) }}</span>
                        </div>

                        @if (empty($resources))
                            <div class="alert alert-light-success p-4">
                                Nenhum recurso órfão encontrado neste grupo.
                            </div>
                        @else
                            <div class="table-responsive">
                                <table class="table align-middle table-row-dashed gy-4">
                                    <thead>
                                        <tr class="text-start text-gray-500 fw-bold fs-7 text-uppercase gs-0">
                                            <th>Recurso</th>
                                            <th>Origem</th>
                                            <th>Motivo</th>
                                            <th>Risco</th>
                                            <th class="text-end">Ação</th>
                                        </tr>
                                    </thead>
                                    <tbody class="fw-semibold text-gray-700">
                                        @foreach ($resources as $resource)
                                            <tr>
                                                <td class="text-gray-900">{{ $resource['name'] }}</td>
                                                <td>{{ $resource['source'] }}</td>
                                                <td>{{ $resource['reason'] }}</td>
                                                <td>{{ $resource['risk'] }}</td>
                                                <td class="text-end">
                                                    <form method="POST" action="{{ route('system.settings.provisioning.orphans.remove') }}" class="d-flex justify-content-end gap-2">
                                                        @csrf
                                                        <input type="hidden" name="type" value="{{ $type }}">
                                                        <input type="hidden" name="name" value="{{ $resource['name'] }}">
                                                        <input type="text" name="confirmation" class="form-control form-control-sm form-control-solid w-200px" placeholder="{{ $resource['name'] }}" required>
                                                        <button type="submit" class="btn btn-sm btn-light-danger">
                                                            <i class="fa-solid fa-trash-can me-1"></i>
                                                            Remover
                                                        </button>
                                                    </form>
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>
    </div>
</div>
@endsection
