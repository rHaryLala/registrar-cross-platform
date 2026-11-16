<?php

namespace App\Domain\Grading;

use App\Domain\Teacher\TeacherAccess;
use App\Exceptions\ApiProblem;
use App\Models\User;
use App\Support\AuditTrail;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Validation des remises de notes par le registrariat (port de `remiseValider`, `remiseRejeter` et `app/.remise/traiter.php`).
 *
 * C'est le SEUL endroit de la nouvelle application qui écrit dans `t_2023_notes` (comme `traiter.php` dans l'ancienne) : réservé aux niveaux 1 à 3.
 *
 * Cycle d'une feuille : brouillon → soumise → validée, ou soumise → rejetée (avec motif) → l'enseignant reprend.
 *
 * « Annuler » est vrai côté serveur (docs/ETUDE-05 §4) : `approve` n'écrit aucune note. Il enregistre une demande (`t_remise_validation_attente`) qui ne peut
 * aboutir qu'au bout de 10 secondes ; `undo` la supprime. Passé le délai, la validation réelle est faite par `finalize` (l'écran qui attend) ou, onglet fermé,
 * par la commande planifiée `remises:finalize-due`. Les deux sont idempotents : une feuille n'est jamais validée deux fois.
 *
 * La validation elle-même est une transaction : tout ou rien. Chaque note écrite est doublée dans `t_notes_modification_history`, pour qu'elle apparaisse aussi
 * dans l'historique de l'étudiant.
 */
final class RemiseReview
{
    public const UNDO_SECONDS = 10;

    private const NAME_JOIN = 'LEFT JOIN tbl_2024_etudiant e ON e.id = (SELECT e2.id FROM tbl_2024_etudiant e2 WHERE e2.student_id = l.student_id ORDER BY e2.remove ASC, e2.id ASC LIMIT 1)';

    public function __construct(private readonly TeacherAccess $access, private readonly AuditTrail $audit) {}

    // ---------------------------------------------------------------- lecture

    /**
     * La file du registrariat : les feuilles, celles à valider d'abord.
     *
     * @return array{items: list<array<string, mixed>>, years: list<string>, counts: array<string, int>}
     */
    public function queue(User $user, ?string $status, ?string $year): array
    {
        $this->requireReviewer($user);
        $status = in_array($status, ['brouillon', 'soumise', 'validee', 'rejetee'], true) ? $status : null;

        $rows = DB::table('t_remise_notes as r')->leftJoin('teacher as t', 't.uid', '=', 'r.teacher_uid')->leftJoin('t_remise_validation_attente as a', 'a.remise_id', '=', 'r.id')
            ->when($status !== null, fn ($q) => $q->where('r.statut', $status))->when($year !== null && $year !== '', fn ($q) => $q->where('r.annee_scolaire', $year))
            ->orderByRaw("FIELD(r.statut, 'soumise', 'rejetee', 'brouillon', 'validee')")->orderByDesc('r.soumis_le')->orderByDesc('r.id')->limit(300)
            ->get(['r.id', 'r.cours_id', 'r.sigle', 'r.titre_cours', 'r.annee_scolaire', 'r.semestre', 'r.statut', 'r.soumis_le', 'r.traite_le', 'r.motif_rejet', 'r.modifie_le', 't.name', 't.lastName', 'a.valide_apres']);

        $items = [];
        foreach ($rows as $r) {
            $summary = null;
            if ($r->statut === 'soumise') {
                $remise = DB::table('t_remise_notes')->where('id', $r->id)->first();
                $check = GradingRules::checkFor($this->lines($remise), GradingLayout::of($remise));
                $summary = ['ready' => $check['ready'], 'total' => $check['total'], 'blockers' => count($check['blockers']), 'warnings' => count($check['warnings'])];
            }
            $items[] = [
                'id' => (int) $r->id, 'course_id' => (int) $r->cours_id, 'code' => $r->sigle, 'title' => $r->titre_cours, 'year' => $r->annee_scolaire,
                'semester' => $r->semestre === null ? null : (int) $r->semestre, 'status' => $r->statut, 'teacher' => trim($r->name.' '.$r->lastName) ?: null,
                'submitted_at' => $r->soumis_le, 'processed_at' => $r->traite_le, 'reject_reason' => $r->motif_rejet, 'modified_at' => $r->modifie_le,
                'pending_until' => $r->valide_apres, 'summary' => $summary,
            ];
        }

        return [
            'items' => $items,
            'years' => DB::table('t_remise_notes')->distinct()->orderByDesc('annee_scolaire')->pluck('annee_scolaire')->map(fn ($y) => (string) $y)->all(),
            'counts' => DB::table('t_remise_notes')->select('statut', DB::raw('COUNT(*) AS n'))->groupBy('statut')->pluck('n', 'statut')->map(fn ($n) => (int) $n)->all(),
        ];
    }

