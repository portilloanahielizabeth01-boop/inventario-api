<?php

namespace Tests\Feature;

use App\Events\ProductoActualizado;
use App\Models\Producto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class ProductoGraphqlTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Event::fake([ProductoActualizado::class]);
    }

    public function test_consulta_solo_los_campos_pedidos(): void
    {
        Producto::factory()->create(['nombre' => 'Mouse', 'precio' => 100, 'stock' => 4]);

        $respuesta = $this->postJson('/graphql', ['query' => '{ productos { nombre precio } }'])
            ->assertOk()
            ->assertExactJson(['data' => ['productos' => [['nombre' => 'Mouse', 'precio' => 100]]]]);

        $this->assertArrayNotHasKey('stock', $respuesta->json('data.productos.0'));
    }

    public function test_consulta_un_producto(): void
    {
        $producto = Producto::factory()->create();

        $this->postJson('/graphql', ['query' => "{ producto(id: {$producto->id}) { id nombre stock } }"])
            ->assertOk()
            ->assertJsonPath('data.producto.id', (string) $producto->id);
    }

    public function test_mutacion_crea_producto(): void
    {
        $this->postJson('/graphql', [
            'query' => 'mutation { crearProducto(nombre: "Webcam", precio: 31200, stock: 15) { id nombre stock } }',
        ])->assertOk()->assertJsonPath('data.crearProducto.nombre', 'Webcam');

        $this->assertDatabaseHas('productos', ['nombre' => 'Webcam', 'stock' => 15]);
        Event::assertDispatched(ProductoActualizado::class);
    }

    public function test_mutacion_usa_las_mismas_validaciones_que_rest(): void
    {
        $this->postJson('/graphql', [
            'query' => 'mutation { crearProducto(nombre: "", precio: -5, stock: -1) { id } }',
        ])
            ->assertOk()
            ->assertJsonStructure(['errors' => [['extensions' => ['validation' => ['nombre', 'precio', 'stock']]]]]);

        $this->assertDatabaseCount('productos', 0);
    }

    public function test_mutacion_actualiza_stock(): void
    {
        $producto = Producto::factory()->create(['stock' => 1]);

        $this->postJson('/graphql', [
            'query' => "mutation { actualizarStock(id: {$producto->id}, stock: 9) { stock } }",
        ])->assertJsonPath('data.actualizarStock.stock', 9);
    }
}
