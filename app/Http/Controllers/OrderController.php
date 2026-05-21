<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\TenantPlan;
use App\Models\TenantPlanItem;
use App\Models\TenantPlanItemConfiguration;
use App\Services\OrderService;
use App\Services\Payments\SubscriptionPaymentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OrderController extends Controller
{
    protected $request;
    private $repository;
    private $orderService;

    public function __construct(Request $request, Order $content, OrderService $orderService)
    {
        $this->request      = $request;
        $this->repository   = $content;
        $this->orderService = $orderService;
    }

    /**
     * Display a listing of the orders.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        return view('pages.orders.index');
    }

    /**
     * Display the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function show($id)
    {
        // Obtém dados do Lead
        $order = $this->repository->find($id);

        if (request()->ajax()) {
            return view('pages.tenants._order')->with([
                'order' => $order,
            ]);
        }

        return view('pages.orders.show')->with([
            'order' => $order,
        ]);

    }

    public function reprocessSubscription($id, SubscriptionPaymentService $subscriptionPaymentService)
    {

        // Busca o pedido
        $order = Order::find($id);

        // Inicia serviço de reprocessamento
        $subscriptionPaymentService->processSubscriptionPayment($order, $order->subscription, $order->plan);

        // Redireciona
        return redirect()
            ->back()
            ->with('success', 'Assinatura reprocessada com sucesso');

    }

    /**
     * Cancela todos os pedidos em andamento e limpa os itens dos planos em rascunho.
     */
    public function cancelDrafts()
    {
        $canceledOrders = 0;
        $clearedPlans = 0;
        $clearedItems = 0;

        DB::transaction(function () use (&$canceledOrders, &$clearedPlans, &$clearedItems) {
            $draftOrders = Order::where('status', 'draft')
                ->get(['id', 'plan_id']);

            if ($draftOrders->isEmpty()) {
                return;
            }

            $orderIds = $draftOrders->pluck('id')->all();
            $planIds = $draftOrders->pluck('plan_id')->filter()->unique()->values()->all();

            $canceledOrders = Order::whereIn('id', $orderIds)
                ->update([
                    'status' => 'canceled_by_admin',
                    'canceled_at' => now(),
                    'updated_at' => now(),
                ]);

            if (empty($planIds)) {
                return;
            }

            $itemIds = TenantPlanItem::whereIn('plan_id', $planIds)
                ->pluck('id')
                ->all();

            if (!empty($itemIds)) {
                TenantPlanItemConfiguration::whereIn('item_id', $itemIds)
                    ->delete();
            }

            $clearedItems = TenantPlanItem::whereIn('plan_id', $planIds)
                ->delete();

            $clearedPlans = TenantPlan::whereIn('id', $planIds)
                ->update([
                    'progress' => 'canceled',
                    'updated_at' => now(),
                ]);
        });

        return redirect()
            ->route('orders.index')
            ->with('success', "Fluxo limpo: {$canceledOrders} pedido(s) cancelado(s), {$clearedPlans} plano(s) ajustado(s), {$clearedItems} item(ns) removido(s).");
    }
    
}
