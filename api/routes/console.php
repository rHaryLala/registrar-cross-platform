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

// Validation des remises de notes : celles dont le délai d'annulation (10 s) est passé et dont l'onglet a été fermé.
Schedule::command('remises:finalize-due')->everyMinute()->withoutOverlapping(5);

// Candidatures : courriels et SMS de décision posés par le registrariat (trois essais espacés, puis « échec » relançable).
Schedule::command('applications:notify')->everyMinute()->withoutOverlapping(5);

// Comptes Google des étudiants créés : l'appel à Google n'est plus fait pendant la requête de création (trois essais espacés, puis « échec » relançable).
Schedule::command('students:google-accounts')->everyMinute()->withoutOverlapping(5);
