<?php

namespace App\Domain\Teacher;

use App\Exceptions\ApiProblem;
use Illuminate\Support\Carbon;

/**
 * L'instant d'une écriture de l'espace enseignant, et le contrôle de conflit des écritures faites hors ligne.
 *
 * Le SERVEUR fait foi. Une écriture faite sans réseau arrive plus tard avec `client_at` (l'instant où l'enseignant l'a
 * faite, en millisecondes) : elle est datée de cet instant, et refusée en 409 si la donnée visée a été modifiée depuis.
 * `force` l'applique quand même, à l'heure actuelle. Un `client_at` de plus de 14 jours est refusé (« périmé ») : la file
 * hors ligne ne garde pas plus, et un si vieux changement écraserait sans le dire un travail plus récent. Un `client_at`
 * dans le futur (horloge de l'appareil en avance) est ramené à maintenant.
 *
 * `$domain` préfixe les codes d'erreur (`presence.conflict`, `grading.conflict`) que l'interface lit.
 */
final class WriteClock
{
    public const MAX_AGE_DAYS = 14;

    /** @return object{stamp: string, client: ?string} */
    public static function at(?int $clientAtMs, bool $force, string $domain): object
    {
        $now = Carbon::now();
        $client = null;
        if (! $force && $clientAtMs !== null) {
            $t = (int) round($clientAtMs / 1000);
            if ($t < $now->getTimestamp() - self::MAX_AGE_DAYS * 86400) {
                throw new ApiProblem(409, $domain.'.stale', 'Changement trop ancien', 'Ce changement a été fait hors ligne il y a plus de '.self::MAX_AGE_DAYS.' jours : il n’a pas été appliqué. Reprenez-le si besoin.', ['target' => 'stale']);
            }
            // createFromTimestamp rend du UTC : on le remet dans le fuseau de l'application, celui des dates de la base.
            $client = Carbon::createFromTimestamp(min($t, $now->getTimestamp()), $now->getTimezone());
        }
        $instant = $client ?? $now;

        return (object) ['stamp' => $instant->format('Y-m-d H:i:s'), 'client' => $client?->format('Y-m-d H:i:s')];
    }

    /** Refuse (409) une écriture faite hors ligne dont la donnée a bougé depuis. */
    public static function conflictIf(?string $modifiedAt, object $at, string $target, string $what, string $domain): void
    {
        if ($at->client !== null && $modifiedAt !== null && $modifiedAt > $at->client) {
            throw new ApiProblem(
                409, $domain.'.conflict', 'Donnée modifiée entre-temps',
                $what.' a été modifié depuis (le '.Carbon::parse($modifiedAt)->format('d/m à H:i').') : ce changement fait hors ligne n’a pas été appliqué.',
                ['target' => $target, 'modified_at' => $modifiedAt],
            );
        }
    }
}
