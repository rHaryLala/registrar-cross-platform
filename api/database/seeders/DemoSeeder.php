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

        User::query()->whereIn('pseudo', ['etudiant.demo', 'etudiant.vide', 'registraire.demo', 'ancien.demo'])->delete();
        DB::table('t_presence_feuille')->where('cours_id', '>=', 990000)->delete();
        DB::table('t_2023_notes')->where('student_id', 'LIKE', 'DEMO-%')->delete();
        DB::table('tbl_2024_etudiant')->where('student_id', 'LIKE', 'DEMO-%')->delete();
        DB::table('t_2023_session')->where('session_code', 'LIKE', 'DEMO-%')->delete();

        foreach (['DEMO-0001' => ['Rakoto', 'Fanja'], 'DEMO-0002' => ['Andria', 'Tojo']] as $id => [$nom, $prenom]) {
            DB::table('tbl_2024_etudiant')->insert([
                'student_id' => $id, 'student_nom' => $nom, 'student_prenom' => $prenom, 'password' => '',
                'abonment' => 0, 'annee_scolaire' => '2025-2026', 'period' => 'S1', 'remove' => 0,
            ]);
        }

        $student('etudiant.demo', 'DEMO-0001', 'Rakoto', 'Fanja');
        $student('etudiant.vide', 'DEMO-0002', 'Andria', 'Tojo');
        User::factory()->staff(3, 'registrar')->create(['pseudo' => 'registraire.demo', 'mail' => 'registraire.demo@zurcher.edu.mg', 'nom' => 'Demo', 'prenom' => 'Registraire']);
        User::factory()->legacyPassword()->staff(6, 'chef_mention')->create(['pseudo' => 'ancien.demo', 'mail' => 'ancien.demo@zurcher.edu.mg', 'nom' => 'Demo', 'prenom' => 'Ancien']);

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
    }

    private function session(string $name, string $year, int $semester): int
    {
        return DB::table('t_2023_session')->insertGetId([
            'session_code' => 'DEMO-'.$name.$year, 'session_name' => $name, 'session_year' => $year,
            'session_semester' => $semester, 'selected' => 0, 'date_entry' => now()->toDateString(),
        ]);
    }
}
