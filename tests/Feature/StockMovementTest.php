<?php

use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Str;

pest()->use(RefreshDatabase::class);

it('records a stock movement and updates inventory for an active user', function (
    string $role,
    string $type,
    int $quantity,
    int $remainingStock,
) {
    $user = User::factory()->create(['role' => $role, 'is_active' => true]);
    $this->actingAs($user, 'web');
    $product = $this->createProduct();
    $openingMovement = $this->recordOpeningStock($product, $user, 8);
    $movement = [
        'product_id' => $product->id,
        'type' => $type,
        'quantity' => $quantity,
        'note' => 'Warehouse transfer',
    ];

    $response = $this->postJson('/api/stock-movements', $movement);

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
    'admin removes all remaining stock' => ['admin', 'out', 8, 0],
]);

it('uses the logged-in session as the author and applies movements to an empty product', function () {
    $employee = User::factory()->create(['role' => 'employee', 'is_active' => true]);
    $otherUser = User::factory()->create();
    $product = $this->createProduct();
    $this->useStatefulSpaRequests();
    $login = $this->loginThroughSpa($employee);
    $this->replaySpaSession($login);

    $movement = ['product_id' => $product->id, 'type' => 'in', 'quantity' => 10];
    $this->postJson('/api/stock-movements', $movement + ['user_id' => $otherUser->id])
        ->assertCreated()->assertJsonPath('data.user_id', $employee->id);
    $this->assertDatabaseHas('stock_movements', $movement + ['user_id' => $employee->id]);
    $this->assertDatabaseHas('products', ['id' => $product->id, 'quantity' => 10]);

    $this->postJson('/api/stock-movements', ['product_id' => $product->id, 'type' => 'out', 'quantity' => 3])
        ->assertCreated();
    $this->assertDatabaseHas('products', ['id' => $product->id, 'quantity' => 7]);
    $this->assertDatabaseCount('stock_movements', 2);
});

it('lists movement history with the product and operator', function () {
    $employee = User::factory()->create(['role' => 'employee', 'is_active' => true]);
    $this->actingAs($employee, 'web');
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
    $this->actingAs($employee, 'web');
    $product = $this->createProduct();
    $openingMovement = $this->recordOpeningStock($product, $employee, 7);

    $this->postJson('/api/stock-movements', ['product_id' => $product->id, 'type' => 'out', 'quantity' => 20])
        ->assertUnprocessable()
        ->assertJsonPath('errors.quantity.0', 'Insufficient stock.');

    $this->assertDatabaseHas('products', ['id' => $product->id, 'quantity' => 7]);
    $this->assertDatabaseHas('stock_movements', $openingMovement->getAttributes());
    $this->assertDatabaseCount('stock_movements', 1);
});

it('blocks movements while a product is disabled and resumes them after reactivation', function (
    string $type,
    int $quantity,
    int $remainingStock,
) {
    $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
    $this->actingAs($admin, 'web');
    $product = $this->createProduct();
    $openingMovement = $this->recordOpeningStock($product, $admin, 7);
    $movement = ['product_id' => $product->id, 'type' => $type, 'quantity' => $quantity];

    $this->patchJson("/api/products/{$product->id}", ['is_active' => false])->assertOk();
    $this->postJson('/api/stock-movements', $movement)
        ->assertUnprocessable()->assertJsonPath('errors.product_id.0', 'This product is inactive.');
    $this->assertDatabaseHas('products', ['id' => $product->id, 'quantity' => 7, 'is_active' => false]);
    $this->assertDatabaseHas('stock_movements', $openingMovement->getAttributes());
    $this->assertDatabaseCount('stock_movements', 1);

    $this->patchJson("/api/products/{$product->id}", ['is_active' => true])->assertOk();
    $this->postJson('/api/stock-movements', $movement)->assertCreated();
    $this->assertDatabaseHas('products', ['id' => $product->id, 'quantity' => $remainingStock, 'is_active' => true]);
    $this->assertDatabaseHas('stock_movements', $movement + ['user_id' => $admin->id]);
    $this->assertDatabaseCount('stock_movements', 2);
})->with([
    'stock in' => ['in', 5, 12],
    'stock out' => ['out', 2, 5],
]);

it('rejects invalid quantities without writing inventory or history', function (mixed $quantity) {
    $employee = User::factory()->create(['role' => 'employee', 'is_active' => true]);
    $this->actingAs($employee, 'web');
    $product = $this->createProduct();
    $openingMovement = $this->recordOpeningStock($product, $employee, 7);

    $this->postJson('/api/stock-movements', [
        'product_id' => $product->id,
        'type' => 'out',
        'quantity' => $quantity,
    ], [], JSON_PRESERVE_ZERO_FRACTION)->assertUnprocessable()->assertJsonValidationErrors('quantity');

    $this->assertDatabaseHas('products', ['id' => $product->id, 'quantity' => 7]);
    $this->assertDatabaseHas('stock_movements', $openingMovement->getAttributes());
    $this->assertDatabaseCount('stock_movements', 1);
})->with([
    'zero' => [0],
    'negative' => [-1],
    'fractional' => [1.5],
    'decimal representation of a whole number' => [1.0],
    'boolean' => [true],
    'above the MySQL unsigned integer limit' => [4_294_967_296],
]);

