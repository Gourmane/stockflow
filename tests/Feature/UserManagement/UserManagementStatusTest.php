<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\UserManagement\RecordsUserManagementResponses;

pest()->use(RefreshDatabase::class, RecordsUserManagementResponses::class);

beforeEach(function () {
    $this->admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
    $this->employee = User::factory()->create(['role' => 'employee', 'is_active' => true]);
    $this->statusRoute = "/api/users/{$this->employee->id}/status";
});

it('deactivates the employee and revokes all of their Sanctum tokens', function () {
    $adminToken = $this->admin->createToken('admin');
    $tokens = [$this->employee->createToken('employee-first'), $this->employee->createToken('employee-second')];

    $this->patchJson($this->statusRoute, ['is_active' => false], [
        'Authorization' => "Bearer {$adminToken->plainTextToken}",
    ])->assertOk()->assertJsonPath('data.id', $this->employee->id)->assertJsonPath('data.is_active', false);

    expect($this->employee->fresh()->is_active)->toBeFalse()
        ->and($this->employee->tokens()->count())->toBe(0);
    $this->assertModelExists($adminToken->accessToken);

    foreach ($tokens as $token) {
        $this->assertModelMissing($token->accessToken);
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/me', ['Authorization' => "Bearer {$token->plainTextToken}"])
            ->assertUnauthorized();
    }
});

it('blocks disabled login and requires fresh authentication after reactivation', function () {
    $credentials = ['email' => $this->employee->email, 'password' => 'password'];
    $oldToken = $this->postJson('/api/login', $credentials)->assertOk()->assertJsonStructure(['token'])->json('token');
    $oldHeaders = ['Authorization' => "Bearer {$oldToken}"];
    $adminToken = $this->admin->createToken('admin')->plainTextToken;
    $adminHeaders = ['Authorization' => "Bearer {$adminToken}"];

    $this->getJson('/api/me', $oldHeaders)->assertOk()->assertJsonPath('user.id', $this->employee->id);

    $this->app['auth']->forgetGuards();
    $this->patchJson($this->statusRoute, ['is_active' => false], $adminHeaders)
        ->assertOk()->assertJsonPath('data.is_active', false);
    $this->postJson('/api/login', $credentials)
        ->assertForbidden()->assertExactJson(['message' => 'Account is disabled']);
    expect($this->employee->tokens()->count())->toBe(0);

    $this->app['auth']->forgetGuards();
    $this->patchJson($this->statusRoute, ['is_active' => true], $adminHeaders)
        ->assertOk()->assertJsonPath('data.is_active', true);
    expect($this->employee->fresh()->is_active)->toBeTrue()
        ->and($this->employee->tokens()->count())->toBe(0);

    $this->app['auth']->forgetGuards();
    $this->getJson('/api/me', $oldHeaders)->assertUnauthorized();

    $newToken = $this->postJson('/api/login', $credentials)->assertOk()->assertJsonStructure(['token'])->json('token');
    expect($newToken)->not->toBe($oldToken);

    $this->app['auth']->forgetGuards();
    $this->getJson('/api/me', ['Authorization' => "Bearer {$newToken}"])
        ->assertOk()->assertJsonPath('user.id', $this->employee->id);
});

it('rejects an invalid employee status and preserves the account and token', function (array $payload) {
    $this->authenticateApiAs($this->admin);
    $token = $this->employee->createToken('employee');

    $this->patchJson($this->statusRoute, $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('is_active');

    expect($this->employee->fresh()->is_active)->toBeTrue();
    $this->assertModelExists($token->accessToken);
})->with([
    'missing status' => [[]],
    'invalid status' => [['is_active' => 'inactive']],
    'null status' => [['is_active' => null]],
]);

it('forbids changing an admin status and preserves the admin token', function () {
    $this->authenticateApiAs($this->admin);
    $target = User::factory()->create(['role' => 'admin', 'is_active' => true]);
    $token = $target->createToken('admin');

    $this->patchJson("/api/users/{$target->id}/status", ['is_active' => false])
        ->assertForbidden()
        ->assertExactJson(['message' => 'Only employee accounts can be managed.']);

    expect($target->fresh()->is_active)->toBeTrue();
    $this->assertModelExists($token->accessToken);
});
