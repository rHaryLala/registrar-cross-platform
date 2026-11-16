<?php

namespace App\Domain\Directory;

use App\Exceptions\ApiProblem;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Les fiches (lecture seule) d'un étudiant, d'un cours et d'un enseignant, depuis les listes du registrariat.
 *
 * Mêmes droits que les listes (`Directory::allows`) et mêmes périmètres : une fiche hors du cycle du compte est « introuvable », comme dans
 * l'ancienne application. Seules les informations utiles à la gestion sont rendues : jamais de mot de passe, de pièce d'identité, de visa,
 * de situation familiale, de sponsor, de groupe sanguin ni de tarif. La modification des fiches reste dans l'ancienne version.
 */
final class Dossiers
{
    private function allow(User $user, string $list): void
    {
        if (! Directory::allows($user, $list)) {
            throw new ApiProblem(403, 'forbidden.directory', 'Accès refusé', match ($list) {
                'students' => 'Les fiches des étudiants sont réservées au personnel.',
                'courses' => 'Seuls le superadmin et le registrariat ont accès aux fiches des cours.',
                default => 'Seuls le superadmin et le registrariat ont accès aux fiches des enseignants.',
            });
        }
    }

    private function notFound(string $what): never
    {
        throw new ApiProblem(404, 'directory.not_found', ucfirst($what).' introuvable', 'Cette fiche n’existe pas ou n’est pas dans votre périmètre.');
    }

    /** @return array<string, mixed> */
    public function student(User $user, int $id): array
    {
        $this->allow($user, 'students');
        $e = DB::table('tbl_2024_etudiant')->where('id', $id)->where('remove', '!=', 1)->first();
        $levels = Directory::levels($user);
        // Un compte limité à un cycle ne voit pas une fiche d'un autre cycle, ni une fiche sans niveau. (La fiche d'un chef de mention reste commune :
        // dans l'ancienne application, seule sa LISTE est limitée à sa mention.)
        if (! $e || ($levels !== null && ($e->annee_etude === null || $e->annee_etude === '' || ! in_array((int) $e->annee_etude, $levels, true)))) {
            $this->notFound('fiche');
        }

        $enrolments = DB::table('t_2023_notes as n')->join('t_2023_cours as c', 'c.id', '=', DB::raw('CAST(n.id_cours AS UNSIGNED)'))
            ->where('n.student_id', $e->student_id)->where('n.remove', 0)
            ->select('n.annee_scolaire', DB::raw('COUNT(*) AS courses'))->groupBy('n.annee_scolaire')->orderByDesc('n.annee_scolaire')->get()
            ->map(fn ($r) => ['year' => $r->annee_scolaire, 'courses' => (int) $r->courses])->all();

        return [
            'id' => (int) $e->id, 'student_id' => (string) $e->student_id, 'last_name' => $e->student_nom, 'first_name' => $e->student_prenom,
            'sex' => $e->sex, 'birth_date' => $e->dateNaissance, 'birth_place' => $e->lieuNaissance, 'nationality' => $e->nationalite,
            'email' => $e->student_email ?: null, 'phone' => $e->student_tel ?: null, 'address' => $e->student_adresse ?: null,
            'mention' => $e->etude_envisage, 'track' => $e->etude_option, 'year' => $e->annee_scolaire, 'level' => $e->annee_etude === null || $e->annee_etude === '' ? null : (int) $e->annee_etude,
            'level_label' => Directory::levelLabel($e->annee_etude), 'is_new' => (int) ($e->new_student ?? 0) === 1,
            'status' => [
                'suspended' => (int) ($e->suspended ?? 0) === 1, 'left_university' => (int) ($e->retrait_universite ?? 0) === 1,
                'graduated' => (int) ($e->graduated ?? 0) === 1, 'file_complete' => (int) ($e->dossier_ok ?? 0) === 1,
                'reenrolment_authorised' => (int) ($e->authorized_reinscription ?? 0) === 1,
            ],
            'enrolments' => $enrolments,
        ];
    }

    /** @return array<string, mixed> */
    public function course(User $user, int $id): array
    {
        $this->allow($user, 'courses');
        $c = DB::table('t_2023_cours')->where('id', $id)->where('remove', '!=', 1)->first();
        $levels = Directory::levels($user);
        if (! $c || ($levels !== null && ($c->yearlevel === null || ! in_array((int) $c->yearlevel, $levels, true)))) {
            $this->notFound('fiche');
        }
        $teacher = $c->id_teacher ? DB::table('teacher')->where('uid', $c->id_teacher)->first() : null;
        $track = $c->parcours === 'all' ? 'Tronc commun' : DB::table('filiere_parcours')->where('shortcode', $c->parcours)->value('description');
        $years = DB::table('t_2023_notes')->where('id_cours', (string) $c->id)->where('remove', 0)
            ->select('annee_scolaire', DB::raw('COUNT(DISTINCT student_id) AS students'))->groupBy('annee_scolaire')->orderByDesc('annee_scolaire')->get()
            ->map(fn ($r) => ['year' => $r->annee_scolaire, 'students' => (int) $r->students])->all();
        $name = $teacher ? trim(mb_strtoupper((string) $teacher->name).' '.$teacher->lastName) : '';

        return [
            'id' => (int) $c->id, 'code' => Directory::courseCode($c->sigle_affiche, $c->Sigle), 'title' => $c->title, 'title_english' => $c->title_english ?: null,
            'mention' => $c->dep_desc, 'track' => $track ?: null, 'credits' => $c->nb_crd, 'category' => Directory::categoryLabel($c->category),
            'level' => $c->yearlevel === null ? null : (int) $c->yearlevel, 'level_label' => Directory::levelLabel($c->yearlevel), 'semester' => $c->semester === null ? null : (int) $c->semester,
            'lab' => (int) ($c->lab ?? 0) === 1, 'active' => (int) ($c->active ?? 1) === 1,
            'teacher' => $teacher && $name !== '' ? ['id' => (int) $teacher->teacher_id, 'name' => $name] : null,
            'enrolments' => $years,
        ];
    }

    /** @return array<string, mixed> */
    public function teacher(User $user, int $id): array
    {
        $this->allow($user, 'teachers');
        $t = DB::table('teacher')->where('teacher_id', $id)->where('remove', '!=', 1)->first();
        if (! $t) {
            $this->notFound('fiche');
        }
        $levels = Directory::levels($user);
        $courses = DB::table('t_2023_cours')->where('id_teacher', $t->uid)->where('remove', '!=', 1)
            ->when($levels !== null, fn ($q) => $q->whereIn('yearlevel', $levels))
            ->orderBy('title')->get(['id', 'Sigle', 'sigle_affiche', 'title', 'yearlevel', 'semester'])
            ->map(fn ($c) => ['id' => (int) $c->id, 'code' => Directory::courseCode($c->sigle_affiche, $c->Sigle), 'title' => $c->title, 'level_label' => Directory::levelLabel($c->yearlevel), 'semester' => $c->semester === null ? null : (int) $c->semester])->all();

        return [
            'id' => (int) $t->teacher_id, 'name' => $t->name, 'last_name' => $t->lastName, 'sex' => $t->sex, 'diploma' => $t->diplome ?: null, 'position' => $t->position ?: null,
            'category' => $t->category ?: null, 'email' => $t->email ?: null, 'phone' => $t->phone ?: null, 'address' => $t->address ?: null, 'courses' => $courses,
        ];
    }
}