it('accepts the unsigned integer limit and rejects a stock increase that would overflow', function () {
    $employee = User::factory()->create(['role' => 'employee', 'is_active' => true]);
    $this->actingAs($employee, 'web');
    $product = $this->createProduct();
    $maximum = 4_294_967_295;

    $response = $this->postJson('/api/stock-movements', [
        'product_id' => $product->id, 'type' => 'in', 'quantity' => (string) $maximum,
    ])->assertCreated()->assertJsonPath('data.quantity', $maximum);

    $this->postJson('/api/stock-movements', ['product_id' => $product->id, 'type' => 'in', 'quantity' => 1])
        ->assertUnprocessable()->assertJsonPath('errors.quantity.0', 'Stock quantity exceeds the maximum allowed.');
    $this->assertDatabaseHas('products', ['id' => $product->id, 'quantity' => $maximum]);
    $this->assertDatabaseHas('stock_movements', ['id' => $response->json('data.id'), 'quantity' => $maximum]);
    $this->assertDatabaseCount('stock_movements', 1);

    $this->postJson('/api/stock-movements', ['product_id' => $product->id, 'type' => 'out', 'quantity' => $maximum])
        ->assertCreated();
    $this->assertDatabaseHas('products', ['id' => $product->id, 'quantity' => 0]);
    $this->assertDatabaseCount('stock_movements', 2);
});

