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
    public function createApplication(): Application
    {
        $app = parent::createApplication();
        $config = $app['config'];
        $connection = $config->get('database.connections.mysql');

        if (! $app->environment('testing')
            || $config->get('database.default') !== 'mysql'
            || $connection['driver'] !== 'mysql'
            || $connection['host'] !== '127.0.0.1'
            || (int) $connection['port'] !== 33316
            || $connection['database'] !== 'stockflow_test'
            || $connection['username'] !== 'stockflow_test'
            || ! empty($connection['url'])
            || ! empty($connection['unix_socket'])
        ) {
            throw new RuntimeException('Feature tests require stockflow_test on the isolated MySQL instance at 127.0.0.1:33316.');
        }

        return $app;
    }

    protected function authenticateApiAs(User $user): void
    {
        $this->withToken($user->createToken('feature-test')->plainTextToken);
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
