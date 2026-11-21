<?php

namespace App\Support;

/**
 * Le bas des documents officiels : lieu, nom de la registraire et, si elle est fournie, l'image de sa signature.
 *
 * Rien n'est écrit dans le code : le nom (REGISTRAR_NAME), le lieu (REGISTRAR_PLACE) et le fichier de signature
 * (REGISTRAR_SIGNATURE_FILE, chemin absolu d'une image, par exemple celle de l'ancienne application) sont des réglages du serveur.
 * Sans fichier valide, le document porte une ligne de signature vide à remplir à la main : jamais d'image par défaut.
 */
final class RegistrarSignature
{
    /** @return array{place: string, name: string, signature: ?string} */
    public static function footer(): array
    {
        return [
            'place' => (string) config('registrar.place', 'Sambaina'),
            'name' => trim((string) config('registrar.registrar_name', '')) !== '' ? trim((string) config('registrar.registrar_name')) : 'La registraire',
            'signature' => self::dataUri(),
        ];
    }

    /** L'image de la signature en URI de données (réduite, redressée), ou null si elle n'est pas configurée ou pas une image sûre. */
    public static function dataUri(): ?string
    {
        $file = trim((string) config('registrar.signature_file', ''));
        if ($file === '') {
            return null;
        }
        $file = str_replace(chr(92), '/', $file);
        $folder = dirname($file);
        $path = SafeImage::resolve($folder, basename($file));
        $jpeg = $path === null ? null : SafeImage::jpeg($path, 480, 160);

        return $jpeg === null ? null : 'data:image/jpeg;base64,'.base64_encode($jpeg);
    }
}
