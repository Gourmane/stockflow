<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

pest()->use(RefreshDatabase::class);

it('returns zero counters and an empty history to active users', function (string $role) {
    $user = User::factory()->create(['role' => $role, 'is_active' => true]);

    $this->actingAs($user, 'web')->getJson('/api/dashboard')
        ->assertOk()
        ->assertExactJson([
            'total_products' => 0,
            'total_units' => 0,
            'low_stock_count' => 0,
            'out_of_stock_count' => 0,
            'recent_movements' => [],
        ]);
})->with(['admin', 'employee']);

it('returns 401 JSON without an authenticated account', function () {
    $this->get('/api/dashboard')
        ->assertUnauthorized()
        ->assertExactJson(['message' => 'Unauthenticated.']);
});

it('returns 403 for an inactive account', function (string $role) {
    config(['sanctum.stateful' => ['stockflow.test']]);
    $user = User::factory()->create(['role' => $role, 'is_active' => false]);

    $this->actingAs($user, 'web')->getJson('/api/dashboard', ['Origin' => 'https://stockflow.test'])
        ->assertForbidden()
        ->assertExactJson(['message' => 'Account disabled.']);
})->with(['admin', 'employee']);

it('aggregates active and inactive references with inclusive low stock thresholds', function () {
    $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
    $emptyProduct = $this->createProduct();
    $emptyProduct->update(['min_stock' => 4]);
    $category = $emptyProduct->category;

    foreach ([
        [0, 0, false],
        [2, 4, true],
        [3, 3, false],
        [5, 3, true],
        [4_294_967_295, 0, false],
    ] as [$quantity, $minStock, $active]) {
        $this->createProduct($category)->forceFill([
            'quantity' => $quantity,
            'min_stock' => $minStock,
            'is_active' => $active,
        ])->save();
    }

    $this->actingAs($admin, 'web')->getJson('/api/dashboard')
        ->assertOk()
        ->assertExactJson([
            'total_products' => 6,
            'total_units' => 4_294_967_305,
            'low_stock_count' => 2,
            'out_of_stock_count' => 2,
            'recent_movements' => [],
        ]);
});

it('returns the five latest movements ordered by date and then ID', function () {
    $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
    $employee = User::factory()->create(['role' => 'employee', 'is_active' => true]);
    $product = $this->createProduct();
    $secondProduct = $this->createProduct($product->category);
    $latestDate = Carbon::parse('2026-01-02 10:00:00', 'UTC');

    $latest = $this->travelTo($latestDate, fn () => $this->recordOpeningStock($product, $admin, 3));
    $sameDate = $this->travelTo($latestDate, fn () => $this->recordOpeningStock($secondProduct, $employee, 6));
    $older = [];

    foreach ([1, 2, 3, 4] as $daysBefore) {
        $older[] = $this->travelTo(
            $latestDate->copy()->subDays($daysBefore),
            fn () => $this->recordOpeningStock($product, $admin, 1),
        );
    }

    $secondProduct->update(['is_active' => false]);
    $this->expectsDatabaseQueryCount(4);

    $this->actingAs($employee, 'web')->getJson('/api/dashboard')
        ->assertOk()
        ->assertJsonCount(5, 'recent_movements')
        ->assertJsonPath('recent_movements.*.id', [
            $sameDate->id, $latest->id, $older[0]->id, $older[1]->id, $older[2]->id,
        ])
        ->assertJsonPath('recent_movements.0', [
            'id' => $sameDate->id,
            'type' => 'in',
            'quantity' => 6,
            'created_at' => '2026-01-02T10:00:00.000000Z',
            'product' => ['id' => $secondProduct->id, 'name' => $secondProduct->name, 'sku' => $secondProduct->sku],
            'user' => ['id' => $employee->id, 'name' => $employee->name],
        ]);
});

it('reflects a stock out recorded through the stock movements API', function () {
    $employee = User::factory()->create(['role' => 'employee', 'is_active' => true]);
    $product = $this->createProduct();
    $product->update(['min_stock' => 2]);
    $this->recordOpeningStock($product, $employee, 3);
    $this->actingAs($employee, 'web');

    $this->getJson('/api/dashboard')
        ->assertOk()
        ->assertJsonPath('total_units', 3)
        ->assertJsonPath('low_stock_count', 0);

    $this->postJson('/api/stock-movements', [
        'product_id' => $product->id,
        'type' => 'out',
        'quantity' => 1,
    ])->assertCreated();

    $this->getJson('/api/dashboard')
        ->assertOk()
        ->assertJsonPath('total_units', 2)
        ->assertJsonPath('low_stock_count', 1)
        ->assertJsonPath('recent_movements.0.type', 'out')
        ->assertJsonPath('recent_movements.0.quantity', 1);
});
