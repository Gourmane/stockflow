<?php

use App\Models\User;
use App\Services\StockMovementService;
use Illuminate\Database\Connectors\MySqlConnector;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

pest()->use(DatabaseMigrations::class);

it('preserves stock and history during row lock contention and accepts a later retry', function () {
    $employee = User::factory()->create(['role' => 'employee', 'is_active' => true]);
    $product = $this->createProduct();
    $this->recordOpeningStock($product, $employee, 5);
    $movement = [
        'product_id' => $product->id,
        'type' => 'out',
        'quantity' => 5,
        'idempotency_key' => (string) Str::uuid(),
    ];
    $service = $this->app->make(StockMovementService::class);
    $connection = DB::connection();
    $holder = (new MySqlConnector)->connect($connection->getConfig());

    $holder->beginTransaction();

    try {
        $lock = $holder->prepare('SELECT id FROM products WHERE id = ? FOR UPDATE');
        $lock->execute([$product->id]);
        expect((int) $lock->fetchColumn())->toBe($product->id);

        $connection->statement('SET SESSION innodb_lock_wait_timeout = 1');
        try {
            $service->create($movement, $employee);
            $this->fail('The movement ignored the lock held by the other connection.');
        } catch (QueryException $exception) {
            expect($exception->errorInfo[1])->toBe(1205);
        }

        $this->assertDatabaseHas('products', ['id' => $product->id, 'quantity' => 5]);
        $this->assertDatabaseMissing('stock_movements', ['idempotency_key' => $movement['idempotency_key']]);
    } finally {
        $holder->rollBack();
    }

    $service->create($movement, $employee);

    $this->assertDatabaseHas('products', ['id' => $product->id, 'quantity' => 0]);
    $this->assertDatabaseHas('stock_movements', $movement + ['user_id' => $employee->id]);
    $this->assertDatabaseCount('stock_movements', 2);
});
