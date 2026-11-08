<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\UserManagement\RecordsUserManagementResponses;

pest()->use(RefreshDatabase::class, RecordsUserManagementResponses::class);

beforeEach(function () {
    $this->authenticateApiAs(User::factory()->create(['role' => 'admin', 'is_active' => true]));
    $this->employee = User::factory()->create(['role' => 'employee', 'is_active' => true]);
});

it('updates the employee name with a partial payload', function () {
    $this->patchJson("/api/users/{$this->employee->id}", ['name' => 'Updated Employee'])
        ->assertOk()
        ->assertJsonPath('data.id', $this->employee->id)
        ->assertJsonPath('data.name', 'Updated Employee')
        ->assertJsonPath('data.email', $this->employee->email);

    $this->assertDatabaseHas('users', [
        'id' => $this->employee->id,
        'name' => 'Updated Employee',
        'email' => $this->employee->email,
    ]);
});

it('updates an employee email and accepts their existing email', function (bool $keepEmail) {
    $email = $keepEmail ? $this->employee->email : 'updated-employee@example.test';

    $this->patchJson("/api/users/{$this->employee->id}", ['email' => $email])
        ->assertOk()
        ->assertJsonPath('data.id', $this->employee->id)
        ->assertJsonPath('data.email', $email)
        ->assertJsonPath('data.name', $this->employee->name)
        ->assertJsonMissingPaths(['data.password', 'data.remember_token']);

    $this->assertDatabaseHas('users', ['id' => $this->employee->id, 'email' => $email]);
})->with(['new address' => [false], 'unchanged address' => [true]]);

it('rejects another user email and preserves the original', function () {
    $other = User::factory()->create();

    $this->patchJson("/api/users/{$this->employee->id}", ['email' => $other->email])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('email');

    expect($this->employee->fresh()->email)->toBe($this->employee->email);
});

it('returns 404 for an unknown user', function () {
    $this->patchJson('/api/users/999999999', ['name' => 'Missing User'])->assertNotFound();
});

it('forbids modifying an admin profile', function () {
    $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);

    $this->patchJson("/api/users/{$admin->id}", ['name' => 'Updated Admin'])
        ->assertForbidden()
        ->assertExactJson(['message' => 'Only employee accounts can be managed.']);

    expect($admin->fresh()->name)->toBe($admin->name);
});

it('rejects direct changes to protected employee fields', function (string $field, mixed $value) {
    $this->patchJson("/api/users/{$this->employee->id}", [
        'name' => 'Should Not Persist',
        $field => $value,
    ])->assertUnprocessable()->assertJsonValidationErrors($field);

    $persisted = $this->employee->fresh();

    expect($persisted->role)->toBe('employee')
        ->and($persisted->password)->toBe($this->employee->password)
        ->and($persisted->is_active)->toBeTrue()
        ->and($persisted->name)->toBe($this->employee->name);
})->with([
    'role' => ['role', 'admin'],
    'password' => ['password', 'replacement-password'],
    'status' => ['is_active', false],
]);
