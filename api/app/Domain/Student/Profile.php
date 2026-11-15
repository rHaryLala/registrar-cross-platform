<?php

namespace App\Domain\Student;

use Illuminate\Support\Facades\DB;

/**
 * « Mes informations » : la fiche de l'étudiant connecté, en lecture seule.
 *
 * Porté de src/student.info.php (page sans aucune écriture). Règles reprises : ligne la plus récente non retirée
 * (ORDER BY remove ASC, annee_scolaire DESC), niveau 0 à 5, type Nouveau / Spécial / Ancien (new_student 1 / 10 / autre),
 * sexe 0 = Féminin, vide = inconnu, suspension (suspended = 1), retrait (retrait_universite >= 2), région lue dans `region`.
 *
 * Écarts assumés : une valeur vide est `null` (le front écrit « — ») ; une date est ISO (le front l'écrit en français) et la
 * date « 0000-00-00 » de MySQL est `null` ; AUCUNE colonne secrète n'est lue (jamais `password`).
 */
final class Profile
{
    public function __construct(private readonly StudentPhoto $photos) {}

    /** @return array<string, mixed>|null null = aucune fiche pour ce matricule */
    public function for(string $studentId): ?array
    {
        $s = DB::table('tbl_2024_etudiant')->where('student_id', $studentId)->orderBy('remove')->orderByDesc('annee_scolaire')->orderByDesc('id')
            ->first([
                'student_id', 'student_nom', 'student_prenom', 'dateNaissance', 'lieuNaissance', 'sex', 'nationalite', 'pays_origine',
                'num_cin', 'cin_date_delivre', 'cin_region', 'student_tel', 'student_email', 'student_adresse', 'student_region',
                'etude_envisage', 'etude_option', 'annee_etude', 'annee_scolaire', 'status', 'new_student', 'religion', 'abonment',
                'situationf', 'nom_conjoint', 'nb_enfant', 'father_name', 'father_prof', 'mother_name', 'mother_prof', 'parent_tel',
                'parent_adresse', 'sponsor_nom', 'sponsor_prenom', 'sponsor_tel', 'sponsor_adresse', 'suspended', 'motif_suspension',
                'retrait_universite',
            ]);
        if ($s === null) {
            return null;
        }

        $level = (int) $s->annee_etude;
        $levels = EnrollmentRules::levels();

        return [
            'student_id' => $studentId,
            'first_name' => $this->text($s->student_prenom),
            'last_name' => $this->text($s->student_nom),
            'has_photo' => $this->photos->exists($studentId),
            'level_label' => $levels[$level]['label'] ?? 'Niveau '.$level,
            'student_type' => match ((int) $s->new_student) {
                1 => 'Nouveau', 10 => 'Spécial', default => 'Ancien',
            },
            'status' => ['suspended' => (int) $s->suspended === 1, 'suspension_reason' => $this->text($s->motif_suspension), 'withdrawn' => (int) $s->retrait_universite >= 2],
            'identity' => [
                'birth_date' => $this->date($s->dateNaissance),
                'birth_place' => $this->text($s->lieuNaissance),
                'sex' => $this->sex($s->sex),
                'nationality' => $this->text($s->nationalite),
                'origin_country' => $this->text($s->pays_origine),
            ],
            'id_document' => [
                'cin_number' => $this->text($s->num_cin),
                'cin_date' => $this->date($s->cin_date_delivre),
                'cin_region' => $this->text($s->cin_region),
            ],
            'contact' => [
                'phone' => $this->text($s->student_tel),
                'email' => $this->text($s->student_email),
                'address' => $this->text($s->student_adresse),
                'region' => $this->region($s->student_region),
            ],
            'academic' => [
                'mention' => $this->text($s->etude_envisage),
                'track' => $this->text($s->etude_option),
                'level_label' => $levels[$level]['label'] ?? 'Niveau '.$level,
                'school_year' => $this->text($s->annee_scolaire),
                'residence' => $this->text($s->status),
                'religion' => $this->text($s->religion),
                'cafeteria' => (int) $s->abonment === 1,
            ],
            'family' => [
                'father_name' => $this->text($s->father_name),
                'father_job' => $this->text($s->father_prof),
                'mother_name' => $this->text($s->mother_name),
                'mother_job' => $this->text($s->mother_prof),
                'parents_phone' => $this->text($s->parent_tel),
                'parents_address' => $this->text($s->parent_adresse),
            ],
            'civil' => [
                'situation' => $this->text($s->situationf),
                'spouse' => $this->text($s->nom_conjoint),
                'children' => $this->text($s->nb_enfant),
            ],
            'sponsor' => [
                'last_name' => $this->text($s->sponsor_nom),
                'first_name' => $this->text($s->sponsor_prenom),
                'phone' => $this->text($s->sponsor_tel),
                'address' => $this->text($s->sponsor_adresse),
            ],
        ];
    }

    /** Texte nettoyé : espaces retirés, vide = null (jamais « — » : c'est au front de dire l'absence). */
    private function text(mixed $value): ?string
    {
        $t = trim((string) $value);

        return $t === '' ? null : $t;
    }

    /** 0 = Féminin, vide = inconnu, toute autre valeur = Masculin (règle de l'ancienne page). */
    private function sex(mixed $value): ?string
    {
        $v = trim((string) $value);

        return match (true) {
            $v === '0' => 'Féminin',
            $v === '' => null,
            default => 'Masculin',
        };
    }

    /** Date ISO valide, ou null (la date « zéro » de MySQL et les valeurs libres ne sont pas des dates). */
    private function date(mixed $value): ?string
    {
        $v = trim((string) $value);
        if (! preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $v, $m) || ! checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return null;
        }

        return "{$m[1]}-{$m[2]}-{$m[3]}";
    }

    private function region(mixed $id): ?string
    {
        $id = trim((string) $id);
        if ($id === '' || ! ctype_digit($id)) {
            return null;
        }

        return $this->text(DB::table('region')->where('id', (int) $id)->value('region'));
    }
}
