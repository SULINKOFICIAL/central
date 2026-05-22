<?php

namespace App\Services;
use GuzzleHttp\Client as Guzzle;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ServerException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Pool;

/**
 * Classe responsável por interagir com a API da eRede para realizar operações
 * relacionadas a transações e tokenização de cartões de crédito.
 * Documentação oficial: https://developer.userede.com.br/e-rede
 */
class GuzzleService
{
    /**
     * Realiza chamadas da central para os tenants com autenticação padrão.
     * Também permite sobrescrever timeout e outras opções sem afetar as chamadas antigas.
     * Isso foi adicionado para jobs rápidos poderem aguardar retorno imediato.
     */
    public function request($method, $url, $tenant, $data = null, array $requestOptions = [], $type = 'api')
    {
        $guzzle = new Guzzle();
        $centralToken = config('services.central.token');

        /**
         * Sem token cacheado a chamada sempre será recusada pelo tenant.
         */
        if (empty($centralToken)) {
            return [
                'success' => false,
                'message' => 'Token da Central não configurado.',
                'data' => $this->buildJsonResponseBody(
                    'Token da Central não configurado.',
                    'Verifique CENTRAL_TOKEN no .env da Central e gere novamente o cache de configuração.'
                ),
            ];
        }

        /**
         * Usa config() porque a Central pode estar com config cacheado.
         */
        $options = [
            'headers' => [
                'Authorization' => 'Bearer ' . $centralToken,
            ],
            'timeout' => 5,
        ];

        // Permite ajustar timeout e demais opções só quando a chamada precisar disso.
        if (!empty($requestOptions)) {
            $options = array_replace_recursive($options, $requestOptions);
        }

        // Envia o payload em JSON quando a operação precisar de dados extras.
        if ($data !== null) {
            $options['json'] = $data;
        }

        try {

            // Monta URL
            if($tenant->domains->count() > 0){
                $domain = $tenant->domains[0]->domain;
                $protocol = $this->protocolForDomain($domain);
                $url = "$protocol://{$domain}/$type/$url";
            } else {
                return [
                    'success' => false,
                    'message' => 'Nenhum domínio encontrado para o cliente.',
                    'data' => $this->buildJsonResponseBody(
                        'Nenhum domínio encontrado para o cliente.',
                        'O cliente não possui domínio ativo para receber a requisição.'
                    ),
                ];
            }

            // Realiza requisição
            $response = $guzzle->$method($url, $options);
            $body = $response->getBody()->getContents();

            return [
                'success' => true,
                'status_code' => $response->getStatusCode(),
                'data' => $body,
            ];

        } catch (ConnectException $e) {
            return [
                'success' => false,
                'message' => 'Falha de conexão',
                'data' => $this->buildJsonResponseBody('Falha de conexão', $e->getMessage()),
            ];
        } catch (ClientException | ServerException | RequestException $e) {
            // Captura qualquer erro HTTP e retorna sem quebrar o fluxo
            $response = $e->getResponse();
            $status = $response ? $response->getStatusCode() : null;
            $body = $response ? $response->getBody()->getContents() : null;
            $message = "Erro HTTP {$status}";

            return [
                'success' => false,
                'status_code' => $status,
                'message' => $message,
                'data' => $this->normalizeErrorResponseBody($body, $message, $e->getMessage()),
            ];
        }
    }

    /**
     * Normaliza o retorno de erro para JSON quando o cliente não devolver corpo válido.
     * Isso mantém o modal do histórico sempre com uma estrutura previsível.
     */
    private function normalizeErrorResponseBody($body, $message, $detail)
    {
        // Reaproveita o JSON original quando o cliente já respondeu nesse formato.
        if (!empty($body)) {
            json_decode($body, true);

            if (json_last_error() === JSON_ERROR_NONE) {
                return $body;
            }
        }

        // Monta um JSON padrão quando o retorno vier vazio ou em texto puro.
        return $this->buildJsonResponseBody($message, $detail, $body);
    }

    /**
     * Monta um corpo JSON padrão para a visualização detalhada do retorno.
     * Assim a listagem mostra uma mensagem curta e o modal guarda o detalhe técnico.
     */
    private function buildJsonResponseBody($message, $detail = null, $rawBody = null)
    {
        $payload = [
            'message' => $message,
        ];

        // Adiciona o detalhe técnico apenas quando ele existir.
        if (!empty($detail)) {
            $payload['detail'] = $detail;
        }

        // Preserva o corpo bruto quando houver conteúdo útil retornado pelo destino.
        if (!empty($rawBody)) {
            $payload['raw_body'] = $rawBody;
        }

        return json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public function pool($method, $url, $tenant, $payloads)
    {
        $centralToken = config('services.central.token');

        /**
         * Sem token cacheado a chamada sempre será recusada pelo tenant.
         */
        if (empty($centralToken)) {
            return [
                'success' => false,
                'message' => 'Token da Central não configurado.',
            ];
        }

        if ($tenant->domains->count() == 0) {
            return [
                'success' => false,
                'message' => 'Nenhum domínio encontrado para o cliente.',
            ];
        }

        $domain = $tenant->domains[0]->domain;
        $protocol = $this->protocolForDomain($domain);
        $baseUrl = "$protocol://{$domain}/api/";

        $guzzle = new Guzzle([
            'base_uri' => $baseUrl,
            'headers' => [
                'Authorization' => 'Bearer ' . $centralToken,
                'Accept' => 'application/json'
            ],
            'timeout' => 5
        ]);

        $requests = function ($payloads) use ($guzzle, $method, $url) {
            foreach ($payloads as $data) {
                yield function () use ($guzzle, $method, $url, $data) {
                    return $guzzle->requestAsync($method, $url, [
                        'json' => $data
                    ]);
                };
            }
        };

        $pool = new Pool($guzzle, $requests($payloads), [
            'concurrency' => 10
        ]);

        $pool->promise()->wait();

        return ['success' => true];
    }

    /**
     * Define protocolo por domínio, não pelo ambiente da central.
     * A central local também pode chamar tenants reais, que precisam de HTTPS.
     */
    private function protocolForDomain($domain)
    {
        if ($domain === '127.0.0.1' || $domain === 'localhost') {
            return 'http';
        }

        if (str_starts_with($domain, '127.')) {
            return 'http';
        }

        return 'https';
    }
}
