@extends('layouts.app')

@section('title', 'Adicionar - Cliente')

@section('content')
<p class="text-center fw-bold text-gray-700 fs-2 mb-4 text-uppercase">
    Adicionar Cliente
</p>
<form action="{{ route('tenants.store') }}" method="POST" enctype="multipart/form-data">
    @csrf
    @include('pages.tenants._form')
    <div class="d-flex justify-content-end mt-4">
        <a href="{{ route('tenants.index') }}" class="btn btn-light text-muted me-2">
            Voltar
        </a>
        <button type="submit" class="btn btn-primary btn-active-danger">
        Cadastrar
        </button>
    </div>
</form>
@endsection
