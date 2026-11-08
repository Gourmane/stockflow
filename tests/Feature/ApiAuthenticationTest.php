<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

pest()->use(RefreshDatabase::class);

it('issues a hashed Sanctum token that authenticates the account', function () {
    $user = User::factory()->create(['role' => 'employee', 'is_active' => true]);

    $response = $this->postJson('/api/login', ['email' => $user->email, 'password' => 'password']);

    $response->assertOk()
        ->assertJsonPath('user.id', $user->id)
        ->assertJsonPath('user.role', 'employee')
        ->assertJsonMissingPaths(['user.password', 'user.remember_token'])
        ->assertJsonStructure(['token']);

    $token = $response->json('token');
    [$tokenId, $secret] = explode('|', $token, 2);
    $this->assertDatabaseHas('personal_access_tokens', [
        'id' => $tokenId,
        'tokenable_id' => $user->id,
        'tokenable_type' => $user->getMorphClass(),
        'token' => hash('sha256', $secret),
    ]);

    $this->getJson('/api/me', ['Authorization' => "Bearer {$token}"])
        ->assertOk()
        ->assertJsonPath('user.id', $user->id);
});

it('returns 401 for invalid credentials without issuing a token', function (bool $knownAccount) {
    $user = User::factory()->create(['is_active' => true]);

    $this->postJson('/api/login', [
        'email' => $knownAccount ? $user->email : 'missing@example.test',
        'password' => 'invalid-password',
    ])->assertUnauthorized()->assertExactJson(['message' => 'Invalid credentials']);

    $this->assertDatabaseEmpty('personal_access_tokens');
})->with(['wrong password' => [true], 'unknown account' => [false]]);

it('returns 422 for malformed login data', function () {
    $this->postJson('/api/login', ['email' => 'invalid'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['email', 'password']);

    $this->assertDatabaseEmpty('personal_access_tokens');
});

it('returns 403 when a disabled account tries to log in', function () {
    $user = User::factory()->create(['is_active' => false]);

    $this->postJson('/api/login', ['email' => $user->email, 'password' => 'password'])
        ->assertForbidden()
        ->assertExactJson(['message' => 'Account is disabled']);

    $this->assertDatabaseEmpty('personal_access_tokens');
});

it('returns 403 for an existing token after its account is disabled', function () {
    $user = User::factory()->create(['is_active' => true]);
    $this->authenticateApiAs($user);
    $user->update(['is_active' => false]);

    $this->getJson('/api/me')
        ->assertForbidden()
        ->assertExactJson(['message' => 'Account disabled.']);
});

it('revokes only the presented token when logging out', function () {
    $user = User::factory()->create(['is_active' => true]);
    $current = $user->createToken('current');
    $other = $user->createToken('other');
    $currentHeaders = ['Authorization' => "Bearer {$current->plainTextToken}"];

    $this->postJson('/api/logout', [], $currentHeaders)
        ->assertOk()
        ->assertExactJson(['message' => 'Logout successful']);

    $this->assertModelMissing($current->accessToken);
    $this->assertModelExists($other->accessToken);

    $this->app['auth']->forgetGuards();
    $this->getJson('/api/me', $currentHeaders)->assertUnauthorized();

    $this->app['auth']->forgetGuards();
    $this->getJson('/api/me', ['Authorization' => "Bearer {$other->plainTextToken}"])
        ->assertOk()
        ->assertJsonPath('user.id', $user->id);
});

it('returns a JSON 401 to a guest even without an Accept header', function () {
    $this->get('/api/products')
        ->assertUnauthorized()
        ->assertExactJson(['message' => 'Unauthenticated.']);
});

it('returns 401 for a tampered Sanctum token', function () {
    $user = User::factory()->create(['is_active' => true]);
    $token = $user->createToken('feature-test')->plainTextToken;

    $this->getJson('/api/me', ['Authorization' => "Bearer {$token}tampered"])
        ->assertUnauthorized();
});

it('returns a JSON 405 for an unsupported HTTP method', function () {
    $this->get('/api/login')
        ->assertMethodNotAllowed()
        ->assertJsonStructure(['message']);
});
