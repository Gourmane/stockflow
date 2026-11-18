<?php

use App\Models\Category;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;

pest()->use(RefreshDatabase::class);

it('creates a product with its category and zero stock as an admin', function () {
    $this->actingAs(User::factory()->create(['role' => 'admin', 'is_active' => true]), 'web');
    $category = Category::create(['name' => 'Accessories']);

    $response = $this->postJson('/api/products', [
        'category_id' => $category->id,
        'sku' => 'USB-C-001',
        'name' => 'USB cable',
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.category.id', $category->id)
        ->assertJsonPath('data.sku', 'USB-C-001');
    $this->assertDatabaseHas('products', [
        'id' => $response->json('data.id'),
        'category_id' => $category->id,
        'sku' => 'USB-C-001',
        'quantity' => 0,
    ]);
    $this->assertDatabaseEmpty('stock_movements');
    $response->assertJsonPath('data.quantity', 0);
});

it('returns 422 when quantity is supplied on product creation', function (?int $quantity) {
    $this->actingAs(User::factory()->create(['role' => 'admin', 'is_active' => true]), 'web');
    $category = Category::create(['name' => 'Accessories']);

    $this->postJson('/api/products', [
        'category_id' => $category->id,
        'sku' => 'USB-C-001',
        'name' => 'USB cable',
        'quantity' => $quantity,
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['quantity']);

    $this->assertDatabaseEmpty('products');
    $this->assertDatabaseEmpty('stock_movements');
})->with([
    'positive quantity' => [5],
    'zero quantity' => [0],
    'null quantity' => [null],
]);

it('updates product details while preserving inventory', function (string $method) {
    $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
    $this->actingAs($admin, 'web');
    $product = $this->createProduct();
    $openingMovement = $this->recordOpeningStock($product, $admin, 5);

    $this->json($method, "/api/products/{$product->id}", [
        'sku' => $product->sku,
        'name' => 'Braided USB cable',
    ])->assertOk()
        ->assertJsonPath('data.name', 'Braided USB cable')
        ->assertJsonPath('data.quantity', 5);

    $this->assertDatabaseHas('products', ['id' => $product->id, 'name' => 'Braided USB cable', 'quantity' => 5]);
    $this->assertDatabaseHas('stock_movements', $openingMovement->getAttributes());
    $this->assertDatabaseCount('stock_movements', 1);
})->with(['PUT', 'PATCH']);

it('returns 405 and preserves a product without movement history', function () {
    $this->actingAs(User::factory()->create(['role' => 'admin', 'is_active' => true]), 'web');
    $product = $this->createProduct();

    $this->deleteJson("/api/products/{$product->id}")
        ->assertMethodNotAllowed()
        ->assertJsonStructure(['message']);

    $this->assertModelExists($product);
    $this->assertModelExists($product->category);
});

it('returns 405 and preserves a product with movement history', function () {
    $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
    $this->actingAs($admin, 'web');
    $product = $this->createProduct();
    $movement = $this->recordOpeningStock($product, $admin, 5);

    $this->deleteJson("/api/products/{$product->id}")
        ->assertMethodNotAllowed()
        ->assertJsonStructure(['message']);

    $this->assertModelExists($product);
    $this->assertDatabaseHas('stock_movements', $movement->getAttributes());
});

it('allows an admin to deactivate and reactivate a product while preserving data and movement history', function (string $method) {
    $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
    $employee = User::factory()->create(['role' => 'employee', 'is_active' => true]);
    $product = $this->createProduct();
    $movement = $this->recordOpeningStock($product, $admin, 5);
    $productData = Arr::except($product->refresh()->getAttributes(), ['is_active', 'updated_at']);
    $path = "/api/products/{$product->id}";

    $this->actingAs($admin, 'web')->json($method, $path, ['is_active' => false])
        ->assertOk()
        ->assertJsonPath('data.is_active', false);
    $this->assertDatabaseHas('products', $productData + ['is_active' => false]);

    $this->actingAs($employee, 'web');
    $this->getJson('/api/products')
        ->assertOk()
        ->assertJsonPath('data.0.id', $product->id);
    $this->getJson($path)
        ->assertOk()
        ->assertJsonPath('data.id', $product->id)
        ->assertJsonPath('data.category.id', $product->category_id)
        ->assertJsonPath('data.quantity', 5);
    $this->getJson('/api/stock-movements')
        ->assertOk()
        ->assertJsonPath('data.0.id', $movement->id)
        ->assertJsonPath('data.0.product.id', $product->id);
    $this->assertDatabaseHas('stock_movements', $movement->getAttributes());

    $this->actingAs($admin, 'web')->json($method, $path, ['is_active' => true])
        ->assertOk()
        ->assertJsonPath('data.is_active', true);
    $this->assertDatabaseHas('products', $productData + ['is_active' => true]);
    $this->assertDatabaseHas('stock_movements', $movement->getAttributes());
    $this->assertDatabaseCount('stock_movements', 1);
})->with(['PUT', 'PATCH']);

it('returns 404 for a missing product', function () {
    $this->actingAs(User::factory()->create(['role' => 'admin', 'is_active' => true]), 'web');

    $this->getJson('/api/products/99999')->assertNotFound()->assertJsonStructure(['message']);
});

it('returns 422 when required product fields are missing', function () {
    $this->actingAs(User::factory()->create(['role' => 'admin', 'is_active' => true]), 'web');

    $this->postJson('/api/products')
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['category_id', 'sku', 'name']);

    $this->assertDatabaseEmpty('products');
});

it('returns 422 for a duplicate SKU, missing category and invalid stock values', function () {
    $this->actingAs(User::factory()->create(['role' => 'admin', 'is_active' => true]), 'web');
    $product = $this->createProduct();

    $this->postJson('/api/products', [
        'category_id' => 99999,
        'sku' => $product->sku,
        'name' => 'Replacement',
        'quantity' => -1,
        'unit_price' => -5,
        'min_stock' => -2,
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['category_id', 'sku', 'quantity', 'unit_price', 'min_stock']);

    $this->assertDatabaseCount('products', 1);
});

it('returns 422 for a direct stock quantity edit and persists none of the changes', function (string $method, ?int $quantity) {
    $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
    $this->actingAs($admin, 'web');
    $product = $this->createProduct();
    $openingMovement = $this->recordOpeningStock($product, $admin, 5);

    $this->json($method, "/api/products/{$product->id}", ['name' => 'Replacement', 'quantity' => $quantity])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['quantity']);

    $this->assertDatabaseHas('products', ['id' => $product->id, 'name' => $product->name, 'quantity' => 5]);
    $this->assertDatabaseHas('stock_movements', $openingMovement->getAttributes());
    $this->assertDatabaseCount('stock_movements', 1);
})->with([
    'PUT with a positive quantity' => ['PUT', 50],
    'PUT with a null quantity' => ['PUT', null],
    'PATCH with a positive quantity' => ['PATCH', 50],
    'PATCH with a null quantity' => ['PATCH', null],
]);
