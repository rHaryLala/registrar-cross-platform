<?php

namespace App\Auth;

use App\Models\User;
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
 *   - un ancien mot de passe (sha256 + sel) est converti en bcrypt au passage.
 */
final class LoginService
{
    public function __construct(private readonly PasswordVerifier $passwords) {}

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
            $this->passwords->burn($password);

            return LoginResult::failed('unknown_account');
        }

        $check = $this->passwords->verify($password, (string) $user->password);

        if ($check === PasswordVerifier::FAIL) {
            return LoginResult::failed('bad_password', $user);
        }

        if ($check === PasswordVerifier::OK_LEGACY) {
            // Conversion transparente ; la connexion reste valide si elle échoue.
            try {
                DB::table('compt_utilisateur')->where('id', $user->id)->update(['password' => Hash::make($password)]);
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return LoginResult::success($user);
    }
}
