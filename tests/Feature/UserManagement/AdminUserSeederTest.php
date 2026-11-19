<?php

use App\Models\User;
use Database\Seeders\AdminUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

pest()->use(RefreshDatabase::class);

it('seeds the first active admin with a verifiable password hash', function () {
    $password = 'first-admin-test-password';

    $this->artisan('db:seed', ['--class' => AdminUserSeeder::class])
        ->expectsQuestion('Admin full name', 'Initial Admin')
        ->expectsQuestion('Admin email', 'initial-admin@example.test')
        ->expectsQuestion('Admin password (minimum 12 characters)', $password)
        ->expectsQuestion('Confirm admin password', $password)
        ->expectsOutput('Admin created successfully.')
        ->assertSuccessful();

    $admin = User::query()->sole();
    expect($admin->name)->toBe('Initial Admin')
        ->and($admin->email)->toBe('initial-admin@example.test')
        ->and($admin->role)->toBe('admin')
        ->and($admin->is_active)->toBeTrue()
        ->and(Hash::check($password, $admin->password))->toBeTrue();
});

it('preserves an existing admin', function () {
    $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);

    $this->artisan('db:seed', ['--class' => AdminUserSeeder::class])
        ->expectsOutput('An admin already exists. No changes made.')
        ->assertSuccessful();

    $this->assertDatabaseCount('users', 1);
    $this->assertModelExists($admin);
});