it('returns 422 for invalid movement data without writing inventory or history', function () {
    $employee = User::factory()->create(['role' => 'employee', 'is_active' => true]);
    $this->actingAs($employee, 'web');
    $product = $this->createProduct();
    $openingMovement = $this->recordOpeningStock($product, $employee, 3);

    $this->postJson('/api/stock-movements', ['product_id' => $product->id + 1, 'type' => 'in', 'quantity' => 1])
        ->assertUnprocessable()->assertJsonValidationErrors('product_id');
    $this->assertDatabaseHas('products', ['id' => $product->id, 'quantity' => 3]);
    $this->assertDatabaseHas('stock_movements', $openingMovement->getAttributes());
    $this->assertDatabaseCount('stock_movements', 1);

    $this->postJson('/api/stock-movements', [
        'product_id' => $product->id,
        'type' => 'transfer',
        'quantity' => 0,
        'note' => str_repeat('A', 1001),
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['type', 'quantity', 'note']);

    $this->assertDatabaseHas('products', ['id' => $product->id, 'quantity' => 3]);
    $this->assertDatabaseHas('stock_movements', $openingMovement->getAttributes());
    $this->assertDatabaseCount('stock_movements', 1);
});

it('rejects guests and inactive accounts without writing inventory or history', function () {
    $employee = User::factory()->create(['role' => 'employee', 'is_active' => false]);
    $product = $this->createProduct();
    $movement = ['product_id' => $product->id, 'type' => 'in', 'quantity' => 10];

    $this->postJson('/api/stock-movements', $movement)->assertUnauthorized();
    $this->assertDatabaseHas('products', ['id' => $product->id, 'quantity' => 0]);
    $this->assertDatabaseEmpty('stock_movements');

    $this->actingAs($employee, 'web')->withHeader('Referer', 'http://localhost');
    $this->postJson('/api/stock-movements', $movement)->assertForbidden();
    $this->assertDatabaseHas('products', ['id' => $product->id, 'quantity' => 0]);
    $this->assertDatabaseEmpty('stock_movements');
});

it('keeps the stock and history unchanged when MySQL rejects the movement insert', function () {
    Exceptions::fake();
    $employee = User::factory()->create(['role' => 'employee', 'is_active' => true]);
    $this->actingAs($employee, 'web');
    $product = $this->createProduct();
    $openingMovement = $this->recordOpeningStock($product, $employee, 8);
    StockMovement::creating(function (StockMovement $movement): void {
        $movement->quantity = 0;
    });

    $this->postJson('/api/stock-movements', ['product_id' => $product->id, 'type' => 'in', 'quantity' => 4])
        ->assertServerError()
        ->assertJsonStructure(['message']);

    Exceptions::assertReported(fn (QueryException $exception): bool => str_contains(
        $exception->getMessage(),
        'chk_stock_movements_quantity',
    ));
    $this->assertDatabaseHas('products', ['id' => $product->id, 'quantity' => 8]);
    $this->assertDatabaseHas('stock_movements', $openingMovement->getAttributes());
    $this->assertDatabaseCount('stock_movements', 1);
});

it('rolls back a history row claimed before a stock update fails', function () {
    Exceptions::fake();
    $employee = User::factory()->create(['role' => 'employee', 'is_active' => true]);
    $this->actingAs($employee, 'web');
    $product = $this->createProduct();
    $key = (string) Str::uuid();
    $historyExistedBeforeFailure = false;
    $failure = new RuntimeException('Simulated failure while saving the stock balance.');

    Product::updating(function (Product $updatingProduct) use ($key, $failure, &$historyExistedBeforeFailure): void {
        $historyExistedBeforeFailure = StockMovement::query()
            ->where('product_id', $updatingProduct->id)
            ->where('idempotency_key', $key)
            ->exists();

        throw $failure;
    });

    $this->postJson('/api/stock-movements', [
        'product_id' => $product->id, 'type' => 'in', 'quantity' => 4, 'idempotency_key' => $key,
    ])->assertServerError();

    expect($historyExistedBeforeFailure)->toBeTrue();
    Exceptions::assertReported(fn (RuntimeException $exception): bool => $exception === $failure);
    $this->assertDatabaseHas('products', ['id' => $product->id, 'quantity' => 0]);
    $this->assertDatabaseEmpty('stock_movements');
});

it('replays a successful withdrawal without consuming stock twice', function () {
    $employee = User::factory()->create(['role' => 'employee', 'is_active' => true]);
    $this->actingAs($employee, 'web');
    $product = $this->createProduct();
    $this->recordOpeningStock($product, $employee, 5);
    $payload = [
        'product_id' => $product->id,
        'type' => 'out',
        'quantity' => 5,
        'note' => 'Stock transfer',
        'idempotency_key' => (string) Str::uuid(),
    ];

    $creation = $this->postJson('/api/stock-movements', $payload)->assertCreated();
    $this->postJson('/api/stock-movements', $payload)->assertCreated()
        ->assertJsonPath('data.id', $creation->json('data.id'));

    $this->assertDatabaseHas('products', ['id' => $product->id, 'quantity' => 0]);
    $this->assertDatabaseHas('stock_movements', $payload + ['id' => $creation->json('data.id'), 'user_id' => $employee->id]);
    $this->assertDatabaseCount('stock_movements', 2);
});

it('rejects a conflicting reuse of an idempotency key', function (array $change, bool $differentProduct) {
    $employee = User::factory()->create(['role' => 'employee', 'is_active' => true]);
    $this->actingAs($employee, 'web');
    $product = $this->createProduct();
    $otherProduct = $this->createProduct();
    $payload = [
        'product_id' => $product->id,
        'type' => 'in',
        'quantity' => 5,
        'note' => 'Opening stock',
        'idempotency_key' => (string) Str::uuid(),
    ];
    $this->postJson('/api/stock-movements', $payload)->assertCreated();

    $conflicting = $differentProduct ? ['product_id' => $otherProduct->id] : $change;
    $this->postJson('/api/stock-movements', $conflicting + $payload)->assertConflict();

    $this->assertDatabaseCount('stock_movements', 1);
    $this->assertDatabaseHas('stock_movements', $payload + ['user_id' => $employee->id]);
    $this->assertDatabaseHas('products', ['id' => $product->id, 'quantity' => 5]);
    $this->assertDatabaseHas('products', ['id' => $otherProduct->id, 'quantity' => 0]);
})->with([
    'quantity' => [['quantity' => 6], false],
    'type' => [['type' => 'out'], false],
    'note' => [['note' => 'Another note'], false],
    'product' => [[], true],
]);

it('scopes idempotency keys to the author', function () {
    $firstEmployee = User::factory()->create(['role' => 'employee', 'is_active' => true]);
    $secondEmployee = User::factory()->create(['role' => 'employee', 'is_active' => true]);
    $product = $this->createProduct();
    $payload = [
        'product_id' => $product->id, 'type' => 'in', 'quantity' => 5, 'idempotency_key' => (string) Str::uuid(),
    ];

    $first = $this->actingAs($firstEmployee, 'web')->postJson('/api/stock-movements', $payload)->assertCreated();
    $second = $this->actingAs($secondEmployee, 'web')->postJson('/api/stock-movements', $payload)->assertCreated();

    expect($second->json('data.id'))->not->toBe($first->json('data.id'));
    $this->assertDatabaseHas('stock_movements', $payload + ['user_id' => $firstEmployee->id]);
    $this->assertDatabaseHas('stock_movements', $payload + ['user_id' => $secondEmployee->id]);
    $this->assertDatabaseHas('products', ['id' => $product->id, 'quantity' => 10]);
});

it('releases the idempotency key from a rolled back withdrawal', function () {
    $employee = User::factory()->create(['role' => 'employee', 'is_active' => true]);
    $this->actingAs($employee, 'web');
    $product = $this->createProduct();
    $payload = [
        'product_id' => $product->id, 'type' => 'out', 'quantity' => 5, 'idempotency_key' => (string) Str::uuid(),
    ];

    $this->postJson('/api/stock-movements', $payload)->assertUnprocessable();
    $this->assertDatabaseMissing('stock_movements', ['idempotency_key' => $payload['idempotency_key']]);

    $this->recordOpeningStock($product, $employee, 7);
    $this->postJson('/api/stock-movements', $payload)->assertCreated();
    $this->assertDatabaseHas('stock_movements', $payload + ['user_id' => $employee->id]);
    $this->assertDatabaseHas('products', ['id' => $product->id, 'quantity' => 2]);
    $this->assertDatabaseCount('stock_movements', 2);
});
