@extends('layouts.app')

@section('title', 'Configurações WhatsApp')

@section('content')
<div class="row g-6">
    <div class="col-12 col-xl-8">
        <form action="{{ route('system.settings.whatsapp.update') }}" method="POST">
            @csrf
            @method('PUT')

            {{-- Centraliza apenas a configuração da Meta e dos destinatários do template. --}}
            <div class="card">
                <div class="card-header border-0 pt-6">
                    <div class="card-title">
                        <h3 class="fw-bold m-0">WhatsApp</h3>
                    </div>
                </div>
                <div class="card-body">
                    @if(!empty($missingWhatsAppSettings))
                        <div class="alert alert-warning d-flex flex-column gap-2 mb-6">
                            <div class="fw-bold">Configurações pendentes para disparar WhatsApp</div>
                            <div>
                                Preencha os campos abaixo antes de testar ou enviar alertas automáticos:
                            </div>
                            <ul class="mb-0">
                                @foreach($missingWhatsAppSettings as $missingWhatsAppSetting)
                                    <li>{{ $missingWhatsAppSetting }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    <div class="row g-6">
                        <div class="col-12">
                            <label class="form-label fw-semibold">Telefones que devem receber o template</label>
                            <textarea
                                name="notification_phones"
                                class="form-control form-control-solid"
                                rows="4"
                                placeholder="5511999999999, 5511888888888"
                            >{{ old('notification_phones', $whatsAppSettings['notification_phones']) }}</textarea>
                            <div class="text-gray-600 fs-7 mt-2">
                                Separe por v&iacute;rgula, ponto e v&iacute;rgula ou quebra de linha.
                            </div>
                            @error('notification_phones')
                                <small class="text-danger d-block mt-1">{{ $message }}</small>
                            @enderror
                        </div>

                        <div class="col-12">
                            <h4 class="fw-bold mb-0">Template WhatsApp</h4>
                            <div class="text-gray-600 fs-7 mt-1">
                                Configure o modelo, a conta dona e o token usados no disparo pela Meta.
                            </div>
                        </div>

                        <div class="col-12 col-md-6">
                            <label class="form-label fw-semibold">Nome do template</label>
                            <input
                                type="text"
                                name="whatsapp_template_name"
                                class="form-control form-control-solid"
                                value="{{ old('whatsapp_template_name', $whatsAppSettings['template_name']) }}"
                            >
                            @error('whatsapp_template_name')
                                <small class="text-danger d-block mt-1">{{ $message }}</small>
                            @enderror
                        </div>

                        <div class="col-12 col-md-3">
                            <label class="form-label fw-semibold">Idioma</label>
                            <input
                                type="text"
                                name="whatsapp_template_language"
                                class="form-control form-control-solid"
                                value="{{ old('whatsapp_template_language', $whatsAppSettings['template_language']) }}"
                            >
                            @error('whatsapp_template_language')
                                <small class="text-danger d-block mt-1">{{ $message }}</small>
                            @enderror
                        </div>

                        <div class="col-12 col-md-3">
                            <label class="form-label fw-semibold">Conta dona</label>
                            <input
                                type="text"
                                name="whatsapp_owner_account_id"
                                class="form-control form-control-solid"
                                value="{{ old('whatsapp_owner_account_id', $whatsAppSettings['owner_account_id']) }}"
                                placeholder="867242672536039"
                            >
                            @error('whatsapp_owner_account_id')
                                <small class="text-danger d-block mt-1">{{ $message }}</small>
                            @enderror
                        </div>

                        <div class="col-12">
                            <label class="form-label fw-semibold">Token de acesso da Meta</label>
                            <input
                                type="text"
                                name="whatsapp_access_token"
                                class="form-control form-control-solid"
                                value="{{ old('whatsapp_access_token', $whatsAppSettings['access_token']) }}"
                                placeholder="Digite o token da Meta"
                            >
                            @error('whatsapp_access_token')
                                <small class="text-danger d-block mt-1">{{ $message }}</small>
                            @enderror
                        </div>
                    </div>
                </div>
            </div>

            <div class="d-flex justify-content-end mt-4">
                <button type="submit" class="btn btn-primary btn-active-danger">
                    Salvar Configurações
                </button>
            </div>
        </form>
    </div>

    <div class="col-12 col-xl-4">
        <form action="{{ route('system.settings.whatsapp.test') }}" method="POST">
            @csrf

            {{-- Mantém a zona de testes do template isolada da configuração persistida. --}}
            <div class="card">
                <div class="card-header border-0 pt-6">
                    <div class="card-title">
                        <h3 class="fw-bold m-0">Teste WhatsApp</h3>
                    </div>
                </div>
                <div class="card-body">
                    <div class="text-gray-600 fs-7 mb-6">
                        O teste usa a conta dona e o token configurados acima para resolver automaticamente o número emissor.
                    </div>

                    <div class="mb-6">
                        <label class="form-label fw-semibold">Telefone de teste</label>
                        <input
                            type="text"
                            name="whatsapp_test_phone"
                            class="form-control form-control-solid"
                            value="{{ old('whatsapp_test_phone') }}"
                            placeholder="5511999999999"
                        >
                        @error('whatsapp_test_phone')
                            <small class="text-danger d-block mt-1">{{ $message }}</small>
                        @enderror
                    </div>

                    <div class="mb-6">
                        <label class="form-label fw-semibold">Sistema {{ '{' }}{{ '1' }}{{ '}' }}</label>
                        <input
                            type="text"
                            name="whatsapp_test_system_name"
                            class="form-control form-control-solid"
                            value="{{ old('whatsapp_test_system_name') }}"
                            placeholder="ERP Financeiro"
                        >
                        @error('whatsapp_test_system_name')
                            <small class="text-danger d-block mt-1">{{ $message }}</small>
                        @enderror
                    </div>

                    <div class="mb-6">
                        <label class="form-label fw-semibold">Descrição {{ '{' }}{{ '2' }}{{ '}' }}</label>
                        <textarea
                            name="whatsapp_test_description"
                            class="form-control form-control-solid"
                            rows="3"
                            placeholder="API fora do ar e fila de processamento parada."
                        >{{ old('whatsapp_test_description') }}</textarea>
                        @error('whatsapp_test_description')
                            <small class="text-danger d-block mt-1">{{ $message }}</small>
                        @enderror
                    </div>

                    <div class="mb-6">
                        <label class="form-label fw-semibold">Data {{ '{' }}{{ '3' }}{{ '}' }}</label>
                        <input
                            type="text"
                            name="whatsapp_test_event_date"
                            class="form-control form-control-solid"
                            value="{{ old('whatsapp_test_event_date', now()->format('d/m/Y H:i')) }}"
                            placeholder="20/03/2026 09:30"
                        >
                        @error('whatsapp_test_event_date')
                            <small class="text-danger d-block mt-1">{{ $message }}</small>
                        @enderror
                    </div>

                    <div class="text-gray-600 fs-7 mb-6">
                        Este teste usa o header <code>Problema detectado em {{ '{' }}{{ '1' }}{{ '}' }}</code> e o corpo com as vari&aacute;veis do template configurado na Meta.
                    </div>

                    <button type="submit" class="btn btn-success btn-active-danger w-100">
                        Enviar Template de Teste
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>
@endsection
