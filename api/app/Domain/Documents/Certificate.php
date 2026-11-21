<?php

namespace App\Domain\Documents;

use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Le certificat de scolarité d'un étudiant : port de src/extenssionPrint/certScolarity.php (43 appels en deux mois).
 *
 * Mêmes mentions que l'ancien certificat : nom et prénoms, naissance, parents, adresse habituelle, mention, parcours, année universitaire,
 * matricule, lieu et date, titre et nom de qui signe (le compte connecté).
 *
 * Écarts assumés avec l'ancien certificat :
 *  - toutes les données viennent de la BASE : l'ancien certificat lisait le parcours dans la requête (`etude_option`) et acceptait donc
 *    n'importe quel texte dans un document officiel ;
 *  - une date de naissance absente ou « zéro » s'écrit « — » (l'ancien certificat imprimait « 30 novembre -0001 » pour 21 étudiants) ;
 *  - sans titre sur le compte qui signe, rien n'est inventé (l'ancien certificat écrivait « Secrétaire Académique » ou « Utilisateur »).
 */
final class Certificate
{
    private const MONTHS = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];

    /** @return array<string, mixed> */
    public function build(object $student, User $signer, ?Carbon $now = null): array
    {
        $now ??= Carbon::now('Indian/Antananarivo');

        return [
            'student_id' => (string) $student->student_id,
            'name' => trim(mb_strtoupper((string) $student->student_nom).' '.$student->student_prenom),
            'birth_date' => self::dateLabel($student->dateNaissance),
            'birth_place' => self::text($student->lieuNaissance),
            'father' => self::text($student->father_name),
            'mother' => self::text($student->mother_name),
            'address' => self::text($student->student_adresse),
            'mention' => self::text($student->etude_envisage),
            'track' => self::text($student->etude_option),
            'year' => self::text($student->annee_scolaire),
            'issued_on' => self::dateLabel($now->toDateString()),
            'signer' => [
                'post' => trim((string) ($signer->post ?? '')),
                'name' => trim(mb_strtoupper((string) $signer->nom).' '.$signer->prenom),
            ],
        ];
    }

    /** « 05 mars 2001 » (jour sur deux chiffres, comme l'ancien certificat) ; null si la date est absente, « zéro » ou impossible. */
    public static function dateLabel(mixed $value): ?string
    {
        if (! is_string($value) || ! preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $value, $m)) {
            return null;
        }
        [$y, $mo, $d] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        if ($y < 1900 || ! checkdate($mo, $d, $y)) {
            return null;
        }

        return sprintf('%02d %s %d', $d, self::MONTHS[$mo - 1], $y);
    }

    private static function text(mixed $v): ?string
    {
        $v = trim((string) ($v ?? ''));

        return $v === '' ? null : $v;
    }
}
