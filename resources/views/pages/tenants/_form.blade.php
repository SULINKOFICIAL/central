@if (!isset($content))
<div class="row g-6">
    <div class="col-12">
        <div class="card h-100">
            <div class="card-header align-items-center">
                <div class="d-flex align-items-center gap-3">
                    <i class="fa-solid fa-building text-primary fs-3"></i>
                    <div>
                        <span class="fw-bolder text-gray-800 fs-5 d-block">Conta</span>
                        <span class="text-gray-600 fs-7">Identificação, domínio e período liberado</span>
                    </div>
                </div>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-12 mb-4">
                        <label class="form-label fs-6 fw-bold text-gray-700 mb-2 required">Nome da conta</label>
                        <input type="text" class="form-control form-control-solid" placeholder="Nome exibido na Central" name="name" value="{{ old('name') }}" maxlength="255" required>
                    </div>
                    <div class="col-12 mb-4">
                        <label class="form-label fs-6 fw-bold text-gray-700 mb-2 required">Razão social</label>
                        <input type="text" class="form-control form-control-solid" placeholder="Razão social ou nome empresarial" name="company" value="{{ old('company') }}" maxlength="255" required>
                    </div>
                    <div class="col-12 mb-4">
                        <label class="form-label fs-6 fw-bold text-gray-700 mb-2 required">Domínio</label>
                        <div class="input-group">
                            <input type="text" class="form-control form-control-solid" name="domain" placeholder="dominio" value="{{ old('domain') }}" style="border-right: solid 1px #dbdfe9" required/>
                            <span class="input-group-text">.micore.com.br</span>
                        </div>
                    </div>
                    <div class="col-12 col-md-4 mb-4">
                        <label class="form-label fs-6 fw-bold text-gray-700 mb-2 required">Tipo</label>
                        <select class="form-select form-select-solid" name="document_type" id="tenant_document_type" required>
                            <option value="cnpj" @if (old('document_type', 'cnpj') === 'cnpj') selected @endif>CNPJ</option>
                            <option value="cpf" @if (old('document_type') === 'cpf') selected @endif>CPF</option>
                        </select>
                    </div>
                    <div class="col-12 col-md-8 mb-4">
                        <label class="form-label fs-6 fw-bold text-gray-700 mb-2 required">Documento</label>
                        <input type="text" class="form-control form-control-solid" placeholder="Somente números" name="document_number" id="tenant_document_number" value="{{ old('document_number') }}" inputmode="numeric" pattern="[0-9]*" maxlength="14" required>
                    </div>
                    <div class="col-12 mb-4">
                        <label class="form-label fs-6 fw-bold text-gray-700 mb-2 required">Email da conta</label>
                        <input type="email" class="form-control form-control-solid" placeholder="financeiro@empresa.com.br" name="email" value="{{ old('email') }}" maxlength="255" required>
                    </div>
                    <div class="col-12 col-md-6 mb-4">
                        <label class="form-label fs-6 fw-bold text-gray-700 mb-2 required">Dias liberados</label>
                        <input type="number" class="form-control form-control-solid" placeholder="30" name="trial_days" value="{{ old('trial_days', 30) }}" min="1" max="365" required>
                    </div>
                    <div class="col-12 col-md-6 mb-4">
                        <label class="form-label fs-6 fw-bold text-gray-700 mb-2">WhatsApp</label>
                        <input type="text" class="form-control form-control-solid" placeholder="48999999999" name="whatsapp" value="{{ old('whatsapp') }}" maxlength="20">
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="col-12">
        <div class="card h-100">
            <div class="card-header align-items-center">
                <div class="d-flex align-items-center gap-3">
                    <i class="fa-solid fa-user-shield text-primary fs-3"></i>
                    <div>
                        <span class="fw-bolder text-gray-800 fs-5 d-block">Primeiro usuário</span>
                        <span class="text-gray-600 fs-7">Acesso inicial criado no ambiente do cliente</span>
                    </div>
                </div>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-12 mb-4">
                        <label class="form-label fs-6 fw-bold text-gray-700 mb-2 required">Nome</label>
                        <input type="text" class="form-control form-control-solid" placeholder="Nome do usuário" name="user[name]" value="{{ old('user.name') }}" maxlength="255" required>
                    </div>
                    <div class="col-12 mb-4">
                        <label class="form-label fs-6 fw-bold text-gray-700 mb-2 required">Email</label>
                        <input type="email" class="form-control form-control-solid" placeholder="email@empresa.com.br" name="user[email]" value="{{ old('user.email') }}" maxlength="255" required>
                    </div>
                    <div class="col-12 mb-4">
                        <label class="form-label fs-6 fw-bold text-gray-700 mb-2 required">Senha</label>
                        <input type="text" class="form-control form-control-solid" placeholder="Senha do usuário" name="user[password]" value="{{ old('user.password') }}" maxlength="255" required>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="col-12">
        <div class="card">
            <div class="card-header align-items-center">
                <div class="d-flex align-items-center gap-3">
                    <i class="fa-solid fa-location-dot text-primary fs-3"></i>
                    <div>
                        <span class="fw-bolder text-gray-800 fs-5 d-block">Endereço</span>
                        <span class="text-gray-600 fs-7">Dados opcionais usados para completar o cadastro do cliente</span>
                    </div>
                </div>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-12 col-md-3 mb-4">
                        <label class="form-label fs-6 fw-bold text-gray-700 mb-2">CEP</label>
                        <input type="text" class="form-control form-control-solid" placeholder="88000000" name="company_zip_code" value="{{ old('company_zip_code') }}" inputmode="numeric" pattern="[0-9]*" maxlength="8">
                    </div>
                    <div class="col-12 col-md-5 mb-4">
                        <label class="form-label fs-6 fw-bold text-gray-700 mb-2">Cidade/UF</label>
                        <input type="text" class="form-control form-control-solid" placeholder="Florianopolis/SC" name="company_city_state" value="{{ old('company_city_state') }}" maxlength="255">
                    </div>
                    <div class="col-12 col-md-4 mb-4">
                        <label class="form-label fs-6 fw-bold text-gray-700 mb-2">Bairro</label>
                        <input type="text" class="form-control form-control-solid" placeholder="Centro" name="company_neighborhood" value="{{ old('company_neighborhood') }}" maxlength="255">
                    </div>
                    <div class="col-12 col-md-7 mb-4">
                        <label class="form-label fs-6 fw-bold text-gray-700 mb-2">Endereço</label>
                        <input type="text" class="form-control form-control-solid" placeholder="Rua, avenida ou travessa" name="company_address" value="{{ old('company_address') }}" maxlength="255">
                    </div>
                    <div class="col-12 col-md-2 mb-4">
                        <label class="form-label fs-6 fw-bold text-gray-700 mb-2">Número</label>
                        <input type="text" class="form-control form-control-solid" placeholder="100" name="company_number" value="{{ old('company_number') }}" maxlength="20">
                    </div>
                    <div class="col-12 col-md-3 mb-4">
                        <label class="form-label fs-6 fw-bold text-gray-700 mb-2">Complemento</label>
                        <input type="text" class="form-control form-control-solid" placeholder="Sala, bloco, andar" name="company_complement" value="{{ old('company_complement') }}" maxlength="255">
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@else
<div class="row">
    <div class="col-12 col-md-12 mb-4">
        <label class="form-label fs-6 fw-bold text-gray-700 mb-2 required">Nome da empresa</label>
        <input type="text" class="form-control form-control-solid" placeholder="Companhia" name="name" value="{{ $content->name ?? old('name') }}" maxlength="255" required>
    </div>
    <div class="col-12 col-md-6 mb-4">
        <label class="form-label fs-6 fw-bold text-gray-700 mb-2 required">Token</label>
        <input type="text" class="form-control form-control-solid" placeholder="token" name="token" value="{{ $content->token ?? old('token') }}" maxlength="255" required>
    </div>
    <div class="col-12 col-md-6 mb-4">
        <label class="form-label fs-6 fw-bold text-gray-700 mb-2">Logo</label>
        <input type="file" name="fileLogo" class="form-control form-control-solid">
    </div>
