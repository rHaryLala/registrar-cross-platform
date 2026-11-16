<?php

namespace App\Domain\Admissions;

use App\Exceptions\ApiProblem;
use App\Models\User;
use App\Support\AuditTrail;
use App\Support\Role;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Candidatures de pré-admission reçues par le site (port de `AdmissionController` et `admissions.candidats.partie.php`).
 *
 * Accès : superadmin et registrariat (`requireAnyPrivileges(['superadmin', 'registrar'])`).
 *
 * Instruire une candidature = changer son statut (nouveau, en cours, accepté, refusé). « Converti » n'est jamais posé à la main : c'est la création
 * de l'étudiant qui le pose, pour que le lien candidat → étudiant reste fiable ; une candidature convertie ne bascule plus.
 * Ranger (archiver) n'est pas décider : l'archivage ne touche pas au statut.
 *
 * Accepter ou refuser PRÉVIENT le candidat (courriel et SMS). Dans l'ancienne application, la page attendait le serveur de messagerie et l'opérateur ;
 * ici, le changement de statut n'enregistre que la décision et pose une tâche par canal (`t_candidat_notification`) que la commande planifiée
 * `applications:notify` envoie, avec trois essais espacés. La décision ne dépend jamais de la disponibilité de la messagerie.
 */
final class Applications
{
    public const STATUSES = ['nouveau', 'en_cours', 'accepte', 'refuse'];

    public const LABELS = ['nouveau' => 'Nouveau', 'en_cours' => 'En cours', 'accepte' => 'Accepté', 'refuse' => 'Refusé', 'converti' => 'Converti'];

    public function __construct(private readonly AuditTrail $audit) {}

    public static function allows(User $user): bool
    {
        return $user->isActive() && in_array($user->role(), [Role::Superadmin, Role::Registrar], true);
    }

    private function require(User $user): void
    {
        if (! self::allows($user)) {
            throw new ApiProblem(403, 'forbidden.registrar_only', 'Réservé au registrariat', 'Seuls le superadmin et le registrariat ont accès aux candidatures.');
        }
    }

    /** Le nom de l'agent, lisible dans la candidature : « Prénom Nom », à défaut le pseudo. */
    private static function agent(User $user): ?string
    {
        $name = trim(trim((string) $user->prenom).' '.trim((string) $user->nom));

        return $name !== '' ? $name : (trim((string) $user->pseudo) ?: null);
    }

    // ------------------------------------------------------------------ lecture

    /**
     * @param  string|null  $filter  '', un statut, ou « archive » (une mise de côté, qui se combine à n'importe quelle décision)
     * @return array<string, mixed>
     */
    public function list(User $user, ?string $filter): array
    {
        $this->require($user);
        $filter = $filter === 'archive' || isset(self::LABELS[(string) $filter]) ? (string) $filter : '';

        $counts = [];
        $total = 0;
        foreach (DB::select('SELECT statut, COUNT(*) AS n FROM candidat WHERE archive_le IS NULL GROUP BY statut') as $r) {
            $counts[$r->statut] = (int) $r->n;
            $total += (int) $r->n;
        }
        $archived = (int) DB::selectOne('SELECT COUNT(*) AS n FROM candidat WHERE archive_le IS NOT NULL')->n;

        // La jointure rapporte l'identifiant de la fiche étudiante : un dossier converti montre un matricule cliquable. LEFT JOIN : si la fiche a été
        // supprimée, la candidature reste visible. Le COLLATE porte sur le côté `candidat` (les deux colonnes n'ont pas la même collation).
        $sql = 'SELECT c.id, c.nom_complet, c.email, c.telephone, c.mention, c.mention_libelle, c.diplome_vise, c.parcours, c.statut, c.student_id, c.traite_par,
                       c.date_creation, c.date_traitement, c.archive_le, c.notifie_le, c.notifie_sms_le, e.id AS fiche_etudiant
                FROM candidat c LEFT JOIN tbl_2024_etudiant e
                  ON e.id = (SELECT e2.id FROM tbl_2024_etudiant e2 WHERE e2.student_id = c.student_id COLLATE utf8mb4_general_ci ORDER BY e2.remove ASC, e2.id ASC LIMIT 1)';
        if ($filter === 'archive') {
            $rows = DB::select($sql.' WHERE c.archive_le IS NOT NULL ORDER BY c.archive_le DESC, c.id DESC');   // les archives se lisent par date de rangement
        } elseif ($filter !== '') {
            $rows = DB::select($sql.' WHERE c.archive_le IS NULL AND c.statut = ? ORDER BY c.date_creation DESC, c.id DESC', [$filter]);
        } else {
            $rows = DB::select($sql.' WHERE c.archive_le IS NULL ORDER BY c.date_creation DESC, c.id DESC');
        }

        $notices = $this->notices(array_map(fn ($r) => (int) $r->id, $rows));

        return [
            'filter' => $filter, 'total' => $total, 'archived' => $archived,
            'counts' => $this->fillCounts($counts),
            'rows' => array_map(fn ($r) => $this->summary($r, $notices[(int) $r->id] ?? []), $rows),
        ];
    }

