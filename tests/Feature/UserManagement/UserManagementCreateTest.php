<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Feature\UserManagement\RecordsUserManagementResponses;

pest()->use(RefreshDatabase::class, RecordsUserManagementResponses::class);

beforeEach(function () {
    $this->admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
    $this->actingAs($this->admin, 'web');
    $this->userPayload = [
        'name' => 'New Employee',
        'email' => 'new-employee@example.test',
        'password' => 'new-password-2026',
        'password_confirmation' => 'new-password-2026',
    ];
});

it('creates an active account with the requested or default role', function (array $roleInput, string $role, string $message) {
    $response = $this->postJson('/api/users', $this->userPayload + $roleInput)->assertCreated();
    $user = User::query()->findOrFail($response->json('data.id'));

    $response->assertExactJson([
        'message' => $message,
        'data' => [
            'id' => $user->id,
            'name' => $this->userPayload['name'],
            'email' => $this->userPayload['email'],
            'role' => $role,
            'is_active' => true,
            'created_at' => $user->created_at->toJSON(),
            'updated_at' => $user->updated_at->toJSON(),
        ],
    ]);

    expect($user->role)->toBe($role)
        ->and($user->is_active)->toBeTrue()
        ->and($user->password)->not->toBe($this->userPayload['password'])
        ->and(Hash::check($this->userPayload['password'], $user->password))->toBeTrue();
    $this->assertDatabaseCount('users', 2);
})->with([
    'legacy employee request' => [[], 'employee', 'Employee created successfully'],
    'explicit employee role' => [['role' => 'employee'], 'employee', 'Employee created successfully'],
    'explicit admin role' => [['role' => 'admin'], 'admin', 'Admin created successfully'],
]);

it('hashes passwords that resemble an existing password hash', function () {
    $password = Hash::make('test-password-2026');
    $response = $this->postJson('/api/users', array_replace($this->userPayload, [
        'password' => $password,
        'password_confirmation' => $password,
    ]))->assertCreated();

    $user = User::query()->findOrFail($response->json('data.id'));
    expect($user->password)->not->toBe($password)
        ->and(Hash::check($password, $user->password))->toBeTrue();
});

it('rejects a duplicate email without creating a user', function () {
    User::factory()->create(['email' => $this->userPayload['email']]);

    $this->postJson('/api/users', $this->userPayload + ['role' => 'admin'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('email');

    $this->assertDatabaseCount('users', 2);
});

it('returns 422 if the email is taken after request validation', function () {
    User::creating(static function (User $user): void {
        User::withoutEvents(static fn () => User::factory()->create(['email' => $user->email]));
    });

    $this->postJson('/api/users', $this->userPayload + ['role' => 'admin'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('email');

    $this->assertDatabaseCount('users', 2);
    $this->assertDatabaseMissing('users', ['email' => $this->userPayload['email'], 'role' => 'admin']);
});

it('rejects missing required fields', function () {
    $this->postJson('/api/users', [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name', 'email', 'password']);

    $this->assertDatabaseCount('users', 1);
});

it('returns 422 for invalid or forbidden account creation fields', function (array $overrides, array $errors) {
    $this->postJson('/api/users', array_replace($this->userPayload, $overrides))
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
    'unknown role' => [['role' => 'superadmin'], ['role']],
    'incorrectly cased role' => [['role' => 'Admin'], ['role']],
    'role list' => [['role' => ['employee', 'admin']], ['role']],
    'client active status' => [['is_active' => false], ['is_active']],
    'explicit null role and status' => [['role' => null, 'is_active' => null], ['role', 'is_active']],
    'null byte in password' => [[
        'password' => "new-password\0more",
        'password_confirmation' => "new-password\0more",
    ], ['password']],
    'bcrypt password longer than 72 bytes' => [[
        'password' => str_repeat('é', 37),
        'password_confirmation' => str_repeat('é', 37),
    ], ['password']],
]);

it('rejects a password without confirmation', function () {
    $payload = $this->userPayload;
    unset($payload['password_confirmation']);

    $this->postJson('/api/users', $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('password');

    $this->assertDatabaseCount('users', 1);
});

it('creates an admin through the SPA session and requires CSRF verification', function () {
    $this->useStatefulSpaRequests();
    $this->enforceSpaCsrf();
    $this->replaySpaSession();

    $csrf = $this->get('/sanctum/csrf-cookie')->assertNoContent();
    $this->replaySpaSession($csrf);
    $login = $this->postJson('/api/login', ['email' => $this->admin->email, 'password' => 'password'], [
        'X-XSRF-TOKEN' => $csrf->getCookie('XSRF-TOKEN', decrypt: false)->getValue(),
    ])->assertOk()->assertJsonPath('user.id', $this->admin->id);

    $this->replaySpaSession($login);
    $this->getJson('/api/dashboard')->assertOk()->assertJsonPath('total_products', 0);

    $payload = $this->userPayload + ['role' => 'admin'];
    $this->replaySpaSession($login);
    $this->postJson('/api/users', $payload)->assertStatus(419);
    $this->assertDatabaseCount('users', 1);

    $this->replaySpaSession($login);
    $this->postJson('/api/users', $payload, [
        'X-XSRF-TOKEN' => $login->getCookie('XSRF-TOKEN', decrypt: false)->getValue(),
    ])->assertCreated()->assertJsonPath('data.role', 'admin');
    $this->assertDatabaseCount('users', 2);
});