    /**
     * Une feuille, telle que le registrariat la contrôle : colonnes, notes calculées, ce que la validation écrirait, et ce qui est en base aujourd'hui.
     *
     * @return array<string, mixed>
     */
    public function detail(User $user, int $id): array
    {
        $this->requireReviewer($user);
        $remise = $this->find($id);
        $components = GradingLayout::of($remise);
        $lines = $this->lines($remise);
        $pending = DB::table('t_remise_validation_attente')->where('remise_id', $remise->id)->first();
        $teacher = $remise->teacher_uid ? DB::table('teacher')->where('uid', $remise->teacher_uid)->first() : null;

        $rows = [];
        $changes = 0;
        foreach ($lines as $l) {
            $calc = GradingRules::computeNoteFor($l['values'], $l['statut_special'], $components);
            $new = $l['note_id'] === null ? null : $this->gradeToWrite($l, $calc);
            $changes += $new !== null && (string) $l['grade_actuel'] !== $new ? 1 : 0;
            $rows[] = [
                'student_id' => $l['student_id'], 'last_name' => $l['last_name'], 'first_name' => $l['first_name'],
                'values' => (object) array_map(fn ($v) => $v === null ? null : (float) $v, array_intersect_key($l['values'], array_flip(array_column($components, 'slot')))),
                'statut_special' => $l['statut_special'] ?: null, 'commentaire' => $l['commentaire'], 'note' => $calc,
                'current_grade' => $l['note_id'] === null ? null : ($l['grade_actuel'] === null || $l['grade_actuel'] === '' ? null : (string) $l['grade_actuel']),
                'new_grade' => $new, 'linked' => $l['note_id'] !== null,
            ];
        }
        $check = GradingRules::checkFor($lines, $components);

        return [
            'id' => (int) $remise->id, 'course' => ['id' => (int) $remise->cours_id, 'code' => $remise->sigle, 'title' => $remise->titre_cours, 'teacher' => $teacher ? trim($teacher->name.' '.$teacher->lastName) : null],
            'year' => $remise->annee_scolaire, 'semester' => $remise->semestre === null ? null : (int) $remise->semestre,
            'status' => $remise->statut, 'reject_reason' => $remise->motif_rejet, 'submitted_at' => $remise->soumis_le, 'processed_at' => $remise->traite_le,
            'components' => $components, 'total_weight' => GradingRules::totalWeightOf($components), 'lines' => $rows, 'check' => $check, 'changes' => $changes,
            'pending' => $pending ? ['until' => $pending->valide_apres, 'remaining_ms' => max(0, $this->msUntil($pending->valide_apres))] : null,
            'can_approve' => $remise->statut === 'soumise' && $check['blockers'] === [],
            'journal' => DB::table('t_remise_notes_journal')->where('remise_id', $remise->id)->orderByDesc('date_action')->orderByDesc('id')->limit(100)->get()
                ->map(fn ($j) => ['at' => (string) $j->date_action, 'author' => $j->auteur_nom, 'role' => $j->auteur_role, 'action' => $j->action, 'field' => $j->champ, 'student_id' => $j->student_id, 'old' => $j->ancienne_valeur, 'new' => $j->nouvelle_valeur])->all(),
            'undo_seconds' => self::UNDO_SECONDS,
        ];
    }

    // --------------------------------------------------------------- décisions

