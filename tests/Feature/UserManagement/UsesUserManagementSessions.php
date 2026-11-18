<?php

namespace Tests\Feature\UserManagement;

use App\Models\User;
use Illuminate\Testing\TestResponse;

trait UsesUserManagementSessions
{
    protected function useStatefulUserManagementRequests(): void
    {
        config([
            'sanctum.stateful' => ['stockflow.test'],
            'session.driver' => 'database',
            'session.connection' => 'mysql',
        ]);

        $this->withHeader('Origin', 'https://stockflow.test')->withCredentials();
    }

    protected function replayUserManagementSession(?TestResponse $response = null): void
    {
        // Rebuild request-scoped authentication and session state before replaying a cookie.
        $this->app['auth']->forgetGuards();
        $this->app->forgetInstance('auth.driver');
        $this->app['session']->forgetDrivers();
        $this->app->forgetInstance('session.store');

        $cookieName = config('session.cookie');
        $cookie = $response?->getCookie($cookieName, decrypt: false);
        $this->withUnencryptedCookie($cookieName, $cookie?->getValue() ?? '');
    }

    protected function loginForUserManagement(User $user): TestResponse
    {
        $this->replayUserManagementSession();

        return $this->postJson('/api/login', ['email' => $user->email, 'password' => 'password'])
            ->assertOk()
            ->assertJsonPath('user.id', $user->id)
            ->assertCookie(config('session.cookie'));
    }
}
