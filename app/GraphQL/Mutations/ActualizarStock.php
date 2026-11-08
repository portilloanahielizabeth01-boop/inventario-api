<?php

namespace App\GraphQL\Mutations;

use App\Models\Producto;
use App\Services\ProductoService;

/**
 * Resolver de la mutación actualizarStock. Delega en ProductoService.
 */
final class ActualizarStock
{
    public function __construct(private ProductoService $servicio) {}

    /**
     * @param  array{id: string, stock: int}  $args
     */
    public function __invoke(mixed $root, array $args): Producto
    {
        $producto = $this->servicio->buscar((int) $args['id']);

        return $this->servicio->cambiarStock($producto, $args['stock']);
    }
}
