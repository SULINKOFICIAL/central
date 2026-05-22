@extends('layouts.app')

@section('title', 'Início')

@section('content')
@if (!empty($mailLastTestError) || $emailDispatchErrors->isNotEmpty())
  <div class="alert alert-danger d-flex align-items-start p-5 mb-6">
    <i class="fa-solid fa-triangle-exclamation fs-2hx text-danger me-4 mt-1"></i>
    <div class="d-flex flex-column flex-grow-1">
      <div class="d-flex flex-column flex-md-row justify-content-between gap-3">
        <div>
          <span class="fw-bold text-gray-800 d-block mb-1">Falha em disparos de e-mail</span>
          <span class="text-gray-700">
            Existe erro ativo na configuração ou falhas recentes no envio de mensagens.
          </span>
        </div>
        <div>
          <a href="{{ route('system.settings.mail.edit') }}" class="btn btn-sm btn-light-danger">
            Ver SMTP
          </a>
        </div>
      </div>

      @if (!empty($mailLastTestError))
        <div class="mt-4">
          <div class="fw-semibold text-gray-800 mb-1">Erro persistente do teste SMTP</div>
          <div class="text-gray-700">{{ $mailLastTestError }}</div>
        </div>
      @endif

      @if ($emailDispatchErrors->isNotEmpty())
        <div class="mt-4">
          <div class="fw-semibold text-gray-800 mb-2">Falhas nas últimas 24 horas</div>
          <div class="d-flex flex-column gap-2">
            @foreach ($emailDispatchErrors as $emailError)
              <div class="text-gray-700">
                <span class="fw-semibold">{{ $emailError->created_at->format('d/m/Y H:i') }}</span>
                -
                {{ $emailError->recipient_email }}
                -
                {{ $emailError->error_message }}
              </div>
            @endforeach
          </div>
        </div>
      @endif
    </div>
  </div>
@endif

