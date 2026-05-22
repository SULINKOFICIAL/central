@extends('layouts.app')

@section('title', 'Configurações SMTP')

@section('content')
<div class="row g-6">
    <div class="col-12 col-xl-8">
        <form action="{{ route('system.settings.mail.update') }}" method="POST">
            @csrf
            @method('PUT')

            {{-- Centraliza apenas os parâmetros de e-mail nessa página. --}}
            <div class="card">
                <div class="card-header border-0 pt-6">
                    <div class="card-title">
                        <h3 class="fw-bold m-0">SMTP</h3>
                    </div>
                </div>
                <div class="card-body">
                    <div class="row g-6">
                        <div class="col-12 col-md-4">
                            <label class="form-label fw-semibold">Mailer</label>
                            <input
                                type="text"
                                name="mailer"
                                class="form-control form-control-solid"
                                value="{{ old('mailer', $mailSettings['mailer']) }}"
                            >
                            @error('mailer')
                                <small class="text-danger d-block mt-1">{{ $message }}</small>
                            @enderror
                        </div>

                        <div class="col-12 col-md-8">
                            <label class="form-label fw-semibold">Host</label>
                            <input
                                type="text"
                                name="host"
                                class="form-control form-control-solid"
                                value="{{ old('host', $mailSettings['host']) }}"
                            >
                            @error('host')
                                <small class="text-danger d-block mt-1">{{ $message }}</small>
                            @enderror
                        </div>

                        <div class="col-12 col-md-4">
                            <label class="form-label fw-semibold">Porta</label>
                            <input
                                type="number"
                                name="port"
                                class="form-control form-control-solid"
                                value="{{ old('port', $mailSettings['port']) }}"
                            >
                            @error('port')
                                <small class="text-danger d-block mt-1">{{ $message }}</small>
                            @enderror
                        </div>

                        <div class="col-12 col-md-4">
                            <label class="form-label fw-semibold">Criptografia</label>
                            <select name="encryption" class="form-select form-select-solid">
                                <option value="" {{ old('encryption', $mailSettings['encryption']) === null || old('encryption', $mailSettings['encryption']) === '' ? 'selected' : '' }}>Nenhuma</option>
                                <option value="ssl" {{ old('encryption', $mailSettings['encryption']) === 'ssl' ? 'selected' : '' }}>SSL</option>
                                <option value="tls" {{ old('encryption', $mailSettings['encryption']) === 'tls' ? 'selected' : '' }}>TLS</option>
                            </select>
                            @error('encryption')
                                <small class="text-danger d-block mt-1">{{ $message }}</small>
                            @enderror
                        </div>

                        <div class="col-12 col-md-4">
                            <label class="form-label fw-semibold">Remetente</label>
                            <input
                                type="text"
                                name="from_name"
                                class="form-control form-control-solid"
                                value="{{ old('from_name', $mailSettings['from_name']) }}"
                            >
                            @error('from_name')
                                <small class="text-danger d-block mt-1">{{ $message }}</small>
                            @enderror
                        </div>

                        <div class="col-12 col-md-6">
                            <label class="form-label fw-semibold">Usuário SMTP</label>
                            <input
                                type="text"
                                name="username"
                                class="form-control form-control-solid"
                                value="{{ old('username', $mailSettings['username']) }}"
                            >
                            @error('username')
                                <small class="text-danger d-block mt-1">{{ $message }}</small>
                            @enderror
                        </div>

                        <div class="col-12 col-md-6">
                            <label class="form-label fw-semibold">Senha SMTP</label>
                            <input
                                type="password"
                                name="password"
                                class="form-control form-control-solid"
                                placeholder="{{ $mailSettings['hasPassword'] ? 'Preencha apenas para alterar a senha' : 'Digite a senha SMTP' }}"
                            >
                            @error('password')
                                <small class="text-danger d-block mt-1">{{ $message }}</small>
                            @enderror
                        </div>

                        <div class="col-12">
                            <label class="form-label fw-semibold">E-mail remetente</label>
                            <input
                                type="email"
                                name="from_address"
                                class="form-control form-control-solid"
                                value="{{ old('from_address', $mailSettings['from_address']) }}"
                            >
                            @error('from_address')
                                <small class="text-danger d-block mt-1">{{ $message }}</small>
                            @enderror
                        </div>

                        <div class="col-12">
                            <label class="form-label fw-semibold">E-mails que devem ser notificados</label>
                            <textarea
                                name="notification_emails"
                                class="form-control form-control-solid"
                                rows="4"
                                placeholder="email1@dominio.com, email2@dominio.com"
                            >{{ old('notification_emails', $mailSettings['notification_emails']) }}</textarea>
                            <div class="text-gray-600 fs-7 mt-2">
                                Separe por v&iacute;rgula, ponto e v&iacute;rgula ou quebra de linha.
                            </div>
                            @error('notification_emails')
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
        <form action="{{ route('system.settings.mail.test') }}" method="POST">
            @csrf

            {{-- Mantém o teste SMTP isolado para não misturar com a configuração do WhatsApp. --}}
            <div class="card">
                <div class="card-header border-0 pt-6">
                    <div class="card-title">
                        <h3 class="fw-bold m-0">Teste de Envio</h3>
                    </div>
                </div>
                <div class="card-body">
                    @if (!empty($mailSettings['last_test_error']))
                        <div class="alert alert-danger d-flex align-items-start p-5 mb-6">
                            <i class="fa-solid fa-triangle-exclamation fs-2hx text-danger me-4 mt-1"></i>
                            <div class="d-flex flex-column">
                                <span class="fw-bold text-gray-800 mb-1">Último teste de e-mail falhou</span>
                                <span class="text-gray-700">{{ $mailSettings['last_test_error'] }}</span>
                            </div>
                        </div>
                    @endif

                    <div class="mb-6">
                        <label class="form-label fw-semibold">Nome do destinatário</label>
                        <input
                            type="text"
                            name="test_name"
                            class="form-control form-control-solid"
                            value="{{ old('test_name') }}"
                            placeholder="Opcional"
                        >
                        @error('test_name')
                            <small class="text-danger d-block mt-1">{{ $message }}</small>
                        @enderror
                    </div>

                    <div class="mb-6">
                        <label class="form-label fw-semibold">E-mail de teste</label>
                        <input
                            type="email"
                            name="test_email"
                            class="form-control form-control-solid"
                            value="{{ old('test_email') }}"
                            placeholder="email@dominio.com"
                        >
                        @error('test_email')
                            <small class="text-danger d-block mt-1">{{ $message }}</small>
                        @enderror
                    </div>

                    <div class="text-gray-600 fs-7 mb-6">
                        O sistema usará a configuração SMTP salva acima para disparar um e-mail simples de validação.
                    </div>

                    <a
                        href="{{ route('system.settings.mail.preview') }}"
                        target="_blank"
                        class="btn btn-light-primary btn-active-danger w-100 mb-3"
                    >
                        Preview do HTML
                    </a>

                    <button type="submit" class="btn btn-success btn-active-danger w-100">
                        Enviar E-mail de Teste
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>
@endsection
