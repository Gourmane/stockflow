<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\UserManagement\RecordsUserManagementResponses;

pest()->use(RefreshDatabase::class, RecordsUserManagementResponses::class);

it('enforces user management authorization on each route', function (string $actor, int $expected, string $method, string $route, array $payload) {
    $employee = User::factory()->create(['role' => 'employee', 'is_active' => true]);

    if ($actor !== 'guest') {
        $user = User::factory()->create([
            'role' => $actor === 'disabled admin' ? 'admin' : 'employee',
            'is_active' => $actor === 'employee',
        ]);
        config(['sanctum.stateful' => ['stockflow.test']]);
        $this->actingAs($user, 'web')->withHeader('Origin', 'https://stockflow.test');
    }

    $this->json($method, str_replace('{id}', (string) $employee->id, $route), $payload)
        ->assertStatus($expected);

    $this->assertDatabaseCount('users', $actor === 'guest' ? 1 : 2);
    $this->assertDatabaseHas('users', [
        'id' => $employee->id,
        'name' => $employee->name,
        'is_active' => true,
    ]);
})->with([
    'guest' => ['guest', 401],
    'active employee' => ['employee', 403],
    'disabled admin' => ['disabled admin', 403],
    'disabled employee' => ['disabled employee', 403],
])->with([
    'list' => ['GET', '/api/users', []],
    'create employee' => ['POST', '/api/users', [
        'name' => 'New Employee',
        'email' => 'new-employee@example.test',
        'password' => 'new-password-2026',
        'password_confirmation' => 'new-password-2026',
        'role' => 'employee',
    ]],
    'create admin' => ['POST', '/api/users', [
        'name' => 'New Admin',
        'email' => 'new-admin@example.test',
        'password' => 'new-password-2026',
        'password_confirmation' => 'new-password-2026',
        'role' => 'admin',
    ]],
    'update' => ['PATCH', '/api/users/{id}', ['name' => 'Renamed Employee']],
    'status' => ['PATCH', '/api/users/{id}/status', ['is_active' => false]],
]);

it('exposes no public registration endpoint', function (string $path) {
    $this->postJson($path, [
        'name' => 'Uninvited User',
        'email' => 'uninvited@example.test',
        'password' => 'new-password-2026',
        'password_confirmation' => 'new-password-2026',
    ])->assertNotFound();

    $this->assertDatabaseEmpty('users');
})->with(['/api/register', '/register']);
