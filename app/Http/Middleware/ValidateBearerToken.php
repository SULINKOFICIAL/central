<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ValidateBearerToken
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {

        /**
         * Obtém o token de autorização enviado pelo sistema chamador.
         */
        $token = $request->bearerToken();

        /**
         * Usa config() para continuar funcionando quando config:cache estiver ativo.
         */
        if (!$token || $token !== config('services.central.token')) {
            return response()->json(['error' => 'Token inválido.'], 401);
        }

        return $next($request);
    }
}
