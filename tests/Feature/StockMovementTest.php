<?php

use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Exceptions;

pest()->use(RefreshDatabase::class);

it('records a stock movement and updates inventory for an active user', function (
    string $role,
    string $type,
    int $quantity,
    int $remainingStock,
) {
    $user = User::factory()->create(['role' => $role, 'is_active' => true]);
    $this->authenticateApiAs($user);
    $product = $this->createProduct();
    $openingMovement = $this->recordOpeningStock($product, $user, 8);
    $movement = [
        'product_id' => $product->id,
        'type' => $type,
        'quantity' => $quantity,
        'note' => 'Warehouse transfer',
    ];

    $response = $this->postJson('/api/stock-movements', $movement + ['user_id' => $user->id + 1]);

    $response->assertCreated()
        ->assertJsonPath('data.product_id', $product->id)
        ->assertJsonPath('data.user_id', $user->id)
        ->assertJsonPath('data.type', $type)
        ->assertJsonPath('data.quantity', $quantity)
        ->assertJsonPath('data.note', 'Warehouse transfer');
    $this->assertDatabaseHas('stock_movements', $movement + [
        'id' => $response->json('data.id'),
        'user_id' => $user->id,
    ]);
    $this->assertDatabaseHas('products', ['id' => $product->id, 'quantity' => $remainingStock]);
    $this->assertDatabaseHas('stock_movements', $openingMovement->getAttributes());
    $this->assertDatabaseCount('stock_movements', 2);
})->with([
    'admin receives stock' => ['admin', 'in', 4, 12],
    'employee receives stock' => ['employee', 'in', 4, 12],
    'employee removes some stock' => ['employee', 'out', 3, 5],
    'employee removes all remaining stock' => ['employee', 'out', 8, 0],
]);

it('lists movement history with the product and operator', function () {
    $employee = User::factory()->create(['role' => 'employee', 'is_active' => true]);
    $this->authenticateApiAs($employee);
    $product = $this->createProduct();
    $movement = $this->recordOpeningStock($product, $employee, 6);

    $this->getJson('/api/stock-movements')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $movement->id)
        ->assertJsonPath('data.0.type', 'in')
        ->assertJsonPath('data.0.quantity', 6)
        ->assertJsonPath('data.0.product.sku', $product->sku)
        ->assertJsonPath('data.0.user.name', $employee->name);

    $this->assertDatabaseHas('products', ['id' => $product->id, 'quantity' => 6]);
});

it('returns 422 for insufficient stock and preserves the balance and history', function () {
    $employee = User::factory()->create(['role' => 'employee', 'is_active' => true]);
    $this->authenticateApiAs($employee);
    $product = $this->createProduct();
    $openingMovement = $this->recordOpeningStock($product, $employee, 3);

    $this->postJson('/api/stock-movements', ['product_id' => $product->id, 'type' => 'out', 'quantity' => 4])
        ->assertUnprocessable()
        ->assertJsonPath('errors.quantity.0', 'Insufficient stock.');

    $this->assertDatabaseHas('products', ['id' => $product->id, 'quantity' => 3]);
    $this->assertDatabaseHas('stock_movements', $openingMovement->getAttributes());
    $this->assertDatabaseCount('stock_movements', 1);
});

it('returns 422 for invalid movement data without writing inventory or history', function () {
    $employee = User::factory()->create(['role' => 'employee', 'is_active' => true]);
    $this->authenticateApiAs($employee);
    $product = $this->createProduct();
    $openingMovement = $this->recordOpeningStock($product, $employee, 3);

    $this->postJson('/api/stock-movements', [
        'product_id' => 99999,
        'type' => 'transfer',
        'quantity' => 0,
        'note' => str_repeat('A', 1001),
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['product_id', 'type', 'quantity', 'note']);

    $this->assertDatabaseHas('products', ['id' => $product->id, 'quantity' => 3]);
    $this->assertDatabaseHas('stock_movements', $openingMovement->getAttributes());
    $this->assertDatabaseCount('stock_movements', 1);
});

it('rolls back the product update when MySQL rejects the movement insert', function () {
    Exceptions::fake();
    $employee = User::factory()->create(['role' => 'employee', 'is_active' => true]);
    $this->authenticateApiAs($employee);
    $product = $this->createProduct();
    $openingMovement = $this->recordOpeningStock($product, $employee, 8);
    $quantityBeforeInsert = null;
    StockMovement::creating(function (StockMovement $movement) use ($product, &$quantityBeforeInsert): void {
        $quantityBeforeInsert = $product->fresh()->quantity;
        $movement->quantity = 0;
    });

    $this->postJson('/api/stock-movements', ['product_id' => $product->id, 'type' => 'in', 'quantity' => 4])
        ->assertServerError()
        ->assertJsonStructure(['message']);

    expect($quantityBeforeInsert)->toBe(12);
    Exceptions::assertReported(fn (QueryException $exception): bool => str_contains(
        $exception->getMessage(),
        'chk_stock_movements_quantity',
    ));
    $this->assertDatabaseHas('products', ['id' => $product->id, 'quantity' => 8]);
    $this->assertDatabaseHas('stock_movements', $openingMovement->getAttributes());
    $this->assertDatabaseCount('stock_movements', 1);
});
