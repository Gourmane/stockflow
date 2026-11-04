<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CategoryTest extends TestCase
{
    use RefreshDatabase;

    // GET all categories
    public function test_can_list_categories(): void
    {
        Category::create(['name' => 'Ordinateurs']);

        $response = $this->getJson('/api/categories');

        $response->assertOk()
                 ->assertJsonCount(1, 'data');
    }

    // GET category by ID
    public function test_can_show_category(): void
    {
        $category = Category::create(['name' => 'Imprimantes']);

        $response = $this->getJson("/api/categories/{$category->id}");

        $response->assertOk()
                 ->assertJsonPath('data.name', 'Imprimantes');
    }

    // POST create category
    public function test_can_create_category(): void
    {
        $response = $this->postJson('/api/categories', [
            'name' => 'Serveurs',
        ]);

        $response->assertCreated()
                 ->assertJsonPath('data.name', 'Serveurs');

        $this->assertDatabaseHas('categories', [
            'name' => 'Serveurs',
        ]);
    }

    // POST duplicate category
    public function test_cannot_create_duplicate_category(): void
    {
        Category::create(['name' => 'Serveurs']);

        $response = $this->postJson('/api/categories', [
            'name' => 'Serveurs',
        ]);

        $response->assertUnprocessable()
                 ->assertJsonValidationErrors(['name']);

        $this->assertDatabaseCount('categories', 1);
    }

    // PATCH update category
    public function test_can_update_category(): void
    {
        $category = Category::create(['name' => 'PC']);

        $response = $this->patchJson("/api/categories/{$category->id}", [
            'name' => 'Ordinateurs',
        ]);

        $response->assertOk()
                 ->assertJsonPath('data.name', 'Ordinateurs');

        $this->assertDatabaseHas('categories', [
            'id' => $category->id,
            'name' => 'Ordinateurs',
        ]);
    }

    // PATCH same name
    public function test_can_update_category_with_same_name(): void
    {
        $category = Category::create(['name' => 'Claviers']);

        $response = $this->patchJson("/api/categories/{$category->id}", [
            'name' => 'Claviers',
        ]);

        $response->assertOk();
    }

    // PATCH duplicate name
    public function test_cannot_update_to_duplicate_name(): void
    {
        Category::create(['name' => 'Ordinateurs']);
        $category = Category::create(['name' => 'Imprimantes']);

        $response = $this->patchJson("/api/categories/{$category->id}", [
            'name' => 'Ordinateurs',
        ]);

        $response->assertUnprocessable()
                 ->assertJsonValidationErrors(['name']);
    }

    // DELETE empty category
    public function test_can_delete_empty_category(): void
    {
        $category = Category::create(['name' => 'Accessoires']);

        $response = $this->deleteJson("/api/categories/{$category->id}");

        $response->assertOk();

        $this->assertDatabaseMissing('categories', [
            'id' => $category->id,
        ]);
    }

    // DELETE category containing products
    public function test_cannot_delete_category_with_products(): void
    {
        $category = Category::create(['name' => 'Ordinateurs']);

        Product::create([
            'category_id' => $category->id,
            'sku' => 'PC-001',
            'name' => 'Dell Laptop',
            'brand' => 'Dell',
            'unit_price' => 5000,
            'quantity' => 5,
            'min_stock' => 2,
            'is_active' => true,
        ]);

        $response = $this->deleteJson("/api/categories/{$category->id}");

        $response->assertStatus(409);

        $this->assertDatabaseHas('categories', [
            'id' => $category->id,
        ]);
    }

    // GET category not found
    public function test_returns_404_for_unknown_category(): void
    {
        $response = $this->getJson('/api/categories/99999');

        $response->assertNotFound();
    }
}
