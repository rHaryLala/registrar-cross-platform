<?php

namespace App\Domain\Finance;

use App\Exceptions\ApiProblem;
use App\Models\User;
use App\Support\AuditTrail;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Autorisation de réinscription (port de `src/finance/authorized_reinscription.php`, `data/reinscription.php`, `app/reinscription.save.php` et des deux
 * points d'entrée `update-authorization` / `update-dossier`).
 *
 * DEUX DRAPEAUX, DEUX RESPONSABLES. Un étudiant ne peut se réinscrire que si la caisse l'autorise (`authorized_reinscription`, niveaux 1 et 4)
 * ET si le registraire a validé son dossier (`dossier_ok`, niveaux 1 et 3). Chaque rôle ne touche que sa colonne. Voir la page : niveaux 1, 3 et 4.
 *
 * Ce qui change par rapport à l'ancienne application :
 *  - une bascule qui ne change rien (déjà dans l'état demandé) n'écrit plus rien : l'auteur et la date d'origine ne sont plus écrasés par un double clic ;
 *  - chaque bascule laisse une ligne dans l'historique de l'étudiant (`t_student_modification_history`), comme la réinitialisation globale le faisait déjà,
 *    et une ligne dans le journal d'activité ;
 *  - l'action groupée (jusqu'à 100 étudiants) : une transaction, un historique par étudiant, annulable en rejouant l'inverse sur les étudiants modifiés.
 *
 * Réinitialisation globale (superadmin SEUL) : portée exacte de l'ancienne. Chaque lot est photographié ligne par ligne dans
 * `t_reinscription_reinitialisation` (valeur, auteur, date d'origine), tracé dans l'historique de chaque étudiant, et annulable pendant sept jours ;
 * l'annulation ne restaure une ligne que si personne ne l'a modifiée depuis (valeur 0, même auteur, même horodatage à la seconde).
 */
final class Reenrolment
{
    /** @var array<string, array{column: string, by: string, date: string, label: string, history: string, levels: list<int>, done: string, undone: string, bulk_done: string, bulk_undone: string}> */
    public const FIELDS = [
        'authorization' => [
            'column' => 'authorized_reinscription', 'by' => 'authorized_by', 'date' => 'authorized_date', 'levels' => [1, 4],
            'label' => 'Autorisation de la caisse', 'history' => 'Autorisation de réinscription',
            'done' => 'Étudiant autorisé pour la réinscription', 'undone' => 'Autorisation retirée',
            'bulk_done' => 'autorisation(s) accordée(s)', 'bulk_undone' => 'autorisation(s) retirée(s)',
        ],
        'file' => [
            'column' => 'dossier_ok', 'by' => 'dossier_checked_by', 'date' => 'dossier_checked_date', 'levels' => [1, 3],
            'label' => 'Dossier validé par le registraire', 'history' => 'Dossier vérifié par le registraire',
            'done' => 'Dossier validé par le registraire', 'undone' => 'Validation du dossier retirée',
            'bulk_done' => 'dossier(s) validé(s)', 'bulk_undone' => 'validation(s) de dossier retirée(s)',
        ],
    ];

    public const PAGE_LEVELS = [1, 3, 4];

    public const BULK_MAX = 100;

    public const UNDO_DAYS = 7;

    /** La phrase à saisir pour la réinitialisation globale (accents et casse tolérés). */
    public const CONFIRMATION = 'REINITIALISER';

    public function __construct(private readonly AuditTrail $audit) {}

    // ------------------------------------------------------------------ droits

    public static function allows(User $user): bool
    {
        return $user->isActive() && in_array((int) $user->level, self::PAGE_LEVELS, true);
    }

    public static function canWrite(User $user, string $field): bool
    {
        return $user->isActive() && isset(self::FIELDS[$field]) && in_array((int) $user->level, self::FIELDS[$field]['levels'], true);
    }

    public static function canReset(User $user): bool
    {
        return $user->isActive() && (int) $user->level === 1;
    }

    /** Ce que CE compte peut faire sur la page, dit une fois en tête plutôt que découvert case par case. @return array{authorization: bool, file: bool, reset: bool} */
    public static function rights(User $user): array
    {
        return ['authorization' => self::canWrite($user, 'authorization'), 'file' => self::canWrite($user, 'file'), 'reset' => self::canReset($user)];
    }

    public function require(User $user): void
    {
        if (! self::allows($user)) {
            throw new ApiProblem(403, 'forbidden.reenrolment', 'Accès refusé', 'L’autorisation de réinscription est réservée au superadmin, au registrariat et à la comptabilité.');
        }
    }

    private function requireWrite(User $user, string $field): void
    {
        $this->require($user);
        if (! self::canWrite($user, $field)) {
            throw new ApiProblem(403, 'forbidden.reenrolment_field', 'Accès refusé', $field === 'authorization'
                ? 'Seuls le superadmin et la comptabilité peuvent autoriser une réinscription.'
                : 'Seuls le superadmin et le registrariat peuvent valider un dossier.');
        }
    }

    private function requireReset(User $user): void
    {
        $this->require($user);
        if (! self::canReset($user)) {
            throw new ApiProblem(403, 'forbidden.reenrolment_reset', 'Accès refusé', 'La réinitialisation globale est réservée au superadmin.');
        }
    }

    // ------------------------------------------------------------------ lecture

    /** Répartition des étudiants par état : les quatre états s'excluent et leur somme vaut le total (`rsnStatistiques`). @return array<string, int> */
    public function stats(): array
    {
        $r = (array) DB::selectOne(
            'SELECT COUNT(*) AS total,
                    SUM(COALESCE(authorized_reinscription,0) = 1 AND COALESCE(dossier_ok,0) = 1) AS prets,
                    SUM(COALESCE(authorized_reinscription,0) = 0 AND COALESCE(dossier_ok,0) = 1) AS attente_caisse,
                    SUM(COALESCE(authorized_reinscription,0) = 1 AND COALESCE(dossier_ok,0) = 0) AS attente_dossier,
                    SUM(COALESCE(authorized_reinscription,0) = 0 AND COALESCE(dossier_ok,0) = 0) AS aucun,
                    SUM(COALESCE(authorized_reinscription,0) = 1) AS autorises,
                    SUM(COALESCE(dossier_ok,0) = 1) AS dossiers_ok
               FROM tbl_2024_etudiant
              WHERE `remove` != 1'
        );
        $stats = [];
        foreach (['total', 'prets', 'attente_caisse', 'attente_dossier', 'aucun', 'autorises', 'dossiers_ok'] as $k) {
            $stats[$k] = (int) ($r[$k] ?? 0);
        }

        return $stats;
    }

    public static function state(bool $authorized, bool $file): string
    {
        return match (true) {
            $authorized && $file => 'prets',
            $file => 'attente_caisse',
            $authorized => 'attente_dossier',
            default => 'aucun',
        };
    }

    /** Les colonnes de la requête de la liste (utilisées par `Directory`) : le drapeau, qui l'a posé, quand. */
    public const SELECT = "COALESCE(e.authorized_reinscription, 0) AS autorise, e.authorized_date,
                           COALESCE(e.dossier_ok, 0) AS dossier, e.dossier_checked_date,
                           TRIM(CONCAT(COALESCE(u.prenom,''), ' ', COALESCE(u.nom,'')))   AS auth_par,
                           TRIM(CONCAT(COALESCE(ud.prenom,''), ' ', COALESCE(ud.nom,''))) AS dossier_par";

    public const FROM = 'tbl_2024_etudiant e
                         LEFT JOIN compt_utilisateur u  ON u.id  = e.authorized_by
                         LEFT JOIN compt_utilisateur ud ON ud.id = e.dossier_checked_by';

    /** @return array{value: bool, by: string|null, at: string|null} */
    public static function flag(mixed $value, ?string $by, ?string $at): array
    {
        $by = trim((string) $by);

        return ['value' => (int) $value === 1, 'by' => $by === '' ? null : $by, 'at' => $at ? Carbon::parse($at)->toIso8601String() : null];
    }

    /** Une ligne de la liste, telle que l'API la rend. @return array<string, mixed> */
    public static function row(object $r): array
    {
        $authorization = self::flag($r->autorise, $r->auth_par, $r->authorized_date);
        $file = self::flag($r->dossier, $r->dossier_par, $r->dossier_checked_date);

        return [
            'id' => (int) $r->id, 'student_id' => (string) $r->student_id, 'last_name' => $r->student_nom, 'first_name' => $r->student_prenom,
            'mention' => $r->etude_envisage, 'track' => $r->etude_option, 'year' => $r->annee_scolaire, 'residence' => $r->status,
            'level' => $r->annee_etude === null || $r->annee_etude === '' ? null : (int) $r->annee_etude,
            'level_label' => \App\Domain\Directory\Directory::levelLabel($r->annee_etude),
            'authorization' => $authorization, 'file' => $file,
            'state' => self::state($authorization['value'], $file['value']),
        ];
    }

    /** Une ligne, relue après écriture (pour que l'écran affiche l'auteur et l'heure posés par le serveur). @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        $r = DB::selectOne(
            'SELECT e.id, e.student_id, e.student_nom, e.student_prenom, e.etude_envisage, e.etude_option, e.annee_etude, e.annee_scolaire, e.status, '.self::SELECT
            .' FROM '.self::FROM.' WHERE e.id = ? AND e.`remove` != 1',
            [$id],
        );

        return $r ? self::row($r) : null;
    }

    // ------------------------------------------------------------------ bascules

    /**
     * Pose ou retire un drapeau pour un étudiant. Sans effet (et sans trace) s'il est déjà dans l'état demandé.
     *
     * @return array{changed: bool, row: array<string, mixed>, stats: array<string, int>, message: string}
     */
    public function toggle(User $user, int $id, string $field, bool $value, string $ip = '', string $ua = ''): array
    {
        $this->requireWrite($user, $field);
        $def = self::FIELDS[$field];

        $changed = DB::transaction(function () use ($user, $id, $def, $value, $ip, $ua) {
            $r = DB::selectOne("SELECT id, student_id, `{$def['column']}` AS v, `{$def['by']}` AS b, `{$def['date']}` AS d FROM tbl_2024_etudiant WHERE id = ? AND `remove` != 1 FOR UPDATE", [$id]);
            if (! $r) {
                throw new ApiProblem(404, 'not_found.student', 'Étudiant introuvable', 'Cet étudiant n’existe pas ou a été retiré.');
            }
            if (((int) ($r->v ?? 0) === 1) === $value) {
                return false;
            }
            $now = Carbon::now()->format('Y-m-d H:i:s');
            DB::update("UPDATE tbl_2024_etudiant SET `{$def['column']}` = ?, `{$def['by']}` = ?, `{$def['date']}` = ? WHERE id = ?", [$value ? 1 : 0, $user->id, $now, $id]);
            $this->history([[$r->student_id, $id]], $def, $value, $user, $now, $ip, $ua, null);
            $this->audit->update('tbl_2024_etudiant', 'id',
                ['id' => $id, $def['column'] => $r->v === null ? null : (int) $r->v, $def['by'] => $r->b, $def['date'] => $r->d],
                ['id' => $id, $def['column'] => $value ? 1 : 0, $def['by'] => $user->id, $def['date'] => $now]);

            return true;
        });

        return ['changed' => $changed, 'row' => $this->find($id) ?? [], 'stats' => $this->stats(), 'message' => $value ? $def['done'] : $def['undone']];
    }

    /**
     * La même bascule pour plusieurs étudiants (au plus {@see BULK_MAX}), en une transaction. Les étudiants déjà dans l'état demandé sont laissés tels quels.
     * Pour annuler : rejouer l'inverse sur `changed`.
     *
     * @param  list<int>  $ids
     * @return array{changed: list<int>, unchanged: int, missing: list<int>, stats: array<string, int>, message: string}
     */
    public function toggleMany(User $user, array $ids, string $field, bool $value, string $ip = '', string $ua = ''): array
    {
        $this->requireWrite($user, $field);
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === [] || count($ids) > self::BULK_MAX) {
            throw new ApiProblem(422, 'validation.bulk_size', 'Sélection invalide', 'Choisissez de 1 à '.self::BULK_MAX.' étudiants à la fois.');
        }
        $def = self::FIELDS[$field];

        [$changed, $missing, $unchanged] = DB::transaction(function () use ($user, $ids, $def, $value, $ip, $ua) {
            $marks = implode(',', array_fill(0, count($ids), '?'));
            $rows = DB::select("SELECT id, student_id, `{$def['column']}` AS v, `{$def['by']}` AS b, `{$def['date']}` AS d FROM tbl_2024_etudiant WHERE id IN ($marks) AND `remove` != 1 ORDER BY id FOR UPDATE", $ids);
            $found = array_map(fn ($r) => (int) $r->id, $rows);
            $missing = array_values(array_diff($ids, $found));
            $todo = array_values(array_filter($rows, fn ($r) => ((int) ($r->v ?? 0) === 1) !== $value));
            $unchanged = count($rows) - count($todo);
            if ($todo === []) {
                return [[], $missing, $unchanged];
            }
            $now = Carbon::now()->format('Y-m-d H:i:s');
            $todoIds = array_map(fn ($r) => (int) $r->id, $todo);
            DB::update("UPDATE tbl_2024_etudiant SET `{$def['column']}` = ?, `{$def['by']}` = ?, `{$def['date']}` = ? WHERE id IN (".implode(',', array_fill(0, count($todoIds), '?')).')',
                array_merge([$value ? 1 : 0, $user->id, $now], $todoIds));
            $this->history(array_map(fn ($r) => [$r->student_id, (int) $r->id], $todo), $def, $value, $user, $now, $ip, $ua, 'Action groupée ('.count($todo).' étudiant'.(count($todo) > 1 ? 's' : '').')');
            $this->audit->updateMany('tbl_2024_etudiant', 'id', array_map(fn ($r) => [
                'cle' => (int) $r->id,
                'avant' => [$def['column'] => $r->v === null ? null : (int) $r->v, $def['by'] => $r->b, $def['date'] => $r->d],
                'apres' => [$def['column'] => $value ? 1 : 0, $def['by'] => $user->id, $def['date'] => $now],
            ], $todo));

            return [$todoIds, $missing, $unchanged];
        });

        $n = count($changed);
        $message = $n === 0
            ? 'Rien à changer : tous les étudiants choisis étaient déjà dans cet état.'
            : $n.' '.($value ? $def['bulk_done'] : $def['bulk_undone']).'.';

        return ['changed' => $changed, 'unchanged' => $unchanged, 'missing' => $missing, 'stats' => $this->stats(), 'message' => $message];
    }

    /**
     * Les lignes d'historique de l'étudiant (visibles depuis sa fiche dans l'ancienne application). « Oui » / « Non » en toutes lettres : l'affichage
     * de l'ancien historique montre « Non renseigné » pour toute valeur vide, donc pour « 0 ».
     *
     * @param  list<array{0: string, 1: int}>  $students  [matricule, id]
     */
    private function history(array $students, array $def, bool $new, User $user, string $now, string $ip, string $ua, ?string $comment): void
    {
        DB::table('t_student_modification_history')->insert(array_map(fn ($s) => [
            'student_id' => $s[0], 'student_db_id' => $s[1], 'field_name' => $def['column'], 'field_label' => $def['history'],
            'old_value' => $new ? 'Non' : 'Oui', 'new_value' => $new ? 'Oui' : 'Non', 'action_type' => 'modification',
            'action_by' => $user->id, 'action_date' => $now, 'ip_address' => mb_substr($ip, 0, 45), 'user_agent' => mb_substr($ua, 0, 255), 'commentaire' => $comment,
        ], $students));
    }

    // ------------------------------------------------------------------ réinitialisation globale (superadmin)

    public function batchesAvailable(): bool
    {
        return Schema::hasTable('t_reinscription_reinitialisation');
    }

    /**
     * Les colonnes demandées, ramenées à des champs connus (un nom de colonne ne part jamais dans une requête sans passer par là).
     *
     * @return list<string> clés de {@see FIELDS}
     */
    private function fields(mixed $requested): array
    {
        return is_array($requested) ? array_values(array_intersect(array_keys(self::FIELDS), array_map('strval', $requested))) : [];
    }

    /**
     * Ce qu'une réinitialisation changerait, sans rien écrire (`rsnApercu`).
     *
     * @return array{by_field: array<string, int>, students: int, blocked: int, available: bool}
     */
    public function preview(User $user, mixed $columns): array
    {
        $this->requireReset($user);
        $fields = $this->fields($columns);

        $byField = [];
        foreach ($fields as $f) {
            $byField[$f] = (int) DB::selectOne('SELECT COUNT(*) AS n FROM tbl_2024_etudiant WHERE `remove` != 1 AND COALESCE(`'.self::FIELDS[$f]['column'].'`, 0) = 1')->n;
        }
        $students = 0;
        $blocked = 0;
        if ($fields !== []) {
            $or = implode(' OR ', array_map(fn ($f) => 'COALESCE(`'.self::FIELDS[$f]['column'].'`,0) = 1', $fields));
            $students = (int) DB::selectOne("SELECT COUNT(*) AS n FROM tbl_2024_etudiant WHERE `remove` != 1 AND ($or)")->n;
            // « Bloqués » : ceux qui pouvaient se réinscrire et ne le pourront plus. C'est le chiffre qui mesure la portée réelle du geste.
            $blocked = (int) DB::selectOne('SELECT COUNT(*) AS n FROM tbl_2024_etudiant WHERE `remove` != 1 AND COALESCE(authorized_reinscription,0) = 1 AND COALESCE(dossier_ok,0) = 1')->n;
        }

        return ['by_field' => $byField, 'students' => $students, 'blocked' => $blocked, 'available' => $this->batchesAvailable()];
    }

    /** La phrase de confirmation, revérifiée côté serveur : accents et casse tolérés (`reinscription.save.php`). */
    public static function confirmed(string $phrase): bool
    {
        return strtoupper(strtr(trim($phrase), ['É' => 'E', 'é' => 'E'])) === self::CONFIRMATION;
    }

    /**
     * Remet à « non coché » les colonnes demandées pour TOUS les étudiants (`rsnReinitialiser`). Une transaction, dans cet ordre : photographie des lignes
     * cochées (pour l'annulation), trace dans l'historique de chaque étudiant, remise à zéro avec l'auteur et un horodatage UNIQUE pour tout le lot.
     *
     * @return array{lot: string, by_field: array<string, int>, total: int, by: string, date: string, stats: array<string, int>, message: string}
     */
    public function reset(User $user, mixed $columns, string $confirmation, string $ip = '', string $ua = ''): array
    {
        $this->requireReset($user);
        if (! self::confirmed($confirmation)) {
            throw new ApiProblem(422, 'validation.confirmation', 'Confirmation manquante', 'Saisissez RÉINITIALISER pour valider.');
        }
        $fields = $this->fields($columns);
        if ($fields === []) {
            throw new ApiProblem(422, 'validation.columns', 'Aucune colonne choisie', 'Choisissez au moins une colonne à réinitialiser.');
        }
        if (! $this->batchesAvailable()) {
            throw new ApiProblem(409, 'conflict.batches_unavailable', 'Réinitialisation indisponible', 'La table d’annulation n’existe pas encore sur cette base : la réinitialisation reste bloquée tant qu’elle ne peut pas être annulée.');
        }

        $lot = bin2hex(random_bytes(8));
        // Un seul horodatage pour tout le lot : il sert de marque à l'annulation, qui compare à la seconde près.
        $now = Carbon::now()->format('Y-m-d H:i:s');
        $ip = mb_substr($ip, 0, 45);
        $ua = mb_substr($ua, 0, 255);

        $byField = DB::transaction(function () use ($fields, $user, $lot, $now, $ip, $ua) {
            $byField = [];
            foreach ($fields as $f) {
                $def = self::FIELDS[$f];
                [$col, $by, $date] = [$def['column'], $def['by'], $def['date']];

                $byField[$f] = DB::affectingStatement(
                    "INSERT INTO t_reinscription_reinitialisation (lot, student_db_id, student_id, colonne, ancienne_valeur, ancien_par, ancienne_date, fait_par, fait_le)
                     SELECT ?, e.id, e.student_id, ?, 1, e.`$by`, e.`$date`, ?, ?
                       FROM tbl_2024_etudiant e WHERE e.`remove` != 1 AND COALESCE(e.`$col`, 0) = 1",
                    [$lot, $col, $user->id, $now],
                );
                DB::insert(
                    "INSERT INTO t_student_modification_history (student_id, student_db_id, field_name, field_label, old_value, new_value, action_type, action_by, action_date, ip_address, user_agent, commentaire)
                     SELECT e.student_id, e.id, ?, ?, 'Oui', 'Non', 'modification', ?, ?, ?, ?, ?
                       FROM tbl_2024_etudiant e WHERE e.`remove` != 1 AND COALESCE(e.`$col`, 0) = 1",
                    [$col, $def['history'], $user->id, $now, $ip, $ua, 'Réinitialisation globale (lot '.$lot.')'],
                );
                $this->audit->updateMany('tbl_2024_etudiant', 'id', array_map(fn ($s) => [
                    'cle' => (int) $s->student_db_id,
                    'avant' => [$col => 1, $by => $s->ancien_par, $date => $s->ancienne_date],
                    'apres' => [$col => 0, $by => $user->id, $date => $now],
                ], DB::select('SELECT student_db_id, ancien_par, ancienne_date FROM t_reinscription_reinitialisation WHERE lot = ? AND colonne = ? ORDER BY id LIMIT 50', [$lot, $col])), $byField[$f]);
                DB::update("UPDATE tbl_2024_etudiant SET `$col` = 0, `$by` = ?, `$date` = ? WHERE `remove` != 1 AND COALESCE(`$col`, 0) = 1", [$user->id, $now]);
            }

            return $byField;
        });

        $total = array_sum($byField);

        return [
            'lot' => $lot, 'by_field' => $byField, 'total' => $total, 'by' => $this->userName((int) $user->id), 'date' => Carbon::parse($now)->format('d/m/Y'),
            'stats' => $this->stats(),
            'message' => $total === 0 ? 'Rien à réinitialiser : aucun étudiant n’était coché.' : $total.' case(s) remise(s) à « non coché ». Vous pouvez annuler depuis le bandeau.',
        ];
    }

    /**
     * Annule un lot : restaure les drapeaux que personne n'a touchés depuis (`rsnAnnuler`).
     *
     * @return array{restored: array<string, list<int>>, ignored: int, stats: array<string, int>, message: string}
     */
    public function undo(User $user, string $lot, string $ip = '', string $ua = ''): array
    {
        $this->requireReset($user);
        if (preg_match('/^[0-9a-f]{16}$/', $lot) !== 1) {
            throw new ApiProblem(422, 'validation.batch', 'Lot invalide', 'Ce lot n’existe pas.');
        }
        if (! $this->batchesAvailable()) {
            throw new ApiProblem(409, 'conflict.batches_unavailable', 'Annulation indisponible', 'La table des lots n’existe pas sur cette base.');
        }
        $parts = DB::select('SELECT colonne, COUNT(*) AS n, MIN(annule_le) AS deja FROM t_reinscription_reinitialisation WHERE lot = ? GROUP BY colonne', [$lot]);
        if ($parts === []) {
            throw new ApiProblem(404, 'not_found.batch', 'Lot introuvable', 'Ce lot n’existe pas.');
        }

        $now = Carbon::now()->format('Y-m-d H:i:s');
        $ip = mb_substr($ip, 0, 45);
        $ua = mb_substr($ua, 0, 255);
        $byColumn = [];
        foreach (self::FIELDS as $key => $d) {
            $byColumn[$d['column']] = $key;
        }

        $restored = [];
        $ignored = 0;
        DB::transaction(function () use ($parts, $byColumn, $lot, $user, $now, $ip, $ua, &$restored, &$ignored) {
            foreach ($parts as $c) {
                $col = (string) $c->colonne;
                if (! isset($byColumn[$col]) || $c->deja !== null) {
                    continue;   // colonne inconnue, ou cette partie du lot est déjà annulée
                }
                $def = self::FIELDS[$byColumn[$col]];
                [$by, $date] = [$def['by'], $def['date']];

                // Lignes restaurables : encore à 0, avec l'auteur et l'horodatage posés par le lot. Toute autre valeur : un humain est repassé depuis, on ne l'écrase pas.
                $targets = DB::select(
                    "SELECT s.id AS photo_id, e.id AS db_id, s.ancien_par, s.ancienne_date, e.student_id
                       FROM t_reinscription_reinitialisation s
                       JOIN tbl_2024_etudiant e ON e.id = s.student_db_id
                      WHERE s.lot = ? AND s.colonne = ? AND s.annule_le IS NULL
                        AND COALESCE(e.`$col`, 0) = 0 AND e.`$by` <=> s.fait_par AND e.`$date` <=> s.fait_le",
                    [$lot, $col],
                );
                $ignored += (int) $c->n - count($targets);

                if ($targets !== []) {
                    $ids = array_map(fn ($t) => (int) $t->db_id, $targets);
                    $marks = implode(',', array_fill(0, count($ids), '?'));
                    DB::insert(
                        "INSERT INTO t_student_modification_history (student_id, student_db_id, field_name, field_label, old_value, new_value, action_type, action_by, action_date, ip_address, user_agent, commentaire)
                         SELECT e.student_id, e.id, ?, ?, 'Non', 'Oui', 'modification', ?, ?, ?, ?, ?
                           FROM tbl_2024_etudiant e WHERE e.id IN ($marks)",
                        array_merge([$col, $def['history'], $user->id, $now, $ip, $ua, 'Annulation de la réinitialisation globale (lot '.$lot.')'], $ids),
                    );
                    DB::update(
                        "UPDATE tbl_2024_etudiant e
                           JOIN t_reinscription_reinitialisation s ON s.student_db_id = e.id AND s.lot = ? AND s.colonne = ?
                            SET e.`$col` = s.ancienne_valeur, e.`$by` = s.ancien_par, e.`$date` = s.ancienne_date
                          WHERE e.id IN ($marks)",
                        array_merge([$lot, $col], $ids),
                    );
                    $this->audit->updateMany('tbl_2024_etudiant', 'id', array_map(fn ($t) => [
                        'cle' => (int) $t->db_id, 'avant' => [$col => 0, $by => $user->id, $date => $now], 'apres' => [$col => 1, $by => $t->ancien_par, $date => $t->ancienne_date],
                    ], $targets));
                    $restored[$byColumn[$col]] = $ids;
                }

                // La partie du lot est close, restaurée ou non ligne à ligne.
                DB::update('UPDATE t_reinscription_reinitialisation SET annule_par = ?, annule_le = ? WHERE lot = ? AND colonne = ? AND annule_le IS NULL', [$user->id, $now, $lot, $col]);
            }
        });

        $n = array_sum(array_map('count', $restored));
        $message = $n.' case(s) restaurée(s) à leur état d’avant la réinitialisation.';
        if ($ignored > 0) {
            $message .= ' '.$ignored.' laissée(s) telle(s) quelle(s) : elles avaient été modifiées depuis.';
        }

        return ['restored' => $restored, 'ignored' => $ignored, 'stats' => $this->stats(), 'message' => $message];
    }

    /**
     * Dernier lot non annulé, s'il est récent (`rsnDernierLot`) : une réinitialisation faite par erreur reste rattrapable après un rechargement
     * ou depuis un autre poste. Superadmin seulement.
     *
     * @return array{lot: string, at: string, by: string|null, rows: int, columns: list<string>}|null
     */
    public function lastBatch(User $user): ?array
    {
        if (! self::canReset($user) || ! $this->batchesAvailable()) {
            return null;
        }
        $since = Carbon::now()->subDays(self::UNDO_DAYS)->format('Y-m-d H:i:s');
        $r = DB::selectOne(
            'SELECT s.lot, MAX(s.fait_le) AS fait_le, MAX(s.fait_par) AS fait_par, COUNT(*) AS lignes, GROUP_CONCAT(DISTINCT s.colonne ORDER BY s.colonne) AS colonnes
               FROM t_reinscription_reinitialisation s
              WHERE s.annule_le IS NULL AND s.fait_le >= ?
              GROUP BY s.lot ORDER BY fait_le DESC LIMIT 1',
            [$since],
        );
        if (! $r) {
            return null;
        }
        $byColumn = [];
        foreach (self::FIELDS as $key => $d) {
            $byColumn[$d['column']] = $key;
        }
        $name = $this->userName((int) $r->fait_par);

        return [
            'lot' => (string) $r->lot, 'at' => Carbon::parse($r->fait_le)->toIso8601String(), 'by' => $name === '' ? null : $name, 'rows' => (int) $r->lignes,
            'columns' => array_values(array_filter(array_map(fn ($c) => $byColumn[$c] ?? null, $r->colonnes !== null ? explode(',', $r->colonnes) : []))),
        ];
    }

    private function userName(int $id): string
    {
        $u = DB::selectOne('SELECT prenom, nom FROM compt_utilisateur WHERE id = ? LIMIT 1', [$id]);

        return $u ? trim(($u->prenom ?? '').' '.($u->nom ?? '')) : '';
    }
}
