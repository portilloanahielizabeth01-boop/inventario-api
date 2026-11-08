<?php

namespace App\Services;

use App\Events\ProductoActualizado;
use App\Models\Producto;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Validator;

/**
 * Capa de servicios: centraliza las reglas del inventario para que REST,
 * GraphQL y la API interna usada por gRPC apliquen exactamente la misma lógica.
 */
class ProductoService
{
    /**
     * @return array<string, string>
     */
    public static function reglasCreacion(): array
    {
        return [
            'nombre' => 'required|string|max:100',
            'precio' => 'required|numeric|min:0',
            'stock' => 'required|integer|min:0',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function reglasActualizacion(): array
    {
        return [
            'nombre' => 'sometimes|required|string|max:100',
            'precio' => 'sometimes|required|numeric|min:0',
            'stock' => 'sometimes|required|integer|min:0',
        ];
    }

    /**
     * @return Collection<int, Producto>
     */
    public function listar(): Collection
    {
        return Producto::orderBy('id')->get();
    }

    public function buscar(int $id): Producto
    {
        return Producto::findOrFail($id);
    }

    /**
     * @param  array<string, mixed>  $datos
     */
    public function crear(array $datos): Producto
    {
        $datos = Validator::make($datos, self::reglasCreacion())->validate();

        $producto = Producto::create($datos);

        event(ProductoActualizado::desde($producto, 'creado'));

        return $producto->refresh();
    }

    /**
     * @param  array<string, mixed>  $datos
     */
    public function actualizar(Producto $producto, array $datos): Producto
    {
        $datos = Validator::make($datos, self::reglasActualizacion())->validate();

        $producto->update($datos);
        $producto->refresh();

        event(ProductoActualizado::desde($producto, 'actualizado'));

        return $producto;
    }

    public function cambiarStock(Producto $producto, int $stock): Producto
    {
        return $this->actualizar($producto, ['stock' => $stock]);
    }

    public function eliminar(Producto $producto): void
    {
        $evento = ProductoActualizado::desde($producto, 'eliminado');

        $producto->delete();

        event($evento);
    }
}
