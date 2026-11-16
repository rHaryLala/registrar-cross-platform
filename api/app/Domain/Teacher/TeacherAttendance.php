<?php

namespace App\Domain\Teacher;

use App\Domain\Attendance\AttendanceRules;
use App\Exceptions\ApiProblem;
use App\Models\User;
use App\Support\AuditTrail;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Registres de présence : lecture et écritures de l'enseignant (port de data/presence.php et app/.presence/enregistrer.php).
 *
 * C'est la première partie de la nouvelle application qui ÉCRIT dans les tables de production partagées avec l'ancienne :
 * chaque écriture suit donc les mêmes règles que l'ancienne, et laisse la même trace dans le journal d'activité.
 *
 * Principes :
 *  - la PROPRIÉTÉ du cours est vérifiée à chaque écriture et à chaque lecture (les identifiants se devinent) ; un registre qui
 *    n'est pas le sien est « introuvable », exactement comme un registre qui n'existe pas : on ne révèle pas son existence ;
 *  - une lecture n'écrit JAMAIS : le registre (feuille) n'est créé qu'à la première écriture, et la ligne d'un étudiant à son
 *    premier pointage ;
 *  - chaque action est un « état voulu » (pointer, justificatif, réglage…) ou idempotente (créer une séance, tout le monde
 *    présent) : la rejouer — cas du hors-ligne — ne change rien de plus ;
 *  - HORS LIGNE : le serveur fait foi. Une écriture faite sans réseau porte `client_at` (l'instant où l'enseignant l'a faite) ;
 *    elle est datée de cet instant, et refusée en 409 si la donnée visée a été modifiée depuis, sauf `force` (appliquer quand même,
 *    à l'heure actuelle). Un `client_at` de plus de 14 jours est refusé (409 « périmée ») : la file hors ligne ne garde pas plus ;
 *  - dès qu'un étudiant dépasse 15 % des séances prévues, il est SIGNALÉ au registrariat (§476), une seule fois par cours.
 */
final class TeacherAttendance
{
    public const MAX_AGE_DAYS = WriteClock::MAX_AGE_DAYS;

    /** Les bilans dépendent de la base (séances prévues) et de chaque séance (§474) : tout recalculer, puis signaler. */
    private const NAME_JOIN = 'LEFT JOIN tbl_2024_etudiant e ON e.id = (SELECT e2.id FROM tbl_2024_etudiant e2 WHERE e2.student_id = %s ORDER BY e2.remove ASC, e2.id ASC LIMIT 1)';

    private const SEMESTER_SQL = 'COALESCE(NULLIF(s.session_semester, 0), n.semester)';

    public function __construct(private readonly TeacherAccess $access, private readonly AuditTrail $audit) {}

    public function audit(): AuditTrail
    {
        return $this->audit;
    }

    // ---------------------------------------------------------------- lecture

    /** Année scolaire en cours (« 2026 - 2027 » à partir de juillet), comme `remiseAnneeCourante()`. */
    public static function currentYear(?Carbon $now = null): string
    {
        $now ??= Carbon::now();

        return $now->month >= 7 ? $now->year.' - '.($now->year + 1) : ($now->year - 1).' - '.$now->year;
    }

    /**
     * Les cours de l'enseignant pour une année, et l'état de leur registre.
     *
     * @return array{linked: bool, year: string, years: list<string>, courses: list<array<string, mixed>>}
     */
    public function courses(User $user, ?string $year): array
    {
        $this->requireAccess($user);
        $uid = $this->access->teacherUid($user);
        $current = self::currentYear();

        if ($uid === null) {
            // Compte non relié à une fiche d'enseignant (le personnel n'a pas de cours propres) : liste vide, sans erreur.
            return ['linked' => $this->access->isStaff($user), 'year' => $year ?: $current, 'years' => [$current], 'courses' => []];
        }

        $withCourses = DB::table('t_2023_notes as n')
            ->join('t_2023_cours as c', 'c.id', '=', 'n.id_cours')
            ->where('c.id_teacher', $uid)->where('c.remove', '!=', 1)->where('n.remove', 0)
            ->distinct()->orderByDesc('n.annee_scolaire')->pluck('n.annee_scolaire')->map(fn ($y) => (string) $y)->all();
        $years = array_values(array_unique([$current, ...$withCourses]));
        rsort($years);
        $default = ! $withCourses || in_array($current, $withCourses, true) ? $current : $withCourses[0];
        $year = $year !== null && $year !== '' ? trim($year) : $default;

        $rows = DB::select(
            'SELECT c.id, c.Sigle, c.sigle_affiche, c.title, c.yearlevel, '.self::SEMESTER_SQL.' AS semestre, COUNT(DISTINCT n.student_id) AS nb_etudiants
             FROM t_2023_cours c
             JOIN t_2023_notes n ON n.id_cours = c.id
             LEFT JOIN t_2023_session s ON s.session_id = n.session_id '.sprintf(self::NAME_JOIN, 'n.student_id').'
             WHERE c.id_teacher = ? AND c.remove != 1 AND n.remove = 0 AND n.annee_scolaire = ?
               AND COALESCE(e.suspended, 0) = 0 AND COALESCE(e.retrait_universite, 0) = 0
             GROUP BY c.id, c.Sigle, c.sigle_affiche, c.title, c.yearlevel, semestre
             ORDER BY semestre ASC, COALESCE(NULLIF(TRIM(c.sigle_affiche), ""), c.Sigle) ASC',
            [$uid, $year],
        );

        $sheets = [];
        foreach (DB::select(
            'SELECT f.id, f.cours_id, f.semestre, f.seances_prevues,
                    (SELECT COUNT(*) FROM t_presence_seance s WHERE s.feuille_id = f.id) AS nb_seances,
                    (SELECT COUNT(*) FROM t_presence_signalement g WHERE g.feuille_id = f.id) AS nb_signalements
             FROM t_presence_feuille f JOIN t_2023_cours c ON c.id = f.cours_id
             WHERE c.id_teacher = ? AND f.annee_scolaire = ?',
            [$uid, $year],
        ) as $f) {
            $sheets[$f->cours_id.'|'.($f->semestre ?? '')] = $f;
        }

        $courses = [];
        foreach ($rows as $c) {
            $f = $sheets[$c->id.'|'.($c->semestre ?? '')] ?? null;
            $courses[] = [
                'id' => (int) $c->id,
                'code' => $this->courseCode($c),
                'title' => $c->title,
                'level' => $c->yearlevel === null ? null : (int) $c->yearlevel,
                'semester' => $c->semestre === null ? null : (int) $c->semestre,
                'semester_label' => AttendanceRules::semesterLabel($c->semestre),
                'students' => (int) $c->nb_etudiants,
                'held_sessions' => $f ? (int) $f->nb_seances : 0,
                'planned_sessions' => $f && $f->seances_prevues ? (int) $f->seances_prevues : null,
                'flagged' => $f ? (int) $f->nb_signalements : 0,
            ];
        }

        return ['linked' => true, 'year' => $year, 'years' => $years, 'courses' => $courses];
    }

    /**
     * Le registre d'un cours : base des 15 %, séances tenues, bilan de chaque étudiant. N'écrit rien.
     *
     * @return array<string, mixed>
     */
    public function register(User $user, int $courseId, string $year, ?int $semester): array
    {
        $course = $this->ownedCourse($user, $courseId);
        $sheet = $this->findSheet($courseId, $year, $semester);
        $enrolled = $this->enrolled($courseId, $year, $semester);
        $planned = $sheet && $sheet->seances_prevues ? (int) $sheet->seances_prevues : null;

        $sessions = $sheet ? $this->sessions((int) $sheet->id, $enrolled) : [];
        $summaries = $sheet ? $this->summaries($sheet) : [];
        $flags = $sheet ? $this->flags((int) $sheet->id) : [];

        $students = [];
        foreach ($enrolled as $s) {
            $summary = $summaries[$s['student_id']] ?? AttendanceRules::summary([], $planned);
            $students[] = [
                'id' => $s['student_id'], 'last_name' => $s['last_name'], 'first_name' => $s['first_name'],
                'summary' => $summary, 'flagged_at' => $flags[$s['student_id']] ?? null,
            ];
        }

        return [
            'course' => ['id' => (int) $course->id, 'code' => $this->courseCode($course), 'title' => $course->title],
            'year' => $year, 'semester' => $semester, 'semester_label' => AttendanceRules::semesterLabel($semester),
            'sheet' => ['id' => $sheet ? (int) $sheet->id : null, 'planned_sessions' => $planned, 'allowed_absences' => AttendanceRules::allowedAbsences($planned)],
            'students' => $students,
            'sessions' => $sessions,
            'exceeded' => count(array_filter($students, fn ($s) => $s['summary']['state'] === 'exceeded')),
            'today' => Carbon::now()->format('Y-m-d'),
            'suggested_time' => $this->slotTime($courseId, $year, $semester, Carbon::now()->format('Y-m-d')),
        ];
    }

    /**
     * L'appel d'une séance : les étudiants inscrits au cours (même ceux qui n'ont pas encore de ligne), leur pointage et leur bilan.
     *
     * @return array<string, mixed>
     */
    public function session(User $user, int $sessionId): array
    {
        [$session, $sheet] = $this->ownedSession($user, $sessionId);
        $semester = $sheet->semestre === null ? null : (int) $sheet->semestre;
        $enrolled = $this->enrolled((int) $sheet->cours_id, $sheet->annee_scolaire, $semester);
        $summaries = $this->summaries($sheet);

        $lines = [];
        foreach (DB::table('t_presence_ligne')->where('seance_id', $sessionId)->get() as $l) {
            $lines[(string) $l->student_id] = $l;
        }

        $rows = [];
        foreach ($enrolled as $s) {
            $l = $lines[$s['student_id']] ?? null;
            $rows[] = $this->lineView($s, $l, $summaries[$s['student_id']] ?? AttendanceRules::summary([], $sheet->seances_prevues));
            unset($lines[$s['student_id']]);
        }
        // Un étudiant déjà pointé puis suspendu ou retiré reste visible : son pointage compte toujours dans son bilan.
        foreach ($lines as $sid => $l) {
            $rows[] = $this->lineView(['student_id' => $sid, 'last_name' => $this->nameOf($sid)['last_name'], 'first_name' => $this->nameOf($sid)['first_name']], $l, $summaries[$sid] ?? AttendanceRules::summary([], $sheet->seances_prevues));
        }

        return [
            'session' => $this->sessionView($session) + ['sheet_id' => (int) $sheet->id, 'course_id' => (int) $sheet->cours_id, 'year' => $sheet->annee_scolaire, 'semester' => $semester],
            'lines' => $rows,
        ];
    }

    // --------------------------------------------------------------- écritures

    /** Base des 15 % : les séances prévues (1 à 200, ou vide). */
    public function setPlanned(User $user, int $courseId, string $year, ?int $semester, ?int $planned, ?int $clientAtMs, bool $force): array
    {
        $this->ownedCourse($user, $courseId);

        return DB::transaction(function () use ($user, $courseId, $year, $semester, $planned, $clientAtMs, $force) {
            $at = $this->writeTime($clientAtMs, $force);
            $sheet = $this->openSheet($user, $courseId, $year, $semester, $at->time);
            $this->conflictIf($sheet->modifie_le, $at, 'planned', 'Le nombre de séances prévues');

            $before = (array) $sheet;
            DB::table('t_presence_feuille')->where('id', $sheet->id)->update(['seances_prevues' => $planned, 'modifie_le' => $at->stamp]);
            $sheet->seances_prevues = $planned;
            $this->audit->update('t_presence_feuille', 'id', $before, ['seances_prevues' => $planned, 'modifie_le' => $at->stamp] + $before);

            // La base change : chaque bilan change, et un étudiant peut franchir le seuil sans nouvelle absence.
            $flagged = $this->flagAllExceeded($user, $sheet, $at->stamp);

            return ['planned_sessions' => $planned, 'allowed_absences' => AttendanceRules::allowedAbsences($planned), 'newly_flagged' => $flagged];
        });
    }

    /**
     * Ouvre une séance (idempotent : même date et même heure prévue = la même séance) et prépare la liste d'appel.
     *
     * @return array{session_id: int, existed: bool}
     */
    public function createSession(User $user, int $courseId, string $year, ?int $semester, string $date, ?string $plannedTime, ?string $arrival, bool $warned, ?int $clientAtMs, bool $force): array
    {
        $this->ownedCourse($user, $courseId);
        if ($date > Carbon::now()->format('Y-m-d')) {
            throw new ApiProblem(422, 'presence.future_session', 'Séance à venir', 'On ne fait pas l’appel d’une séance à venir.');
        }

        return DB::transaction(function () use ($user, $courseId, $year, $semester, $date, $plannedTime, $arrival, $warned, $clientAtMs, $force) {
            $at = $this->writeTime($clientAtMs, $force);
            $sheet = $this->openSheet($user, $courseId, $year, $semester, $at->time);
            // Deux appareils qui rejouent la même ouverture en même temps : le verrou du registre les met à la queue, le second
            // trouve alors la séance du premier au lieu d'en créer une deuxième.
            DB::table('t_presence_feuille')->where('id', $sheet->id)->lockForUpdate()->value('id');

            // Deux fois la même séance fausserait les comptes : même date et même heure prévue (NULL = NULL, comme `<=>`).
            $existing = DB::table('t_presence_seance')->where('feuille_id', $sheet->id)->where('date_seance', $date)
                ->when($plannedTime === null, fn ($q) => $q->whereNull('heure_prevue'), fn ($q) => $q->where('heure_prevue', $plannedTime))
                ->value('id');
            if ($existing) {
                return ['session_id' => (int) $existing, 'existed' => true];
            }

            $row = [
                'feuille_id' => $sheet->id, 'date_seance' => $date, 'heure_prevue' => $plannedTime, 'arrivee_enseignant' => $arrival,
                'message_retard' => $warned ? 1 : 0, 'cree_par' => $user->id, 'cree_le' => $at->stamp, 'modifie_par' => $user->id, 'modifie_le' => $at->stamp,
            ];
            $id = (int) DB::table('t_presence_seance')->insertGetId($row);
            $this->audit->insert('t_presence_seance', 'id', ['id' => $id] + $row);

            // La liste d'appel : une ligne par étudiant inscrit, comme l'ancienne application (elle ne supprime jamais rien).
            $created = [];
            foreach ($this->enrolled($courseId, $year, $semester) as $s) {
                if (DB::table('t_presence_ligne')->insertOrIgnore(['seance_id' => $id, 'student_id' => $s['student_id']]) > 0) {
                    $created[] = ['seance_id' => $id, 'student_id' => $s['student_id']];
                }
            }
            $this->audit->insertMany('t_presence_ligne', 'seance_id', $created);

            return ['session_id' => $id, 'existed' => false];
        });
    }

    /** Heures et message d'une séance (§474). */
    public function updateSession(User $user, int $sessionId, ?string $plannedTime, ?string $arrival, bool $warned, ?string $comment, ?int $clientAtMs, bool $force): array
    {
        [$session, $sheet] = $this->ownedSession($user, $sessionId);

        return DB::transaction(function () use ($user, $session, $sheet, $plannedTime, $arrival, $warned, $comment, $clientAtMs, $force) {
            $at = $this->writeTime($clientAtMs, $force);
            $this->conflictIf($session->modifie_le, $at, 'session', 'Cette séance');

            $comment = $comment === null ? null : trim($comment);
            $after = [
                'heure_prevue' => $plannedTime, 'arrivee_enseignant' => $arrival, 'message_retard' => $warned ? 1 : 0,
                'commentaire' => $comment === null || $comment === '' ? null : mb_substr($comment, 0, 255), 'modifie_par' => $user->id, 'modifie_le' => $at->stamp,
            ];
            $before = (array) $session;
            DB::table('t_presence_seance')->where('id', $session->id)->update($after);
            $this->audit->update('t_presence_seance', 'id', $before, $after + $before);

            // Le §474 peut rendre la séance sans pénalité, ou l'inverse : tous les bilans bougent.
            $flagged = $this->flagAllExceeded($user, $sheet, $at->stamp);
            $fresh = DB::table('t_presence_seance')->where('id', $session->id)->first();

            return ['session' => $this->sessionView($fresh), 'newly_flagged' => $flagged];
        });
    }

    /** Supprime une séance saisie par erreur, et ses pointages. */
    public function deleteSession(User $user, int $sessionId, ?int $clientAtMs, bool $force): array
    {
        [$session] = $this->ownedSession($user, $sessionId);

        return DB::transaction(function () use ($session, $clientAtMs, $force) {
            $at = $this->writeTime($clientAtMs, $force);
            $this->conflictIf($session->modifie_le, $at, 'session', 'Cette séance');
            $lines = DB::table('t_presence_ligne')->where('seance_id', $session->id)->get()->map(fn ($l) => (array) $l)->all();
            // Les pointages partent avec elle (clé étrangère en cascade) ; le journal les nomme pour qu'on sache ce qui a disparu.
            DB::table('t_presence_seance')->where('id', $session->id)->delete();
            $this->audit->delete('t_presence_ligne', 'id', $lines);
            $this->audit->delete('t_presence_seance', 'id', [(array) $session]);

            return ['deleted' => true];
        });
    }

    /**
     * Pointe UN étudiant : état voulu. `status` null efface le pointage. Renvoie le bilan à jour.
     *
     * @return array<string, mixed>
     */
    public function mark(User $user, int $sessionId, string $studentId, ?string $status, ?int $minutes, ?int $clientAtMs, bool $force): array
    {
        [$session, $sheet] = $this->ownedSession($user, $sessionId);
        $studentId = trim($studentId);
        if ($status === 'retard') {
            $minutes ??= AttendanceRules::LATE_TOLERATED;
        } else {
            $minutes = null;
        }

        return DB::transaction(function () use ($user, $session, $sheet, $studentId, $status, $minutes, $clientAtMs, $force) {
            $at = $this->writeTime($clientAtMs, $force);
            $line = $this->lineFor($session, $sheet, $studentId, $at->stamp);
            $this->conflictIf($line->modifie_le, $at, 'mark', 'Le pointage de cet étudiant');

            $after = ['statut' => $status, 'minutes_retard' => $minutes, 'modifie_par' => $user->id, 'modifie_le' => $at->stamp];
            DB::table('t_presence_ligne')->where('id', $line->id)->update($after);
            $this->audit->update('t_presence_ligne', 'id', (array) $line, $after + (array) $line);

            return $this->afterMark($user, $sheet, $studentId, $status, $minutes, $at->stamp);
        });
    }

    /** Justificatif présenté au retour (§476) : il ne change pas les comptes, il permet de refaire les tests manqués. */
    public function justify(User $user, int $sessionId, string $studentId, ?string $justification, ?int $clientAtMs, bool $force): array
    {
        [$session, $sheet] = $this->ownedSession($user, $sessionId);
        $studentId = trim($studentId);

        return DB::transaction(function () use ($user, $session, $sheet, $studentId, $justification, $clientAtMs, $force) {
            $at = $this->writeTime($clientAtMs, $force);
            $line = $this->lineFor($session, $sheet, $studentId, $at->stamp);
            $this->conflictIf($line->modifie_le, $at, 'mark', 'Le justificatif de cet étudiant');

            $after = ['justificatif' => $justification, 'modifie_par' => $user->id, 'modifie_le' => $at->stamp];
            DB::table('t_presence_ligne')->where('id', $line->id)->update($after);
            $this->audit->update('t_presence_ligne', 'id', (array) $line, $after + (array) $line);

            return ['student_id' => $studentId, 'justification' => $justification];
        });
    }

    /** Tous les étudiants non encore pointés sont présents (ne touche pas à un pointage déjà fait). */
    public function markAllPresent(User $user, int $sessionId, ?int $clientAtMs, bool $force): array
    {
        [$session, $sheet] = $this->ownedSession($user, $sessionId);

        return DB::transaction(function () use ($user, $session, $sheet, $clientAtMs, $force) {
            $at = $this->writeTime($clientAtMs, $force);
            // Les étudiants inscrits qui n'ont pas encore de ligne en reçoivent une (inscrits après l'ouverture de la séance).
            foreach ($this->enrolled((int) $sheet->cours_id, $sheet->annee_scolaire, $sheet->semestre === null ? null : (int) $sheet->semestre) as $s) {
                $this->lineFor($session, $sheet, $s['student_id'], $at->stamp);
            }
            $lines = DB::table('t_presence_ligne')->where('seance_id', $session->id)->whereNull('statut')->get();
            foreach ($lines as $line) {
                $after = ['statut' => 'present', 'minutes_retard' => null, 'modifie_par' => $user->id, 'modifie_le' => $at->stamp];
                DB::table('t_presence_ligne')->where('id', $line->id)->update($after);
                $this->audit->update('t_presence_ligne', 'id', (array) $line, $after + (array) $line);
            }

            return ['marked' => $lines->count()];
        });
    }

    // ------------------------------------------------------------------ privé

    private function requireAccess(User $user): void
    {
        if (! $this->access->canEnter($user)) {
            throw new ApiProblem(403, 'forbidden.teacher_only', 'Espace réservé aux enseignants', 'Ce contenu n’existe que pour un compte enseignant.');
        }
    }

    private function notFound(): ApiProblem
    {
        return new ApiProblem(404, 'presence.not_found', 'Registre introuvable', 'Ce registre n’existe pas, ou ce cours ne fait pas partie de vos enseignements.');
    }

    /** Le cours, s'il est bien l'un des siens ; sinon « introuvable » (on ne révèle pas qu'il existe). */
    private function ownedCourse(User $user, int $courseId): object
    {
        $this->requireAccess($user);
        if (! $this->access->ownsCourse($user, $courseId)) {
            throw $this->notFound();
        }

        return DB::table('t_2023_cours')->where('id', $courseId)->first() ?? throw $this->notFound();
    }

    /** @return array{0: object, 1: object} la séance et son registre */
    private function ownedSession(User $user, int $sessionId): array
    {
        $this->requireAccess($user);
        $session = DB::table('t_presence_seance')->where('id', $sessionId)->first();
        $sheet = $session ? DB::table('t_presence_feuille')->where('id', $session->feuille_id)->first() : null;
        if (! $session || ! $sheet || ! $this->access->ownsCourse($user, (int) $sheet->cours_id)) {
            throw $this->notFound();
        }

        return [$session, $sheet];
    }

    private function findSheet(int $courseId, string $year, ?int $semester): ?object
    {
        return DB::table('t_presence_feuille')->where('cours_id', $courseId)->where('annee_scolaire', $year)
            ->when($semester === null, fn ($q) => $q->whereNull('semestre'), fn ($q) => $q->where('semestre', $semester))->first();
    }

    /** Le registre du cours, créé à la première écriture (jamais à une lecture). */
    private function openSheet(User $user, int $courseId, string $year, ?int $semester, string $stamp): object
    {
        if ($sheet = $this->findSheet($courseId, $year, $semester)) {
            return $sheet;
        }
        $course = DB::table('t_2023_cours')->where('id', $courseId)->first() ?? throw $this->notFound();
        $row = [
            'cours_id' => $courseId, 'sigle' => $course->Sigle, 'titre_cours' => $course->title, 'teacher_uid' => $course->id_teacher,
            'annee_scolaire' => $year, 'semestre' => $semester, 'cree_par' => $user->id, 'cree_le' => $stamp, 'modifie_le' => $stamp,
        ];
        DB::table('t_presence_feuille')->insertOrIgnore($row);
        $sheet = $this->findSheet($courseId, $year, $semester) ?? throw $this->notFound();
        $this->audit->insert('t_presence_feuille', 'id', ['id' => $sheet->id] + $row);

        return $sheet;
    }

    /** La ligne d'un étudiant dans une séance ; créée s'il est bien inscrit au cours, 404 sinon. */
    private function lineFor(object $session, object $sheet, string $studentId, string $stamp): object
    {
        $line = DB::table('t_presence_ligne')->where('seance_id', $session->id)->where('student_id', $studentId)->first();
        if ($line) {
            return $line;
        }
        $enrolled = array_column($this->enrolled((int) $sheet->cours_id, $sheet->annee_scolaire, $sheet->semestre === null ? null : (int) $sheet->semestre), 'student_id');
        if (! in_array($studentId, $enrolled, true)) {
            throw new ApiProblem(404, 'presence.student_not_listed', 'Étudiant introuvable', 'Cet étudiant n’est pas inscrit à ce cours.');
        }
        DB::table('t_presence_ligne')->insertOrIgnore(['seance_id' => $session->id, 'student_id' => $studentId]);
        $line = DB::table('t_presence_ligne')->where('seance_id', $session->id)->where('student_id', $studentId)->first();
        $this->audit->insert('t_presence_ligne', 'id', ['id' => $line->id, 'seance_id' => $session->id, 'student_id' => $studentId]);

        return $line;
    }

    /**
     * Après un pointage : le bilan à jour de l'étudiant, et son signalement s'il vient de dépasser 15 %.
     *
     * @return array<string, mixed>
     */
    private function afterMark(User $user, object $sheet, string $studentId, ?string $status, ?int $minutes, string $stamp): array
    {
        $summary = AttendanceRules::summary($this->records((int) $sheet->id, $studentId), $sheet->seances_prevues);
        $flagged = $this->flagIfExceeded($user, $sheet, $studentId, $summary, $stamp);

        return [
            'student_id' => $studentId, 'status' => $status, 'minutes_late' => $minutes,
            'label' => AttendanceRules::recordLabel($status, $minutes), 'category' => AttendanceRules::category($status, $minutes),
            'summary' => $summary, 'newly_flagged' => $flagged,
        ];
    }

    /** §476 : signale au registrariat l'étudiant qui dépasse 15 %. Un seul signalement par étudiant et par cours. */
    private function flagIfExceeded(User $user, object $sheet, string $studentId, array $summary, string $stamp): bool
    {
        if ($summary['state'] !== 'exceeded') {
            return false;
        }
        $row = ['feuille_id' => $sheet->id, 'student_id' => $studentId, 'absences' => $summary['counted'], 'seances_prevues' => $summary['planned'], 'signale_le' => $stamp, 'signale_par' => $user->id];
        if (DB::table('t_presence_signalement')->insertOrIgnore($row) > 0) {
            $this->audit->insert('t_presence_signalement', 'feuille_id', $row);

            return true;
        }

        return false;
    }

    /** @return int le nombre d'étudiants nouvellement signalés */
    private function flagAllExceeded(User $user, object $sheet, string $stamp): int
    {
        $n = 0;
        foreach ($this->summaries($sheet) as $sid => $summary) {
            $n += $this->flagIfExceeded($user, $sheet, (string) $sid, $summary, $stamp) ? 1 : 0;
        }

        return $n;
    }

    /**
     * Pointages d'une feuille (d'un étudiant, ou de tous), du plus ancien au plus récent, avec ce qu'il faut au bilan (§474 compris).
     *
     * @return list<array{status: ?string, minutes_late: ?int, no_penalty: bool}>
     */
    private function records(int $sheetId, ?string $studentId = null): array
    {
        return DB::table('t_presence_ligne as l')->join('t_presence_seance as s', 's.id', '=', 'l.seance_id')
            ->where('s.feuille_id', $sheetId)->when($studentId !== null, fn ($q) => $q->where('l.student_id', $studentId))
            ->orderBy('s.date_seance')->orderBy('s.heure_prevue')->orderBy('s.id')
            ->get(['l.student_id', 'l.statut', 'l.minutes_retard', 's.heure_prevue', 's.arrivee_enseignant', 's.message_retard'])
            ->map(fn ($r) => [
                'student_id' => (string) $r->student_id, 'status' => $r->statut, 'minutes_late' => $r->minutes_retard === null ? null : (int) $r->minutes_retard,
                'no_penalty' => AttendanceRules::teacherLateWithoutPenalty($r->heure_prevue, $r->arrivee_enseignant, $r->message_retard),
            ])->all();
    }

    /** @return array<string, array<string, mixed>> student_id => bilan, pour tous les étudiants ayant au moins une ligne */
    private function summaries(object $sheet): array
    {
        $by = [];
        foreach ($this->records((int) $sheet->id) as $r) {
            $by[$r['student_id']][] = $r;
        }
        $out = [];
        foreach ($by as $sid => $records) {
            $out[(string) $sid] = AttendanceRules::summary($records, $sheet->seances_prevues);
        }

        return $out;
    }

    /** @return array<string, string> student_id => date du signalement */
    private function flags(int $sheetId): array
    {
        return DB::table('t_presence_signalement')->where('feuille_id', $sheetId)->pluck('signale_le', 'student_id')->map(fn ($d) => (string) $d)->all();
    }

    /**
     * L'état d'assiduité (§476) de chaque étudiant d'un cours : student_id => ok|limit|exceeded|unknown. Vide tant que le cours n'a
     * pas de registre. Sert à signaler, sur la feuille de notes, ceux qui doivent quitter le cours (au-delà de 15 %).
     *
     * @return array<string, string>
     */
    public function states(int $courseId, string $year, ?int $semester): array
    {
        $sheet = $this->findSheet($courseId, $year, $semester);

        return $sheet ? array_map(fn ($s) => $s['state'], $this->summaries($sheet)) : [];
    }

    /**
     * Étudiants inscrits au cours : la même règle que la feuille de notes (l'appel et la remise portent sur les mêmes étudiants).
     * Ni suspendus, ni retirés de l'université. Nom pris dans la première fiche (la non retirée d'abord).
     *
     * @return list<array{student_id: string, last_name: ?string, first_name: ?string}>
     */
    public function enrolled(int $courseId, string $year, ?int $semester): array
    {
        $params = [(string) $courseId, $year];
        $semesterSql = '';
        if ($semester !== null) {
            $semesterSql = ' AND '.self::SEMESTER_SQL.' = ?';
            $params[] = $semester;
        }

        return array_map(fn ($r) => ['student_id' => (string) $r->student_id, 'last_name' => $r->student_nom, 'first_name' => $r->student_prenom], DB::select(
            'SELECT n.student_id, MAX(e.student_nom) AS student_nom, MAX(e.student_prenom) AS student_prenom
             FROM t_2023_notes n
             LEFT JOIN t_2023_session s ON s.session_id = n.session_id '.sprintf(self::NAME_JOIN, 'n.student_id').'
             WHERE n.id_cours = ? AND n.annee_scolaire = ? AND n.remove = 0'.$semesterSql.'
               AND COALESCE(e.suspended, 0) = 0 AND COALESCE(e.retrait_universite, 0) = 0
             GROUP BY n.student_id ORDER BY student_nom ASC, student_prenom ASC, n.student_id ASC',
            $params,
        ));
    }

    /** @return array{last_name: ?string, first_name: ?string} */
    private function nameOf(string $studentId): array
    {
        $r = DB::selectOne('SELECT e.student_nom, e.student_prenom FROM tbl_2024_etudiant e WHERE e.student_id = ? ORDER BY e.remove ASC, e.id ASC LIMIT 1', [$studentId]);

        return ['last_name' => $r->student_nom ?? null, 'first_name' => $r->student_prenom ?? null];
    }

    /**
     * Séances d'une feuille, de la plus récente à la plus ancienne, avec leurs comptes.
     *
     * @param  list<array{student_id: string}>  $enrolled
     * @return list<array<string, mixed>>
     */
    private function sessions(int $sheetId, array $enrolled): array
    {
        $ids = array_column($enrolled, 'student_id');
        $stats = [];
        foreach (DB::table('t_presence_ligne as l')->join('t_presence_seance as s', 's.id', '=', 'l.seance_id')->where('s.feuille_id', $sheetId)
            ->get(['l.seance_id', 'l.student_id', 'l.statut']) as $l) {
            $a = &$stats[$l->seance_id];
            $a ??= ['absent' => 0, 'late' => 0, 'marked' => 0];
            if ($l->statut !== null && in_array((string) $l->student_id, $ids, true)) {
                $a['marked']++;
            }
            $a['absent'] += $l->statut === 'absent' ? 1 : 0;
            $a['late'] += $l->statut === 'retard' ? 1 : 0;
            unset($a);
        }

        return DB::table('t_presence_seance')->where('feuille_id', $sheetId)
            ->orderByDesc('date_seance')->orderByDesc('heure_prevue')->orderByDesc('id')->get()
            ->map(fn ($s) => $this->sessionView($s) + [
                'absent' => $stats[$s->id]['absent'] ?? 0, 'late' => $stats[$s->id]['late'] ?? 0,
                'to_mark' => count($ids) - ($stats[$s->id]['marked'] ?? 0),
            ])->all();
    }

    /** @return array<string, mixed> */
    private function sessionView(object $s): array
    {
        $planned = $s->heure_prevue === null ? null : substr((string) $s->heure_prevue, 0, 5);
        $arrival = $s->arrivee_enseignant === null ? null : substr((string) $s->arrivee_enseignant, 0, 5);

        return [
            'id' => (int) $s->id, 'date' => (string) $s->date_seance, 'planned_time' => $planned, 'teacher_arrival' => $arrival,
            'warned' => (bool) $s->message_retard, 'comment' => $s->commentaire,
            'teacher_late_minutes' => AttendanceRules::teacherLateMinutes($planned, $arrival),
            'no_penalty' => AttendanceRules::teacherLateWithoutPenalty($planned, $arrival, $s->message_retard),
            'modified_at' => $s->modifie_le,
        ];
    }

    /** @return array<string, mixed> */
    private function lineView(array $student, ?object $line, array $summary): array
    {
        $status = $line?->statut;
        $minutes = $line?->minutes_retard === null ? null : (int) $line->minutes_retard;

        return [
            'student_id' => $student['student_id'], 'last_name' => $student['last_name'], 'first_name' => $student['first_name'],
            'status' => $status, 'minutes_late' => $minutes, 'justification' => $line?->justificatif,
            'label' => AttendanceRules::recordLabel($status, $minutes), 'category' => AttendanceRules::category($status, $minutes),
            'modified_at' => $line?->modifie_le, 'summary' => $summary,
        ];
    }

    /** Début prévu par l'emploi du temps pour ce cours ce jour-là, s'il y a un créneau (une séance datée l'emporte sur le créneau hebdomadaire). */
    private function slotTime(int $courseId, string $year, ?int $semester, string $date): ?string
    {
        $days = ['Dimanche', 'Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi'];
        $d = Carbon::createFromFormat('!Y-m-d', $date);
        if (! $d) {
            return null;
        }
        $h = DB::selectOne(
            'SELECT heure_debut FROM t_2024_emploi_du_temps
             WHERE cours_id = ? AND REPLACE(annee_scolaire, " ", "") = REPLACE(?, " ", "") AND (? IS NULL OR semester = ?)
               AND COALESCE(statut, "planifie") <> "annule" AND (date_seance = ? OR (date_seance IS NULL AND jour_semaine = ?))
             ORDER BY date_seance IS NULL, heure_debut LIMIT 1',
            [$courseId, $year, $semester, $semester, $date, $days[$d->dayOfWeek]],
        );

        return $h && $h->heure_debut ? substr((string) $h->heure_debut, 0, 5) : null;
    }

    private function courseCode(object $c): string
    {
        $alias = trim((string) ($c->sigle_affiche ?? ''));

        return $alias !== '' ? $alias : (string) $c->Sigle;
    }

    // ---------------------------------------------------------------- hors ligne

    /** L'instant d'une écriture (voir WriteClock). `time` est conservé pour les appelants existants. */
    private function writeTime(?int $clientAtMs, bool $force): object
    {
        $at = WriteClock::at($clientAtMs, $force, 'presence');

        return (object) ['time' => $at->stamp, 'stamp' => $at->stamp, 'client' => $at->client];
    }

    /** Refuse (409) une écriture faite hors ligne dont la donnée a bougé depuis : le serveur fait foi. */
    private function conflictIf(?string $modifiedAt, object $at, string $target, string $what): void
    {
        WriteClock::conflictIf($modifiedAt, $at, $target, $what, 'presence');
    }
}