    /** @param array<string, int> $counts @return array<string, int> chaque statut, zéro compris */
    private function fillCounts(array $counts): array
    {
        $out = [];
        foreach (array_keys(self::LABELS) as $s) {
            $out[$s] = $counts[$s] ?? 0;
        }

        return $out;
    }

    /** La fiche complète : tout ce que le candidat a saisi (filiation, tuteur, adresse, message entier). */
    public function detail(User $user, int $id): array
    {
        $this->require($user);
        $c = DB::selectOne(
            'SELECT c.*, e.id AS fiche_etudiant FROM candidat c LEFT JOIN tbl_2024_etudiant e
               ON e.id = (SELECT e2.id FROM tbl_2024_etudiant e2 WHERE e2.student_id = c.student_id COLLATE utf8mb4_general_ci ORDER BY e2.remove ASC, e2.id ASC LIMIT 1)
             WHERE c.id = ?',
            [$id],
        ) ?? throw new ApiProblem(404, 'applications.not_found', 'Candidature introuvable', 'Cette candidature n’existe pas.');
        $x = (array) $c;
        $notices = $this->notices([$id])[$id] ?? [];

        return $this->summary($c, $notices) + [
            'first_name' => $x['prenom'], 'last_name' => $x['nom'], 'gender' => $x['genre'], 'birth_date' => $x['date_naissance'], 'birth_year' => $x['annee_naissance'], 'birth_place' => $x['lieu_naissance'],
            'marital_status' => $x['etat_civil'], 'origin_country' => $x['pays_origine'], 'nationality' => $x['nationalite'], 'city' => $x['ville'], 'address' => $x['adresse'],
            'father' => $x['nom_pere'], 'mother' => $x['nom_mere'], 'parent_contact' => $x['contact_parent'], 'guardian' => $x['tuteur'], 'guardian_contact' => $x['contact_tuteur'],
            'baccalaureate_year' => $x['annee_bacc'], 'message' => $x['message'], 'internal_note' => $x['note_interne'],
        ];
    }

    /** @return array<string, mixed> */
    private function summary(object $r, array $notices): array
    {
        return [
            'id' => (int) $r->id, 'name' => $r->nom_complet, 'email' => $r->email ?: null, 'phone' => $r->telephone ?: null,
            'mention_code' => $r->mention, 'mention' => ($r->mention_libelle ?? '') !== '' ? $r->mention_libelle : $r->mention, 'degree' => $r->diplome_vise ?: null, 'track' => $r->parcours ?: null,
            'status' => $r->statut, 'status_label' => self::LABELS[$r->statut] ?? $r->statut, 'is_master' => Messages::isMaster((array) $r),
            'received_at' => $r->date_creation, 'processed_at' => $r->date_traitement, 'processed_by' => $r->traite_par, 'archived_at' => $r->archive_le,
            'student_id' => $r->student_id ?: null, 'student_file_id' => $r->fiche_etudiant === null ? null : (int) $r->fiche_etudiant,
            'notified_email_at' => $r->notifie_le, 'notified_sms_at' => $r->notifie_sms_le, 'notifications' => $notices,
        ];
    }

