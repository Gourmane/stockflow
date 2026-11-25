<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

pest()->use(RefreshDatabase::class);

beforeEach(function () {
    $this->useStatefulSpaRequests();
});

it('logs in with credentials and restores the authenticated user from the session cookie', function () {
    $user = User::factory()->create(['role' => 'employee', 'is_active' => true]);

    $login = $this->loginThroughSpa($user);
    $login->assertJsonPath('message', 'Login successful')
        ->assertJsonPath('user.role', 'employee')
        ->assertJsonMissingPath('user.password');

    $this->replaySpaSession($login);
    $this->getJson('/api/me')->assertOk()
        ->assertJsonPath('user.id', $user->id)
        ->assertJsonPath('user.email', $user->email);

    $this->replaySpaSession();
    $this->getJson('/api/me')->assertUnauthorized();
});

it('rejects invalid credentials and leaves the client unauthenticated', function (bool $knownAccount) {
    $email = $knownAccount ? User::factory()->create()->email : 'missing@example.test';

    $attempt = $this->postJson('/api/login', ['email' => $email, 'password' => 'wrong'])
        ->assertUnauthorized()
        ->assertJsonPath('message', 'Invalid credentials');

    $this->replaySpaSession($attempt);
    $this->getJson('/api/me')->assertUnauthorized();
})->with([true, false]);

it('validates malformed login data', function () {
    $this->postJson('/api/login', ['email' => 'invalid'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['email', 'password']);
});

it('rejects login for a disabled account', function () {
    $user = User::factory()->create(['is_active' => false]);

    $this->postJson('/api/login', ['email' => $user->email, 'password' => 'password'])
        ->assertForbidden()
        ->assertJsonPath('message', 'Account is disabled');
    $this->assertGuest('web');
});

it('invalidates the previous session when the account becomes inactive', function () {
    $user = User::factory()->create(['is_active' => true]);
    $login = $this->loginThroughSpa($user);
    $user->update(['is_active' => false]);

    $this->replaySpaSession($login);
    $this->getJson('/api/me')->assertForbidden()->assertJsonPath('message', 'Account disabled.');

    $this->replaySpaSession($login);
    $this->getJson('/api/me')->assertUnauthorized();
});

it('logs the user out and rejects the old session cookie', function () {
    $login = $this->loginThroughSpa(User::factory()->create(['is_active' => true]));

    $this->replaySpaSession($login);
    $logout = $this->postJson('/api/logout')->assertOk()->assertJsonPath('message', 'Logout successful');

    $this->replaySpaSession($logout);
    $this->getJson('/api/me')->assertUnauthorized();

    $this->replaySpaSession($login);
    $this->getJson('/api/me')->assertUnauthorized();
});

it('rejects an expired database session cookie', function () {
    config(['session.lifetime' => 10]);
    $user = User::factory()->create(['is_active' => true]);
    $login = $this->loginThroughSpa($user);

    $this->travel(11)->minutes();

    $this->replaySpaSession($login);
    $this->getJson('/api/me')->assertUnauthorized();
});

it('returns a JSON 401 for guests even without an Accept header', function () {
    $this->get('/api/me')->assertUnauthorized()->assertJsonStructure(['message']);
    $this->postJson('/api/logout')->assertUnauthorized();
});

it('rate limits repeated login attempts for the same email and IP', function () {
    $this->freezeTime();
    $payload = ['email' => 'first@example.test', 'password' => 'wrong'];

    for ($attempt = 0; $attempt < 5; $attempt++) {
        $this->postJson('/api/login', $payload)->assertUnauthorized();
    }

    $this->postJson('/api/login', ['email' => 'FIRST@example.test'] + $payload)
        ->assertTooManyRequests()
        ->assertHeader('Retry-After', '60');
    $this->postJson('/api/login', ['email' => 'second@example.test'] + $payload)
        ->assertUnauthorized();

    $this->travel(61)->seconds();
    $this->postJson('/api/login', $payload)->assertUnauthorized();
});

it('rejects missing and invalid CSRF tokens before authenticating', function () {
    $this->enforceSpaCsrf();
    $user = User::factory()->create(['is_active' => true]);
    $credentials = ['email' => $user->email, 'password' => 'password'];

    $csrf = $this->get('/sanctum/csrf-cookie')->assertNoContent()->assertCookie('XSRF-TOKEN');
    foreach ([[], ['X-XSRF-TOKEN' => 'invalid']] as $headers) {
        $this->replaySpaSession($csrf);
        $this->postJson('/api/login', $credentials, $headers)->assertStatus(419);
    }

    $this->assertSame(0, DB::table('sessions')->where('user_id', $user->id)->count());

    $this->replaySpaSession($csrf);
    $login = $this->postJson('/api/login', $credentials, [
        'X-XSRF-TOKEN' => $csrf->getCookie('XSRF-TOKEN', decrypt: false)->getValue(),
    ])->assertOk();

    $this->replaySpaSession($login);
    $this->getJson('/api/me')->assertOk()->assertJsonPath('user.id', $user->id);
});

it('returns a JSON 405 for an unsupported method', function () {
    $this->get('/api/login')->assertMethodNotAllowed()->assertJsonStructure(['message']);
});
