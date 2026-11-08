<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Feature\UserManagement\RecordsUserManagementResponses;

pest()->use(RefreshDatabase::class, RecordsUserManagementResponses::class);

beforeEach(function () {
    $this->authenticateApiAs(User::factory()->create(['role' => 'admin', 'is_active' => true]));
    $this->employeePayload = [
        'name' => 'New Employee',
        'email' => 'new-employee@example.test',
        'password' => 'new-password-2026',
        'password_confirmation' => 'new-password-2026',
    ];
});

it('creates an active employee and stores a verifiable password hash', function () {
    $response = $this->postJson('/api/users', $this->employeePayload)
        ->assertCreated()
        ->assertJsonPath('data.name', $this->employeePayload['name'])
        ->assertJsonPath('data.email', $this->employeePayload['email'])
        ->assertJsonPath('data.role', 'employee')
        ->assertJsonPath('data.is_active', true)
        ->assertJsonMissingPaths(['data.password', 'data.remember_token']);

    $employee = User::query()->findOrFail($response->json('data.id'));

    expect($employee->role)->toBe('employee')
        ->and($employee->is_active)->toBeTrue()
        ->and($employee->password)->not->toBe($this->employeePayload['password'])
        ->and(Hash::check($this->employeePayload['password'], $employee->password))->toBeTrue();
    $this->assertDatabaseCount('users', 2);
});

it('rejects a duplicate email without creating a user', function () {
    User::factory()->create(['email' => $this->employeePayload['email']]);

    $this->postJson('/api/users', $this->employeePayload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('email');

    $this->assertDatabaseCount('users', 2);
});

it('rejects missing required fields', function () {
    $this->postJson('/api/users', [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name', 'email', 'password']);

    $this->assertDatabaseCount('users', 1);
});

it('rejects invalid or forbidden employee creation fields', function (array $overrides, array $errors) {
    $this->postJson('/api/users', array_replace($this->employeePayload, $overrides))
        ->assertUnprocessable()
        ->assertJsonValidationErrors($errors);

    $this->assertDatabaseCount('users', 1);
})->with([
    'invalid name, email and short password' => [[
        'name' => ['not a string'],
        'email' => 'invalid',
        'password' => 'short',
        'password_confirmation' => 'short',
    ], ['name', 'email', 'password']],
    'mismatched confirmation' => [['password_confirmation' => 'different-password'], ['password']],
    'client role' => [['role' => 'admin'], ['role']],
    'client active status' => [['is_active' => false], ['is_active']],
    'explicit null role and status' => [['role' => null, 'is_active' => null], ['role', 'is_active']],
]);

it('rejects a password without confirmation', function () {
    $payload = $this->employeePayload;
    unset($payload['password_confirmation']);

    $this->postJson('/api/users', $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('password');

    $this->assertDatabaseCount('users', 1);
});
