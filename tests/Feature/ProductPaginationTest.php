<?php

use App\Models\Category;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

pest()->use(RefreshDatabase::class);

it('returns native pagination metadata for an empty catalog', function () {
    $this->actingAs(User::factory()->create(['role' => 'admin', 'is_active' => true]), 'web');

    $this->getJson('/api/products')->assertOk()
        ->assertJsonPath('data', [])
        ->assertJsonPath('current_page', 1)
        ->assertJsonPath('last_page', 1)
        ->assertJsonPath('per_page', 10)
        ->assertJsonPath('total', 0)
        ->assertJsonPath('next_page_url', null)
        ->assertJsonPath('prev_page_url', null);
});

it('returns active and inactive products with their full category for each catalog reader', function (string $role) {
    $this->actingAs(User::factory()->create(['role' => $role, 'is_active' => true]), 'web');
    $category = Category::create(['name' => 'Accessories']);
    $active = $this->createProduct($category);
    $inactive = $this->createProduct($category);
    $inactive->update(['is_active' => false]);

    $this->getJson('/api/products')->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0', $active->refresh()->load('category')->toArray())
        ->assertJsonPath('data.1', $inactive->refresh()->load('category')->toArray())
        ->assertJsonPath('last_page', 1)
        ->assertJsonPath('total', 2)
        ->assertJsonPath('next_page_url', null);
})->with(['admin', 'employee']);

it('returns distinct default pages in stable product id order', function () {
    $this->actingAs(User::factory()->create(['role' => 'employee', 'is_active' => true]), 'web');
    $category = Category::create(['name' => 'Accessories']);
    $productIds = [];

    for ($index = 0; $index < 12; $index++) {
        $productIds[] = $this->createProduct($category)->id;
    }

    $firstPage = $this->getJson('/api/products?page=1')->assertOk()
        ->assertJsonPath('current_page', 1)
        ->assertJsonPath('last_page', 2)
        ->assertJsonPath('per_page', 10)
        ->assertJsonPath('total', 12)
        ->assertJsonPath('prev_page_url', null)
        ->assertJsonPath('next_page_url', url('/api/products').'?page=2');
    $secondPage = $this->getJson($firstPage->json('next_page_url'))->assertOk()
        ->assertJsonPath('current_page', 2)
        ->assertJsonPath('prev_page_url', url('/api/products').'?page=1')
        ->assertJsonPath('next_page_url', null);

    expect(array_column($firstPage->json('data'), 'id'))->toBe(array_slice($productIds, 0, 10))
        ->and(array_column($secondPage->json('data'), 'id'))->toBe(array_slice($productIds, 10))
        ->and($this->getJson('/api/products')->assertOk()->json('data'))->toBe($firstPage->json('data'));
});

it('accepts the requested page size and preserves it in navigation links', function (int $perPage, int $lastPage) {
    $this->actingAs(User::factory()->create(['role' => 'admin', 'is_active' => true]), 'web');
    $category = Category::create(['name' => 'Accessories']);

    for ($index = 0; $index < 101; $index++) {
        $this->createProduct($category);
    }

    $this->getJson('/api/products?per_page='.$perPage)->assertOk()
        ->assertJsonCount($perPage, 'data')
        ->assertJsonPath('per_page', $perPage)
        ->assertJsonPath('total', 101)
        ->assertJsonPath('last_page', $lastPage)
        ->assertJsonPath('next_page_url', url('/api/products')."?per_page={$perPage}&page=2");
})->with([[5, 21], [100, 2]]);

it('returns an empty page beyond the last page without changing the requested page', function () {
    $this->actingAs(User::factory()->create(['role' => 'employee', 'is_active' => true]), 'web');
    $this->createProduct();

    $this->getJson('/api/products?page=2&per_page=1')->assertOk()
        ->assertJsonPath('data', [])
        ->assertJsonPath('current_page', 2)
        ->assertJsonPath('last_page', 1)
        ->assertJsonPath('total', 1)
        ->assertJsonPath('from', null)
        ->assertJsonPath('to', null)
        ->assertJsonPath('next_page_url', null)
        ->assertJsonPath('prev_page_url', url('/api/products').'?per_page=1&page=1');
});

it('rejects invalid pagination parameters', function (string $query, string $parameter) {
    $this->actingAs(User::factory()->create(['role' => 'employee', 'is_active' => true]), 'web');

    $this->getJson('/api/products?'.$query)->assertUnprocessable()
        ->assertJsonValidationErrors([$parameter]);
})->with([
    'zero page' => ['page=0', 'page'],
    'negative page' => ['page=-1', 'page'],
    'fractional page' => ['page=1.5', 'page'],
    'empty page' => ['page=', 'page'],
    'array page' => ['page[]=1', 'page'],
    'page beyond safe SQL offset' => ['page='.(intdiv(PHP_INT_MAX, 100) + 1), 'page'],
    'zero page size' => ['per_page=0', 'per_page'],
    'negative page size' => ['per_page=-1', 'per_page'],
    'non-numeric page size' => ['per_page=all', 'per_page'],
    'fractional page size' => ['per_page=5.5', 'per_page'],
    'empty page size' => ['per_page=', 'per_page'],
    'array page size' => ['per_page[]=5', 'per_page'],
    'page size above the limit' => ['per_page=101', 'per_page'],
]);

it('requires authentication to read the catalog', function () {
    $this->getJson('/api/products')->assertUnauthorized();
});

it('paginates in SQL and loads categories with a single additional query', function () {
    $this->actingAs(User::factory()->create(['role' => 'employee', 'is_active' => true]), 'web');
    $category = Category::create(['name' => 'Accessories']);
    $this->createProduct($category);
    $this->createProduct($category);
    $first = $this->createProduct($category);
    $second = $this->createProduct(Category::create(['name' => 'Cables']));

    DB::enableQueryLog();
    $response = $this->getJson('/api/products?page=2&per_page=2');
    $queries = array_column(DB::getQueryLog(), 'query');
    DB::disableQueryLog();

    $response->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.category.id', $first->category_id)
        ->assertJsonPath('data.1.category.id', $second->category_id);

    expect($queries)->toHaveCount(3)
        ->and($queries[0])->toBe('select count(*) as `aggregate` from `products`')
        ->and($queries[1])->toBe('select * from `products` order by `id` asc limit 2 offset 2')
        ->and($queries[2])->toStartWith('select * from `categories` where `categories`.`id` in (');
});
