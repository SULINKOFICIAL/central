<?php

namespace App\Http\Controllers;

use App\Enums\LogApiStatusEnum;
use App\Models\LogsApi;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class SqsErrorController extends Controller
{
    /**
     * Função responsavel por receber o Webhook de erros do SQS e salvar em logsApi
     */
    public function return(Request $request)
    {
        /**
         * SNS envia o corpo como JSON mas com Content-Type text/plain,
         * então $request->input() fica vazio. Decodificamos o corpo cru.
         */
        $data = json_decode($request->getContent(), true) ?? [];

        /**
         * Verifica se é uma confirmação de assinatura do SNS
         * O SNS envia esse tipo de requisição quando uma nova assinatura é criada
         */
        if ($request->header('x-amz-sns-message-type') === 'SubscriptionConfirmation') {

            // URL de confirmação enviada pelo SNS
            $subscribeUrl = $data['SubscribeURL'] ?? null;

            /**
             * Só confirma se a URL existir e pertencer ao domínio da AWS.
             * Evita SSRF: a SubscribeURL nunca deve apontar para outro host.
             */
            if ($subscribeUrl && $this->isAwsUrl($subscribeUrl)) {
                Http::get($subscribeUrl);
            }

            return response()->json(['status' => 'confirmed'], 200);
        }

        // Dispara para a função que resolve
        $this->handle($data);

        // Retorno Sucesso imediato para o Meta (202 Accepted)
        return response()->json([
            'status' => 'Accepted',
            'message' => 'Webhook recebido e será processado em background.'
        ], 202);

    }

    /**
     * Valida se a URL usa HTTPS e pertence ao domínio amazonaws.com.
     * Protege contra SSRF na confirmação de assinatura do SNS.
     */
    private function isAwsUrl(string $url): bool
    {
        // Quebra a URL em partes
        $parts = parse_url($url);

        // Sem host ou esquema inválida
        if (empty($parts['scheme']) || empty($parts['host'])) {
            return false;
        }

        // Exige HTTPS
        if ($parts['scheme'] !== 'https') {
            return false;
        }

        // Host deve ser amazonaws.com ou subdomínio dele
        $host = strtolower($parts['host']);

        return $host === 'amazonaws.com' || str_ends_with($host, '.amazonaws.com');
    }

    public function handle(array $data): void
    {
        /**
         * Extrai e salva os campos relevantes do erro recebido pelo SNS
         * - Subject: resumo do erro
         * - Message: payload original do webhook que causou o erro
         * - MessageAttributes: dados detalhados do erro (platform, errorType, errorMessage, occurredAt)
         */
        $log = [
            'subject'           => $data['Subject'],
            'payload'           => json_decode($data['Message'], true),
            'attributes'        => $data['MessageAttributes'],
        ];

        /**
         * Salva o erro na tabela de logs para debug e rastreamento
         */
        LogsApi::create([
            'api'    => 'SQS',
            'json'   => json_encode($log),
            'status' => LogApiStatusEnum::FAILED->value,
        ]);
    }
}
