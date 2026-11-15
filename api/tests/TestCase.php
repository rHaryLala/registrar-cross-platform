<?php

namespace Tests;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    // Chaque test s'exécute dans une transaction annulée à la fin : la base de test reste vide.
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        // Garde-fou : ces tests écrivent dans la base. Ils ne doivent jamais tourner ailleurs que sur la base *_test.
        $db = DB::getDatabaseName();
        if (! str_ends_with((string) $db, '_test')) {
            throw new RuntimeException("Refus de lancer les tests sur la base « {$db} » : seule une base *_test est autorisée.");
        }
    }

    /**
     * Changer d'utilisateur en cours de test. Le magasin de session « array » survit d'une requête à l'autre dans un même
     * test : il garde l'empreinte du mot de passe du PREMIER utilisateur (middleware AuthenticateSession de Sanctum), et le
     * second serait déconnecté d'office (401 trompeur). On vide donc la session et les gardes avant chaque connexion simulée.
     */
    public function actingAs(\Illuminate\Contracts\Auth\Authenticatable $user, $guard = null)
    {
        $this->app['session.store']->flush();
        $this->app['auth']->forgetGuards();

        return parent::actingAs($user, $guard);
    }

    /** Une requête venant de l'application (domaine « stateful » de Sanctum) : session et cookies actifs. */
    protected function fromApp(): static
    {
        return $this->withHeaders(['Origin' => 'http://localhost:3000', 'Referer' => 'http://localhost:3000/']);
    }
}
