<?php

namespace App\Http\Controllers;

use App\Enums\LogApiStatusEnum;
use App\Jobs\PagHiperDispatchRequest;
use App\Models\LogsApi;
use App\Support\JobDispatcher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PagHiperController extends Controller
{
    /**
     * Função responsavel por receber o Webhook da PagHiper
     */
    public function return(Request $request, $logOld = null)
    {
        // Obtém dados
        $data = $request->all();

        // Dispara para a função que resolve
        $this->handle($data, $logOld);

        // Retorno Sucesso imediato para a PagHiper (202 Accepted)
        return response()->json([
            'status'  => 'Accepted',
            'message' => 'Webhook PagHiper recebido e será processado em background.',
        ], 202);
    }

    public function handle(array $data, $logOld = null)
    {

        /**
         * Salvamos em uma tabela interna no miCore
         * para debugar e garantir que o webhook foi
         * recebido e salvo.
         */
        $logApi = LogsApi::create([
            'api'    => 'PagHiper',
            'json'   => json_encode($data),
            'status' => LogApiStatusEnum::RECEIVED,
        ]);

        Log::info('PagHiper webhook recebido', $data);

        // Se for um LogApi que está sendo reprocessado
        if ($logOld) {
            $logOld->new_log_id = $logApi->id;
            $logOld->save();
        }

        // Dispara o job para processar o webhook
        JobDispatcher::dispatch(PagHiperDispatchRequest::class, [$data], $logApi->id, 'paghiper');
    }
}
