<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Support\Facades\Gate;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
         Gate::define('manage-catalog', function (User $user) {
            return $user->role === 'admin'
                && $user->is_active;
        });

        Gate::define('manage-users', function (User $user) {
        return $user->role === 'admin'
            && $user->is_active;
    });

        RateLimiter::for('login', function (Request $request) {
            $email = strtolower(trim((string) $request->input('email')));

            return [
                Limit::perMinute(5)
                   ->by('login:'.$email.'|'.$request->ip()),

             Limit::perMinute(30)
                    ->by('login-ip:'.$request->ip()),
            ];
        });
    }
}
