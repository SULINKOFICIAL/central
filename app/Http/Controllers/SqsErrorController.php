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
         * Verifica se é uma confirmação de assinatura do SNS
         * O SNS envia esse tipo de requisição quando uma nova assinatura é criada
         */
        if ($request->header('x-amz-sns-message-type') === 'SubscriptionConfirmation') {

            /**
             * Confirma a assinatura fazendo um GET na SubscribeURL enviada pelo SNS
             */
            Http::get($request->input('SubscribeURL'));

            return response()->json(['status' => 'confirmed'], 200);
        }

        // Obtém dados
        $data = $request->all();

        // Dispara para a função que resolve
        $this->handle($data);

        // Retorno Sucesso imediato para o Meta (202 Accepted)
        return response()->json([
            'status' => 'Accepted',
            'message' => 'Webhook recebido e será processado em background.'
        ], 202);

    }

    public function handle(array $data): void
    {

        /**
         * Salvamos em uma tabela interna no miCore
         * para debugar e garantir que o webhook foi 
         * recebido e salvo.
         */
        LogsApi::create([
            'api'    => 'SQS',
            'json'   => json_encode($data),
            'status' => LogApiStatusEnum::FAILED->value,
        ]);

    }
}
