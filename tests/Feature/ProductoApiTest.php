<?php

namespace Tests\Feature;

use App\Events\ProductoActualizado;
use App\Models\Producto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class ProductoApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Event::fake([ProductoActualizado::class]);
    }

    public function test_lista_productos(): void
    {
        Producto::factory()->count(3)->create();

        $this->getJson('/api/productos')->assertOk()->assertJsonCount(3);
    }

    public function test_crea_producto_y_emite_evento(): void
    {
        $this->postJson('/api/productos', ['nombre' => 'Mouse Gamer', 'precio' => 18500, 'stock' => 20])
            ->assertCreated()
            ->assertJsonPath('nombre', 'Mouse Gamer')
            ->assertJsonPath('stock', 20);

        $this->assertDatabaseHas('productos', ['nombre' => 'Mouse Gamer']);
        Event::assertDispatched(ProductoActualizado::class, fn (ProductoActualizado $e) => $e->accion === 'creado');
    }

    public function test_crear_valida_datos(): void
    {
        $this->postJson('/api/productos', ['nombre' => '', 'precio' => -1, 'stock' => 'abc'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['nombre', 'precio', 'stock']);

        Event::assertNotDispatched(ProductoActualizado::class);
    }

    public function test_muestra_producto(): void
    {
        $producto = Producto::factory()->create();

        $this->getJson("/api/productos/{$producto->id}")->assertOk()->assertJsonPath('id', $producto->id);
    }

    public function test_producto_inexistente_devuelve_404(): void
    {
        $this->getJson('/api/productos/999')->assertNotFound();
        $this->patchJson('/api/productos/999', ['stock' => 1])->assertNotFound();
        $this->deleteJson('/api/productos/999')->assertNotFound();
    }

    public function test_actualiza_stock_y_emite_evento(): void
    {
        $producto = Producto::factory()->create(['stock' => 5]);

        $this->patchJson("/api/productos/{$producto->id}", ['stock' => 8])
            ->assertOk()
            ->assertJsonPath('stock', 8);

        Event::assertDispatched(
            ProductoActualizado::class,
            fn (ProductoActualizado $e) => $e->accion === 'actualizado' && $e->producto['stock'] === 8,
        );
    }

    public function test_actualizar_valida_datos(): void
    {
        $producto = Producto::factory()->create();

        $this->putJson("/api/productos/{$producto->id}", ['stock' => -3])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['stock']);
    }

    public function test_elimina_producto(): void
    {
        $producto = Producto::factory()->create();

        $this->deleteJson("/api/productos/{$producto->id}")->assertNoContent();

        $this->assertDatabaseMissing('productos', ['id' => $producto->id]);
        Event::assertDispatched(ProductoActualizado::class, fn (ProductoActualizado $e) => $e->accion === 'eliminado');
    }

    public function test_evento_se_emite_en_canal_inventario(): void
    {
        $producto = Producto::factory()->create(['nombre' => 'Teclado', 'stock' => 3]);
        $evento = ProductoActualizado::desde($producto, 'actualizado');

        $this->assertSame('inventario', $evento->broadcastOn()[0]->name);
        $this->assertSame('producto.actualizado', $evento->broadcastAs());
        $this->assertSame('Teclado', $evento->broadcastWith()['producto']['nombre']);
    }
}
