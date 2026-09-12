<?php

namespace Database\Seeders;

use App\Models\Product;
use Illuminate\Database\Seeder;

class ProductSeeder extends Seeder
{
    /**
     * Seed the application's products.
     */
    public function run(): void
    {
        Product::query()->firstOrCreate(
            ['name' => 'Test Widget'],
            ['stock' => 100],
        );

        Product::query()->firstOrCreate(
            ['name' => 'Low Stock Gadget'],
            ['stock' => 1],
        );

        Product::query()->firstOrCreate(
            ['name' => 'Out Of Stock Gizmo'],
            ['stock' => 0],
        );
    }
}
