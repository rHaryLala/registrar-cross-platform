<?php

namespace Database\Seeders;

use App\Models\User;
use Database\Factories\UserFactory;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Données de DÉMONSTRATION, entièrement fictives, pour essayer l'application en local.
 *
 * Refuse de s'exécuter hors environnement « local » : la production ne reçoit jamais ces comptes.
 *   php artisan db:seed --class=DemoSeeder
 *
 * Comptes créés (mot de passe commun : voir UserFactory::PASSWORD) :
 *   etudiant.demo  (étudiant, notes publiées et masquées)
 *   etudiant.vide  (étudiant sans aucune note)
 *   registraire.demo (personnel, niveau 3)
 *   ancien.demo    (ancien format de mot de passe sha256, converti à la première connexion)
 *   enseignant.demo (enseignant, 2 cours dont un registre de présence déjà ouvert) et enseignant.autre (un autre enseignant : isolation)
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local')) {
            throw new RuntimeException('DemoSeeder : réservé à l’environnement local.');
        }

        $student = fn (string $pseudo, string $matricule, string $nom, string $prenom) => User::factory()->create([
            'pseudo' => $pseudo, 'mail' => $pseudo.'@zurcher.edu.mg', 'student_id' => $matricule, 'nom' => $nom, 'prenom' => $prenom,
        ]);

        User::query()->whereIn('pseudo', ['etudiant.demo', 'etudiant.vide', 'registraire.demo', 'ancien.demo', 'enseignant.demo', 'enseignant.autre', 'rindra'])->delete();
        // Registres et cours de l'enseignant de démonstration (les pointages et signalements suivent : clés étrangères en cascade).
        DB::table('t_presence_feuille')->whereIn('cours_id', DB::table('t_2023_cours')->where('Sigle', 'LIKE', 'DEMO %')->pluck('id'))->delete();
        $demoRemises = DB::table('t_remise_notes')->whereIn('cours_id', DB::table('t_2023_cours')->where('Sigle', 'LIKE', 'DEMO %')->pluck('id'))->pluck('id');
        DB::table('t_remise_notes_journal')->whereIn('remise_id', $demoRemises)->delete();
        DB::table('t_remise_notes')->whereIn('id', $demoRemises)->delete();   // les lignes suivent (clé étrangère en cascade)
        DB::table('teacher')->where('uid', '>=', 990100)->where('uid', '<', 990200)->delete();
        User::query()->where('student_id', 'LIKE', 'DEMO-%')->delete(); // dont les comptes créés par la connexion avec le mot de passe de la fiche
        DB::table('t_presence_feuille')->where('cours_id', '>=', 990000)->delete();
        $demoSessions = DB::table('t_2023_session')->where('session_code', 'LIKE', 'DEMO-%')->pluck('session_id');
        DB::table('t_paiement_echeance')->whereIn('session_id', $demoSessions)->delete();
        DB::table('t_work_education')->where('student_id', 'LIKE', 'DEMO-%')->delete();
        DB::table('t_2024_etudiant_frais_perso')->where('student_id', 'LIKE', 'DEMO-%')->delete();
        DB::table('t_2024_etudiant_finace')->where('student_id', 'LIKE', 'DEMO-%')->delete();
        DB::table('t_2024_cours_finance')->where('student_id', 'LIKE', 'DEMO-%')->delete();
        DB::table('t_2024_inscription_session')->where('student_id', 'LIKE', 'DEMO-%')->delete();
        DB::table('t_2023_cours')->where('Sigle', 'LIKE', 'DEMO %')->delete();
        DB::table('t_quiz_partie')->where('student_id', 'LIKE', 'DEMO-%')->delete(); // les réponses suivent (cascade)
        DB::table('t_quiz_joueur')->where('student_id', 'LIKE', 'DEMO-%')->delete();
        DB::table('t_2023_notes')->where('student_id', 'LIKE', 'DEMO-%')->delete();
        DB::table('tbl_2024_etudiant')->where('student_id', 'LIKE', 'DEMO-%')->delete();
        DB::table('t_2023_session')->where('session_code', 'LIKE', 'DEMO-%')->delete();

        foreach (['DEMO-0001' => ['Rakoto', 'Fanja'], 'DEMO-0002' => ['Andria', 'Tojo'], 'DEMO-0003' => ['Randria', 'Hery'], 'DEMO-0004' => ['Ravelo', 'Soa']] as $id => [$nom, $prenom]) {
            DB::table('tbl_2024_etudiant')->insert([
                'student_id' => $id, 'student_nom' => $nom, 'student_prenom' => $prenom, 'password' => '',
                'abonment' => 0, 'annee_scolaire' => '2025-2026', 'period' => 'S1', 'remove' => 0,
            ]);
        }

        $student('etudiant.demo', 'DEMO-0001', 'Rakoto', 'Fanja');
        $student('etudiant.vide', 'DEMO-0002', 'Andria', 'Tojo');
        User::factory()->staff(3, 'registrar')->create(['pseudo' => 'registraire.demo', 'mail' => 'registraire.demo@zurcher.edu.mg', 'nom' => 'Demo', 'prenom' => 'Registraire']);
        User::factory()->legacyPassword()->staff(4, 'comptabilite')->create(['pseudo' => 'ancien.demo', 'mail' => 'ancien.demo@zurcher.edu.mg', 'nom' => 'Demo', 'prenom' => 'Ancien']);
        User::factory()->staff(1, 'superadmin')->create(['pseudo' => 'superadmin.demo', 'mail' => 'superadmin.demo@zurcher.edu.mg', 'nom' => 'Demo', 'prenom' => 'Superadmin']);

        $s1 = $this->session('Session 1', '2025-2026', 1);
        $s2 = $this->session('Session 2', '2025-2026', 2);
        DB::table('t_notes_publication')->updateOrInsert(['session_id' => $s1], ['publiee' => 1]);
        // $s2 : aucune ligne = masquée. Ses notes ne doivent JAMAIS apparaître chez l'étudiant.

        $notes = [
            [$s1, 'Algorithmique II', 'INF 201', '14.5', 3], [$s1, 'Base de données', 'INF 205', '12', 3],
            [$s1, 'Anglais', 'ANG 101', '15', 2], [$s1, 'Mathématiques', 'MAT 110', '11.5', 4],
            [$s1, 'Réseaux', 'INF 230', '-2', 3], [$s2, 'Compilation (masqué)', 'INF 310', '19', 3],
        ];
        foreach ($notes as [$session, $titre, $sigle, $grade, $credit]) {
            DB::table('t_2023_notes')->insert([
                'student_id' => 'DEMO-0001', 'session_id' => $session, 'Sigle' => $sigle, 'title_cours' => $titre,
                'grade' => $grade, 'credit' => $credit, 'cours_category' => 1, 'ajout' => 1,
                'annee_scolaire' => '2025-2026', 'remove' => 0, 'user_id_entry' => 'demo',
            ]);
        }

        $this->attendance();
        $this->teachers();
        $this->quiz();
        $this->enrollments();
        $this->studentFileLogins();
        $this->news();
        $this->siteNews();
        $this->staffData();

        $this->command?->info('Comptes de démonstration créés. Mot de passe : '.UserFactory::PASSWORD);
    }

    /**
     * Deux registres fictifs pour DEMO-0001 :
     *  - INF 201 (20 séances prévues = 3 absences permises) : 2 absences + 2 retards (= 1 absence) = 3 → « à la limite »,
     *    et une absence NON comptée parce que l'enseignant est arrivé 25 min en retard sans prévenir (§474) ;
     *  - MAT 110 sans nombre de séances prévues : seuil non calculé ; un retard de 12 min (= absence) avec certificat médical.
     */
    private function attendance(): void
    {
        $sheet = fn (int $coursId, string $code, string $title, ?int $planned) => DB::table('t_presence_feuille')->insertGetId([
            'cours_id' => $coursId, 'sigle' => $code, 'titre_cours' => $title, 'annee_scolaire' => '2026 - 2027', 'semestre' => 1, 'seances_prevues' => $planned,
        ]);
        $meeting = fn (int $sheet, string $date, string $time, ?string $arrival = null) => DB::table('t_presence_seance')->insertGetId([
            'feuille_id' => $sheet, 'date_seance' => $date, 'heure_prevue' => $time, 'arrivee_enseignant' => $arrival, 'message_retard' => 0,
        ]);
        $line = fn (int $meeting, ?string $status, ?int $minutes = null, ?string $justification = null) => DB::table('t_presence_ligne')->insert([
            'seance_id' => $meeting, 'student_id' => 'DEMO-0001', 'statut' => $status, 'minutes_retard' => $minutes, 'justificatif' => $justification,
        ]);

        $inf = $sheet(990001, 'INF 201', 'Algorithmique II', 20);
        $line($meeting($inf, '2026-09-07', '08:00:00'), 'present');
        $line($meeting($inf, '2026-09-08', '08:00:00'), 'absent');
        $line($meeting($inf, '2026-09-09', '08:00:00'), 'absent');
        $line($meeting($inf, '2026-09-10', '08:00:00'), 'retard', 7);
        $line($meeting($inf, '2026-09-11', '08:00:00'), 'retard', 6);
        $line($meeting($inf, '2026-09-14', '08:00:00'), 'present');
        $line($meeting($inf, '2026-09-15', '08:00:00', '08:25:00'), 'absent'); // §474 : non compté
        $line($meeting($inf, '2026-09-16', '08:00:00'), 'present');

        $mat = $sheet(990002, 'MAT 110', 'Mathématiques', null);
        $line($meeting($mat, '2026-09-07', '10:00:00'), 'present');
        $line($meeting($mat, '2026-09-14', '10:00:00'), 'retard', 12, 'medical');

        // Assiduité du registrariat : un signalement dont l'étudiant est depuis revenu à la limite (une absence a été corrigée : 3 comptées sur 3
        // permises), et un autre déjà traité.
        DB::table('t_presence_signalement')->insert(['feuille_id' => $inf, 'student_id' => 'DEMO-0001', 'absences' => 4, 'seances_prevues' => 20, 'signale_le' => '2026-09-12 11:00:00']);
        DB::table('t_presence_signalement')->insert(['feuille_id' => $mat, 'student_id' => 'DEMO-0001', 'absences' => 2, 'seances_prevues' => 10, 'signale_le' => '2026-09-10 11:00:00', 'traite_le' => '2026-09-20 09:00:00']);
    }

    /**
     * Espace enseignant : enseignant.demo donne « DEMO 301 » (6 étudiants, 10 séances prévues = 1 absence permise) et « DEMO 302 » (2 étudiants, sans
     * registre ouvert). Le registre de DEMO 301 a 3 séances : DEMO-0101 y a 2 absences (au-delà de 15 %, déjà signalé), DEMO-0102 en a 1
     * (à la limite). enseignant.autre a son propre cours : l'un ne doit jamais voir ni toucher celui de l'autre.
     */
    private function teachers(): void
    {
        $year = '2026 - 2027';
        $teacher = function (int $uid, string $name, string $lastName, string $pseudo) {
            DB::table('teacher')->insert(['uid' => $uid, 'name' => $name, 'lastName' => $lastName, 'email' => $pseudo.'@zurcher.edu.mg', 'sex' => 0, 'remove' => 0]);

            return User::factory()->staff(7, 'teacher')->create(['pseudo' => $pseudo, 'mail' => $pseudo.'@zurcher.edu.mg', 'nom' => $lastName, 'prenom' => $name, 'teacher_uid' => $uid]);
        };
        $teacher(990101, 'Hasina', 'Rasolofo', 'enseignant.demo');
        $teacher(990102, 'Mamy', 'Ramanana', 'enseignant.autre');
        // Compte de test demandé : identifiant « rindra », mot de passe « rindra2026 » (base de démonstration locale seulement).
        DB::table('teacher')->insert(['uid' => 990103, 'name' => 'Rindra', 'lastName' => 'Test', 'email' => 'rindra@zurcher.edu.mg', 'sex' => 0, 'remove' => 0]);
        User::factory()->staff(7, 'teacher')->create([
            'pseudo' => 'rindra', 'mail' => 'rindra@zurcher.edu.mg', 'nom' => 'Test', 'prenom' => 'Rindra', 'teacher_uid' => 990103,
            'password' => \Illuminate\Support\Facades\Hash::make('rindra2026'),
        ]);

        $course = fn (int $uid, string $code, string $title) => DB::table('t_2023_cours')->insertGetId([
            'Sigle' => $code, 'title' => $title, 'id_teacher' => $uid, 'yearlevel' => 2, 'cout' => 0, 'cout_lab' => 0, 'ajout' => 0, 'remove' => 0,
        ]);
        $os = $course(990101, 'DEMO 301', 'Systèmes d’exploitation (démo)');
        $os2 = $course(990101, 'DEMO 302', 'Réseaux avancés (démo)');
        $other = $course(990102, 'DEMO 303', 'Cours d’un autre enseignant (démo)');

        $enrol = function (int $courseId, string $id, string $nom, string $prenom) use ($year) {
            if (! DB::table('tbl_2024_etudiant')->where('student_id', $id)->exists()) {
                DB::table('tbl_2024_etudiant')->insert(['student_id' => $id, 'student_nom' => $nom, 'student_prenom' => $prenom, 'password' => '', 'abonment' => 0, 'annee_scolaire' => '2025-2026', 'period' => 'S1', 'remove' => 0]);
            }
            DB::table('t_2023_notes')->insert([
                'id_cours' => (string) $courseId, 'student_id' => $id, 'semester' => 1, 'annee_scolaire' => $year, 'Sigle' => 'DEMO', 'title_cours' => 'Démo',
                'cours_category' => 1, 'yearlevel' => 2, 'ajout' => 1, 'remove' => 0, 'user_id_entry' => 'demo',
            ]);
        };
        $students = ['DEMO-0101' => ['Andrianina', 'Lalaina'], 'DEMO-0102' => ['Bezara', 'Mirana'], 'DEMO-0103' => ['Rabary', 'Toky'], 'DEMO-0104' => ['Ranaivo', 'Fitia'], 'DEMO-0105' => ['Razafy', 'Noro'], 'DEMO-0106' => ['Soloniaina', 'Tahina']];
        foreach ($students as $id => [$nom, $prenom]) {
            $enrol($os, $id, $nom, $prenom);
        }
        $enrol($other, 'DEMO-0107', 'Voahangy', 'Haingo');
        $enrol($os2, 'DEMO-0101', 'Andrianina', 'Lalaina');
        $enrol($os2, 'DEMO-0102', 'Bezara', 'Mirana');
        // Le cours de rindra : 3 étudiants, aucun registre ni feuille ouverts (tout est à créer).
        $rindraCourse = $course(990103, 'DEMO 304', 'Programmation web (démo rindra)');
        foreach (['DEMO-0108' => ['Rabemananjara', 'Hanta'], 'DEMO-0109' => ['Randriamampionona', 'Lova'], 'DEMO-0110' => ['Rasolondraibe', 'Mahery']] as $id => [$nom, $prenom]) {
            $enrol($rindraCourse, $id, $nom, $prenom);
        }

        $sheet = DB::table('t_presence_feuille')->insertGetId([
            'cours_id' => $os, 'sigle' => 'DEMO 301', 'titre_cours' => 'Systèmes d’exploitation (démo)', 'teacher_uid' => 990101, 'annee_scolaire' => $year, 'semestre' => 1,
            'seances_prevues' => 10, 'cree_le' => '2026-09-01 08:00:00', 'modifie_le' => '2026-09-01 08:00:00',
        ]);
        $marks = [
            '2026-09-07' => ['DEMO-0101' => 'absent', 'DEMO-0102' => 'absent', 'DEMO-0103' => 'present', 'DEMO-0104' => 'present', 'DEMO-0105' => 'present', 'DEMO-0106' => 'present'],
            '2026-09-14' => ['DEMO-0101' => 'absent', 'DEMO-0102' => 'present', 'DEMO-0103' => 'present', 'DEMO-0104' => 'present', 'DEMO-0105' => 'present', 'DEMO-0106' => 'present'],
            '2026-09-21' => ['DEMO-0101' => 'present', 'DEMO-0102' => 'present', 'DEMO-0103' => 'present', 'DEMO-0104' => 'present', 'DEMO-0105' => 'present', 'DEMO-0106' => 'present'],
        ];
        foreach ($marks as $date => $lines) {
            $m = DB::table('t_presence_seance')->insertGetId(['feuille_id' => $sheet, 'date_seance' => $date, 'heure_prevue' => '08:00:00', 'message_retard' => 0, 'cree_le' => $date.' 08:00:00', 'modifie_le' => $date.' 09:30:00']);
            foreach ($lines as $id => $status) {
                DB::table('t_presence_ligne')->insert(['seance_id' => $m, 'student_id' => $id, 'statut' => $status, 'modifie_le' => $date.' 09:30:00']);
            }
        }
        $this->gradeSheets($os, $os2, $year);

        // DEMO-0101 : 2 absences sur 10 prévues, 1 permise → au-delà de 15 %, déjà signalé au registrariat.
        DB::table('t_presence_signalement')->insert(['feuille_id' => $sheet, 'student_id' => 'DEMO-0101', 'absences' => 2, 'seances_prevues' => 10, 'signale_le' => '2026-09-14 09:30:00']);
    }

    /**
     * Feuilles de notes de l'enseignant de démonstration :
     *  - DEMO 301 : brouillon (pondération par défaut) ; 3 étudiants complets, 1 incomplet, 1 en statut spécial, 1 vierge ;
     *  - DEMO 302 : colonnes LIBRES (Devoir 1 / Projet / Examen, 30-30-40), renvoyée par le registrariat avec un motif ; à corriger puis retransmettre.
     */
    private function gradeSheets(int $os, int $os2, string $year): void
    {
        $remise = fn (int $course, string $code, string $title, string $status, array $extra = []) => DB::table('t_remise_notes')->insertGetId([
            'cours_id' => $course, 'sigle' => $code, 'titre_cours' => $title, 'teacher_uid' => 990101, 'annee_scolaire' => $year, 'semestre' => 1,
            'statut' => $status, 'cree_le' => '2026-10-01 09:00:00', 'modifie_le' => '2026-10-20 16:00:00',
        ] + $extra);
        $line = function (int $remise, string $student, array $values) use ($year) {
            // La note du COURS de la remise (un étudiant inscrit à deux cours a deux lignes de notes).
            $course = DB::table('t_remise_notes')->where('id', $remise)->value('cours_id');
            $note = DB::table('t_2023_notes')->where('id_cours', (string) $course)->where('student_id', $student)->where('annee_scolaire', $year)->orderBy('id')->value('id');
            DB::table('t_remise_notes_ligne')->insert(['remise_id' => $remise,'note_id' => $note, 'student_id' => $student, 'modifie_le' => '2026-10-20 16:00:00'] + $values);
        };
        $full = fn (array $v) => ['quiz' => $v[0], 'recherche' => $v[1], 'autres' => $v[2], 'mi_semestre' => $v[3], 'examen_final' => $v[4]];

        $r1 = $remise($os, 'DEMO 301', 'Systèmes d’exploitation (démo)', 'brouillon');
        $line($r1, 'DEMO-0101', $full([14, 12, 15, 11, 13]));
        $line($r1, 'DEMO-0102', $full([16, 15, 14, 17, 18]));
        $line($r1, 'DEMO-0103', $full([8, 9, 10, 7, 6]));
        $line($r1, 'DEMO-0104', ['quiz' => 12, 'recherche' => 10]);            // incomplet
        $line($r1, 'DEMO-0105', ['statut_special' => 'V']);                    // validé sans note
        $line($r1, 'DEMO-0106', []);                                           // vierge

        // DEMO 302 : colonnes LIBRES (3 colonnes aux titres et poids choisis par l'enseignant), renvoyée par le registrariat.
        $r2 = $remise($os2, 'DEMO 302', 'Réseaux avancés (démo)', 'rejetee', [
            'motif_rejet' => 'Le projet de DEMO-0102 semble trop élevé : vérifiez la copie, puis retransmettez.', 'soumis_le' => '2026-10-21 10:00:00', 'traite_le' => '2026-10-22 09:30:00',
            'poids_quiz' => 30, 'poids_recherche' => 30, 'poids_autres' => 40, 'poids_mi_semestre' => 0, 'poids_final' => 0,   // copie historique des trois colonnes
        ]);
        foreach ([[1, 'Devoir 1', 30], [2, 'Projet', 30], [3, 'Examen', 40]] as [$slot, $label, $weight]) {
            DB::table('t_remise_notes_composante')->insert(['remise_id' => $r2, 'slot' => $slot, 'position' => $slot, 'libelle' => $label, 'poids' => $weight]);
        }
        $free = [['DEMO-0101', [12, 14, 13]], ['DEMO-0102', [11, 20, 15]]];
        foreach ($free as [$student, $notes]) {
            $note = DB::table('t_2023_notes')->where('id_cours', (string) $os2)->where('student_id', $student)->where('annee_scolaire', $year)->orderBy('id')->value('id');   // la note du cours DEMO 302
            $ligne =DB::table('t_remise_notes_ligne')->insertGetId(['remise_id' => $r2, 'note_id' => $note, 'student_id' => $student, 'modifie_le' => '2026-10-20 16:00:00', 'quiz' => $notes[0], 'recherche' => $notes[1], 'autres' => $notes[2]]);
            foreach ($notes as $i => $value) {
                DB::table('t_remise_notes_valeur')->insert(['ligne_id' => $ligne, 'slot' => $i + 1, 'valeur' => $value, 'modifie_le' => '2026-10-20 16:00:00']);
            }
        }
    }

    /**
     * Quiz : la banque de questions, et trois parties terminées de joueurs FICTIFS pour que le classement ne soit pas vide.
     * etudiant.demo n'a encore joué aucune partie : les tests de bout en bout en jouent une.
     */
    /**
     * Écrans du personnel (listes, tableau de bord, candidatures, création d'étudiant) : mentions, parcours, régions, fiches rattachées à une mention,
     * et sept candidatures à tous les stades. Les étudiants créés par les tests de bout en bout (noms « Testcreation » et « Acceptee ») sont retirés à chaque passage.
     */
    private function staffData(): void
    {
        $created = DB::table('tbl_2024_etudiant')->whereIn('student_nom', ['Testcreation', 'Acceptee'])->pluck('student_id');
        DB::table('t_etudiant_creation')->whereIn('student_id', $created)->delete();
        DB::table('t_2024_bacc')->whereIn('student_id', $created)->delete();
        DB::table('t_2024_diplome_preced')->whereIn('student_id', $created)->delete();
        DB::table('tbl_2024_etudiant')->whereIn('student_nom', ['Testcreation', 'Acceptee'])->delete();
        $oldApps = DB::table('candidat')->where('email', 'LIKE', '%@demo.test')->orWhere('nom_complet', 'LIKE', 'Demo %')->pluck('id');
        DB::table('t_candidat_notification')->whereIn('candidat_id', $oldApps)->delete();
        DB::table('candidat')->whereIn('id', $oldApps)->delete();

        // Autorisation de réinscription : DEMO-0101 prêt (caisse ET dossier), DEMO-0102 attend le dossier, DEMO-0103 attend la caisse, les autres n'ont rien.
        // Les lots de réinitialisation et l'historique d'une passe précédente sont effacés : chaque passage repart du même état (base de démonstration seulement).
        DB::table('t_reinscription_reinitialisation')->delete();
        DB::table('t_student_modification_history')->where('commentaire', 'LIKE', 'Réinitialisation globale%')->orWhere('commentaire', 'LIKE', 'Annulation de la réinitialisation%')->orWhere('commentaire', 'LIKE', 'Action groupée%')->delete();
        DB::table('tbl_2024_etudiant')->update(['authorized_reinscription' => 0, 'authorized_by' => null, 'authorized_date' => null, 'dossier_ok' => 0, 'dossier_checked_by' => null, 'dossier_checked_date' => null]);
        $cashier = DB::table('compt_utilisateur')->where('pseudo', 'ancien.demo')->value('id');
        $registrar = DB::table('compt_utilisateur')->where('pseudo', 'registraire.demo')->value('id');
        $when = now()->subDays(2)->format('Y-m-d H:i:s');
        DB::table('tbl_2024_etudiant')->whereIn('student_id', ['DEMO-0101', 'DEMO-0102'])->update(['authorized_reinscription' => 1, 'authorized_by' => $cashier, 'authorized_date' => $when]);
        DB::table('tbl_2024_etudiant')->whereIn('student_id', ['DEMO-0101', 'DEMO-0103'])->update(['dossier_ok' => 1, 'dossier_checked_by' => $registrar, 'dossier_checked_date' => $when]);

        foreach ([['INFO', 'Informatique', 1], ['GEST', 'Gestion', 1], ['EDUC', 'Éducation', 0]] as $i => [$sigle, $label, $open]) {
            DB::table('filiere')->updateOrInsert(['filiere_sigle' => $sigle], ['filiere_id' => 9001 + $i, 'filiere_description' => $label, 'filiere_description_ang' => '', 'domaine_signe' => '', 'domaine_name' => '', 'ouverte' => $open]);
        }
        foreach ([['I_RES', 'Réseaux', 'INFO', 'L,M'], ['I_DEV', 'Développement', 'INFO', 'L'], ['I_IA', 'Intelligence artificielle', 'INFO', 'M'], ['G_GEN', 'Gestion générale', 'GEST', 'L,M']] as [$code, $label, $mention, $cycles]) {
            DB::table('filiere_parcours')->updateOrInsert(['shortcode' => $code], ['description' => $label, 'description_ang' => '', 'departement' => $mention, 'cycles' => $cycles]);
        }
        foreach (['Vakinankaratra', 'Analamanga'] as $region) {
            DB::table('region')->updateOrInsert(['region' => $region], ['chefLieu' => '', 'province' => '']);
        }

        // Les fiches de démonstration : une mention, un parcours, un niveau.
        // Seulement les étudiants des cours de l'enseignant de démonstration (DEMO-01xx) : les quatre comptes étudiants (DEMO-0001 à 0004) gardent leur fiche telle
        // que les écrans de l'espace étudiant la lisent.
        DB::table('tbl_2024_etudiant')->where('student_id', 'LIKE', 'DEMO-01%')->update(['etude_envisage' => 'Informatique', 'etude_option' => 'Réseaux', 'annee_etude' => '2', 'annee_scolaire' => '2026 - 2027', 'new_student' => 0]);
        DB::table('tbl_2024_etudiant')->whereIn('student_id', ['DEMO-0105', 'DEMO-0106'])->update(['etude_envisage' => 'Gestion', 'etude_option' => 'Gestion générale', 'annee_etude' => '1', 'new_student' => 1]);
        // Seulement les cours de l'enseignant de démonstration : le catalogue « DEMO INF 210 » etc. (fiche d'inscription de l'étudiant) garde ses catégories.
        DB::table('t_2023_cours')->whereIn('Sigle', ['DEMO 301', 'DEMO 302', 'DEMO 303', 'DEMO 304'])->update(['dep_desc' => 'INFO', 'nb_crd' => 3, 'category' => 1, 'semester' => 1, 'parcours' => 'I_RES']);

        $app = fn (string $name, string $status, array $over = []) => DB::table('candidat')->insertGetId(array_merge([
            'nom_complet' => $name, 'nom' => explode(' ', $name)[1] ?? $name, 'prenom' => explode(' ', $name)[2] ?? '', 'telephone' => '034 12 345 67', 'email' => strtolower(str_replace(' ', '.', $name)).'@demo.test',
            'mention' => 'informatique', 'mention_libelle' => 'Informatique', 'diplome_vise' => 'licence', 'statut' => $status, 'genre' => 'F', 'etat_civil' => 'celibataire',
            'nationalite' => 'Malagasy', 'pays_origine' => 'Madagascar', 'adresse' => 'Lot 12 Antsirabe', 'date_naissance' => '2005-03-12', 'lieu_naissance' => 'Antsirabe',
            'parcours' => 'Réseaux', 'nom_pere' => 'Papa Demo', 'nom_mere' => 'Maman Demo', 'tuteur' => 'Oncle Demo', 'date_creation' => now()->subDays(3)->format('Y-m-d H:i:s'),
        ], $over));
        $app('Demo Nouvelle Candidate', 'nouveau');
        $app('Demo Sansmoyen Contact', 'nouveau', ['email' => '', 'telephone' => '12']);
        $app('Demo Encours Candidat', 'en_cours');
        $app('Demo Acceptee Licence', 'accepte', ['genre' => 'M']);
        $app('Demo Acceptee Master', 'accepte', ['diplome_vise' => 'master', 'genre' => 'M', 'date_naissance' => '1998-06-02']);
        $app('Demo Refusee Candidate', 'refuse', ['date_traitement' => now()->subDay()->format('Y-m-d H:i:s'), 'traite_par' => 'Registraire Demo']);
        $app('Demo Deja Converti', 'converti', ['student_id' => 'DEMO-0001', 'archive_le' => now()->subDay()->format('Y-m-d H:i:s'), 'archive_par' => 'Registraire Demo', 'date_traitement' => now()->subDay()->format('Y-m-d H:i:s')]);

        $this->balances();
    }

    /**
     * Solde de compte : une session « Soldes (démo) » (jamais la session active) avec cinq inscrits : une dette (DEMO-0101, solde 150 000), une avance
     * (DEMO-0102, -25 000), un solde nul (DEMO-0103), un étudiant SANS ligne finance (DEMO-0104) et un solde calculé (DEMO-0105 : 500 000 dus, 200 000 versés).
     * Le matricule « DEMO-0101 » est rapproché de la ligne finance 101 par sa partie numérique, comme l'ancienne page.
     */
    private function balances(): void
    {
        $old = DB::table('t_2023_session')->where('session_code', 'DEMO-SOLDES')->pluck('session_id');
        DB::table('finance')->whereIn('session_id', $old)->delete();
        DB::table('t_paiement_echeance')->whereIn('session_id', $old)->delete();
        DB::table('t_2024_inscription_session')->whereIn('session_id', $old)->delete();
        DB::table('t_2023_session')->where('session_code', 'DEMO-SOLDES')->delete();

        $session = DB::table('t_2023_session')->insertGetId(['session_code' => 'DEMO-SOLDES', 'session_name' => 'Soldes (démo)', 'session_year' => '2026 - 2027', 'session_semester' => 1, 'selected' => 0, 'date_entry' => '2026-09-28', 'graduation_date' => '2027-02-12']);
        foreach (['DEMO-0101', 'DEMO-0102', 'DEMO-0103', 'DEMO-0104', 'DEMO-0105'] as $id) {
            DB::table('t_2024_inscription_session')->insert([
                'student_id' => $id, 'session_id' => $session, 'niveau_std' => 2, 'etude_mention' => 'Informatique', 'abonment_std' => 0, 'adresse_actuel_std' => '', 'annee_scolaire' => '2026 - 2027',
                'caisse_verification' => 0, 'conjoint_name' => '', 'cours_selected' => 0, 'data_completion' => 0, 'data_verification' => 0, 'date_entry' => '2026-09-01', 'depot_list' => 0,
                'etat_civil_std' => '', 'graduated' => 0, 'mode_payement' => 0, 'nb_enfant' => 0, 'nbr_semester' => 0, 'new_student' => 0, 'parcours_std' => '', 'remove' => 0,
                'sponsor_address' => '', 'sponsor_contact' => '', 'sponsor_lastName' => '', 'sponsor_name' => '', 'status' => '', 'test_niveau' => 0, 'verification_signatures' => 0,
            ]);
        }
        $row = fn (int $id, ?string $balance, array $over = []) => DB::table('finance')->insert(array_merge(['student_id' => $id, 'session_id' => $session, 'total_payment' => '0', 'balance_amount' => $balance], $over));
        $row(101, '150000.00');
        $row(102, '-25000.00');
        $row(103, '0.00');
        $row(105, null, ['total_payment' => '500 000', 'amount_pay_1' => '150 000', 'amount_pay_2' => '50 000']);
    }

    private function quiz(): void
    {
        $this->command?->callSilent('quiz:import-questions');

        $finished = fn (string $student, int $score, int $ms) => DB::table('t_quiz_partie')->insert([
            'student_id' => $student, 'commencee_le' => now()->subMinutes(5), 'terminee_le' => now()->subMinutes(2), 'expire_le' => now()->addMinutes(25),
            'nb_questions' => 10, 'score' => $score, 'duree_ms' => $ms,
        ]);
        $finished('DEMO-0003', 9, 61000);
        $finished('DEMO-0004', 7, 48000);
        $finished('DEMO-0002', 5, 95000);
    }

    /**
     * Inscriptions fictives de DEMO-0001 en 2026-2027 (Licence 2) :
     *  - Session 1 : 4 cours dont 3 à laboratoire (le 3e est offert), frais, paiement en deux tranches, Work Education ;
     *  - Session 2 : inscrit, mais AUCUNE ligne financière (la fiche le dit et ne vaut pas justificatif).
     */
    private function enrollments(): void
    {
        DB::table('tbl_2024_etudiant')->where('student_id', 'DEMO-0001')->update([
            'etude_envisage' => 'Informatique', 'etude_option' => 'Génie logiciel', 'annee_etude' => 2, 'status' => 'Interne', 'abonment' => 1,
            'student_email' => 'fanja.demo@example.test', 'student_tel' => '034 00 000 00', 'student_adresse' => 'Lot 12, Antsirabe (fictif)',
        ]);

        $s1 = $this->session('Session 1', '2026 - 2027', 1);
        $s2 = $this->session('Session 2', '2026 - 2027', 2);

        $base = ['student_id' => 'DEMO-0001', 'niveau_std' => 2, 'etude_mention' => 'Informatique', 'abonment_std' => 0, 'adresse_actuel_std' => '', 'annee_scolaire' => '',
            'caisse_verification' => 0, 'conjoint_name' => '', 'cours_selected' => 0, 'data_completion' => 0, 'data_verification' => 0, 'date_entry' => '2026-09-07',
            'depot_list' => 0, 'etat_civil_std' => '', 'graduated' => 0, 'mode_payement' => 0, 'nb_enfant' => 0, 'nbr_semester' => 0, 'new_student' => 0,
            'parcours_std' => '', 'remove' => 0, 'sponsor_address' => '', 'sponsor_contact' => '', 'sponsor_lastName' => '', 'sponsor_name' => '', 'status' => '',
            'test_niveau' => 0, 'verification_signatures' => 0];
        DB::table('t_2024_inscription_session')->insert([$base + ['session_id' => $s1], $base + ['session_id' => $s2, 'date_entry' => '2027-01-18']]);

        $catalog = fn (string $code, string $title, int $category, int $lab, float $labCost) => DB::table('t_2023_cours')->insertGetId([
            'Sigle' => $code, 'title' => $title, 'category' => $category, 'lab' => $lab, 'cout_lab' => $labCost, 'cout' => 0, 'ajout' => 1, 'remove' => 0,
        ]);
        $courses = [
            [$catalog('DEMO INF 210', 'Structures de données', 1, 1, 40000), 'INF 210', 'Structures de données', 3, 420000],
            [$catalog('DEMO INF 220', 'Réseaux informatiques', 1, 1, 30000), 'INF 220', 'Réseaux informatiques', 3, 420000],
            [$catalog('DEMO INF 230', 'Systèmes d’exploitation', 1, 1, 25000), 'INF 230', 'Systèmes d’exploitation', 3, 420000],
            [$catalog('DEMO ANG 102', 'Anglais académique', 0, 0, 0), 'ANG 102', 'Anglais académique', 2, 280000],
        ];
        foreach ($courses as [$id, $code, $title, $credits, $cost]) {
            DB::table('t_2024_cours_finance')->insert([
                'student_id' => 'DEMO-0001', 'session_id' => $s1, 'cours_id' => $id, 'cours_sigle' => $code, 'cours_title' => $title, 'cours_credit' => $credits,
                'cours_cout' => $cost, 'lab_cout' => 0, 'remove' => 0, 'date_entry' => '2026-09-07', 'last_change_datetime' => '2026-09-07', 'last_change_user_id' => 0,
            ]);
        }

        DB::table('t_2024_etudiant_finace')->insert([
            'student_id' => 'DEMO-0001', 'session_id' => $s1, 'mention' => 'Informatique', 'level' => 2, 'status' => 'Interne', 'cout_logement' => 300000,
            'cout_fraix_generaux' => 150000, 'cout_fondDepot_dortoir' => 0, 'cout_abonment' => 200000, 'cout_frais_graduation' => 0, 'cout_totalCours' => 0,
            'cout_totalLab' => 0, 'cout_costume' => 0, 'cout_voyage' => 50000, 'mode_payement' => 'B', 'remove' => 0, 'date_entry' => '2026-09-07',
            'last_change_datetime' => '2026-09-07', 'last_change_user_id' => 0,
        ]);
        DB::table('t_2024_etudiant_frais_perso')->insert(['student_id' => 'DEMO-0001', 'session_id' => $s1, 'frais_id' => 1, 'libelle' => 'Alliance française', 'montant' => 25000, 'remove' => 0]);
        DB::table('t_paiement_echeance')->insert([
            ['session_id' => $s1, 'type_paiement' => 'B', 'tranche' => 1, 'date_limite' => '2026-10-01'],
            ['session_id' => $s1, 'type_paiement' => 'B', 'tranche' => 2, 'date_limite' => '2026-12-15'],
        ]);
        DB::table('t_work_education')->insert(['session_id' => $s1, 'student_id' => 'DEMO-0001', 'tache' => 'Entretien de la bibliothèque']);
    }

    /**
     * Connexion par la FICHE étudiante : mots de passe de la table (en clair, comme en production) et photo fictive.
     *  - DEMO-0001 a un compte : la fiche ouvre aussi ce compte ;
     *  - DEMO-0005 n'a AUCUN compte : sa première connexion le crée.
     */
    private function studentFileLogins(): void
    {
        DB::table('region')->where('region', 'Vakinankaratra (démo)')->delete();
        $region = DB::table('region')->insertGetId(['region' => 'Vakinankaratra (démo)', 'chefLieu' => 'Antsirabe', 'province' => 'Antananarivo']);
        DB::table('tbl_2024_etudiant')->where('student_id', 'DEMO-0001')->update([
            'password' => 'Demo-Fiche-1',
            'dateNaissance' => '2001-03-12', 'lieuNaissance' => 'Antsirabe', 'sex' => '0', 'nationalite' => 'Malagasy', 'pays_origine' => 'Madagascar',
            'num_cin' => '101 202 303 404', 'cin_date_delivre' => '2019-05-01', 'cin_region' => 'Vakinankaratra', 'student_region' => (string) $region,
            'religion' => 'Adventiste', 'situationf' => 'Célibataire', 'nom_conjoint' => '', 'nb_enfant' => '0',
            'father_name' => 'Rakoto Jean', 'father_prof' => 'Enseignant', 'mother_name' => 'Soa Marie', 'mother_prof' => 'Commerçante',
            'parent_tel' => '033 11 111 11', 'parent_adresse' => 'Lot 3, Antsirabe (fictif)',
            'sponsor_nom' => 'Église', 'sponsor_prenom' => 'Locale (démo)', 'sponsor_tel' => '032 22 222 22', 'sponsor_adresse' => 'Centre-ville (fictif)',
            'new_student' => 0,
        ]);
        DB::table('tbl_2024_etudiant')->insert([
            'student_id' => 'DEMO-0005', 'student_nom' => 'Rasoa', 'student_prenom' => 'Tsiory', 'student_email' => 'Tsiory.Demo@example.test',
            'password' => 'Demo-Fiche-5', 'abonment' => 0, 'annee_scolaire' => '2025-2026', 'period' => 'S1', 'remove' => 0,
            'annee_etude' => 4, 'etude_envisage' => 'Théologie', 'new_student' => 1, 'sex' => '1',
            'suspended' => 1, 'motif_suspension' => 'Frais de scolarité impayés (démo)', 'retrait_universite' => 2,
        ]);

        // Photo fictive (un aplat avec une silhouette) : aucune vraie photo n'est copiée dans le dépôt.
        $dir = storage_path('app/demo-photos');
        if (! is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        $img = imagecreatetruecolor(600, 780);
        imagefill($img, 0, 0, imagecolorallocate($img, 24, 60, 72));
        $skin = imagecolorallocate($img, 0, 196, 212);
        imagefilledellipse($img, 300, 290, 230, 270, $skin);
        imagefilledellipse($img, 300, 640, 480, 420, $skin);
        imagejpeg($img, $dir.'/demo-0001.jpg', 85);
        DB::table('tbl_2024_etudiant')->where('student_id', 'DEMO-0001')->update(['image_student' => 'demo-0001.jpg']);
    }

    /**
     * Annonces FICTIVES dans tous les états : visibles (dont une épinglée, une urgente, une avec image, une « piégée » qui contient
     * du HTML) et cachées (programmée, expirée, désactivée). Supprimées par leur auteur « Démo (fictif) » : jamais d'autres lignes.
     */
    private function news(): void
    {
        DB::table('t_annonces')->where('author', 'Démo (fictif)')->delete();

        $dir = storage_path('app/demo-announcements');
        if (! is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        $img = imagecreatetruecolor(2000, 1000);
        imagefill($img, 0, 0, imagecolorallocate($img, 24, 60, 72));
        imagefilledellipse($img, 1000, 500, 600, 600, imagecolorallocate($img, 0, 196, 212));
        imagejpeg($img, $dir.'/demo-annonce.jpg', 85);

        $day = fn (int $offset) => now()->addDays($offset)->toDateString();
        $row = fn (string $title, string $content, array $extra = []) => array_merge([
            'title' => $title, 'content' => $content, 'excerpt' => null, 'image' => null, 'category' => 'info', 'is_pinned' => 0, 'is_active' => 1,
            'author' => 'Démo (fictif)', 'publish_date' => $day(-3), 'expire_date' => null,
        ], $extra);

        DB::table('t_annonces')->insert([
            $row('Réunion d’accueil de la rentrée (démo)', 'Bienvenue à tous.\nLa réunion aura lieu à la chapelle.\nProgramme complet sur https://zurcher.edu.mg/rentree.', ['category' => 'event', 'is_pinned' => 1, 'image' => 'demo-annonce.jpg', 'publish_date' => $day(-10), 'expire_date' => $day(20), 'excerpt' => 'La réunion d’accueil réunit tous les étudiants.']),
            $row('Coupure d’eau samedi (démo)', 'Le réseau sera coupé de 8 h à 12 h.', ['category' => 'urgent', 'publish_date' => $day(-1)]),
            $row('Tournoi inter-mentions (démo)', 'Inscriptions auprès des délégués de classe. '.str_repeat('Venez nombreux. ', 20), ['category' => 'sport', 'publish_date' => $day(-30)]),
            $row('<b>Titre piégé</b> (démo)', '<script>window.__xss = 1</script> et javascript:alert(1) et <img src=x onerror="window.__xss = 2">', ['category' => 'culture', 'publish_date' => $day(-5)]),
            $row('Annonce programmée (démo)', 'Pas encore visible.', ['publish_date' => $day(2)]),
            $row('Annonce expirée (démo)', 'N’est plus visible.', ['publish_date' => $day(-20), 'expire_date' => $day(-1)]),
            $row('Annonce désactivée (démo)', 'Retirée par le registrariat.', ['is_active' => 0]),
        ]);
    }

    /**
     * Copie FICTIVE d'articles du site public (t_site_news), comme la laisserait `news:sync`. Supprimée par le préfixe « demo- »
     * du slug : jamais d'autres lignes. Le troisième article porte des blocs HOSTILES (lien javascript:, image http, balises en
     * texte) pour vérifier que l'écran les rend inertes même si le serveur les avait laissés passer.
     */
    private function siteNews(): void
    {
        DB::table('t_site_news')->where('slug', 'LIKE', 'demo-%')->delete();
        DB::table('t_site_news_sync')->where('message', 'démo (fictif)')->delete();

        $day = fn (int $offset) => now()->addDays($offset)->toDateString();
        $row = fn (string $slug, string $title, string $excerpt, ?string $author, int $offset, array $blocks) => [
            'slug' => $slug, 'url' => 'https://zurcher.edu.mg/actualites/'.$slug, 'title' => $title, 'excerpt' => $excerpt, 'category' => 'Actualités',
            'image_url' => null, 'author' => $author, 'published_on' => $day($offset), 'blocks' => json_encode($blocks, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'lastmod' => now()->addDays($offset)->format('Y-m-d H:i:s'), 'content_hash' => sha1($slug), 'is_visible' => 1,
            'fetched_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ];
        $p = fn (string ...$t) => ['type' => 'paragraph', 'inlines' => array_map(fn ($x) => ['text' => $x], $t)];

        DB::table('t_site_news')->insert([
            $row('demo-ouverture-master', 'Ouverture du Master (démo)', 'La nouvelle filière ouvre ses portes à la rentrée.', 'Service communication (fictif)', -2, [
                ['type' => 'paragraph', 'inlines' => [['text' => 'Premier paragraphe avec du '], ['text' => 'gras', 'bold' => true], ['text' => ' et de l’'], ['text' => 'italique', 'italic' => true], ['br' => true], ['text' => 'Deuxième ligne du même paragraphe.']]],
                ['type' => 'heading', 'level' => 2, 'inlines' => [['text' => 'Les conditions d’admission']]],
                ['type' => 'list', 'ordered' => false, 'items' => [[['text' => 'Être titulaire d’une licence']], [['text' => 'Déposer un dossier avant le 30 juin']]]],
                ['type' => 'quote', 'inlines' => [['text' => 'Une citation du recteur (fictive).']]],
                ['type' => 'paragraph', 'inlines' => [['text' => 'Détails sur '], ['text' => 'le site', 'href' => 'https://zurcher.edu.mg/formations'], ['text' => '.']]],
            ]),
            $row('demo-culte-ouverture', 'Culte d’ouverture de l’année (démo)', 'Un moment de recueillement pour toute la communauté.', null, -10, [$p('Texte simple, sans auteur ni image.')]),
            $row('demo-article-piege', 'Article piégé <b>gras</b> (démo)', 'Des blocs hostiles, rendus comme du texte.', 'Pirate', -30, [
                $p('<script>window.__xss = 1</script> ceci est du TEXTE.'),
                ['type' => 'paragraph', 'inlines' => [['text' => 'lien piégé', 'href' => 'javascript:window.__xss=2'], ['text' => ' et '], ['text' => 'lien vbscript', 'href' => 'vbscript:x'], ['text' => ' et '], ['text' => 'lien sûr', 'href' => 'https://zurcher.edu.mg/ok']]],
                ['type' => 'image', 'src' => 'http://insecure.example/pixel.png', 'alt' => 'image non sécurisée', 'width' => 10, 'height' => 10],
                ['type' => 'image', 'src' => 'javascript:window.__xss=3', 'alt' => 'image javascript', 'width' => null, 'height' => null],
            ]),
        ]);
        DB::table('t_site_news_sync')->insert([
            'started_at' => now()->subMinutes(12), 'finished_at' => now()->subMinutes(11), 'status' => 'ok', 'seen' => 3, 'created' => 3, 'message' => 'démo (fictif)',
        ]);
    }

    private function session(string $name, string $year, int $semester): int
    {
        return DB::table('t_2023_session')->insertGetId([
            'session_code' => 'DEMO-'.$name.$year, 'session_name' => $name, 'session_year' => $year,
            'session_semester' => $semester, 'selected' => 0, 'date_entry' => now()->toDateString(),
        ]);
    }
}
