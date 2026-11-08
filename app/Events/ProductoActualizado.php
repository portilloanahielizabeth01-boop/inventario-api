<?php

namespace App\Events;

use App\Models\Producto;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Evento que se emite por WebSocket (Laravel Reverb) cada vez que un producto
 * se crea, se modifica o se elimina. Se dispara desde ProductoService
 * después de guardar correctamente el cambio en la base de datos.
 */
class ProductoActualizado implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param  array{id: int, nombre: string, precio: float, stock: int}  $producto
     * @param  string  $accion  creado | actualizado | eliminado
     */
    public function __construct(
        public array $producto,
        public string $accion = 'actualizado',
    ) {}

    /**
     * Construye el evento a partir de un modelo, copiando solo los datos que el cliente necesita.
     */
    public static function desde(Producto $producto, string $accion): self
    {
        return new self([
            'id' => (int) $producto->id,
            'nombre' => (string) $producto->nombre,
            'precio' => (float) $producto->precio,
            'stock' => (int) $producto->stock,
        ], $accion);
    }

    /**
     * Canal público de la práctica. En producción se usaría un PrivateChannel con autorización.
     *
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new Channel('inventario'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'producto.actualizado';
    }

    /**
     * @return array{accion: string, producto: array{id: int, nombre: string, precio: float, stock: int}, fecha: string}
     */
    public function broadcastWith(): array
    {
        return [
            'accion' => $this->accion,
            'producto' => $this->producto,
            'fecha' => now()->toIso8601String(),
        ];
    }
}
