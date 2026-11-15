<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Actualités : copie locale du site public, au plus toutes les 30 minutes. Exige la tâche cron `* * * * * php artisan schedule:run`.
// withoutOverlapping : jamais deux synchronisations en même temps ; onOneServer n'est pas nécessaire (un seul serveur).
Schedule::command('news:sync')->everyThirtyMinutes()->withoutOverlapping(20);
