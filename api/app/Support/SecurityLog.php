<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Écrit dans `security_logs`, le journal que l'ancienne application alimente déjà
 * et que la page « Journal d'activité » lit : un seul journal pour les deux.
 *
 * Noms d'événements repris de l'existant (login_success, login_failed,
 * google_login_success, google_login_user_not_found, google_login_rejected, logout).
 * Les motifs d'échec sont dans `details.reason` : c'est ce qui manquait pour
 * comprendre les ~20 % de connexions qui échouent.
 *
 * Jamais de mot de passe, ni de jeton, ni d'identifiant saisi à l'inconnu : un
 * utilisateur tape parfois son mot de passe dans le champ identifiant.
 */
final class SecurityLog
{
    /** @param array<string, mixed> $details */
    public static function record(string $event, ?int $userId, array $details = []): void
    {
        try {
            DB::table('security_logs')->insert([
                'event_type' => $event,
                'user_id' => $userId,
                'ip_address' => request()->ip() ?? '0.0.0.0',
                'user_agent' => request()->userAgent(),
                'details' => json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
        } catch (Throwable $e) {
            // Le journal ne doit jamais empêcher une connexion.
            report($e);
        }
    }
}
