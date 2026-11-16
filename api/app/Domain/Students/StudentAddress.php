<?php

namespace App\Domain\Students;

use Illuminate\Support\Facades\DB;

/**
 * Adresse universitaire d'un nouvel étudiant (@zurcher.edu.mg), UNIQUE (port de `data/adresse.etudiant.php`).
 *
 * Elle se dérive du nom : « nom.pre » (trois premières lettres du prénom). Règle du registrariat (08/10/2026) : une adresse déjà prise, par qui que ce soit
 * (la même personne passée de Licence en Master comprise), n'est jamais reprise ; la nouvelle fiche reçoit une adresse neuve.
 *
 * Ordre des essais : nom.pre, nom.pren, nom.preno… jusqu'au prénom entier, puis nom.prenom2, nom.prenom3… Sans prénom : nom.uaz, nom.uaz2… Lettres et chiffres
 * seulement : Google refuse les accents, et les espaces, apostrophes et tirets des noms malgaches composés n'y ont pas leur place.
 *
 * Une adresse est prise si une fiche étudiante (même supprimée : son compte Google peut exister), un compte de l'application ou une fiche enseignant la porte
 * déjà, ou — quand l'API Google Admin est configurée et qu'on le demande — si elle existe dans Google.
 */
final class StudentAddress
{
    public const DOMAIN = 'zurcher.edu.mg';

    /** Le texte sans accents ni caractères hors ASCII, casse et ponctuation gardées. */
    public static function withoutAccents(string $text): string
    {
        $text = strtr($text, [
            'à' => 'a', 'â' => 'a', 'ä' => 'a', 'á' => 'a', 'ã' => 'a', 'å' => 'a', 'ç' => 'c', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
            'ñ' => 'n', 'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'ö' => 'o', 'õ' => 'o', 'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ý' => 'y', 'ÿ' => 'y', 'œ' => 'oe', 'æ' => 'ae',
            'À' => 'A', 'Â' => 'A', 'Ä' => 'A', 'Á' => 'A', 'Ã' => 'A', 'Å' => 'A', 'Ç' => 'C', 'É' => 'E', 'È' => 'E', 'Ê' => 'E', 'Ë' => 'E', 'Í' => 'I', 'Ì' => 'I', 'Î' => 'I', 'Ï' => 'I',
            'Ñ' => 'N', 'Ó' => 'O', 'Ò' => 'O', 'Ô' => 'O', 'Ö' => 'O', 'Õ' => 'O', 'Ú' => 'U', 'Ù' => 'U', 'Û' => 'U', 'Ü' => 'U', 'Ý' => 'Y', 'Ÿ' => 'Y', 'Œ' => 'OE', 'Æ' => 'AE',
            '’' => "'", '‘' => "'", '«' => '"', '»' => '"', '–' => '-', '—' => '-', "\u{00A0}" => ' ',
        ]);

        return (string) preg_replace('/[^\x20-\x7E]/', '', $text);
    }

    /** Minuscules sans accents, lettres et chiffres seulement. */
    public static function normalize(string $text): string
    {
        return (string) preg_replace('/[^a-z0-9]/', '', strtolower(self::withoutAccents(trim($text))));
    }

    /**
     * Adresses déjà prises qui commencent par « $start » (minuscules), toutes sources confondues.
     *
     * @return array<string, true>
     */
    public static function takenStartingWith(string $start): array
    {
        $pattern = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $start).'%';
        $taken = [];
        foreach ([
            'SELECT LOWER(TRIM(student_email)) AS a FROM tbl_2024_etudiant WHERE LOWER(TRIM(student_email)) LIKE ?',
            'SELECT LOWER(TRIM(mail)) AS a FROM compt_utilisateur WHERE LOWER(TRIM(mail)) LIKE ?',
            'SELECT LOWER(TRIM(email)) AS a FROM teacher WHERE LOWER(TRIM(email)) LIKE ?',
        ] as $sql) {
            foreach (DB::select($sql, [$pattern]) as $r) {
                $taken[(string) $r->a] = true;
            }
        }

        return $taken;
    }

    /** L'adresse est-elle déjà portée par une fiche, un compte ou un enseignant ? (Google n'est consulté qu'à la demande : voir `free`.) */
    public static function taken(string $address): bool
    {
        $address = strtolower(trim($address));

        return $address !== '' && isset(self::takenStartingWith($address)[$address]);
    }

    /**
     * La première adresse libre pour ce nom et ce prénom.
     *
     * @param  bool  $askGoogle  au plus cinq questions à Google (une demi-seconde chacune) quand l'API est configurée
     * @return array{address: string, usual: string} `address` : l'adresse attribuée ('' sans nom) ; `usual` : l'adresse habituelle (nom.pre), pour dire qu'elle était prise
     */
    public static function free(string $lastName, string $firstName, bool $askGoogle = false): array
    {
        $n = self::normalize($lastName);
        $p = self::normalize($firstName);
        if ($n === '') {
            return ['address' => '', 'usual' => ''];
        }
        $suffix = '@'.self::DOMAIN;
        $tries = [];
        if ($p === '') {
            $tries[] = "$n.uaz";
            $root = "$n.uaz";
        } else {
            for ($k = min(3, strlen($p)); $k <= strlen($p); $k++) {
                $tries[] = $n.'.'.substr($p, 0, $k);
            }
            $root = "$n.$p";
        }
        for ($i = 2; $i <= 99; $i++) {
            $tries[] = $root.$i;
        }

        $taken = self::takenStartingWith("$n.");
        $google = $askGoogle && GoogleAdmin::configured();
        $asked = 0;
        foreach ($tries as $t) {
            if (isset($taken[$t.$suffix])) {
                continue;
            }
            if ($google && $asked < 5) {
                $asked++;
                if (GoogleAdmin::addressExists($t.$suffix) === true) {
                    continue;
                }
            }

            return ['address' => $t.$suffix, 'usual' => $tries[0].$suffix];
        }

        return ['address' => '', 'usual' => $tries[0].$suffix];
    }
}
