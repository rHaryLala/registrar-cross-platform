<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * Comptes FICTIFS de la table `compt_utilisateur` (tests et développement local uniquement).
 *
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    public const PASSWORD = 'mot-de-passe-test';

    public function definition(): array
    {
        $n = fake()->unique()->numerify('####');

        return [
            'nom' => fake()->lastName(),
            'prenom' => fake()->firstName(),
            'post' => '',
            'pseudo' => 'test'.$n,
            'mail' => 'test'.$n.'@zurcher.edu.mg',
            'level' => 8,
            'password' => Hash::make(self::PASSWORD),
            'privilege' => 'student',
            'photos' => '',
            'etat' => 1,
            'theme' => 'dark',
            'update_user' => 0,
            'date_entry' => now()->toDateString(),
            'user_type' => 'student',
            'student_id' => 'UAZ-TEST-'.$n,
        ];
    }

    /** Compte du personnel : niveau 1 à 7 ou 9, sans matricule étudiant. */
    public function staff(int $level = 3, string $type = 'registrar'): static
    {
        return $this->state(fn () => ['level' => $level, 'user_type' => $type, 'privilege' => $type, 'student_id' => null]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['etat' => 0]);
    }

    /** Ancien format : sha256(mot de passe + sel statique), encore présent sur 11 comptes en production. */
    public function legacyPassword(?string $plain = null): static
    {
        return $this->state(fn () => ['password' => hash('sha256', ($plain ?? self::PASSWORD).config('registrar.legacy_password_salt'))]);
    }
}
