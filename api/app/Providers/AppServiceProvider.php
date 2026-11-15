<?php

namespace App\Providers;

use App\Auth\Google\GoogleJwtVerifier;
use App\Auth\Google\GoogleTokenVerifier;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(
            GoogleTokenVerifier::class,
            fn () => new GoogleJwtVerifier((string) config('registrar.google_client_id')),
        );
    }

    public function boot(): void
    {
        // 10 tentatives par 15 minutes pour un même identifiant depuis une même adresse IP.
        RateLimiter::for('login', fn (Request $request) => Limit::perMinutes(15, 10)->by(
            mb_strtolower((string) $request->input('identifier', 'google')).'|'.$request->ip()
        ));
    }
}
