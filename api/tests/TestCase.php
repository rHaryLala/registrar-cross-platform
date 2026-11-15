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

    /** Une requête venant de l'application (domaine « stateful » de Sanctum) : session et cookies actifs. */
    protected function fromApp(): static
    {
        return $this->withHeaders(['Origin' => 'http://localhost:3000', 'Referer' => 'http://localhost:3000/']);
    }
}
