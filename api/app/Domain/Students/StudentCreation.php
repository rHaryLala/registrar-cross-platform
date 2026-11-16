<?php

namespace App\Domain\Students;

use App\Domain\Admissions\Messages;
use App\Exceptions\ApiProblem;
use App\Models\User;
use App\Support\AuditTrail;
use App\Support\Role;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Création d'un étudiant (port de `src/creat.student.php` et `app/.student/addStd.php`).
 *
 * Accès : niveaux 1 à 3 (superadmin, administration, registrariat), comme l'ancienne page (`exigerConnexion(3)`).
 *
 * Ce que la création fait, dans une transaction : attribue un matricule LIBRE (jamais celui qui est proposé s'il est pris entre-temps), une adresse universitaire
 * UNIQUE, un mot de passe initial ; écrit la fiche, le diplôme précédent (baccalauréat en licence, diplôme en master) ; et, depuis une candidature ACCEPTÉE, la
 * convertit (statut « converti », matricule, archivage) — jamais deux fois.
 *
 * Ce qui change (docs/PLAN-PAGES) : l'appel à Google Admin n'est plus fait pendant la requête (1,4 s en moyenne, 8,9 s au pire) : une tâche pose le compte
 * (`students:google-accounts`), son statut se lit sur l'écran. Le jeton anti-doublon est en base (UNIQUE), plus dans la session. Le mot de passe initial est
 * ALÉATOIRE (l'ancien « 4 chiffres + Student + année » n'avait que 9 000 valeurs par an) et n'est rendu qu'UNE fois, à la création.
 * Pas repris ici : la photo (à ajouter depuis l'ancienne version).
 */
final class StudentCreation
{
    /** Début de plage numérique par mention : le matricule encode la mention dans sa plage (Théologie 10000, Gestion 20000, …). */
    public const RANGES = ['THEO' => 10000, 'GEST' => 20000, 'INFO' => 30000, 'NURS' => 40000, 'EDUC' => 50000, 'COMM' => 60000, 'LANG' => 70000, 'CPRE' => 80000, 'DROI' => 90000];

    public const STATUSES = ['Externe', 'Interne', 'Bungalow'];

    public const MARITAL = ['Célibataire', 'Marié'];

    public const RELIGIONS = ['Adventiste', 'FLM', 'Apokalypsy', 'Catholique', 'FJKM', 'Lutherien', 'Musulman', 'Shyn', 'Jesosy Mamonjy', 'Autre...'];

    public const SERIES = ['A1', 'A2', 'BTP', 'C', 'D', 'Electronique', 'G1', 'G2', 'G3', 'L', 'S', 'OSE', 'Technique Ouvrage Bois', 'Technique Ouvrage Métalique', 'Technique Maintenance Automobile'];

    public const SIZES = ['S', 'M', 'L', 'XL', 'XXL', 'XXXL'];

    /** Correspondance clé du formulaire public (candidat.mention) → sigle de la filière. */
    public const APPLICATION_KEYS = ['theologie' => 'THEO', 'gestion' => 'GEST', 'informatique' => 'INFO', 'sciences-infirmieres' => 'NURS', 'education' => 'EDUC', 'communication' => 'COMM', 'etudes-anglophones' => 'LANG', 'droit' => 'DROI'];

    public function __construct(private readonly AuditTrail $audit) {}

    public static function allows(User $user): bool
    {
        return $user->isActive() && in_array($user->role(), [Role::Superadmin, Role::Admin, Role::Registrar], true);
    }

    /** Le droit est vérifié AVANT toute validation : un compte sans droit reçoit 403, jamais la liste des champs attendus. */
    public function require(User $user): void
    {
        if (! self::allows($user)) {
            throw new ApiProblem(403, 'forbidden.student_creation', 'Accès refusé', 'La création d’un étudiant est réservée au superadmin, à l’administration et au registrariat.');
        }
    }

    // ------------------------------------------------------------------ choix du formulaire

    /** Le cycle d'un niveau : « M » à partir du Master 1 (niveau 4), « L » sinon (la remise à niveau fait partie de la Licence). */
    public static function cycleOf(int $level): string
    {
        return $level >= 4 ? 'M' : 'L';
    }

    /** @return array<string, mixed> */
    public function options(User $user): array
    {
        $this->require($user);
        $start = (int) date('Y');
        $years = [];
        for ($i = 0; $i <= 7; $i++) {
            $years[] = ($start - $i).' - '.($start - $i + 1);
        }

        return [
            'mentions' => array_map(fn ($m) => ['code' => $m->filiere_sigle, 'label' => $m->filiere_description, 'open' => (int) $m->ouverte === 1], DB::select('SELECT filiere_sigle, filiere_description, ouverte FROM filiere ORDER BY filiere_description')),
            'tracks' => array_map(fn ($p) => ['code' => $p->shortcode, 'label' => $p->description, 'mention' => $p->departement, 'cycles' => $this->validCycles($p->cycles)], DB::select('SELECT shortcode, description, departement, cycles FROM filiere_parcours ORDER BY description')),
            'regions' => array_map(fn ($r) => ['id' => (int) $r->id, 'name' => $r->region], DB::select('SELECT id, region FROM region ORDER BY region')),
            'years' => $years, 'statuses' => self::STATUSES, 'marital' => self::MARITAL, 'religions' => self::RELIGIONS, 'series' => self::SERIES, 'sizes' => self::SIZES,
        ];
    }

    private function validCycles(?string $value): string
    {
        $v = strtoupper((string) $value);
        $l = str_contains($v, 'L');
        $m = str_contains($v, 'M');

        return $l && $m ? 'L,M' : ($m ? 'M' : ($l ? 'L' : 'L,M'));
    }

    /** Le matricule libre le plus proche de $proposed : on avance la seule partie numérique, le suffixe (« 20481MG ») est conservé. */
    public static function freeStudentId(string $proposed): string
    {
        $proposed = trim($proposed);
        if (! preg_match('/^(\d+)(.*)$/', $proposed, $m)) {
            return $proposed;
        }
        $number = (int) $m[1];
        for ($i = 0; $i < 1000; $i++) {
            $candidate = $number.$m[2];
            if (! DB::table('tbl_2024_etudiant')->where('student_id', $candidate)->exists()) {
                return $candidate;
            }
            $number++;
        }

        return $proposed;
    }

    /**
     * Ce que le formulaire propose pour une mention, un niveau et un nom : le prochain matricule libre et l'adresse universitaire libre.
     *
     * @return array{student_id: string, email: string, email_usual: string}
     */
    public function suggest(User $user, string $mention, int $level, string $lastName, string $firstName): array
    {
        $this->require($user);

        return ['student_id' => $this->nextStudentId($mention, $level)] + (function () use ($lastName, $firstName) {
            $free = StudentAddress::free($lastName, $firstName, askGoogle: true);

            return ['email' => $free['address'], 'email_usual' => $free['usual']];
        })();
    }

    /** « 00000 » : mention inconnue, rien de sensé à proposer. */
    public function nextStudentId(string $mention, int $level): string
    {
        $label = DB::table('filiere')->where('filiere_sigle', $mention)->value('filiere_description');
        if ($label === null || ! isset(self::RANGES[$mention])) {
            return '00000';
        }
        $master = $level > 3;
        $suffix = $master ? 'M'.substr($mention, 0, 1) : '';
        $max = DB::selectOne('SELECT MAX(CAST(student_id AS UNSIGNED)) AS m FROM tbl_2024_etudiant WHERE etude_envisage = ? AND annee_etude '.($master ? '> 3' : '<= 3'), [$label])->m;
        $number = $max !== null && (int) $max > 0 ? (int) $max + 1 : self::RANGES[$mention];
        for ($i = 0; $i < 1000; $i++) {
            if (! DB::table('tbl_2024_etudiant')->where('student_id', $number.$suffix)->exists()) {
                return $number.$suffix;
            }
            $number++;
        }

        return '00000';   // plage saturée : on le dit plutôt que de renvoyer un numéro faux
    }

    // ------------------------------------------------------------------ depuis une candidature

    /**
     * Ce qu'une candidature donne au formulaire : état civil, filiation, contact. L'e-mail personnel n'est VOLONTAIREMENT pas repris : l'adresse étudiante est
     * dérivée du nom (nom.pre@zurcher.edu.mg), pas celle qui a servi à joindre le candidat.
     *
     * @return array<string, mixed>
     */
    public function fromApplication(User $user, int $id): array
    {
        $this->require($user);
        $c = DB::table('candidat')->where('id', $id)->first() ?? throw new ApiProblem(404, 'applications.not_found', 'Candidature introuvable', 'Ce dossier n’existe plus. Il a peut-être été supprimé.');
        $this->requireConvertible($c);

        if (trim((string) $c->nom) !== '' || trim((string) $c->prenom) !== '') {
            [$last, $first] = [trim((string) $c->nom), trim((string) $c->prenom)];
        } else {
            $parts = preg_split('/\s+/', trim((string) $c->nom_complet), 2);   // convention locale : le nom de famille vient en premier
            [$last, $first] = [$parts[0] ?? '', $parts[1] ?? ''];
        }
        $sigle = self::APPLICATION_KEYS[(string) $c->mention] ?? strtoupper((string) $c->mention);
        $mention = DB::table('filiere')->where('filiere_sigle', $sigle)->exists() ? $sigle : '';

        return [
            'application_id' => (int) $c->id, 'application_name' => $c->nom_complet,
            'last_name' => $last, 'first_name' => $first, 'phone' => $c->telephone ?: null, 'birth_date' => $c->date_naissance, 'birth_place' => $c->lieu_naissance,
            'nationality' => $c->nationalite, 'origin_country' => $c->pays_origine, 'address' => $c->adresse, 'father_name' => $c->nom_pere, 'mother_name' => $c->nom_mere,
            'parent_phone' => $c->contact_parent, 'sponsor_name' => $c->tuteur, 'sponsor_phone' => $c->contact_tuteur, 'track' => $c->parcours ?: null, 'mention' => $mention,
            'sex' => $c->genre === 'M' ? 1 : ($c->genre === 'F' ? 0 : null),
            'marital_status' => ['celibataire' => 'Célibataire', 'marie' => 'Marié'][(string) $c->etat_civil] ?? null,   // divorcé / veuf : pas d'équivalent, l'agent choisit
            'level' => strtolower((string) $c->diplome_vise) === 'master' ? 4 : 1,
            'is_master' => Messages::isMaster((array) $c),
        ];
    }

    /** Une candidature n'ouvre le formulaire que si elle est ACCEPTÉE et pas déjà convertie. */
    private function requireConvertible(object $c): void
    {
        if ($c->statut === 'converti') {
            $file = $c->student_id ? DB::table('tbl_2024_etudiant')->where('student_id', $c->student_id)->orderBy('remove')->orderBy('id')->value('id') : null;
            throw new ApiProblem(409, 'students.application_converted', 'Cet étudiant est déjà créé', trim((string) $c->nom_complet).' a déjà été inscrit'.($c->student_id ? ' sous le matricule '.$c->student_id : '').'. Créer une seconde fiche produirait un doublon.',
                ['student_id' => $c->student_id, 'student_file_id' => $file === null ? null : (int) $file]);
        }
        if ($c->statut !== 'accepte') {
            $why = ['nouveau' => 'n’a pas encore été instruite', 'en_cours' => 'est encore en cours d’instruction', 'refuse' => 'a été refusée'][$c->statut] ?? 'n’est pas au statut « accepté »';
            throw new ApiProblem(409, 'students.application_not_accepted', 'Candidature non acceptée', 'La candidature de '.trim((string) $c->nom_complet).' '.$why.'. Seule une candidature acceptée peut donner lieu à une inscription.', ['status' => $c->statut]);
        }
    }

    // ------------------------------------------------------------------ création

    /**
     * @param  array<string, mixed>  $d  les champs validés (voir StudentCreationController)
     * @return array<string, mixed>
     */
    public function create(User $user, array $d, string $token): array
    {
        $this->require($user);

        if ($existing = $this->byToken($token)) {
            return $this->result($existing, null, already: true);
        }
        $application = null;
        if (! empty($d['application_id'])) {
            $application = DB::table('candidat')->where('id', (int) $d['application_id'])->first() ?? throw new ApiProblem(404, 'applications.not_found', 'Candidature introuvable', 'Ce dossier n’existe plus.');
            $this->requireConvertible($application);
        }

        $label = DB::table('filiere')->where('filiere_sigle', $d['mention'])->first();
        if (! $label) {
            throw new ApiProblem(422, 'students.unknown_mention', 'Mention inconnue', 'Cette mention n’existe pas.');
        }
        // Une mention fermée ne recrute plus : sauf celle qui vient de la candidature (sinon le choix du candidat serait perdu).
        $fromApplication = $application !== null && (self::APPLICATION_KEYS[(string) $application->mention] ?? strtoupper((string) $application->mention)) === $d['mention'];
        if ((int) $label->ouverte !== 1 && ! $fromApplication) {
            throw new ApiProblem(422, 'students.closed_mention', 'Mention fermée', 'Cette mention ne recrute plus : choisissez-en une autre.');
        }
        $this->requireTrack($d['track'], $d['mention'], (int) $d['level']);

        $lock = DB::selectOne('SELECT GET_LOCK(?, 10) AS l', ['registrar-student-id'])->l;   // deux créations simultanées ne reçoivent pas le même matricule
        try {
            return DB::transaction(function () use ($user, $d, $token, $label, $application) {
                $now = Carbon::now();
                // On ne se fie jamais au matricule reçu : le proposé peut avoir été pris entre-temps. Vide, on en propose un.
                $proposed = trim((string) ($d['student_id'] ?? ''));
                $studentId = self::freeStudentId($proposed !== '' ? $proposed : $this->nextStudentId($d['mention'], (int) $d['level']));
                if ($studentId === '00000') {
                    throw new ApiProblem(422, 'students.no_student_id', 'Matricule introuvable', 'Aucun matricule libre pour cette mention : indiquez-en un.');
                }

                // Adresse universitaire UNIQUE : celle du formulaire si elle est libre ; prise, ou vide, la première libre est attribuée et l'agent en est averti.
                $email = strtolower(str_replace(' ', '', (string) ($d['email'] ?? '')));
                $notice = null;
                if ($email === '' || StudentAddress::taken($email)) {
                    $free = StudentAddress::free($d['last_name'], $d['first_name']);
                    if ($free['address'] !== '') {
                        $notice = $email !== '' ? "L’adresse {$email} était déjà prise : {$free['address']} a été attribuée." : null;
                        $email = $free['address'];
                    }
                }

                $year = (int) $now->format('m') < 7 ? (int) $now->format('Y') : (int) $now->format('Y') + 1;
                $password = self::initialPassword();
                $isNew = $this->isNew($studentId, (bool) ($d['is_new'] ?? true));
                $row = [
                    'student_nom' => trim($d['last_name']), 'student_prenom' => trim($d['first_name']), 'dateNaissance' => $d['birth_date'], 'lieuNaissance' => $d['birth_place'],
                    'num_cin' => $d['cin_number'] ?? '', 'cin_date_delivre' => $d['cin_date'] ?? null, 'cin_region' => $d['cin_region'] ?? '', 'sex' => (string) $d['sex'], 'nationalite' => $d['nationality'],
                    'student_tel' => $d['phone'] ?? '', 'student_email' => $email, 'password' => $password, 'lookup_code' => $year.$studentId, 'pays_origine' => $d['origin_country'],
                    'student_region' => (string) $d['region'], 'student_adresse' => $d['address'], 'image_student' => '', 'student_id' => $studentId, 'etude_envisage' => $label->filiere_description,
                    'annee_etude' => (string) $d['level'], 'status' => $d['status'], 'etude_option' => $d['track'], 'annee_scolaire' => $d['academic_year'], 'new_student' => $isNew ? 1 : 0,
                    'father_name' => $d['father_name'] ?? '', 'father_prof' => $d['father_job'] ?? '', 'mother_name' => $d['mother_name'] ?? '', 'mother_prof' => $d['mother_job'] ?? '',
                    'parent_tel' => $d['parent_phone'] ?? '', 'parent_adresse' => $d['parent_address'] ?? '', 'sponsor_nom' => $d['sponsor_last_name'] ?? '', 'sponsor_prenom' => $d['sponsor_first_name'] ?? '',
                    'sponsor_tel' => $d['sponsor_phone'] ?? '', 'sponsor_adresse' => $d['sponsor_address'] ?? '', 'situationf' => $d['marital_status'], 'nom_conjoint' => $d['spouse_name'] ?? '',
                    'nb_enfant' => (int) ($d['children'] ?? 0), 'religion' => $d['religion'], 'num_visa' => $d['visa_number'] ?? '', 'abonment' => (int) ($d['cafeteria'] ?? 0),
                    'last_change_user_id' => $user->id, 'date_entry' => $now->format('Y-m-d'), 'taille' => $d['size'] ?? '', 'period' => '', 'remove' => 0, 'graduated' => 0,
                ];
                $fileId = DB::table('tbl_2024_etudiant')->insertGetId($row);
                $this->audit->insert('tbl_2024_etudiant', 'id', ['id' => $fileId] + array_merge($row, ['password' => '••••']));   // le mot de passe n'entre pas dans le journal

                // Diplôme précédent : baccalauréat en licence, diplôme en master. Rien n'est écrit quand il n'est pas renseigné (une date est exigée avec lui).
                if ((int) $d['level'] <= 3 && ! empty($d['bacc_date']) && ! empty($d['bacc_series'])) {
                    $previous = ['student_id' => $studentId, 'date_obtent' => $d['bacc_date'], 'bacc_serie' => $d['bacc_series'], 'user_id' => $user->id, 'date_entry' => $now->format('Y-m-d')];
                    DB::table('t_2024_bacc')->insert($previous);
                    $this->audit->insert('t_2024_bacc', 'student_id', $previous);
                } elseif ((int) $d['level'] > 3 && ! empty($d['previous_degree']) && ! empty($d['previous_degree_date'])) {
                    $previous = ['student_id' => $studentId, 'diplome_name' => $d['previous_degree'], 'date_obtent' => $d['previous_degree_date'], 'user_id' => $user->id, 'date_entry' => $now->format('Y-m-d')];
                    DB::table('t_2024_diplome_preced')->insert($previous);
                    $this->audit->insert('t_2024_diplome_preced', 'student_id', $previous);
                }

                $google = GoogleAdmin::configured() && $email !== '';
                $creation = [
                    'jeton' => $token, 'fiche_id' => $fileId, 'student_id' => $studentId, 'candidat_id' => $application?->id, 'adresse' => $email !== '' ? $email : null,
                    'google_statut' => $google ? 'en_attente' : 'non_configure', 'google_raison' => $google ? null : ($email === '' ? 'Aucune adresse universitaire attribuée.' : 'L’API Google Admin n’est pas configurée.'),
                    'google_essais' => 0, 'google_prochain_essai' => null, 'cree_par' => $user->id, 'cree_le' => $now->format('Y-m-d H:i:s'),
                ];
                $creation['id'] = DB::table('t_etudiant_creation')->insertGetId($creation);
                $this->audit->insert('t_etudiant_creation', 'id', $creation);

                $converted = false;
                if ($application !== null) {
                    $converted = $this->convert($user, $application, $studentId, $email, $now->format('Y-m-d H:i:s'));
                }

                return $this->result((object) $creation, $password, already: false, notice: $notice, converted: $converted);
            });
        } catch (\Illuminate\Database\QueryException $e) {
            // Le même jeton arrivé deux fois en même temps : la contrainte UNIQUE l'a refusé ; on rend la fiche déjà créée.
            if (($existing = $this->byToken($token)) !== null) {
                return $this->result($existing, null, already: true);
            }
            throw $e;
        } finally {
            if ((int) $lock === 1) {
                DB::select('SELECT RELEASE_LOCK(?)', ['registrar-student-id']);
            }
        }
    }

    private function byToken(string $token): ?object
    {
        return DB::table('t_etudiant_creation')->where('jeton', $token)->first();
    }

    /** Ancien dès qu'il a suivi une autre session ; sinon le « type étudiant » choisi (par défaut nouveau). */
    private function isNew(string $studentId, bool $chosen): bool
    {
        $history = DB::selectOne('SELECT EXISTS (SELECT 1 FROM t_2024_inscription_session WHERE student_id = ?) OR EXISTS (SELECT 1 FROM t_2023_notes WHERE student_id = ?) AS h', [$studentId, $studentId])->h;

        return ! (bool) $history && $chosen;
    }

    private function requireTrack(string $track, string $mention, int $level): void
    {
        $tracks = DB::select('SELECT description, cycles FROM filiere_parcours WHERE departement = ?', [$mention]);
        foreach ($tracks as $t) {
            if ($t->description === $track && str_contains($this->validCycles($t->cycles), self::cycleOf($level))) {
                return;
            }
        }
        throw new ApiProblem(422, 'students.unknown_track', 'Parcours invalide', 'Ce parcours n’existe pas pour cette mention et ce niveau.');
    }

    /** Mot de passe initial ALÉATOIRE : 12 caractères sans ambiguïté (ni 0/O, ni 1/l/I), avec majuscule, minuscule et chiffre. */
    public static function initialPassword(): string
    {
        $sets = ['ABCDEFGHJKLMNPQRSTUVWXYZ', 'abcdefghijkmnopqrstuvwxyz', '23456789'];
        $all = implode('', $sets);
        $chars = array_map(fn ($s) => $s[random_int(0, strlen($s) - 1)], $sets);
        while (count($chars) < 12) {
            $chars[] = $all[random_int(0, strlen($all) - 1)];
        }
        for ($i = count($chars) - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            [$chars[$i], $chars[$j]] = [$chars[$j], $chars[$i]];
        }

        return implode('', $chars);
    }

    /** Ferme la boucle : la candidature devient « convertie » et archivée (sa trace et sa date d'archivage, si elle l'était déjà, sont gardées). */
    private function convert(User $user, object $application, string $studentId, string $email, string $now): bool
    {
        $agent = trim(trim((string) $user->prenom).' '.trim((string) $user->nom)) ?: (trim((string) $user->pseudo) ?: null);
        $changed = DB::update(
            'UPDATE candidat SET statut = "converti", student_id = ?, date_traitement = ?, archive_le = COALESCE(archive_le, ?), archive_par = COALESCE(archive_par, ?) WHERE id = ? AND statut = "accepte"',
            [$studentId, $now, $now, $agent, $application->id],
        );
        if ($changed !== 1) {
            return false;
        }
        $this->audit->update('candidat', 'id', (array) $application, ['statut' => 'converti', 'student_id' => $studentId, 'date_traitement' => $now] + (array) $application);

        // Master : c'est maintenant, avec l'adresse universitaire et le mot de passe que cette création vient de produire, que part le courriel d'acceptation.
        if (Messages::isMaster((array) $application)) {
            $mail = trim((string) $application->email);
            [$state, $reason] = $mail === '' ? ['ignoree', 'Le candidat n’a pas laissé d’adresse email.'] : (! filter_var($mail, FILTER_VALIDATE_EMAIL) ? ['ignoree', 'Adresse email du candidat invalide.'] : ($email === '' ? ['ignoree', 'Aucune adresse universitaire attribuée : le mail d’acceptation n’est pas parti.'] : ['en_attente', null]));
            $row = ['candidat_id' => $application->id, 'canal' => 'email', 'decision' => 'identifiants', 'statut' => $state, 'raison' => $reason, 'essais' => 0, 'prochain_essai' => null, 'cree_le' => $now, 'traite_le' => $state === 'ignoree' ? $now : null];
            $row['id'] = DB::table('t_candidat_notification')->insertGetId($row);
            $this->audit->insert('t_candidat_notification', 'id', $row);
        }

        return true;
    }

    /** @return array<string, mixed> */
    private function result(object $creation, ?string $password, bool $already, ?string $notice = null, bool $converted = false): array
    {
        return [
            'id' => (int) $creation->fiche_id, 'student_id' => $creation->student_id, 'email' => $creation->adresse, 'email_notice' => $notice,
            'initial_password' => $password,   // rendu UNE fois, à la création ; jamais relu
            'already' => $already, 'application_converted' => $converted, 'application_id' => $creation->candidat_id === null ? null : (int) $creation->candidat_id,
            'google' => ['status' => $creation->google_statut, 'reason' => $creation->google_raison],
        ];
    }

    /** L'état du compte Google d'un étudiant créé, pour l'écran qui attend. @return array{status: string, reason: ?string, attempts: int} */
    public function googleStatus(User $user, int $fileId): array
    {
        $this->require($user);
        $c = DB::table('t_etudiant_creation')->where('fiche_id', $fileId)->orderByDesc('id')->first() ?? throw new ApiProblem(404, 'students.creation_not_found', 'Création introuvable', 'Cet étudiant n’a pas été créé depuis la nouvelle application.');

        return ['status' => $c->google_statut, 'reason' => $c->google_raison, 'attempts' => (int) $c->google_essais];
    }

    /** Relance la création du compte Google (« Réessayer »). */
    public function retryGoogle(User $user, int $fileId): array
    {
        $this->require($user);
        $c = DB::table('t_etudiant_creation')->where('fiche_id', $fileId)->orderByDesc('id')->first() ?? throw new ApiProblem(404, 'students.creation_not_found', 'Création introuvable', 'Cet étudiant n’a pas été créé depuis la nouvelle application.');
        if (! in_array($c->google_statut, ['echec', 'non_configure'], true)) {
            throw new ApiProblem(409, 'students.google_not_retryable', 'Rien à relancer', 'Le compte Google est déjà créé ou en cours de création.');
        }
        if (! GoogleAdmin::configured() || $c->adresse === null) {
            throw new ApiProblem(409, 'students.google_not_configured', 'Google non configuré', 'L’API Google Admin n’est pas configurée sur ce serveur : le compte ne peut pas être créé d’ici.');
        }
        DB::table('t_etudiant_creation')->where('id', $c->id)->update(['google_statut' => 'en_attente', 'google_essais' => 0, 'google_prochain_essai' => null, 'google_raison' => null]);

        return $this->googleStatus($user, $fileId);
    }
}