    /**
     * Demande la validation : AUCUNE note n'est écrite. La demande peut être annulée pendant 10 secondes. Refusée tant que le contrôle a des points bloquants.
     *
     * @return array{until: string, remaining_ms: int, seconds: int}
     */
    public function approve(User $user, int $id): array
    {
        $this->requireReviewer($user);

        return DB::transaction(function () use ($user, $id) {
            $remise = $this->find($id, lock: true);
            if ($remise->statut !== 'soumise') {
                throw new ApiProblem(409, 'review.not_submitted', 'Remise non transmise', 'Seule une remise transmise peut être validée.');
            }
            $check = GradingRules::checkFor($this->lines($remise), GradingLayout::of($remise));
            if ($check['blockers'] !== []) {
                throw new ApiProblem(422, 'review.not_ready', 'Incohérences à lever', 'Incohérences à lever avant validation.', ['check' => $check]);
            }

            $pending = DB::table('t_remise_validation_attente')->where('remise_id', $remise->id)->first();
            if (! $pending) {
                $now = Carbon::now();
                $row = ['remise_id' => $remise->id, 'demande_par' => $user->id, 'demande_le' => $now->format('Y-m-d H:i:s'), 'valide_apres' => $now->copy()->addSeconds(self::UNDO_SECONDS)->format('Y-m-d H:i:s')];
                $row['id'] = DB::table('t_remise_validation_attente')->insertGetId($row);
                $this->audit->insert('t_remise_validation_attente', 'id', $row);
                $pending = (object) $row;
            }

            return ['until' => (string) $pending->valide_apres, 'remaining_ms' => max(0, $this->msUntil($pending->valide_apres)), 'seconds' => self::UNDO_SECONDS];
        });
    }

    /** Annule une demande encore dans son délai. Rien n'avait été écrit. */
    public function undo(User $user, int $id): array
    {
        $this->requireReviewer($user);

        return DB::transaction(function () use ($id) {
            $remise = $this->find($id, lock: true);
            $pending = DB::table('t_remise_validation_attente')->where('remise_id', $remise->id)->first();
            if (! $pending) {
                throw new ApiProblem(409, 'review.nothing_to_undo', 'Rien à annuler', 'Aucune validation en cours : elle est déjà faite, ou n’a pas été demandée.');
            }
            if ($this->msUntil($pending->valide_apres) <= 0) {
                throw new ApiProblem(409, 'review.too_late', 'Délai d’annulation dépassé', 'Le délai d’annulation de '.self::UNDO_SECONDS.' secondes est passé : la validation va aboutir.');
            }
            DB::table('t_remise_validation_attente')->where('id', $pending->id)->delete();
            $this->audit->delete('t_remise_validation_attente', 'id', [(array) $pending]);

            return ['status' => $remise->statut, 'undone' => true];
        });
    }

    /**
     * Mène la validation à son terme si le délai d'annulation est passé. Appelée par l'écran qui attend ; sans effet de plus si c'est déjà fait.
     *
     * @return array<string, mixed>
     */
    public function finalize(User $user, int $id): array
    {
        $this->requireReviewer($user);

        $problem = null;
        $result = DB::transaction(function () use ($user, $id, &$problem) {
            $remise = $this->find($id, lock: true);
            $pending = DB::table('t_remise_validation_attente')->where('remise_id', $remise->id)->first();
            if (! $pending) {
                if ($remise->statut === 'validee') {
                    return ['status' => 'validee', 'written' => null, 'already' => true];
                }
                throw new ApiProblem(409, 'review.nothing_pending', 'Aucune validation en cours', 'Cette validation a été annulée ou n’a pas été demandée.');
            }
            $left = $this->msUntil($pending->valide_apres);
            if ($left > 0) {
                throw new ApiProblem(409, 'review.not_due', 'Délai d’annulation en cours', 'La validation peut encore être annulée.', ['remaining_ms' => $left]);
            }

            try {
                return $this->execute($remise, (int) ($pending->demande_par ?? $user->id), $user) + ['already' => false];
            } catch (ApiProblem $e) {
                $problem = $e;   // la feuille ne peut plus être validée : la demande est levée (execute l'a fait) ET cette levée doit être conservée

                return null;
            }
        });
        if ($problem) {
            throw $problem;
        }

        return $result;
    }

    /**
     * Valide toutes les demandes dont le délai est passé (commande planifiée : l'onglet qui les a demandées peut avoir été fermé).
     *
     * @return int le nombre de feuilles validées
     */
    public function finalizeDue(?callable $each = null): int
    {
        $done = 0;
        $due = DB::table('t_remise_validation_attente')->where('valide_apres', '<=', Carbon::now()->format('Y-m-d H:i:s'))->orderBy('id')->pluck('remise_id');
        foreach ($due as $remiseId) {
            $this->audit->reset();
            $result = DB::transaction(function () use ($remiseId) {
                $remise = $this->find((int) $remiseId, lock: true);
                $pending = DB::table('t_remise_validation_attente')->where('remise_id', $remise->id)->first();
                if (! $pending || $this->msUntil($pending->valide_apres) > 0) {
                    return null;
                }
                try {
                    return [$this->execute($remise, (int) ($pending->demande_par ?? 0), User::find($pending->demande_par)), $pending->demande_par];
                } catch (ApiProblem) {
                    return null;   // feuille revenue en arrière entre-temps : la demande est levée (execute), rien n'est écrit
                }
            });
            if ($result) {
                $done++;
                if ($each) {
                    $each($remiseId, $result[1]);
                }
            }
        }

        return $done;
    }

