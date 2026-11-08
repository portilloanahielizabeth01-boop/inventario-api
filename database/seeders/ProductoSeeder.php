<?php

namespace Database\Seeders;

use App\Models\Producto;
use Illuminate\Database\Seeder;

class ProductoSeeder extends Seeder
{
    /**
     * Carga productos ficticios para las pruebas comparativas.
     */
    public function run(): void
    {
        $productos = [
            ['nombre' => 'Mouse Gamer', 'precio' => 18500, 'stock' => 20],
            ['nombre' => 'Teclado Mecánico', 'precio' => 45999.90, 'stock' => 12],
            ['nombre' => 'Monitor 24"', 'precio' => 189000, 'stock' => 7],
            ['nombre' => 'Auriculares USB', 'precio' => 23750.50, 'stock' => 30],
            ['nombre' => 'Webcam HD', 'precio' => 31200, 'stock' => 15],
        ];

        foreach ($productos as $producto) {
            Producto::updateOrCreate(['nombre' => $producto['nombre']], $producto);
        }
    }
}
