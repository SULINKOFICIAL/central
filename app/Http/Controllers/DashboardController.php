<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Models\CouponRedemption;
use App\Models\EmailDispatchLog;
use App\Models\Order;
use App\Services\MailSettingsService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __construct(
        private readonly MailSettingsService $mailSettingsService
    ) {
    }

    /**
     * Monta os dados da dashboard e retorna a view principal.
     * Calcula métricas mensais, totais atuais e a lista dos últimos MiCores.
     */
    public function index(): View
    {
        // Define o período base dos últimos 12 meses (incluindo o mês atual).
        $startDate = now()->startOfMonth()->subMonths(11);

        // Agrupa clientes ativos por mês de criação para gerar a série mensal.
        $grouped = Tenant::where('status', true)
            ->where('created_at', '>=', $startDate)
            ->get(['created_at'])
            ->groupBy(fn (Tenant $tenant) => Carbon::parse($tenant->created_at)->format('Y-m'))
            ->map(fn ($items) => $items->count());

        // Garante todos os meses no gráfico, preenchendo com zero quando não houver dados.
        $monthlyActiveSystems = collect(range(0, 11))
            ->map(function (int $offset) use ($startDate, $grouped) {
                $date = (clone $startDate)->addMonths($offset);
                $key = $date->format('Y-m');

                return [
                    'month' => Carbon::parse($date)->translatedFormat('m/Y'),
                    'value' => (int) ($grouped[$key] ?? 0),
                ];
            });

        // Total geral de sistemas ativos.
        $totalActiveSystems = Tenant::where('status', true)
            ->count();

        // Total de sistemas ativos criados no mês atual.
        $activeSystemsThisMonth = Tenant::where('status', true)
            ->whereBetween('created_at', [now()->startOfMonth(), now()->endOfMonth()])
            ->count();

        // Busca os 5 MiCores mais recentes para o card de resumo.
        $latestMiCores = Tenant::with('domains')
            ->orderByDesc('id')
            ->limit(5)
            ->get();

        // Carrega as últimas vendas pagas para o card de vendas recentes.
        $latestSales = Order::with('tenant')
            ->whereNotNull('paid_at')
            ->orderByDesc('paid_at')
            ->limit(6)
            ->get();

        // Busca os cupons mais usados considerando os últimos 3 meses.
        $topCoupons = CouponRedemption::query()
            ->where('redeemed_at', '>=', now()->subMonths(3)->startOfDay())
            ->whereNotNull('code_snapshot')
            ->selectRaw('code_snapshot, COUNT(*) as total_uses')
            ->groupBy('code_snapshot')
            ->orderByDesc('total_uses')
            ->limit(6)
            ->get();

        // Carrega falhas recentes de disparo para destacar na home operacional.
        $emailDispatchErrors = EmailDispatchLog::query()
            ->where('status', 'error')
            ->where('created_at', '>=', now()->subDay())
            ->orderByDesc('created_at')
            ->limit(5)
            ->get();

        // Carrega o gráfico diário já com o mês atual.
        $dailyChartData = $this->buildDailyChartData();

        // Retorna a view com os dados consolidados da dashboard.
        return view('pages.dashboard.index', [
            'monthlyActiveSystems' => $monthlyActiveSystems,
            'totalActiveSystems' => $totalActiveSystems,
            'activeSystemsThisMonth' => $activeSystemsThisMonth,
            'maxMonthlyValue' => max(1, $monthlyActiveSystems->max('value')),
            'latestMiCores' => $latestMiCores,
            'latestSales' => $latestSales,
            'topCoupons' => $topCoupons,
            'dailyChartLabels' => $dailyChartData['labels'],
            'dailyChartSystemsSeries' => $dailyChartData['systemsSeries'],
            'dailyChartSalesSeries' => $dailyChartData['salesSeries'],
            'dailyChartMonthLabel' => $dailyChartData['monthLabel'],
            'dailyChartMonthValue' => $dailyChartData['monthValue'],
            'mailLastTestError' => $this->mailSettingsService->getLastTestError(),
            'emailDispatchErrors' => $emailDispatchErrors,
        ]);
    }

    /**
     * Retorna os dados do gráfico diário para o mês informado via query string.
     * Usado pelo AJAX para trocar o período do gráfico sem recarregar a página.
     */
    public function dailySystemsByMonth(Request $request): JsonResponse
    {
        $validatedData = $request->validate([
            'month' => ['nullable', 'regex:/^\d{4}-\d{2}$/'],
        ]);

        $monthValue = $validatedData['month'] ?? null;
        $dailyChartData = $this->buildDailyChartData($monthValue);

        return response()->json($dailyChartData);
    }

    /**
     * Gera labels e séries diárias de sistemas e vendas no mês informado.
     * Quando o mês não é informado, usa automaticamente o mês atual.
     */
    private function buildDailyChartData(?string $monthValue = null): array
    {
        // Interpreta o mês recebido (YYYY-MM) com fallback para o mês atual.
        $parsedMonth = $monthValue
            ? Carbon::createFromFormat('Y-m', $monthValue)->startOfMonth()
            : now()->startOfMonth();

        $monthStartDate = $parsedMonth->copy()->startOfMonth();
        $monthEndDate = $parsedMonth->copy()->endOfMonth();
        $daysInMonth = $monthStartDate->daysInMonth;

        // Agrupa os sistemas por dia de criação dentro do mês selecionado.
        $createdByDay = Tenant::whereBetween('created_at', [$monthStartDate, $monthEndDate])
            ->get(['created_at'])
            ->groupBy(fn (Tenant $tenant) => Carbon::parse($tenant->created_at)->day)
            ->map(fn ($items) => $items->count());

        // Agrupa as vendas por dia com base no campo paid_at.
        $salesByDay = Order::whereNotNull('paid_at')
            ->whereBetween('paid_at', [$monthStartDate, $monthEndDate])
            ->get(['paid_at'])
            ->groupBy(fn (Order $order) => Carbon::parse($order->paid_at)->day)
            ->map(fn ($items) => $items->count());

        $labels = collect(range(1, $daysInMonth))
            ->map(fn (int $day) => str_pad((string) $day, 2, '0', STR_PAD_LEFT))
            ->values()
            ->all();

        $systemsSeries = collect(range(1, $daysInMonth))
            ->map(fn (int $day) => (int) ($createdByDay[$day] ?? 0))
            ->values()
            ->all();

        $salesSeries = collect(range(1, $daysInMonth))
            ->map(fn (int $day) => (int) ($salesByDay[$day] ?? 0))
            ->values()
            ->all();

        return [
            'labels' => $labels,
            'systemsSeries' => $systemsSeries,
            'salesSeries' => $salesSeries,
            'monthLabel' => $monthStartDate->translatedFormat('F/Y'),
            'monthValue' => $monthStartDate->format('Y-m'),
        ];
    }
}
