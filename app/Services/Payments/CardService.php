<?php

namespace App\Services\Payments;

use App\Models\TenantCard;

class CardService
{
    /**
     * Resolve o cartão que será utilizado no pagamento.
     *
     * Aceita cartão existente por id ou cria/reutiliza cartão enviado no payload.
     */
    public function resolveForPayment(array $data): array
    {
        // Resolve cartão existente quando um id é informado.
        if (isset($data['card_id'])) {
            return $this->resolveExistingCard($data);
        }

        // Rejeita payload parcial de cartão novo.
        if ($this->hasIncompleteCardPayload($data)) {
            return ['card' => null, 'error' => 'Parâmetros faltando', 'status' => 400];
        }

        // Exige cartão quando não for informado card_id.
        if (!$this->hasNewCardPayload($data)) {
            return ['card' => null, 'error' => 'Cartão não informado', 'status' => 400];
        }

        // Cria ou reutiliza o cartão enviado no payload.
        return $this->resolveNewCard($data);
    }

    /**
     * Extrai o CVV enviado no payload.
     *
     * Retorna null quando não informado.
     */
    public function extractCvv(array $data): ?string
    {
        return $data['card']['cvv'] ?? null;
    }

    /**
     * Resolve um cartão já cadastrado pelo identificador informado.
     *
     * Garante que o cartão pertence ao próprio cliente.
     */
    private function resolveExistingCard(array $data): array
    {
        // Busca cartão existente do próprio cliente.
        $existingCard = TenantCard::where('tenant_id', $data['tenant']->id)
            ->where('id', $data['card_id'])
            ->first();

        // Impede uso de cartão inexistente para o cliente.
        if (!$existingCard) {
            return ['card' => null, 'error' => 'Cartão não encontrado para esse cliente', 'status' => 404];
        }

        // Retorna cartão existente para uso no pagamento.
        return ['card' => $existingCard, 'error' => null, 'status' => 200];
    }

    /**
     * Cria ou reutiliza o cartão enviado no payload do cliente.
     *
     * Reaproveita um cartão já salvo com o mesmo número antes de criar.
     */
    private function resolveNewCard(array $data): array
    {
        // Normaliza número para comparação/armazenamento.
        $number = $this->normalizeCardNumber($data['card']['number']);

        // Reaproveita cartão já salvo para esse cliente.
        $card = TenantCard::where('tenant_id', $data['tenant']->id)
            ->where('number', $number)
            ->first();

        // Cria novo cartão quando ainda não existe.
        if (!$card) {

            // Salva novo cartão no banco.
            $card = TenantCard::create([
                'tenant_id'        => $data['tenant']->id,
                'main'             => true,
                'name'             => $data['card']['name'],
                'number'           => $number,
                'expiration_month' => substr($data['card']['expiration'], 0, 2),
                'expiration_year'  => '20' . substr($data['card']['expiration'], -2),
            ]);
        }

        // Retorna cartão para uso no pagamento.
        return ['card' => $card, 'error' => null, 'status' => 200];
    }

    /**
     * Verifica se o payload possui todos os campos obrigatórios do cartão novo.
     *
     * Retorna true apenas quando nome, número, vencimento e cvv estão presentes.
     */
    private function hasNewCardPayload(array $data): bool
    {
        return isset($data['card'])
            && isset($data['card']['name'])
            && isset($data['card']['number'])
            && isset($data['card']['expiration'])
            && isset($data['card']['cvv']);
    }

    /**
     * Verifica se o payload de cartão foi enviado parcialmente.
     *
     * Usado para responder erro de parâmetros faltando.
     */
    private function hasIncompleteCardPayload(array $data): bool
    {
        return isset($data['card']) && !$this->hasNewCardPayload($data);
    }

    /**
     * Normaliza o número do cartão removendo espaços.
     *
     * Retorna o número limpo no formato inteiro.
     */
    private function normalizeCardNumber(string $number): int
    {
        return (int) str_replace(' ', '', $number);
    }
}
