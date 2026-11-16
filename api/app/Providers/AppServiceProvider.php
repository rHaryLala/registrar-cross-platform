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
        // Un seul journal d'activité par requête : le domaine y déclare ce qu'il change, le contrôleur l'écrit.
        $this->app->scoped(\App\Support\AuditTrail::class);

        $this->app->bind(
            GoogleTokenVerifier::class,
            fn () => new GoogleJwtVerifier((string) config('registrar.google_client_id')),
        );
    }

    public function boot(): void
    {
        $this->app->bind(
            \App\Domain\News\SiteArticleParser::class,
            fn () => new \App\Domain\News\SiteArticleParser((string) config('registrar.site_news.base')),
        );
        $this->rateLimiters();
    }

    private function rateLimiters(): void
    {
        // 10 tentatives par 15 minutes pour un même identifiant depuis une même adresse IP.
        RateLimiter::for('login', fn (Request $request) => Limit::perMinutes(15, 10)->by(
            mb_strtolower((string) $request->input('identifier', 'google')).'|'.$request->ip()
        ));

        // Espace enseignant : 600 écritures par minute et par compte (un appel de 150 étudiants rejoué d'un coup reste largement permis).
        RateLimiter::for('teacher-write', fn (Request $request) => Limit::perMinute(600)->by('teacher|'.($request->user()?->id ?? $request->ip())));

        // Quiz : au plus 30 parties démarrées par heure et par compte.
        RateLimiter::for('quiz-start', fn (Request $request) => Limit::perHour(30)->by('quiz|'.($request->user()?->id ?? $request->ip())));
    }
}
