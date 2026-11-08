<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Producto;
use App\Services\ProductoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * API REST de productos. Las validaciones y reglas de negocio viven en ProductoService.
 *
 * Códigos HTTP: 200 OK, 201 Created, 204 No Content,
 * 404 Not Found (producto inexistente) y 422 Unprocessable Content (validación).
 */
class ProductoController extends Controller
{
    public function __construct(private ProductoService $servicio) {}

    /**
     * GET /api/productos
     */
    public function index(): JsonResponse
    {
        return response()->json($this->servicio->listar(), 200);
    }

    /**
     * POST /api/productos
     */
    public function store(Request $request): JsonResponse
    {
        $producto = $this->servicio->crear($request->all());

        return response()->json($producto, 201);
    }

    /**
     * GET /api/productos/{producto}
     */
    public function show(Producto $producto): JsonResponse
    {
        return response()->json($producto, 200);
    }

    /**
     * PUT/PATCH /api/productos/{producto}
     */
    public function update(Request $request, Producto $producto): JsonResponse
    {
        return response()->json($this->servicio->actualizar($producto, $request->all()), 200);
    }

    /**
     * DELETE /api/productos/{producto}
     */
    public function destroy(Producto $producto): Response
    {
        $this->servicio->eliminar($producto);

        return response()->noContent();
    }
}
