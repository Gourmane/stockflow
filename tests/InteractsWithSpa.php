<?php

namespace Tests;

use App\Models\User;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Testing\TestResponse;

trait InteractsWithSpa
{
    protected function useStatefulSpaRequests(): void
    {
        config([
            'sanctum.stateful' => ['stockflow.test'],
            'session.driver' => 'database',
            'session.connection' => 'mysql',
        ]);

        $this->withHeader('Origin', 'https://stockflow.test')->withCredentials();
    }

    protected function replaySpaSession(?TestResponse $response = null): void
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

    protected function loginThroughSpa(User $user): TestResponse
    {
        $this->replaySpaSession();

        return $this->postJson('/api/login', ['email' => $user->email, 'password' => 'password'])
            ->assertOk()
            ->assertJsonPath('user.id', $user->id)
            ->assertCookie(config('session.cookie'));
    }

    protected function enforceSpaCsrf(): void
    {
        $this->app->bind(ValidateCsrfToken::class, fn ($app) => new class($app, $app->make(Encrypter::class)) extends ValidateCsrfToken
        {
            protected function runningUnitTests(): bool
            {
                return false;
            }
        });
    }
}
