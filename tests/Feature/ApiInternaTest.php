<?php

namespace Tests\Feature;

use App\Events\ProductoActualizado;
use App\Models\Producto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class ApiInternaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.interno.token' => 'secreto-de-prueba']);
        Event::fake([ProductoActualizado::class]);
    }

    public function test_rechaza_peticiones_sin_token(): void
    {
        $producto = Producto::factory()->create();

        $this->getJson("/api/interno/productos/{$producto->id}")->assertUnauthorized();
        $this->getJson("/api/interno/productos/{$producto->id}", ['X-Internal-Token' => 'otro'])->assertUnauthorized();
    }

    public function test_devuelve_producto_con_token(): void
    {
        $producto = Producto::factory()->create();

        $this->getJson("/api/interno/productos/{$producto->id}", ['X-Internal-Token' => 'secreto-de-prueba'])
            ->assertOk()
            ->assertJsonPath('id', $producto->id);

        $this->getJson('/api/interno/productos/999', ['X-Internal-Token' => 'secreto-de-prueba'])->assertNotFound();
    }

    public function test_crea_producto_con_token(): void
    {
        $this->postJson(
            '/api/interno/productos',
            ['nombre' => 'Desde gRPC', 'precio' => 10, 'stock' => 1],
            ['X-Internal-Token' => 'secreto-de-prueba'],
        )->assertCreated();

        $this->assertDatabaseHas('productos', ['nombre' => 'Desde gRPC']);
    }
}
