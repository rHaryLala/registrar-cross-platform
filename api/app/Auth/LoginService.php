<?php

namespace App\Auth;

use App\Models\User;
use App\Support\Role;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Connexion par identifiant et mot de passe.
 *
 * Règles reprises de l'ancien code (data/middleware.php, Middleware::authenticate) :
 *   - on cherche d'abord par `pseudo` ;
 *   - à défaut, par adresse e-mail, mais seulement si elle désigne UN SEUL compte
 *     actif (adresse partagée : refusée plutôt que tranchée au hasard) ;
 *   - seuls les comptes actifs (`etat = 1`) peuvent se connecter ;
 *   - un ancien mot de passe (sha256 + sel) est converti en bcrypt au passage ;
 *   - NOUVEAU : un étudiant peut aussi entrer avec le mot de passe de sa fiche (matricule ou e-mail), même sans compte
 *     utilisateur : voir StudentTableLogin.
 */
final class LoginService
{
    public function __construct(private readonly PasswordVerifier $passwords, private readonly StudentTableLogin $studentTable) {}

    public function attempt(string $identifier, string $password): LoginResult
    {
        $identifier = trim($identifier);
        if ($identifier === '' || $password === '') {
            return LoginResult::failed('empty_credentials');
        }

        $user = User::query()->where('pseudo', $identifier)->where('etat', 1)->first();

        if (! $user && str_contains($identifier, '@')) {
            $matches = User::query()
                ->whereRaw('LOWER(TRIM(mail)) = ?', [mb_strtolower($identifier)])
                ->where('etat', 1)
                ->limit(2)
                ->get();

            if ($matches->count() > 1) {
                return LoginResult::failed('ambiguous_email');
            }
            $user = $matches->first();
        }

        if (! $user) {
            // Pas de compte à ce pseudo / cette adresse : c'est peut-être un étudiant (matricule ou e-mail de sa fiche).
            return $this->studentTable->attempt($identifier, $password);
        }

        $check = $this->passwords->verify($password, (string) $user->password);

        if ($check === PasswordVerifier::FAIL) {
            // Compte étudiant (souvent créé à une connexion Google : mot de passe aléatoire) : le mot de passe de la fiche reste valable.
            if ($user->role() === Role::Student && ! empty($user->student_id)) {
                // Par e-mail (souvent partagé entre frères et sœurs) : le mot de passe de la fiche désigne l'étudiant, quel que
                // soit le compte trouvé par l'adresse. Par pseudo / matricule : on reste sur l'étudiant de CE compte.
                $viaStudent = str_contains($identifier, '@')
                    ? $this->studentTable->attempt($identifier, $password)
                    : $this->studentTable->attempt($identifier, $password, (string) $user->student_id);
                if ($viaStudent->ok()) {
                    return $viaStudent;
                }
            }

            return LoginResult::failed('bad_password', $user);
        }

        if ($check === PasswordVerifier::OK_LEGACY) {
            // Conversion transparente ; la connexion reste valide si elle échoue.
            try {
                $hash = Hash::make($password);
                DB::table('compt_utilisateur')->where('id', $user->id)->update(['password' => $hash]);
                // Le modèle en mémoire doit porter la NOUVELLE empreinte : la session retient l'empreinte du mot de passe
                // (AuthenticateSession), et une ancienne valeur déconnecterait l'étudiant dès sa requête suivante.
                $user->setRawAttributes(array_merge($user->getAttributes(), ['password' => $hash]), true);
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return LoginResult::success($user);
    }
}
