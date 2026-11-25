<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\UserManagement\RecordsUserManagementResponses;

pest()->use(RefreshDatabase::class, RecordsUserManagementResponses::class);

beforeEach(function () {
    $this->useStatefulSpaRequests();
    $this->admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
    $this->employee = User::factory()->create(['role' => 'employee', 'is_active' => true]);
    $this->statusRoute = "/api/users/{$this->employee->id}/status";
});

it('deactivates the employee and revokes all of their login sessions', function () {
    $adminSession = $this->loginThroughSpa($this->admin);
    $employeeSessions = [
        $this->loginThroughSpa($this->employee),
        $this->loginThroughSpa($this->employee),
    ];
    expect(DB::table('sessions')->where('user_id', $this->employee->id)->count())->toBe(2);

    $this->replaySpaSession($adminSession);
    $this->patchJson($this->statusRoute, ['is_active' => false])
        ->assertOk()->assertJsonPath('data.id', $this->employee->id)->assertJsonPath('data.is_active', false);

    expect($this->employee->fresh()->is_active)->toBeFalse();
    $this->assertDatabaseMissing('sessions', ['user_id' => $this->employee->id]);

    foreach ($employeeSessions as $session) {
        $this->replaySpaSession($session);
        $this->getJson('/api/me')->assertUnauthorized();
    }

    $this->replaySpaSession($adminSession);
    $this->getJson('/api/me')->assertOk()->assertJsonPath('user.id', $this->admin->id);
});

it('blocks disabled login and requires fresh authentication after reactivation', function () {
    $credentials = ['email' => $this->employee->email, 'password' => 'password'];
    $employeeSession = $this->loginThroughSpa($this->employee);
    $adminSession = $this->loginThroughSpa($this->admin);

    $this->replaySpaSession($employeeSession);
    $this->getJson('/api/me')->assertOk()->assertJsonPath('user.id', $this->employee->id);

    $this->replaySpaSession($adminSession);
    $this->patchJson($this->statusRoute, ['is_active' => false])
        ->assertOk()->assertJsonPath('data.is_active', false);

    $this->replaySpaSession();
    $this->postJson('/api/login', $credentials)
        ->assertForbidden()->assertExactJson(['message' => 'Account is disabled']);
    $this->assertDatabaseMissing('sessions', ['user_id' => $this->employee->id]);

    $this->replaySpaSession($adminSession);
    $this->patchJson($this->statusRoute, ['is_active' => true])
        ->assertOk()->assertJsonPath('data.is_active', true);
    expect($this->employee->fresh()->is_active)->toBeTrue();
    $this->assertDatabaseMissing('sessions', ['user_id' => $this->employee->id]);

    $this->replaySpaSession($employeeSession);
    $this->getJson('/api/me')->assertUnauthorized();

    $newSession = $this->loginThroughSpa($this->employee);
    $this->replaySpaSession($newSession);
    $this->getJson('/api/me')->assertOk()->assertJsonPath('user.id', $this->employee->id);
});

it('rejects an invalid employee status and preserves the account and session', function (array $payload) {
    $session = $this->loginThroughSpa($this->employee);
    $this->replaySpaSession();
    $this->actingAs($this->admin, 'web');

    $this->patchJson($this->statusRoute, $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('is_active');

    expect($this->employee->fresh()->is_active)->toBeTrue();
    $this->replaySpaSession($session);
    $this->getJson('/api/me')->assertOk()->assertJsonPath('user.id', $this->employee->id);
})->with([
    'missing status' => [[]],
    'invalid status' => [['is_active' => 'inactive']],
    'null status' => [['is_active' => null]],
]);

it('forbids deactivating an admin, including the last active admin', function (bool $onlyAdmin) {
    $target = $onlyAdmin ? $this->admin : User::factory()->create(['role' => 'admin', 'is_active' => true]);
    $session = $this->loginThroughSpa($this->admin);
    $this->replaySpaSession($session);

    $this->patchJson("/api/users/{$target->id}/status", ['is_active' => false])
        ->assertForbidden()
        ->assertExactJson(['message' => 'Only employee accounts can be managed.']);

    expect($target->fresh()->is_active)->toBeTrue();
    $this->replaySpaSession($session);
    $this->getJson('/api/me')->assertOk()->assertJsonPath('user.id', $this->admin->id);
})->with(['last active admin' => [true], 'another active admin' => [false]]);
