<?php

namespace App\Services;

use App\Models\TenantPlan;
use App\Models\Order;

class OrderService
{
    /**
     * Cria um pedido em rascunho com base nos módulos e configurações.
     */
    public function getOrderInProgress($tenant, $plan): Order
    {
        $order = Order::where('tenant_id', $tenant->id)
            ->where('status', 'draft')
            ->orderByDesc('id')
            ->first();

        if ($order) {
            if ($order->plan_id != $plan->id) {
                $order->plan_id = $plan->id;
                $order->save();
            }

            return $order;
        }

        return Order::create([
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
            'status' => 'draft',
        ]);
    }

    /**
     * Cria um pacote em rascunho com base nos módulos e configurações.
     */
    public function getPlanInProgress($tenant): TenantPlan
    {
        return TenantPlan::firstOrCreate(
            [
                'tenant_id' => $tenant->id,
                'progress'  => 'draft',
            ],
        );
    }

    /**
     * Recalcula o total do pedido considerando o cupom aplicado.
     */
    public function recalculateOrderTotals(Order $order, ?float $subtotal = null): void
    {
        // Soma o subtotal dos itens com base no preço aplicado canônico
        $itemsSubtotal = $subtotal ?? $this->calculateItemsSubtotal($order);

        // Calcula o desconto do cupom quando existir
        $couponDiscount = $this->calculateCouponDiscount($order, $itemsSubtotal);

        // Calcula o total final do pedido
        $totalAmount = max(0.0, $itemsSubtotal - $couponDiscount);

        $order->update([
            'total_amount' => $totalAmount,
            'coupon_discount_amount' => $couponDiscount,
        ]);
    }

    /**
     * Calcula subtotal do rascunho pela soma de applied_price dos itens.
     */
    private function calculateItemsSubtotal(Order $order): float
    {
        if (!$order->plan) {
            return 0.0;
        }

        return (float) $order->plan->items()->sum('applied_price');
    }

    /**
     * Calcula o desconto do cupom aplicado no pedido.
     */
    private function calculateCouponDiscount(Order $order, float $subtotal): float
    {

        if (!$order->coupon_id || !$order->coupon_type_snapshot) {
            return 0.0;
        }

        $type = $order->coupon_type_snapshot;
        $value = (float) ($order->coupon_value_snapshot ?? 0);

        if ($subtotal <= 0) {
            return 0.0;
        }

        if ($type === 'percent') {
            $discount = $subtotal * ($value / 100);
        } elseif ($type === 'fixed') {
            $discount = $value;
        } elseif ($type === 'trial') {
            $discount = $subtotal;
        } else {
            $discount = 0.0;
        }

        if ($discount > $subtotal) {
            $discount = $subtotal;
        }

        return $discount;
    }
}