    /**
     * Les notifications de chaque candidature (la dernière tâche de chaque canal et décision).
     *
     * @param  list<int>  $ids
     * @return array<int, list<array<string, mixed>>>
     */
    private function notices(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $out = [];
        $rows = DB::table('t_candidat_notification')->whereIn('candidat_id', $ids)->orderBy('id')->get();
        $latest = [];
        foreach ($rows as $n) {
            $latest[$n->candidat_id][$n->canal.'|'.$n->decision] = $n;   // la plus récente l'emporte
        }
        foreach ($latest as $candidate => $tasks) {
            foreach ($tasks as $n) {
                $out[(int) $candidate][] = [
                    'id' => (int) $n->id, 'channel' => $n->canal, 'decision' => $n->decision, 'state' => $n->statut, 'reason' => $n->raison,
                    'attempts' => (int) $n->essais, 'next_attempt_at' => $n->prochain_essai, 'handled_at' => $n->traite_le,
                ];
            }
        }

        return $out;
    }

    // ------------------------------------------------------------------ instruction

    /**
     * Change le statut d'une candidature. Accepter ou refuser pose les tâches de notification (courriel, SMS).
     *
     * @return array{id: int, status: string, changed: bool, notifications: list<array<string, mixed>>}
     */
    public function setStatus(User $user, int $id, string $status): array
    {
        $this->require($user);
        if ($id <= 0 || ! in_array($status, self::STATUSES, true)) {
            throw new ApiProblem(422, 'applications.invalid_status', 'Demande invalide', 'Le statut demandé n’existe pas.');
        }

        return DB::transaction(function () use ($user, $id, $status) {
            $row = DB::table('candidat')->where('id', $id)->lockForUpdate()->first()
                ?? throw new ApiProblem(404, 'applications.not_found', 'Candidature introuvable', 'Cette candidature n’existe pas.');
            if ($row->statut === 'converti') {
                throw new ApiProblem(409, 'applications.converted', 'Déjà convertie', 'Cette candidature est déjà convertie en étudiant : son statut ne change plus.');
            }
            if ($row->statut === $status) {
                // Un second clic (ou une action rejouée) ne prévient pas le candidat une seconde fois.
                return ['id' => $id, 'status' => $status, 'changed' => false, 'notifications' => $this->notices([$id])[$id] ?? []];
            }

            $now = Carbon::now()->format('Y-m-d H:i:s');
            $after = ['statut' => $status, 'traite_par' => self::agent($user), 'date_traitement' => $now];
            DB::table('candidat')->where('id', $id)->update($after);
            $this->audit->update('candidat', 'id', (array) $row, $after + (array) $row);

            // La décision a changé : ce qui n'était pas encore parti ne doit plus partir.
            DB::table('t_candidat_notification')->where('candidat_id', $id)->where('statut', 'en_attente')->where('decision', '<>', $status)
                ->update(['statut' => 'ignoree', 'raison' => 'Décision modifiée avant l’envoi.', 'traite_le' => $now]);

            if (in_array($status, ['accepte', 'refuse'], true)) {
                $this->enqueue((array) $row, $status, $now);
            }

            return ['id' => $id, 'status' => $status, 'changed' => true, 'notifications' => $this->notices([$id])[$id] ?? []];
        });
    }

