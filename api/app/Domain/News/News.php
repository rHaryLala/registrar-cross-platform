<?php

namespace App\Domain\News;

use App\Support\SafeImage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Actualités de l'université (table `t_annonces`), en lecture seule pour l'étudiant.
 *
 * Porté de src/student.actus.php. Règles reprises : annonce ACTIVE, déjà publiée (publish_date vide ou passée ou du jour)
 * et non expirée (expire_date vide ou du jour ou future) ; les épinglées d'abord, puis la plus récente. « Aujourd'hui » est
 * la date de Madagascar (fuseau de l'application), comme la connexion de l'ancienne application.
 *
 * Le contenu est du TEXTE BRUT (l'ancienne page l'échappait) : on le renvoie tel quel, c'est à l'écran de l'échapper.
 * Écarts assumés : l'extrait ne reçoit « … » que s'il a réellement été coupé ; la coupe se fait en caractères, pas en octets
 * (« é » n'est plus déchiré) ; ordre total (l'identifiant départage deux annonces du même jour).
 */
final class News
{
    /** @var array<string, string> catégorie => libellé */
    public const CATEGORIES = [
        'info' => 'Information', 'event' => 'Événement', 'academic' => 'Académique',
        'urgent' => 'Urgent', 'sport' => 'Sport', 'culture' => 'Culture',
    ];

    public const EXCERPT_LENGTH = 140;

    /**
     * @return array{items: list<array<string, mixed>>, counts: array<string, int>, total: int}
     */
    public function list(?string $category = null): array
    {
        $rows = $this->visible()->orderByDesc('is_pinned')->orderByDesc('publish_date')->orderByDesc('id')
            ->get(['id', 'title', 'content', 'excerpt', 'image', 'category', 'is_pinned', 'author', 'publish_date']);

        $counts = array_fill_keys(array_keys(self::CATEGORIES), 0);
        foreach ($rows as $r) {
            if (isset($counts[$r->category])) {
                $counts[$r->category]++;
            }
        }

        $shown = $category === null ? $rows : $rows->filter(fn ($r) => $r->category === $category);

        return [
            'items' => $shown->map(fn ($r) => $this->summary($r))->values()->all(),
            'counts' => $counts,
            'total' => $rows->count(),
        ];
    }

    /** @return array<string, mixed>|null null = annonce inconnue, inactive, pas encore publiée ou expirée (même réponse : rien n'est révélé) */
    public function find(int $id): ?array
    {
        $r = $this->visible()->where('id', $id)->first();
        if ($r === null) {
            return null;
        }

        return $this->summary($r) + [
            'content' => $this->plain((string) $r->content),
            'expires_on' => $this->date($r->expire_date),
        ];
    }

    /** Chemin de l'image d'une annonce VISIBLE (jamais celle d'une annonce masquée), ou null. */
    public function imagePath(int $id): ?string
    {
        $image = $this->visible()->where('id', $id)->value('image');

        return $image === null ? null : SafeImage::resolve((string) config('registrar.announcement_images_path'), (string) $image);
    }

    /** @return \Illuminate\Database\Query\Builder */
    private function visible()
    {
        $today = Carbon::today()->toDateString();

        return DB::table('t_annonces')
            ->where('is_active', 1)
            ->where(fn ($q) => $q->whereNull('publish_date')->orWhere('publish_date', '<=', $today))
            ->where(fn ($q) => $q->whereNull('expire_date')->orWhere('expire_date', '>=', $today));
    }

    /** @return array<string, mixed> */
    private function summary(object $r): array
    {
        $category = isset(self::CATEGORIES[$r->category]) ? $r->category : 'info';   // l'ancienne page retombe sur « info »

        return [
            'id' => (int) $r->id,
            'title' => trim((string) $r->title),
            'excerpt' => $this->excerpt($r),
            'category' => $category,
            'category_label' => self::CATEGORIES[$category],
            'pinned' => (int) $r->is_pinned === 1,
            'published_on' => $this->date($r->publish_date),
            'author' => ($a = trim((string) $r->author)) === '' ? null : $a,
            'has_image' => $r->image !== null && trim((string) $r->image) !== '',
        ];
    }

    private function excerpt(object $r): string
    {
        $given = trim((string) $r->excerpt);
        if ($given !== '') {
            return $given;
        }
        $flat = trim((string) preg_replace('/\s+/u', ' ', $this->plain((string) $r->content)));

        return mb_strlen($flat) > self::EXCERPT_LENGTH ? rtrim(mb_substr($flat, 0, self::EXCERPT_LENGTH)).'…' : $flat;
    }

    /** Certaines annonces portent la séquence littérale « \n » (deux caractères) au lieu d'un saut de ligne : l'ancienne page la convertissait. */
    private function plain(string $content): string
    {
        return str_replace(["\r\n", '\n'], "\n", $content);
    }

    private function date(mixed $value): ?string
    {
        $v = trim((string) $value);

        return preg_match('/^\d{4}-\d{2}-\d{2}/', $v) && ! str_starts_with($v, '0000') ? substr($v, 0, 10) : null;
    }
}