<div class="row gx-5 gx-xl-10">
  <div class="col-xl-12 mb-5 mb-xl-10">
    @include('pages.dashboard.widgets.relatorySalles')
  </div>
  <div class="col-xxl-4 mb-5 mb-xl-10">
    <div class="card card-flush h-xl-100">
      <div class="card-header py-7">
        <h3 class="card-title align-items-start flex-column">
          <span class="card-label fw-bold text-gray-800"
            >Últimos MiCores</span
          >
          <span class="text-gray-500 mt-1 fw-semibold fs-6">
            {{ $latestMiCores->count() }} recentes
          </span>
        </h3>
        <div class="card-toolbar">
          <a href="{{ route('tenants.index') }}" class="btn btn-sm btn-light">Ver MiCores</a>
        </div>
      </div>

      <div class="card-body d-flex justify-content-between flex-column pt-3">
        @forelse ($latestMiCores as $miCore)
          @php($domain = optional($miCore->domains->first())->domain)
          <div class="d-flex flex-stack">
            <div class="symbol symbol-30px me-4">
              <span class="symbol-label bg-light-primary">
                <i class="ki-outline ki-abstract-26 fs-3 text-primary"></i>
              </span>
            </div>

            <div
              class="d-flex align-items-center flex-stack flex-wrap flex-row-fluid d-grid gap-2"
            >
              <div class="me-5">
                <a
                  href="{{ route('tenants.show', $miCore->id) }}"
                  class="text-gray-800 fw-bold text-hover-primary fs-6"
                >
                  {{ $miCore->name }}
                </a>

                <span
                  class="text-gray-500 fw-semibold fs-7 d-block text-start ps-0"
                >
                  {{ $domain ?: 'Sem domínio' }}
                </span>
              </div>

              <div class="d-flex align-items-center">
                <span class="badge badge-light-primary fs-8">
                  {{ $miCore->created_at->format('d/m/Y') }}
                </span>
              </div>
            </div>
          </div>

          @if (! $loop->last)
            <div class="separator separator-dashed my-3"></div>
          @endif
        @empty
          <div class="text-gray-500 fw-semibold fs-6">Nenhum MiCore encontrado.</div>
        @endforelse
      </div>
    </div>
  </div>

  <div class="col-xl-4 mb-5 mb-xl-10">
    <div class="card card-flush h-xl-100">
      <div class="card-header pt-5">
        <h3 class="card-title align-items-start flex-column">
          <span class="card-label fw-bold text-gray-800">Últimas Vendas</span>

          <span class="text-gray-500 pt-1 fw-semibold fs-6"
            >{{ $latestSales->count() }} vendas pagas recentes</span
          >
        </h3>

        <div class="card-toolbar">
          <a href="{{ route('orders.index') }}" class="btn btn-sm btn-light">Ver todos</a>
        </div>
      </div>

      <div class="card-body py-3">
        <div class="table-responsive">
          <table class="table table-row-dashed align-middle gs-0 gy-4">
            <thead>
              <tr class="fs-7 fw-bold border-0 text-gray-500">
                <th class="min-w-200px">CLIENTE</th>
                <th class="min-w-120px">DATA</th>
                <th class="text-end min-w-120px">VALOR</th>
                <th class="text-end min-w-120px">STATUS</th>
              </tr>
            </thead>

            <tbody>
              @forelse ($latestSales as $sale)
                <tr>
                  <td>
                    <a
                      href="{{ route('orders.show', $sale->id) }}"
                      class="text-gray-800 fw-bold text-hover-primary mb-1 fs-6"
                    >
                      {{ optional($sale->tenant)->name ?? 'Cliente não identificado' }}
                    </a>
                  </td>
                  <td>
                    <span class="text-gray-700 fw-semibold fs-7">
                      {{ optional($sale->paid_at)->format('d/m/Y H:i') }}
                    </span>
                  </td>
                  <td class="text-end">
                    <span class="text-gray-900 fw-bold fs-6">
                      R$ {{ number_format($sale->total_amount, 2, ',', '.') }}
                    </span>
                  </td>
                  <td class="text-end">
                    <span class="badge badge-light-success">Pago</span>
                  </td>
                </tr>
              @empty
                <tr>
                  <td colspan="4" class="text-center text-gray-500 py-6">
                    Nenhuma venda paga encontrada.
                  </td>
                </tr>
              @endforelse
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>

  <div class="col-xl-4 mb-5 mb-xl-10">
    <div class="card card-flush h-xl-100">
      <div class="card-header pt-5">
        <h3 class="card-title align-items-start flex-column">
          <span class="card-label fw-bold text-gray-800"
            >Cupons mais usados</span
          >

          <span class="text-gray-500 pt-2 fw-semibold fs-6"
            >Contagem nos últimos 3 meses</span
          >
        </h3>

        <div class="card-toolbar">
          <a href="{{ route('coupons.index') }}" class="btn btn-sm btn-light">Ver cupons</a>
        </div>
      </div>

      <div class="card-body d-flex justify-content-between flex-column py-3">
        <div class="m-0"></div>

        <div class="table-responsive mb-n2">
          <table class="table table-row-dashed gs-0 gy-4">
            <thead>
              <tr class="fs-7 fw-bold border-0 text-gray-500">
                <th class="min-w-300px">CUPOM</th>
                <th class="min-w-100px">USOS</th>
              </tr>
            </thead>

            <tbody>
              @php($maxCouponUses = max(1, (int) ($topCoupons->max('total_uses') ?? 0)))
              @forelse ($topCoupons as $coupon)
                @php($couponWidth = max(12, (int) (($coupon->total_uses / $maxCouponUses) * 160)))
                <tr>
                  <td>
                    <a
                      href="{{ route('coupons.index') }}"
                      class="text-gray-600 fw-bold text-hover-primary mb-1 fs-6"
                    >
                      {{ $coupon->code_snapshot }}
                    </a>
                  </td>
                  <td class="d-flex align-items-center border-0">
                    <span class="fw-bold text-gray-800 fs-6 me-3">{{ $coupon->total_uses }}</span>

                    <div class="progress rounded-start-0">
                      <div
                        class="progress-bar bg-success m-0"
                        role="progressbar"
                        style="height: 12px; width: {{ $couponWidth }}px"
                        aria-valuenow="{{ $couponWidth }}"
                        aria-valuemin="0"
                        aria-valuemax="160"
                      ></div>
                    </div>
                  </td>
                </tr>
              @empty
                <tr>
                  <td colspan="2" class="text-center text-gray-500 py-6">
                    Nenhum cupom utilizado nos últimos 3 meses.
                  </td>
                </tr>
              @endforelse
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</div>
@endsection