    /** Pose une tâche par canal ; celles qui n'ont pas lieu d'être sont gardées « ignorées », avec la raison (traçabilité). */
    private function enqueue(array $c, string $decision, string $now): void
    {
        $already = fn (string $channel) => DB::table('t_candidat_notification')->where('candidat_id', $c['id'])->where('canal', $channel)->where('decision', $decision)->where('statut', 'envoyee')->exists();

        $email = trim((string) ($c['email'] ?? ''));
        if ($decision === 'accepte' && Messages::isMaster($c)) {
            [$state, $reason] = ['ignoree', 'Master : le mail d’acceptation partira à la création de l’étudiant, avec son adresse et son mot de passe.'];
        } elseif ($already('email')) {
            [$state, $reason] = ['ignoree', 'Le candidat a déjà reçu ce courriel.'];
        } elseif ($email === '') {
            [$state, $reason] = ['ignoree', 'Le candidat n’a pas laissé d’adresse email.'];
        } elseif (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            [$state, $reason] = ['ignoree', 'Adresse email du candidat invalide.'];
        } else {
            [$state, $reason] = ['en_attente', null];
        }
        $this->task((int) $c['id'], 'email', $decision, $state, $reason, $now);

        $phone = trim((string) ($c['telephone'] ?? ''));
        if ($already('sms')) {
            [$state, $reason] = ['ignoree', 'Le candidat a déjà reçu ce SMS.'];
        } elseif ($phone === '') {
            [$state, $reason] = ['ignoree', 'Le candidat n’a pas laissé de numéro.'];
        } elseif (Sms::normalize($phone) === null) {
            [$state, $reason] = ['ignoree', 'Numéro de téléphone inexploitable.'];
        } else {
            [$state, $reason] = ['en_attente', null];
        }
        $this->task((int) $c['id'], 'sms', $decision, $state, $reason, $now);
    }

    private function task(int $candidate, string $channel, string $decision, string $state, ?string $reason, string $now): void
    {
        $row = ['candidat_id' => $candidate, 'canal' => $channel, 'decision' => $decision, 'statut' => $state, 'raison' => $reason, 'essais' => 0, 'prochain_essai' => null, 'cree_le' => $now, 'traite_le' => $state === 'ignoree' ? $now : null];
        $row['id'] = DB::table('t_candidat_notification')->insertGetId($row);
        $this->audit->insert('t_candidat_notification', 'id', $row);
    }

    /** Remet en attente les notifications échouées de la décision actuelle (« Réessayer »). @return int le nombre de tâches relancées */
    public function retry(User $user, int $id): int
    {
        $this->require($user);

        return DB::transaction(function () use ($id) {
            $row = DB::table('candidat')->where('id', $id)->lockForUpdate()->first() ?? throw new ApiProblem(404, 'applications.not_found', 'Candidature introuvable', 'Cette candidature n’existe pas.');
            $tasks = DB::table('t_candidat_notification')->where('candidat_id', $id)->where('statut', 'echec')->where(fn ($q) => $q->where('decision', $row->statut)->orWhere('decision', 'identifiants'))->get();
            foreach ($tasks as $t) {
                DB::table('t_candidat_notification')->where('id', $t->id)->update(['statut' => 'en_attente', 'essais' => 0, 'prochain_essai' => null, 'traite_le' => null]);
                $this->audit->update('t_candidat_notification', 'id', (array) $t, ['statut' => 'en_attente', 'essais' => 0, 'prochain_essai' => null, 'traite_le' => null] + (array) $t);
            }

            return $tasks->count();
        });
    }

    /** Range une candidature dans les archives, ou l'en ressort. L'archivage ne touche PAS au statut. */
    public function archive(User $user, int $id, bool $archive): array
    {
        $this->require($user);

        return DB::transaction(function () use ($user, $id, $archive) {
            $row = DB::table('candidat')->where('id', $id)->lockForUpdate()->first()
                ?? throw new ApiProblem(404, 'applications.not_found', 'Candidature introuvable', 'Cette candidature n’existe pas.');
            if ($archive === ($row->archive_le !== null)) {
                throw new ApiProblem(409, 'applications.already', 'Déjà dans cet état', $archive ? 'Cette candidature est déjà archivée.' : 'Cette candidature n’est pas archivée.');
            }
            $after = $archive ? ['archive_le' => Carbon::now()->format('Y-m-d H:i:s'), 'archive_par' => self::agent($user)] : ['archive_le' => null, 'archive_par' => null];
            DB::table('candidat')->where('id', $id)->update($after);
            $this->audit->update('candidat', 'id', (array) $row, $after + (array) $row);

            return ['id' => $id, 'archived' => $archive];
        });
    }
}