    /** Renvoie la feuille à l'enseignant, avec le motif. Il redevient libre de corriger. */
    public function reject(User $user, int $id, string $reason): array
    {
        $this->requireReviewer($user);
        $reason = trim($reason);
        if ($reason === '') {
            throw new ApiProblem(422, 'review.reason_required', 'Motif obligatoire', 'Indiquez à l’enseignant ce qui doit être corrigé.');
        }

        return DB::transaction(function () use ($user, $id, $reason) {
            $remise = $this->find($id, lock: true);
            if ($remise->statut !== 'soumise') {
                throw new ApiProblem(409, 'review.not_submitted', 'Remise non transmise', 'Seule une remise transmise peut être renvoyée.');
            }
            $now = Carbon::now()->format('Y-m-d H:i:s');
            $after = ['statut' => 'rejetee', 'motif_rejet' => $reason, 'traite_par' => $user->id, 'traite_le' => $now, 'modifie_le' => $now];
            DB::table('t_remise_notes')->where('id', $remise->id)->update($after);
            $this->audit->update('t_remise_notes', 'id', (array) $remise, $after + (array) $remise);
            $this->journal($user->id, $user, (int) $remise->id, 'rejet', 'statut', 'soumise', 'rejetee — '.$reason, $now);
            if ($pending = DB::table('t_remise_validation_attente')->where('remise_id', $remise->id)->first()) {
                DB::table('t_remise_validation_attente')->where('id', $pending->id)->delete();
                $this->audit->delete('t_remise_validation_attente', 'id', [(array) $pending]);
            }

            return ['status' => 'rejetee'];
        });
    }

    // ------------------------------------------------------------------ privé

    // ------------------------------------------------------------ validation groupée

    /** Nombre maximal de feuilles par demande groupée (une demande = un journal d'activité : on le garde lisible). */
    public const BULK_MAX = 25;

    /**
     * Demande la validation de plusieurs feuilles d'un coup. Chaque feuille garde SA demande, SON délai d'annulation et SES points bloquants :
     * une feuille refusée n'empêche pas les autres, elle est rendue avec la raison.
     *
     * @param  list<int>  $ids
     * @return array{approved: list<array<string, mixed>>, skipped: list<array<string, mixed>>, seconds: int}
     */
    public function approveMany(User $user, array $ids): array
    {
        $r = $this->each($user, $ids, fn (int $id) => $this->approve($user, $id));

        return ['approved' => $r['done'], 'skipped' => $r['failed'], 'seconds' => self::UNDO_SECONDS];
    }

    /**
     * Annule plusieurs demandes encore dans leur délai.
     *
     * @param  list<int>  $ids
     * @return array{undone: list<array<string, mixed>>, skipped: list<array<string, mixed>>}
     */
    public function undoMany(User $user, array $ids): array
    {
        $r = $this->each($user, $ids, fn (int $id) => $this->undo($user, $id));

        return ['undone' => $r['done'], 'skipped' => $r['failed']];
    }

    /**
     * Mène à terme plusieurs validations dont le délai est passé. Une feuille dont le délai court encore est rendue avec le temps restant.
     *
     * @param  list<int>  $ids
     * @return array{validated: list<array<string, mixed>>, skipped: list<array<string, mixed>>}
     */
    public function finalizeMany(User $user, array $ids): array
    {
        $r = $this->each($user, $ids, fn (int $id) => $this->finalize($user, $id));

        return ['validated' => $r['done'], 'skipped' => $r['failed']];
    }

    /**
     * Applique une opération à chaque feuille, indépendamment : une erreur attendue (ApiProblem) met la feuille de côté sans arrêter les autres.
     *
     * @param  list<int>  $ids
     * @return array{done: list<array<string, mixed>>, failed: list<array<string, mixed>>}
     */
    private function each(User $user, array $ids, callable $operation): array
    {
        $this->requireReviewer($user);
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === [] || count($ids) > self::BULK_MAX) {
            throw new ApiProblem(422, 'review.bulk_size', 'Sélection invalide', 'Choisissez entre 1 et '.self::BULK_MAX.' feuilles.');
        }

