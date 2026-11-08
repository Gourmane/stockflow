<?php

use App\Models\Category;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

pest()->use(RefreshDatabase::class);

it('creates a product with its category and zero stock as an admin', function () {
    $this->authenticateApiAs(User::factory()->create(['role' => 'admin', 'is_active' => true]));
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
    $this->authenticateApiAs(User::factory()->create(['role' => 'admin', 'is_active' => true]));
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
    $this->authenticateApiAs($admin);
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

it('deletes a product without movement history', function () {
    $this->authenticateApiAs(User::factory()->create(['role' => 'admin', 'is_active' => true]));
    $product = $this->createProduct();

    $this->deleteJson("/api/products/{$product->id}")->assertOk();

    $this->assertModelMissing($product);
    $this->assertModelExists($product->category);
});

it('returns a JSON 409 when deleting a product with movement history', function () {
    $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
    $this->authenticateApiAs($admin);
    $product = $this->createProduct();
    $movement = $this->recordOpeningStock($product, $admin, 5);

    $response = $this->deleteJson("/api/products/{$product->id}");

    $this->assertModelExists($product);
    $this->assertModelExists($movement);
    $response->assertConflict()->assertJsonStructure(['message']);
});

it('returns 404 for a missing product', function () {
    $this->authenticateApiAs(User::factory()->create(['role' => 'admin', 'is_active' => true]));

    $this->getJson('/api/products/99999')->assertNotFound()->assertJsonStructure(['message']);
});

it('returns 422 when required product fields are missing', function () {
    $this->authenticateApiAs(User::factory()->create(['role' => 'admin', 'is_active' => true]));

    $this->postJson('/api/products')
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['category_id', 'sku', 'name']);

    $this->assertDatabaseEmpty('products');
});

it('returns 422 for a duplicate SKU, missing category and invalid stock values', function () {
    $this->authenticateApiAs(User::factory()->create(['role' => 'admin', 'is_active' => true]));
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
    $this->authenticateApiAs($admin);
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
