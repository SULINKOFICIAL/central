@extends('layouts.app')

@section('title', 'Filas da Central')

@section('content')
<div class="row g-6">
    <div class="col-12 col-xl-8">
        <div class="card">
            <div class="card-header border-0 pt-6">
                <div class="card-title">
                    <h3 class="fw-bold m-0">Filas da Central</h3>
                </div>
                <div class="card-toolbar d-flex gap-2">
                    <a href="{{ route('system.settings.central.queues') }}" class="btn btn-sm btn-light-primary">
                        <i class="fa-solid fa-rotate-right me-1"></i>
                        Atualizar status
                    </a>
                    <form method="POST" action="{{ route('system.settings.central.queues.restart') }}">
                        @csrf
                        <button type="submit" class="btn btn-sm btn-primary">
                            <i class="fa-solid fa-arrows-rotate me-1"></i>
                            Reiniciar filas
                        </button>
                    </form>
                </div>
            </div>
            <div class="card-body">
                @if (($supervisorStatus['success'] ?? false) === true)
                    <div class="alert alert-success d-flex align-items-start p-5 mb-6">
                        <i class="fa-solid fa-circle-check fs-2hx text-success me-4 mt-1"></i>
                        <div class="d-flex flex-column">
                            <span class="fw-bold text-gray-800 mb-1">Supervisor respondeu com sucesso</span>
                            <span class="text-gray-700">As filas abaixo refletem o status atual do grupo central-worker.</span>
                        </div>
                    </div>
                @else
                    <div class="alert alert-danger d-flex align-items-start p-5 mb-6">
                        <i class="fa-solid fa-triangle-exclamation fs-2hx text-danger me-4 mt-1"></i>
                        <div class="d-flex flex-column">
                            <span class="fw-bold text-gray-800 mb-1">Falha ao consultar Supervisor</span>
                            <span class="text-gray-700">{{ $supervisorStatus['error'] ?: $supervisorStatus['output'] ?: 'Erro desconhecido.' }}</span>
                        </div>
                    </div>
                @endif

                <div class="table-responsive">
                    <table class="table align-middle table-row-dashed gy-4">
                        <thead>
                            <tr class="text-start text-gray-500 fw-bold fs-7 text-uppercase gs-0">
                                <th>Processo</th>
                                <th>Status</th>
                                <th>Detalhe</th>
                            </tr>
                        </thead>
                        <tbody class="fw-semibold text-gray-700">
                            @forelse (($supervisorStatus['processes'] ?? []) as $process)
                                <tr>
                                    <td class="text-gray-900">{{ $process['name'] }}</td>
                                    <td>
                                        @if ($process['status'] === 'RUNNING')
                                            <span class="badge badge-light-success">
                                                <i class="fa-solid fa-check text-success me-1"></i>
                                                {{ $process['status'] }}
                                            </span>
                                        @else
                                            <span class="badge badge-light-danger">
                                                <i class="fa-solid fa-xmark text-danger me-1"></i>
                                                {{ $process['status'] }}
                                            </span>
                                        @endif
                                    </td>
                                    <td>{{ $process['detail'] }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="3" class="text-center text-gray-500 py-8">
                                        Nenhum processo retornado pelo Supervisor.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <div class="col-12 col-xl-4">
        <div class="card">
            <div class="card-header border-0 pt-6">
                <div class="card-title">
                    <h3 class="fw-bold m-0">Retorno bruto</h3>
                </div>
            </div>
            <div class="card-body">
                <div class="mb-4">
                    <div class="text-gray-600 fs-7 mb-1">Código de saída</div>
                    <div class="fw-bold text-gray-900">{{ $supervisorStatus['exit_code'] ?? '-' }}</div>
                </div>

                <label class="form-label fw-semibold">Saída</label>
                <pre class="bg-light text-gray-800 rounded p-4 mb-4" style="white-space: pre-wrap; word-break: break-word; max-height: 30vh; overflow: auto;">{{ $supervisorStatus['output'] ?? '' }}</pre>

                <label class="form-label fw-semibold">Erro</label>
                <pre class="bg-light-danger text-gray-800 rounded p-4 mb-0" style="white-space: pre-wrap; word-break: break-word; max-height: 30vh; overflow: auto;">{{ $supervisorStatus['error'] ?? '' }}</pre>
            </div>
        </div>
    </div>
</div>
@endsection
