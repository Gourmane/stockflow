<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\UserManagement\RecordsUserManagementResponses;

pest()->use(RefreshDatabase::class, RecordsUserManagementResponses::class);

it('lists only employees in ascending name order with an exact public field set', function () {
    $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
    User::factory()->create(['role' => 'admin', 'is_active' => false]);
    $this->authenticateApiAs($admin);

    $last = User::factory()->create(['name' => 'Zara', 'role' => 'employee', 'is_active' => true]);
    $first = User::factory()->create(['name' => 'Amine', 'role' => 'employee', 'is_active' => false]);
    $middle = User::factory()->create(['name' => 'Leila', 'role' => 'employee', 'is_active' => true]);

    $this->getJson('/api/users')->assertOk()->assertExactJson([
        'data' => array_map(static fn (User $employee): array => [
            'id' => $employee->id,
            'name' => $employee->name,
            'email' => $employee->email,
            'role' => 'employee',
            'is_active' => $employee->is_active,
            'created_at' => $employee->created_at->toJSON(),
        ], [$first, $middle, $last]),
    ]);
});
