<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Protege la API interna: solo acepta peticiones con el header X-Internal-Token correcto.
 */
class VerificarTokenInterno
{
    public function handle(Request $request, Closure $next): Response
    {
        $tokenEsperado = (string) config('services.interno.token');

        if ($tokenEsperado === '' || ! hash_equals($tokenEsperado, (string) $request->header('X-Internal-Token'))) {
            return response()->json(['message' => 'Token interno inválido.'], 401);
        }

        return $next($request);
    }
}
