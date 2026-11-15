<?php

namespace App\Http\Controllers\Api\V1;

use App\Auth\Google\GoogleLoginService;
use App\Auth\LoginResult;
use App\Auth\LoginService;
use App\Exceptions\ApiProblem;
use App\Http\Controllers\Controller;
use App\Support\SecurityLog;
use App\Support\UserPayload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

class AuthController extends Controller
{
    /** Pose le cookie XSRF-TOKEN que le front renvoie ensuite en en-tête. */
    public function csrf(): Response
    {
        return response()->noContent();
    }

    public function login(Request $request, LoginService $service): JsonResponse
    {
        $data = $request->validate([
            'identifier' => ['required', 'string', 'max:150'],
            'password' => ['required', 'string', 'max:200'],
        ]);

        return $this->finish($request, $service->attempt($data['identifier'], $data['password']), 'password');
    }

    public function google(Request $request, GoogleLoginService $service): JsonResponse
    {
        $data = $request->validate(['credential' => ['required', 'string', 'max:4096']]);

        return $this->finish($request, $service->attempt($data['credential']), 'google');
    }

    public function logout(Request $request): Response
    {
        SecurityLog::record('logout', $request->user()?->id);

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->noContent();
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['data' => UserPayload::from($request->user())]);
    }

    private function finish(Request $request, LoginResult $result, string $method): JsonResponse
    {
        if (! $request->hasSession()) {
            throw new ApiProblem(400, 'client.not_stateful', 'Requête non reconnue', 'Cette connexion doit venir de l’application Registrar.');
        }

        if (! $result->ok()) {
            $event = $method === 'password'
                ? 'login_failed'
                : ($result->reason === 'user_not_found' ? 'google_login_user_not_found' : 'google_login_rejected');

            SecurityLog::record($event, $result->knownUser?->id, ['reason' => $result->reason, 'method' => $method]);

            throw $this->problemFor($method, (string) $result->reason);
        }

        Auth::guard('web')->login($result->user);
        $request->session()->regenerate();

        SecurityLog::record($method === 'google' ? 'google_login_success' : 'login_success', $result->user->id, ['method' => $method, 'via' => $result->via]);

        return response()->json(['data' => UserPayload::from($result->user)]);
    }

    private function problemFor(string $method, string $reason): ApiProblem
    {
        if ($method === 'google') {
            return match ($reason) {
                'email_not_verified', 'invalid_domain' => new ApiProblem(403, 'auth.google_domain_not_allowed', 'Adresse non autorisée', 'Connectez-vous avec votre adresse @zurcher.edu.mg.'),
                'user_not_found' => new ApiProblem(401, 'auth.google_unknown_account', 'Aucun compte pour cette adresse', 'Aucun compte n’est associé à cette adresse. Contactez le registrariat.'),
                default => new ApiProblem(401, 'auth.google_invalid_token', 'Connexion Google impossible', 'Réessayez ; si cela continue, utilisez votre identifiant et votre mot de passe.'),
            };
        }

        if (in_array($reason, ['ambiguous_email', 'ambiguous_password'], true)) {
            return new ApiProblem(401, 'auth.ambiguous_email', 'Adresse partagée', 'Cette adresse est liée à plusieurs comptes. Connectez-vous avec votre matricule ou votre identifiant.');
        }

        // Un seul message pour « compte inconnu » et « mauvais mot de passe » : on ne révèle pas quels comptes existent.
        return new ApiProblem(401, 'auth.invalid_credentials', 'Identifiant ou mot de passe incorrect', 'Vérifiez votre matricule, votre identifiant ou votre adresse e-mail, et votre mot de passe. Si c’est le mot de passe initial commun à plusieurs étudiants, il n’ouvre plus de compte : utilisez « Continuer avec Google » ou demandez un mot de passe personnel au registrariat.');
    }
}
