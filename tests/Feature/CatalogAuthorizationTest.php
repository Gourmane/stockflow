<?php

use App\Models\Category;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

pest()->use(RefreshDatabase::class);

it('allows employees to read catalog listings and details', function () {
    $this->authenticateApiAs(User::factory()->create(['role' => 'employee', 'is_active' => true]));
    $product = $this->createProduct();

    $this->getJson('/api/categories')
        ->assertOk()->assertJsonPath('data.0.id', $product->category_id);
    $this->getJson("/api/categories/{$product->category_id}")
        ->assertOk()->assertJsonPath('data.id', $product->category_id);
    $this->getJson('/api/products')
        ->assertOk()->assertJsonPath('data.0.id', $product->id);
    $this->getJson("/api/products/{$product->id}")
        ->assertOk()->assertJsonPath('data.id', $product->id);
});

it('returns 403 when an employee tries to write a product', function (string $method) {
    $employee = User::factory()->create(['role' => 'employee', 'is_active' => true]);
    $this->authenticateApiAs($employee);
    $product = $this->createProduct();
    $openingMovement = $this->recordOpeningStock($product, $employee, 4);
    $path = $method === 'POST' ? '/api/products' : "/api/products/{$product->id}";
    $payload = ['name' => 'Replacement', 'category_id' => $product->category_id, 'sku' => 'REPLACEMENT'];

    $this->json($method, $path, $payload)->assertForbidden();

    $this->assertDatabaseCount('products', 1);
    $this->assertDatabaseHas('products', [
        'id' => $product->id,
        'category_id' => $product->category_id,
        'sku' => $product->sku,
        'name' => $product->name,
        'quantity' => 4,
    ]);
    $this->assertDatabaseHas('stock_movements', $openingMovement->getAttributes());
    $this->assertDatabaseCount('stock_movements', 1);
})->with(['POST', 'PUT', 'PATCH', 'DELETE']);

it('returns 403 when an employee tries to write a category', function (string $method) {
    $this->authenticateApiAs(User::factory()->create(['role' => 'employee', 'is_active' => true]));
    $category = Category::create(['name' => 'Accessories']);
    $path = $method === 'POST' ? '/api/categories' : "/api/categories/{$category->id}";

    $this->json($method, $path, ['name' => 'Replacement'])->assertForbidden();

    $this->assertDatabaseCount('categories', 1);
    $this->assertDatabaseHas('categories', ['id' => $category->id, 'name' => 'Accessories']);
})->with(['POST', 'PUT', 'PATCH', 'DELETE']);
