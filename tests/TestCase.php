<?php

namespace Tests;

use App\Models\Category;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\StockMovementService;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    use InteractsWithSpa;

    public function createApplication(): Application
    {
        if (! is_file(dirname(__DIR__).'/.env.testing')) {
            throw new RuntimeException('Copy .env.testing.example to .env.testing and configure the isolated MySQL instance.');
        }

        $app = parent::createApplication();
        IsolatedMySql::verify($app);
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));

        return $app;
    }

    protected function createProduct(?Category $category = null): Product
    {
        $category ??= Category::create(['name' => fake()->unique()->word()]);

        return $category->products()->create([
            'sku' => fake()->unique()->bothify('SKU-#####'),
            'name' => 'USB cable',
        ]);
    }

    protected function recordOpeningStock(Product $product, User $user, int $quantity): StockMovement
    {
        return $this->app->make(StockMovementService::class)->create([
            'product_id' => $product->id,
            'type' => 'in',
            'quantity' => $quantity,
        ], $user);
    }
}
