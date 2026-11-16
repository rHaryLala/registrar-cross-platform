<?php

namespace App\Domain\Teacher;

use App\Domain\Attendance\AttendanceRules;
use App\Domain\Grading\GradingLayout;
use App\Domain\Grading\GradingRules;
use App\Exceptions\ApiProblem;
use App\Models\User;
use App\Support\AuditTrail;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Remise de notes, côté enseignant (port de `data/remise.notes.php`, `app/.remise/enregistrer.php` et `soumettre.php`), avec des COLONNES LIBRES :
 * l'enseignant choisit le nombre de colonnes, leur titre et leur pondération ; seul le total (100 %) est exigé pour transmettre.
 *
 * RIEN de ce qui est saisi ici n'atteint le dossier de l'étudiant : la feuille s'écrit dans `t_remise_notes_ligne` / `t_remise_notes_valeur`, et seule la
 * VALIDATION du registrariat reporte les notes dans `t_2023_notes` (lot suivant ; l'ancienne application assure ce rôle d'ici là).
 *
 * Structure : tant qu'une remise n'a aucune ligne dans `t_remise_notes_composante`, c'est celle de l'ancienne feuille (cinq colonnes fixes, slots 1 à 5, lues
 * dans `t_remise_notes.poids_*` et `t_remise_notes_ligne`). La première écriture la « matérialise » dans les nouvelles tables, qui font alors foi ; les cinq
 * PREMIÈRES colonnes sont recopiées dans les colonnes historiques à chaque écriture, pour que l'ancienne validation reste exacte (feuille de cinq colonnes au
 * plus) ou bloque (total ≠ 100 % au-delà) au lieu de calculer une note fausse. Une feuille modifiée ici ne doit plus l'être dans l'ancienne application.
 *
 * Mêmes garanties que la présence (voir TeacherAttendance) : propriété vérifiée à chaque appel (« introuvable » pour un cours d'autrui) ; une lecture n'écrit
 * jamais ; chaque saisie est un « état voulu » ; hors ligne le serveur fait foi (409 si la donnée a changé, `force` pour appliquer quand même ; une feuille
 * figée reste refusée) ; chaque écriture laisse sa trace dans le journal de la remise ET dans le journal d'activité commun.
 */
final class TeacherGrading
{
    private const NAME_JOIN = 'LEFT JOIN tbl_2024_etudiant e ON e.id = (SELECT e2.id FROM tbl_2024_etudiant e2 WHERE e2.student_id = %s ORDER BY e2.remove ASC, e2.id ASC LIMIT 1)';

    private const SEMESTER_SQL = 'COALESCE(NULLIF(s.session_semester, 0), n.semester)';

    /** Une colonne ajoutée reçoit le slot 1000 + son numéro de ligne : jamais réutilisé, et distinct des slots 1 à 5 de l'ancienne feuille. */
    private const NEW_SLOT_BASE = 1000;

    public function __construct(
        private readonly TeacherAccess $access,
        private readonly TeacherAttendance $attendance,
        private readonly AuditTrail $audit,
    ) {}

    // ---------------------------------------------------------------- lecture

    /**
     * Mes cours de l'année, avec l'état de leur feuille de notes.
     *
     * @return array{linked: bool, year: string, years: list<string>, courses: list<array<string, mixed>>}
     */
    public function courses(User $user, ?string $year): array
    {
        $base = $this->attendance->courses($user, $year);
        $uid = $this->access->teacherUid($user);
        if (! $base['linked'] || $uid === null || $base['courses'] === []) {
            return ['linked' => $base['linked'], 'year' => $base['year'], 'years' => $base['years'], 'courses' => []];
        }

        $counts = [];
        foreach (DB::select(
            'SELECT c.id, '.self::SEMESTER_SQL.' AS semestre, c.nb_crd,
                    SUM(CASE WHEN n.grade IS NULL OR n.grade = "" OR n.grade < 0 THEN 1 ELSE 0 END) AS sans_note
             FROM t_2023_cours c JOIN t_2023_notes n ON n.id_cours = c.id
             LEFT JOIN t_2023_session s ON s.session_id = n.session_id '.sprintf(self::NAME_JOIN, 'n.student_id').'
             WHERE c.id_teacher = ? AND c.remove != 1 AND n.remove = 0 AND n.annee_scolaire = ?
               AND COALESCE(e.suspended, 0) = 0 AND COALESCE(e.retrait_universite, 0) = 0
             GROUP BY c.id, semestre, c.nb_crd',
            [$uid, $base['year']],
        ) as $r) {
            $counts[$r->id.'|'.($r->semestre ?? '')] = $r;
        }

        $states = [];
        foreach (DB::table('t_remise_notes')->where('teacher_uid', $uid)->where('annee_scolaire', $base['year'])->get() as $r) {
            $states[$r->cours_id.'|'.($r->semestre ?? '')] = $r;
        }

        $courses = [];
        foreach ($base['courses'] as $c) {
            $key = $c['id'].'|'.($c['semester'] ?? '');
            $r = $states[$key] ?? null;
            $n = $counts[$key] ?? null;
            $courses[] = [
                'id' => $c['id'], 'code' => $c['code'], 'title' => $c['title'], 'level' => $c['level'],
                'credits' => $n ? (int) $n->nb_crd : null,
                'semester' => $c['semester'], 'semester_label' => $c['semester_label'], 'students' => $c['students'],
                'without_grade' => $n ? (int) $n->sans_note : 0,
                'status' => $r->statut ?? 'brouillon',
                'modified_at' => $r->modifie_le ?? null, 'submitted_at' => $r->soumis_le ?? null, 'processed_at' => $r->traite_le ?? null,
                'reject_reason' => $r->motif_rejet ?? null,
            ];
        }

        return ['linked' => true, 'year' => $base['year'], 'years' => $base['years'], 'courses' => $courses];
    }

    /**
     * La feuille de notes d'un cours : colonnes et pondération, une ligne par étudiant (même ceux qui n'en ont pas encore), note calculée, contrôle.
     *
     * @return array<string, mixed>
     */
    public function sheet(User $user, int $courseId, string $year, ?int $semester): array
    {
        $course = $this->ownedCourse($user, $courseId);
        $remise = $this->findRemise($courseId, $year, $semester);
        $components = $this->layout($remise);
        $lines = $this->lines($remise, $courseId, $year, $semester);
        $states = $this->attendance->states($courseId, $year, $semester);

        $rows = array_map(fn (array $l) => $this->lineView($l, $components, $states[$l['student_id']] ?? null), $lines);
        $teacher = $course->id_teacher ? DB::table('teacher')->where('uid', $course->id_teacher)->first() : null;
        $status = $remise->statut ?? 'brouillon';

        return [
            'course' => [
                'id' => (int) $course->id, 'code' => $this->courseCode($course), 'title' => $course->title, 'credits' => $course->nb_crd === null ? null : (int) $course->nb_crd,
                'level' => $course->yearlevel === null ? null : (int) $course->yearlevel, 'teacher' => $teacher ? trim($teacher->name.' '.$teacher->lastName) : null,
                'validation_course' => $this->isValidationCourse($course),
            ],
            'year' => $year, 'semester' => $semester, 'semester_label' => AttendanceRules::semesterLabel($semester),
            'remise' => [
                'id' => $remise ? (int) $remise->id : null, 'status' => $status, 'editable' => in_array($status, ['brouillon', 'rejetee'], true),
                'reject_reason' => $remise->motif_rejet ?? null, 'submitted_at' => $remise->soumis_le ?? null, 'processed_at' => $remise->traite_le ?? null,
                'modified_at' => $remise->modifie_le ?? null,
            ],
            'components' => $components, 'total_weight' => GradingRules::totalWeightOf($components),
            'limits' => ['max_components' => GradingRules::MAX_COMPONENTS, 'max_label' => GradingRules::MAX_LABEL],
            'lines' => $rows,
            'check' => GradingRules::checkFor($lines, $components),
            'journal' => $remise ? $this->journal((int) $remise->id, 60) : [],
        ];
    }

    // --------------------------------------------------------------- écritures

    /**
     * Les colonnes de la feuille : la liste COMPLÈTE, dans l'ordre voulu. Une colonne qui porte un `slot` existant est mise à jour (titre, poids, place) ;
     * sans `slot`, c'est une nouvelle colonne ; une colonne absente de la liste est supprimée avec toutes ses notes.
     *
     * @param  list<array{slot?: int|null, label?: string, weight?: mixed}>  $items
     * @return array<string, mixed>
     */
    public function setComponents(User $user, int $courseId, string $year, ?int $semester, array $items, ?int $clientAtMs, bool $force): array
    {
        $this->ownedCourse($user, $courseId);
        $parsed = $this->parseItems($items);

        return DB::transaction(function () use ($user, $courseId, $year, $semester, $parsed, $clientAtMs, $force) {
            $at = WriteClock::at($clientAtMs, $force, 'grading');
            $remise = $this->openRemise($user, $courseId, $year, $semester, $at->stamp);
            $this->requireEditable($remise);

            if ($at->client !== null) {
                $last = DB::table('t_remise_notes_journal')->where('remise_id', $remise->id)->where('action', 'ponderation')->max('date_action');
                WriteClock::conflictIf($last ? (string) $last : null, $at, 'weights', 'La structure de la feuille', 'grading');
            }
            $this->materialize($remise);

            $existing = DB::table('t_remise_notes_composante')->where('remise_id', $remise->id)->get()->keyBy('slot');
            $keep = [];
            foreach ($parsed as $p) {
                if ($p['slot'] !== null && ! $existing->has($p['slot'])) {
                    throw new ApiProblem(422, 'grading.invalid_components', 'Colonne inconnue', 'Une colonne indiquée n’existe pas dans cette feuille. Rechargez la page.');
                }
                if ($p['slot'] !== null) {
                    $keep[$p['slot']] = true;
                }
            }

            // Colonnes supprimées : avec leurs notes.
            $lineIds = DB::table('t_remise_notes_ligne')->where('remise_id', $remise->id)->pluck('id')->all();
            foreach ($existing as $slot => $row) {
                if (isset($keep[$slot])) {
                    continue;
                }
                $lost = $lineIds === [] ? 0 : DB::table('t_remise_notes_valeur')->whereIn('ligne_id', $lineIds)->where('slot', $slot)->whereNotNull('valeur')->count();
                $this->journalize($user, (int) $remise->id, 'ponderation', $row->libelle, $this->describe($row->libelle, (float) $row->poids).($lost ? " — $lost note(s) supprimée(s)" : ''), null, null, null, $at->stamp);
                if ($lineIds !== []) {
                    DB::table('t_remise_notes_valeur')->whereIn('ligne_id', $lineIds)->where('slot', $slot)->delete();
                }
                DB::table('t_remise_notes_composante')->where('id', $row->id)->delete();
                $this->audit->delete('t_remise_notes_composante', 'id', [(array) $row]);
            }

            foreach ($parsed as $position => $p) {
                if ($p['slot'] !== null) {
                    $row = $existing[$p['slot']];
                    $after = ['position' => $position + 1, 'libelle' => $p['label'], 'poids' => $p['weight']];
                    if ((float) $row->poids !== $p['weight'] || $row->libelle !== $p['label']) {
                        $this->journalize($user, (int) $remise->id, 'ponderation', $p['label'], $this->describe($row->libelle, (float) $row->poids), $this->describe($p['label'], $p['weight']), null, null, $at->stamp);
                    }
                    if ((int) $row->position !== $position + 1 || $row->libelle !== $p['label'] || (float) $row->poids !== $p['weight']) {
                        DB::table('t_remise_notes_composante')->where('id', $row->id)->update($after);
                        $this->audit->update('t_remise_notes_composante', 'id', (array) $row, $after + (array) $row);
                    }

                    continue;
                }
                $id = (int) DB::table('t_remise_notes_composante')->insertGetId(['remise_id' => $remise->id, 'slot' => 0, 'position' => $position + 1, 'libelle' => $p['label'], 'poids' => $p['weight']]);
                DB::table('t_remise_notes_composante')->where('id', $id)->update(['slot' => self::NEW_SLOT_BASE + $id]);
                $this->audit->insert('t_remise_notes_composante', 'id', ['id' => $id, 'remise_id' => $remise->id, 'slot' => self::NEW_SLOT_BASE + $id, 'position' => $position + 1, 'libelle' => $p['label'], 'poids' => $p['weight']]);
                $this->journalize($user, (int) $remise->id, 'ponderation', $p['label'], null, $this->describe($p['label'], $p['weight']), null, null, $at->stamp);
            }

            $before = (array) $remise;
            DB::table('t_remise_notes')->where('id', $remise->id)->update(['modifie_le' => $at->stamp]);
            $this->audit->update('t_remise_notes', 'id', $before, ['modifie_le' => $at->stamp] + $before);
            $this->mirror((int) $remise->id);

            $fresh = DB::table('t_remise_notes')->where('id', $remise->id)->first();
            $components = $this->layout($fresh);
            $lines = $this->lines($fresh, $courseId, $year, $semester);

            return [
                'components' => $components, 'total_weight' => GradingRules::totalWeightOf($components),
                'notes' => $this->notesOf($lines, $components), 'check' => GradingRules::checkFor($lines, $components), 'modified_at' => $at->stamp,
            ];
        });
    }

    /**
     * Une cellule de la grille : la note d'une colonne (`c<slot>`, 0 à 20 ou vide), le statut spécial (V, E, NN, ou vide) ou le commentaire.
     * Renvoie la note recalculée et le contrôle à jour.
     *
     * @return array<string, mixed>
     */
    public function setCell(User $user, int $courseId, string $year, ?int $semester, string $studentId, string $field, ?string $raw, ?int $clientAtMs, bool $force): array
    {
        $this->ownedCourse($user, $courseId);
        $slot = null;
        if (preg_match('/^c(\d{1,5})$/', $field, $m)) {
            $slot = (int) $m[1];
        } elseif (! in_array($field, ['statut_special', 'commentaire'], true)) {
            throw new ApiProblem(422, 'grading.unknown_field', 'Champ inconnu', 'Ce champ ne se saisit pas.');
        }
        $studentId = trim($studentId);
        $value = $this->parseCell($field, trim((string) $raw));

        return DB::transaction(function () use ($user, $courseId, $year, $semester, $studentId, $field, $slot, $value, $clientAtMs, $force) {
            $at = WriteClock::at($clientAtMs, $force, 'grading');
            $remise = $this->openRemise($user, $courseId, $year, $semester, $at->stamp);
            $this->requireEditable($remise);

            $line = DB::table('t_remise_notes_ligne')->where('remise_id', $remise->id)->where('student_id', $studentId)->first();
            if (! $line) {
                $note = $this->noteIdOf($courseId, $year, $semester, $studentId);
                if ($note === null) {
                    throw new ApiProblem(404, 'grading.student_not_listed', 'Étudiant introuvable', 'Cet étudiant n’est pas inscrit à ce cours.');
                }
                DB::table('t_remise_notes_ligne')->insertOrIgnore(['remise_id' => $remise->id, 'note_id' => $note, 'student_id' => $studentId]);
                $line = DB::table('t_remise_notes_ligne')->where('remise_id', $remise->id)->where('student_id', $studentId)->first();
                $this->audit->insert('t_remise_notes_ligne', 'id', ['id' => $line->id, 'remise_id' => $remise->id, 'note_id' => $note, 'student_id' => $studentId]);
            }

            $cell = null;
            if ($slot !== null) {
                $this->materialize($remise);
                $component = DB::table('t_remise_notes_composante')->where('remise_id', $remise->id)->where('slot', $slot)->first();
                if (! $component) {
                    throw new ApiProblem(422, 'grading.unknown_field', 'Colonne inconnue', 'Cette colonne n’existe pas (ou plus) dans la feuille. Rechargez la page.');
                }
                $cell = DB::table('t_remise_notes_valeur')->where('ligne_id', $line->id)->where('slot', $slot)->first();
                $current = $cell->valeur ?? null;
                $modified = $cell->modifie_le ?? null;
                $label = $component->libelle;
            } else {
                $current = $line->{$field};
                $modified = $line->modifie_le;
                $label = $field;
            }

            // Hors ligne : rien à refuser si la cellule a déjà cette valeur (la saisie est un état voulu).
            if ($at->client !== null && ! $this->same($current, $value)) {
                WriteClock::conflictIf($modified ? (string) $modified : null, $at, 'cell', 'Cette cellule', 'grading');
            }

            $this->journalize($user, (int) $remise->id, 'saisie', $label, $current, $value, (int) $line->id, $studentId, $at->stamp, $slot !== null);
            if ($slot !== null) {
                if ($cell) {
                    DB::table('t_remise_notes_valeur')->where('id', $cell->id)->update(['valeur' => $value, 'modifie_le' => $at->stamp]);
                    $this->audit->update('t_remise_notes_valeur', 'id', (array) $cell, ['valeur' => $value, 'modifie_le' => $at->stamp] + (array) $cell);
                } else {
                    $row = ['ligne_id' => $line->id, 'slot' => $slot, 'valeur' => $value, 'modifie_le' => $at->stamp];
                    $this->audit->insert('t_remise_notes_valeur', 'id', ['id' => DB::table('t_remise_notes_valeur')->insertGetId($row)] + $row);
                }
                DB::table('t_remise_notes_ligne')->where('id', $line->id)->update(['modifie_le' => $at->stamp]);
                $this->mirror((int) $remise->id, (int) $line->id);
            } else {
                DB::table('t_remise_notes_ligne')->where('id', $line->id)->update([$field => $value, 'modifie_le' => $at->stamp]);
                $this->audit->update('t_remise_notes_ligne', 'id', (array) $line, [$field => $value, 'modifie_le' => $at->stamp] + (array) $line);
            }

            $touched = Carbon::now()->format('Y-m-d H:i:s');   // « dernière modification » de la feuille : l'instant réel, comme l'ancienne app
            DB::table('t_remise_notes')->where('id', $remise->id)->update(['modifie_le' => $touched]);
            $this->audit->update('t_remise_notes', 'id', (array) $remise, ['modifie_le' => $touched] + (array) $remise);

            $fresh = DB::table('t_remise_notes')->where('id', $remise->id)->first();
            $components = $this->layout($fresh);
            $lines = $this->lines($fresh, $courseId, $year, $semester);
            $mine = collect($lines)->firstWhere('student_id', $studentId);

            return [
                'student_id' => $studentId, 'field' => $field, 'value' => $value, 'modified_at' => $at->stamp,
                'note' => GradingRules::computeNoteFor($mine['values'] ?? [], $mine['statut_special'] ?? null, $components), 'check' => GradingRules::checkFor($lines, $components),
            ];
        });
    }

    /** Transmet la feuille au registrariat : elle se fige. Refusée tant que le contrôle a des points bloquants. */
    public function submit(User $user, int $courseId, string $year, ?int $semester): array
    {
        $this->ownedCourse($user, $courseId);

        return DB::transaction(function () use ($user, $courseId, $year, $semester) {
            $at = WriteClock::at(null, false, 'grading');
            $remise = $this->openRemise($user, $courseId, $year, $semester, $at->stamp);
            if (! in_array($remise->statut, ['brouillon', 'rejetee'], true)) {
                throw new ApiProblem(409, 'grading.already_submitted', 'Remise déjà transmise', 'Cette remise a déjà été transmise.');
            }

            $components = $this->layout($remise);
            $lines = $this->lines($remise, $courseId, $year, $semester);
            $check = GradingRules::checkFor($lines, $components);
            if ($check['blockers'] !== []) {
                throw new ApiProblem(422, 'grading.not_ready', 'Remise incomplète', 'La remise ne peut pas être transmise en l’état.', ['check' => $check]);
            }
            $this->mirror((int) $remise->id);   // l'ancienne validation lit la copie : elle doit être à jour au moment de la transmission

            $after = ['statut' => 'soumise', 'soumis_par' => $user->id, 'soumis_le' => $at->stamp, 'modifie_le' => $at->stamp, 'motif_rejet' => null];
            DB::table('t_remise_notes')->where('id', $remise->id)->update($after);
            $this->audit->update('t_remise_notes', 'id', (array) $remise, $after + (array) $remise);
            $this->journalize($user, (int) $remise->id, 'soumission', 'statut', $remise->statut, 'soumise', null, null, $at->stamp);

            return ['status' => 'soumise', 'submitted_at' => $at->stamp, 'check' => $check];
        });
    }

    // ------------------------------------------------------------------ privé

    private function notFound(): ApiProblem
    {
        return new ApiProblem(404, 'grading.not_found', 'Feuille introuvable', 'Ce cours n’existe pas, ou il ne fait pas partie de vos enseignements.');
    }

    private function ownedCourse(User $user, int $courseId): object
    {
        if (! $this->access->canEnter($user)) {
            throw new ApiProblem(403, 'forbidden.teacher_only', 'Espace réservé aux enseignants', 'Ce contenu n’existe que pour un compte enseignant.');
        }
        if (! $this->access->ownsCourse($user, $courseId)) {
            throw $this->notFound();
        }

        return DB::table('t_2023_cours')->where('id', $courseId)->first() ?? throw $this->notFound();
    }

    private function courseCode(object $c): string
    {
        $alias = trim((string) ($c->sigle_affiche ?? ''));

        return $alias !== '' ? $alias : (string) $c->Sigle;
    }

    /** Cours noté seulement en V / E (catégorie 5, RELP 291, « formation spirituelle ») : voir data/notes.statut.php. */
    private function isValidationCourse(object $c): bool
    {
        return (int) ($c->category ?? 0) === 5
            || stripos((string) $c->Sigle, 'RELP 291') !== false
            || stripos((string) $c->title, 'formation spirituelle') !== false;
    }

    private function findRemise(int $courseId, string $year, ?int $semester): ?object
    {
        return DB::table('t_remise_notes')->where('cours_id', $courseId)->where('annee_scolaire', $year)
            ->when($semester === null, fn ($q) => $q->whereNull('semestre'), fn ($q) => $q->where('semestre', $semester))->first();
    }

    /**
     * Les colonnes de la feuille, dans l'ordre : celles de la remise si elle en a, sinon les cinq de l'ancienne feuille.
     *
     * @return list<array{slot: int, label: string, weight: float}>
     */
    private function layout(?object $remise): array
    {
        return GradingLayout::of($remise);
    }

    private function requireEditable(object $remise): void
    {
        if (! in_array($remise->statut, ['brouillon', 'rejetee'], true)) {
            throw new ApiProblem(409, 'grading.frozen', 'Feuille figée', 'Cette remise a été transmise au registrariat : elle est en lecture seule.', ['target' => 'frozen']);
        }
    }

    /** Lit et valide la liste des colonnes demandée. @param list<array<string, mixed>> $items @return list<array{slot: ?int, label: string, weight: float}> */
    private function parseItems(array $items): array
    {
        if ($items === [] || count($items) > GradingRules::MAX_COMPONENTS) {
            throw new ApiProblem(422, 'grading.invalid_components', 'Colonnes invalides', 'Une feuille compte de 1 à '.GradingRules::MAX_COMPONENTS.' colonnes.');
        }
        $out = [];
        $seen = [];
        $slots = [];
        foreach (array_values($items) as $item) {
            $label = GradingRules::cleanLabel((string) ($item['label'] ?? ''));
            if ($label === null) {
                throw new ApiProblem(422, 'grading.invalid_components', 'Titre de colonne invalide', 'Chaque colonne a un titre (de 1 à '.GradingRules::MAX_LABEL.' caractères).');
            }
            $key = mb_strtolower($label);
            if (isset($seen[$key])) {
                throw new ApiProblem(422, 'grading.invalid_components', 'Titres en double', 'Deux colonnes portent le titre « '.$label.' » : donnez-leur des titres différents.');
            }
            $seen[$key] = true;
            $slot = isset($item['slot']) && $item['slot'] !== '' ? (int) $item['slot'] : null;
            if ($slot !== null) {
                if (isset($slots[$slot])) {
                    throw new ApiProblem(422, 'grading.invalid_components', 'Colonne en double', 'La même colonne est indiquée deux fois.');
                }
                $slots[$slot] = true;
            }
            $out[] = ['slot' => $slot, 'label' => $label, 'weight' => GradingRules::parseWeight((string) ($item['weight'] ?? ''))];
        }

        return $out;
    }

    private function describe(string $label, float $weight): string
    {
        return $label.' ('.rtrim(rtrim(number_format($weight, 2, '.', ''), '0'), '.').' %)';
    }

    /**
     * Ouvre la remise du cours, créée à la première écriture, avec une ligne par étudiant inscrit (jamais supprimée : une note déjà
     * posée ne doit pas disparaître parce qu'une inscription a bougé). Une remise soumise ou validée ne bouge plus.
     */
    private function openRemise(User $user, int $courseId, string $year, ?int $semester, string $stamp): object
    {
        $remise = $this->findRemise($courseId, $year, $semester);
        if (! $remise) {
            $course = DB::table('t_2023_cours')->where('id', $courseId)->first() ?? throw $this->notFound();
            $row = [
                'cours_id' => $courseId, 'sigle' => $course->Sigle, 'titre_cours' => $course->title, 'teacher_uid' => $course->id_teacher,
                'annee_scolaire' => $year, 'semestre' => $semester, 'statut' => 'brouillon', 'cree_par' => $user->id, 'cree_le' => $stamp, 'modifie_le' => $stamp,
            ];
            DB::table('t_remise_notes')->insertOrIgnore($row);
            $remise = $this->findRemise($courseId, $year, $semester) ?? throw $this->notFound();
            $this->audit->insert('t_remise_notes', 'id', ['id' => $remise->id] + $row);
            $this->journalize($user, (int) $remise->id, 'creation', null, null, $course->Sigle.' — '.$year.($semester ? ' / S'.$semester : ''), null, null, $stamp);
        }
        // Deux appareils qui rejouent la même ouverture en même temps : le verrou les met à la queue.
        $remise = DB::table('t_remise_notes')->where('id', $remise->id)->lockForUpdate()->first();

        if (in_array($remise->statut, ['brouillon', 'rejetee'], true)) {
            $created = [];
            foreach ($this->noteRows($courseId, $year, $semester) as $n) {
                if (DB::table('t_remise_notes_ligne')->insertOrIgnore(['remise_id' => $remise->id, 'note_id' => $n->id, 'student_id' => $n->student_id]) > 0) {
                    $created[] = ['remise_id' => $remise->id, 'note_id' => $n->id, 'student_id' => $n->student_id];
                } else {
                    DB::table('t_remise_notes_ligne')->where('remise_id', $remise->id)->where('student_id', $n->student_id)->whereNull('note_id')->update(['note_id' => $n->id]);
                }
            }
            $this->audit->insertMany('t_remise_notes_ligne', 'remise_id', $created);
        }

        return $remise;
    }

    /**
     * Fait passer la structure de l'ancienne feuille (cinq colonnes fixes) dans les nouvelles tables, avec les notes déjà saisies. Sans effet si c'est fait.
     * Les slots 1 à 5 sont ceux de l'ancienne feuille : une saisie mise en file hors ligne avant ce passage garde sa cible.
     */
    private function materialize(object $remise): void
    {
        if (DB::table('t_remise_notes_composante')->where('remise_id', $remise->id)->exists()) {
            return;
        }
        $rows = [];
        $slot = 0;
        foreach (GradingRules::COMPONENTS as $meta) {
            $slot++;
            $rows[] = ['remise_id' => $remise->id, 'slot' => $slot, 'position' => $slot, 'libelle' => $meta['label'], 'poids' => (float) $remise->{$meta['weight']}];
        }
        DB::table('t_remise_notes_composante')->insert($rows);
        $this->audit->insertMany('t_remise_notes_composante', 'remise_id', $rows);

        $values = [];
        foreach (DB::table('t_remise_notes_ligne')->where('remise_id', $remise->id)->get() as $l) {
            $slot = 0;
            foreach (array_keys(GradingRules::COMPONENTS) as $field) {
                $slot++;
                if ($l->{$field} !== null) {
                    $values[] = ['ligne_id' => $l->id, 'slot' => $slot, 'valeur' => $l->{$field}, 'modifie_le' => $l->modifie_le];
                }
            }
        }
        foreach (array_chunk($values, 500) as $chunk) {
            DB::table('t_remise_notes_valeur')->insert($chunk);
        }
        $this->audit->insertMany('t_remise_notes_valeur', 'ligne_id', $values);
    }

    /**
     * Recopie les cinq PREMIÈRES colonnes dans les colonnes historiques (poids de la remise, notes des lignes) : l'ancienne validation les lit encore.
     * Pour une feuille de cinq colonnes au plus, elle calcule alors exactement la même note ; au-delà, le total des cinq premiers n'est pas 100 % et
     * elle bloque. Copie technique : pas de ligne de journal d'activité.
     */
    private function mirror(int $remiseId, ?int $onlyLine = null): void
    {
        $components = DB::table('t_remise_notes_composante')->where('remise_id', $remiseId)->orderBy('position')->orderBy('id')->get()->values();
        if ($components->isEmpty()) {
            return;
        }
        $metas = array_values(GradingRules::COMPONENTS);
        $fields = array_keys(GradingRules::COMPONENTS);

        if ($onlyLine === null) {
            $weights = [];
            foreach ($metas as $i => $meta) {
                $weights[$meta['weight']] = isset($components[$i]) ? (float) $components[$i]->poids : 0.0;
            }
            DB::table('t_remise_notes')->where('id', $remiseId)->update($weights);
        }

        $lineIds = $onlyLine !== null ? [$onlyLine] : DB::table('t_remise_notes_ligne')->where('remise_id', $remiseId)->pluck('id')->all();
        if ($lineIds === []) {
            return;
        }
        $values = [];
        foreach (DB::table('t_remise_notes_valeur')->whereIn('ligne_id', $lineIds)->get() as $v) {
            $values[$v->ligne_id][$v->slot] = $v->valeur;
        }
        foreach ($lineIds as $id) {
            $set = [];
            foreach ($fields as $i => $field) {
                $set[$field] = isset($components[$i]) ? ($values[$id][$components[$i]->slot] ?? null) : null;
            }
            DB::table('t_remise_notes_ligne')->where('id', $id)->update($set);
        }
    }

    /**
     * Les lignes de notes des étudiants inscrits au cours (même règle que l'appel : ni suspendus, ni retirés), avec leur numéro de note.
     *
     * @return list<object{id: int, student_id: string}>
     */
    private function noteRows(int $courseId, string $year, ?int $semester): array
    {
        $params = [(string) $courseId, $year];
        $semesterSql = '';
        if ($semester !== null) {
            $semesterSql = ' AND '.self::SEMESTER_SQL.' = ?';
            $params[] = $semester;
        }

        return DB::select(
            'SELECT n.id, n.student_id FROM t_2023_notes n
             LEFT JOIN t_2023_session s ON s.session_id = n.session_id '.sprintf(self::NAME_JOIN, 'n.student_id').'
             WHERE n.id_cours = ? AND n.annee_scolaire = ? AND n.remove = 0'.$semesterSql.'
               AND COALESCE(e.suspended, 0) = 0 AND COALESCE(e.retrait_universite, 0) = 0
             ORDER BY n.student_id ASC, n.id ASC',
            $params,
        );
    }

    private function noteIdOf(int $courseId, string $year, ?int $semester, string $studentId): ?int
    {
        foreach ($this->noteRows($courseId, $year, $semester) as $n) {
            if ((string) $n->student_id === $studentId) {
                return (int) $n->id;
            }
        }

        return null;
    }

    /**
     * Les lignes de la feuille : celles de la remise, plus les inscrits qui n'en ont pas encore (vierges). Triées par nom, comme
     * l'ancienne page (le tri est fait par MySQL : mêmes règles d'accents et de casse). Chaque ligne porte `values` : slot => note.
     *
     * @return list<array<string, mixed>>
     */
    private function lines(?object $remise, int $courseId, string $year, ?int $semester): array
    {
        $existing = [];
        $materialized = false;
        if ($remise) {
            foreach (DB::table('t_remise_notes_ligne')->where('remise_id', $remise->id)->get() as $l) {
                $existing[(string) $l->student_id] = (array) $l;
            }
            $materialized = DB::table('t_remise_notes_composante')->where('remise_id', $remise->id)->exists();
        }
        $ids = array_keys($existing);
        if ($remise === null || in_array($remise->statut, ['brouillon', 'rejetee'], true)) {
            foreach ($this->noteRows($courseId, $year, $semester) as $n) {
                $id = (string) $n->student_id;
                if (! isset($existing[$id])) {
                    $existing[$id] = ['id' => null, 'note_id' => (int) $n->id, 'student_id' => $id, 'quiz' => null, 'recherche' => null, 'autres' => null, 'mi_semestre' => null, 'examen_final' => null, 'statut_special' => null, 'commentaire' => null, 'modifie_le' => null];
                    $ids[] = $id;
                }
            }
        }
        if ($ids === []) {
            return [];
        }

        $stored = [];
        if ($materialized) {
            $lineIds = array_filter(array_column($existing, 'id'));
            foreach ($lineIds === [] ? [] : DB::table('t_remise_notes_valeur')->whereIn('ligne_id', $lineIds)->get() as $v) {
                $stored[$v->ligne_id][(int) $v->slot] = $v->valeur === null ? null : (float) $v->valeur;
            }
        }

        $union = implode(' UNION ALL ', array_fill(0, count($ids), 'SELECT CAST(? AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_general_ci AS sid'));
        $ordered = DB::select(
            'SELECT x.sid AS student_id, e.student_nom, e.student_prenom, e.annee_etude FROM ('.$union.') x '.sprintf(self::NAME_JOIN, 'x.sid').'
             ORDER BY e.student_nom ASC, e.student_prenom ASC, x.sid ASC',
            $ids,
        );

        $out = [];
        foreach ($ordered as $o) {
            $l = $existing[(string) $o->student_id];
            $values = $materialized ? ($stored[$l['id']] ?? []) : GradingRules::legacyValues($l);
            $out[] = $l + ['values' => $values, 'last_name' => $o->student_nom, 'first_name' => $o->student_prenom, 'level' => $o->annee_etude === null ? null : (int) $o->annee_etude];
        }

        return $out;
    }

    /** @param array<string, mixed> $line @param list<array{slot: int, label: string, weight: float}> $components */
    private function lineView(array $line, array $components, ?string $attendance): array
    {
        $values = [];
        foreach ($components as $c) {
            $v = $line['values'][$c['slot']] ?? null;
            $values[$c['slot']] = $v === null ? null : (float) $v;
        }

        return [
            'student_id' => (string) $line['student_id'], 'last_name' => $line['last_name'], 'first_name' => $line['first_name'], 'level' => $line['level'],
            'values' => (object) $values, 'statut_special' => $line['statut_special'] ?: null, 'commentaire' => $line['commentaire'],
            'note' => GradingRules::computeNoteFor($line['values'], $line['statut_special'], $components),
            'modified_at' => $line['modifie_le'] ? (string) $line['modifie_le'] : null,
            'attendance_exceeded' => $attendance === 'exceeded',
        ];
    }

    /** @param list<array<string, mixed>> $lines @param list<array{slot: int, label: string, weight: float}> $components @return array<string, array<string, mixed>> */
    private function notesOf(array $lines, array $components): array
    {
        $out = [];
        foreach ($lines as $l) {
            $out[(string) $l['student_id']] = GradingRules::computeNoteFor($l['values'], $l['statut_special'], $components);
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    private function journal(int $remiseId, int $limit): array
    {
        return DB::table('t_remise_notes_journal')->where('remise_id', $remiseId)->orderByDesc('date_action')->orderByDesc('id')->limit($limit)->get()
            ->map(fn ($j) => [
                'at' => (string) $j->date_action, 'author' => $j->auteur_nom, 'role' => $j->auteur_role, 'action' => $j->action, 'field' => $j->champ,
                'student_id' => $j->student_id, 'old' => $j->ancienne_valeur, 'new' => $j->nouvelle_valeur,
            ])->all();
    }

    /** Valeur d'une cellule saisie : note d'une colonne (0–20), statut spécial ou commentaire ; 422 sinon. */
    private function parseCell(string $field, string $raw): float|string|null
    {
        if ($field === 'commentaire') {
            return $raw === '' ? null : mb_substr($raw, 0, 255);
        }
        if ($field === 'statut_special') {
            $code = strtoupper($raw);
            if ($code !== '' && ! in_array($code, GradingRules::SPECIAL_CODES, true)) {
                throw new ApiProblem(422, 'grading.invalid_value', 'Statut invalide', 'Statut attendu : V, E ou NN.');
            }

            return $code === '' ? null : $code;
        }
        $value = GradingRules::parseComponent($raw);
        if ($value === false) {
            throw new ApiProblem(422, 'grading.invalid_value', 'Note invalide', 'Note attendue entre 0 et 20.');
        }

        return $value;
    }

    /** La cellule a-t-elle déjà cette valeur ? (nombres comparés à 0,001 près, comme l'ancienne app) */
    private function same(mixed $current, float|string|null $value): bool
    {
        if ($current === null || $current === '') {
            return $value === null;
        }

        return $value !== null && (is_numeric($value) && is_numeric($current) ? abs((float) $current - (float) $value) < 0.001 : (string) $current === (string) $value);
    }

    /**
     * Consigne un changement dans le journal DE LA REMISE (qui a posé quelle note, quand). Les valeurs identiques ne sont pas
     * journalisées (sinon un enregistrement automatique remplirait le journal de lignes sans changement).
     */
    private function journalize(User $user, int $remiseId, string $action, ?string $field, mixed $old, mixed $new, ?int $lineId, ?string $studentId, string $at, bool $numeric = false): void
    {
        $oldText = ($old === null || $old === '') ? null : (string) $old;
        $newText = ($new === null || $new === '') ? null : (string) $new;
        // Une valeur identique n'est pas journalisée (« 14.00 » en base et « 14 » saisi sont la même note).
        if ($action === 'saisie' && ($oldText === $newText || ($numeric && is_numeric($oldText) && is_numeric($newText) && abs((float) $oldText - (float) $newText) < 0.001))) {
            return;
        }
        $name = trim($user->nom.' '.$user->prenom) ?: $user->pseudo;
        $row = [
            'remise_id' => $remiseId, 'ligne_id' => $lineId, 'student_id' => $studentId, 'action' => $action, 'champ' => $field === null ? null : mb_substr($field, 0, 40),
            'ancienne_valeur' => $oldText === null ? null : mb_substr($oldText, 0, 255), 'nouvelle_valeur' => $newText === null ? null : mb_substr($newText, 0, 255),
            'auteur_id' => $user->id, 'auteur_nom' => mb_substr((string) $name, 0, 150),
            'auteur_role' => mb_substr((string) $user->privilege, 0, 30), 'date_action' => $at, 'ip' => request()?->ip(),
        ];
        $id = DB::table('t_remise_notes_journal')->insertGetId($row);
        $this->audit->insert('t_remise_notes_journal', 'id', ['id' => $id] + $row);
    }
}
