<?php

namespace App\GraphQL\Mutations;

use App\Models\Producto;
use App\Services\ProductoService;

/**
 * Resolver de la mutación crearProducto. Delega en ProductoService para reutilizar
 * las mismas validaciones y reglas de negocio que la API REST.
 */
final class CrearProducto
{
    public function __construct(private ProductoService $servicio) {}

    /**
     * @param  array{nombre: string, precio: float, stock: int}  $args
     */
    public function __invoke(mixed $root, array $args): Producto
    {
        return $this->servicio->crear($args);
    }
}
