<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ProductoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * API interna protegida por token, consumida por el microservicio gRPC (carpeta grpc/).
 * Reutiliza ProductoService, por lo que aplica las mismas reglas que REST y GraphQL.
 */
class InternoProductoController extends Controller
{
    public function __construct(private ProductoService $servicio) {}

    public function show(int $id): JsonResponse
    {
        return response()->json($this->servicio->buscar($id));
    }

    public function store(Request $request): JsonResponse
    {
        return response()->json($this->servicio->crear($request->all()), 201);
    }
}