        $done = [];
        $failed = [];
        foreach ($ids as $id) {
            try {
                $done[] = ['id' => $id] + $operation($id);
            } catch (ApiProblem $e) {
                $failed[] = ['id' => $id, 'code' => $e->problemCode, 'title' => $e->getMessage(), 'detail' => $e->detail] + (isset($e->extra['remaining_ms']) ? ['remaining_ms' => (int) $e->extra['remaining_ms']] : []);
            }
        }

        return ['done' => $done, 'failed' => $failed];
    }

    private function requireReviewer(User $user): void
    {
        if (! $user->isActive() || ! $this->access->isStaff($user)) {
            throw new ApiProblem(403, 'forbidden.registrar_only', 'Réservé au registrariat', 'La validation des notes est réservée au registrariat.');
        }
    }

    private function find(int $id, bool $lock = false): object
    {
        $q = DB::table('t_remise_notes')->where('id', $id);

        return ($lock ? $q->lockForUpdate() : $q)->first() ?? throw new ApiProblem(404, 'review.not_found', 'Remise introuvable', 'Cette remise n’existe pas.');
    }

    private function msUntil(string $when): int
    {
        return (int) ((Carbon::parse($when)->getTimestamp() - Carbon::now()->getTimestamp()) * 1000);
    }

    /**
     * Les lignes d'une remise, avec le nom de l'étudiant, la note actuellement en base (celle que la validation remplacerait) et leurs valeurs par colonne.
     *
     * @return list<array<string, mixed>>
     */
    private function lines(object $remise): array
    {
        $rows = DB::select(
            'SELECT l.*, e.student_nom, e.student_prenom, n.grade AS grade_actuel, n.cours_category, n.title_cours
             FROM t_remise_notes_ligne l '.self::NAME_JOIN.'
             LEFT JOIN t_2023_notes n ON n.id = l.note_id
             WHERE l.remise_id = ? ORDER BY e.student_nom ASC, e.student_prenom ASC, l.student_id ASC',
            [$remise->id],
        );
        $stored = [];
        if (GradingLayout::isCustom($remise) && $rows !== []) {
            foreach (DB::table('t_remise_notes_valeur')->whereIn('ligne_id', array_column($rows, 'id'))->get() as $v) {
                $stored[$v->ligne_id][(int) $v->slot] = $v->valeur === null ? null : (float) $v->valeur;
            }
        }
        $custom = GradingLayout::isCustom($remise);

        return array_map(function ($r) use ($custom, $stored) {
            $a = (array) $r;

            return $a + [
                'values' => $custom ? ($stored[$r->id] ?? []) : GradingRules::legacyValues($a),
                'last_name' => $r->student_nom, 'first_name' => $r->student_prenom,
            ];
        }, $rows);
    }

    /**
     * Cours de validation, comme l'ancienne validation le décide LIGNE PAR LIGNE (data/notes.statut.php, noteEstCoursValidation) : catégorie 5, ou titre
     * « formation spirituelle ». (L'ancienne liste de lignes ne porte pas le sigle : « RELP 291 » n'y compte pas. Même résultat ici.)
     *
     * @param  array<string, mixed>  $line
     */
    private function isValidationLine(array $line): bool
    {
        return (int) ($line['cours_category'] ?? 0) === 5 || stripos((string) ($line['title_cours'] ?? ''), 'formation spirituelle') !== false;
    }

    /** La valeur à écrire dans `t_2023_notes.grade` : celle de la note, ou « pas encore noté » (-4) quand la ligne n'est pas transférable. */
    private function gradeToWrite(array $line, array $calc): string
    {
        return GradingRules::gradeFromNote($calc, $this->isValidationLine($line)) ?? '-4';
    }

    /**
     * La validation : la note de chaque ligne entre dans `t_2023_notes`, avec son historique. Tout se joue dans la transaction de l'appelant.
     *
     * @return array{status: string, written: int}
     */
    private function execute(object $remise, int $actorId, ?User $actor): array
    {
        $pending = DB::table('t_remise_validation_attente')->where('remise_id', $remise->id)->first();
        if ($remise->statut !== 'soumise') {
            if ($pending) {
                DB::table('t_remise_validation_attente')->where('id', $pending->id)->delete();
            }
            throw new ApiProblem(409, 'review.not_submitted', 'Remise non transmise', 'Seule une remise transmise peut être validée.');
        }
        $components = GradingLayout::of($remise);
        $lines = $this->lines($remise);
        $check = GradingRules::checkFor($lines, $components);
        if ($check['blockers'] !== []) {
            if ($pending) {
                DB::table('t_remise_validation_attente')->where('id', $pending->id)->delete();
            }
            throw new ApiProblem(422, 'review.not_ready', 'Incohérences à lever', 'Incohérences à lever avant validation.', ['check' => $check]);
        }

        $now = Carbon::now()->format('Y-m-d H:i:s');
        $ip = request()?->ip();
        $written = 0;
        foreach ($lines as $l) {
            if ($l['note_id'] === null) {
                continue;   // ligne orpheline : aucune inscription à mettre à jour
            }
            $grade = $this->gradeToWrite($l, GradingRules::computeNoteFor($l['values'], $l['statut_special'], $components));
            $old = $l['grade_actuel'];
            if ((string) $old === $grade) {
                continue;   // rien à écrire, rien à historiser
            }

            $before = ['id' => $l['note_id'], 'grade' => $old, 'last_change_user_id' => null, 'last_change_datetime' => null];
            $noteRow = DB::table('t_2023_notes')->where('id', $l['note_id'])->first(['session_id', 'last_change_user_id', 'last_change_datetime']);
            DB::table('t_2023_notes')->where('id', $l['note_id'])->update(['grade' => $grade, 'last_change_user_id' => $actorId, 'last_change_datetime' => $now]);
            $this->audit->update('t_2023_notes', 'id', ['last_change_user_id' => $noteRow->last_change_user_id ?? null, 'last_change_datetime' => $noteRow->last_change_datetime ?? null] + $before, ['grade' => $grade, 'last_change_user_id' => (string) $actorId, 'last_change_datetime' => $now] + $before);

            // old_grade et new_grade sont des DECIMAL : -2/-3/-4 y tiennent, une valeur non numérique (ancienne saisie libre) ferait échouer l'insertion.
            $history = [
                'note_id' => $l['note_id'], 'student_id' => $l['student_id'], 'session_id' => $noteRow->session_id ?: null,
                'cours_sigle' => $remise->sigle, 'cours_titre' => $l['title_cours'] ?? $remise->titre_cours,
                'old_grade' => is_numeric($old) ? $old : null, 'new_grade' => is_numeric($grade) ? $grade : null,
                'action_type' => ($old === null || $old === '') ? 'creation' : 'modification', 'action_by' => $actorId, 'action_date' => $now, 'ip_address' => $ip,
                'commentaire' => 'Validation de la remise #'.$remise->id.' de '.($remise->sigle ?? '').' ('.$remise->annee_scolaire.')',
            ];
            $this->audit->insert('t_notes_modification_history', 'id', ['id' => DB::table('t_notes_modification_history')->insertGetId($history)] + $history);
            $written++;
        }

        $after = ['statut' => 'validee', 'traite_par' => $actorId, 'traite_le' => $now, 'modifie_le' => $now, 'motif_rejet' => null];
        DB::table('t_remise_notes')->where('id', $remise->id)->update($after);
        $this->audit->update('t_remise_notes', 'id', (array) $remise, $after + (array) $remise);
        $this->journal($actorId, $actor, (int) $remise->id, 'validation', 'statut', 'soumise', 'validee — '.$written.' note(s) transferee(s)', $now);
        if ($pending) {
            DB::table('t_remise_validation_attente')->where('id', $pending->id)->delete();
            $this->audit->delete('t_remise_validation_attente', 'id', [(array) $pending]);
        }

        return ['status' => 'validee', 'written' => $written];
    }

    private function journal(int $actorId, ?User $actor, int $remiseId, string $action, string $field, ?string $old, ?string $new, string $at): void
    {
        $name = $actor ? (trim($actor->nom.' '.$actor->prenom) ?: $actor->pseudo) : null;
        $row = [
            'remise_id' => $remiseId, 'action' => $action, 'champ' => $field, 'ancienne_valeur' => $old, 'nouvelle_valeur' => $new === null ? null : mb_substr($new, 0, 255),
            'auteur_id' => $actorId ?: null, 'auteur_nom' => $name === null ? null : mb_substr((string) $name, 0, 150), 'auteur_role' => $actor ? mb_substr((string) $actor->privilege, 0, 30) : null,
            'date_action' => $at, 'ip' => request()?->ip(),
        ];
        $this->audit->insert('t_remise_notes_journal', 'id', ['id' => DB::table('t_remise_notes_journal')->insertGetId($row)] + $row);
    }
}