</div>
@endif

@if (!isset($content))
<script>
    document.addEventListener('DOMContentLoaded', function () {
        const documentType = document.getElementById('tenant_document_type');
        const documentNumber = document.getElementById('tenant_document_number');
        const zipCode = document.querySelector('[name="company_zip_code"]');
        const cityState = document.querySelector('[name="company_city_state"]');
        const neighborhood = document.querySelector('[name="company_neighborhood"]');
        const address = document.querySelector('[name="company_address"]');

        if (!documentType || !documentNumber) {
            return;
        }

        function configureDocumentField() {
            const isCpf = documentType.value === 'cpf';

            documentNumber.maxLength = isCpf ? 11 : 14;
            documentNumber.placeholder = isCpf ? '00000000000' : '00000000000000';
            documentNumber.value = documentNumber.value.replace(/\D/g, '').slice(0, documentNumber.maxLength);
        }

        documentType.addEventListener('change', configureDocumentField);
        documentNumber.addEventListener('input', function () {
            documentNumber.value = documentNumber.value.replace(/\D/g, '').slice(0, documentNumber.maxLength);
        });

        function fillAddressFromZipCode() {
            if (!zipCode || !cityState || !neighborhood || !address) {
                return;
            }

            const zipCodeDigits = zipCode.value.replace(/\D/g, '').slice(0, 8);
            zipCode.value = zipCodeDigits;

            if (zipCodeDigits.length !== 8) {
                return;
            }

            fetch('https://viacep.com.br/ws/' + zipCodeDigits + '/json/')
                .then(function (response) {
                    return response.json();
                })
                .then(function (payload) {
                    if (payload.erro) {
                        return;
                    }

                    cityState.value = payload.localidade + '/' + payload.uf;
                    neighborhood.value = payload.bairro || neighborhood.value;
                    address.value = payload.logradouro || address.value;
                })
                .catch(function () {
                });
        }

        if (zipCode) {
            zipCode.addEventListener('input', function () {
                zipCode.value = zipCode.value.replace(/\D/g, '').slice(0, 8);
            });

            zipCode.addEventListener('blur', fillAddressFromZipCode);
            zipCode.addEventListener('change', fillAddressFromZipCode);
        }

        configureDocumentField();
    });
</script>
@endif
