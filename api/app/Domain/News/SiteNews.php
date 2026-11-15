<?php

namespace App\Domain\News;

use Illuminate\Support\Facades\DB;

/**
 * Lecture de la copie locale des actualités du site public (voir SiteNewsSync) : ce que l'étudiant voit dans « Actualités ».
 *
 * Aucune écriture, aucun appel réseau : l'application ne dépend pas de la disponibilité du site pour s'afficher.
 */
final class SiteNews
{
    /**
     * @return array{items: list<array<string, mixed>>, total: int, synced_at: ?string, source_url: string}
     */
    public function list(): array
    {
        $rows = DB::table('t_site_news')->where('is_visible', 1)
            ->orderByDesc('published_on')->orderByDesc('lastmod')->orderByDesc('id')
            ->get(['id', 'slug', 'url', 'title', 'excerpt', 'category', 'image_url', 'thumb_url', 'author', 'published_on']);

        return [
            'items' => $rows->map(fn ($r) => $this->summary($r))->values()->all(),
            'total' => $rows->count(),
            'synced_at' => $this->syncedAt(),
            'source_url' => $this->sourceUrl(),
        ];
    }

    /** @return array<string, mixed>|null */
    public function find(string $slug): ?array
    {
        $r = DB::table('t_site_news')->where('is_visible', 1)->where('slug', $slug)->first();
        if ($r === null) {
            return null;
        }
        $blocks = json_decode((string) $r->blocks, true);

        return $this->summary($r) + [
            'blocks' => is_array($blocks) ? $blocks : [],
            'synced_at' => $this->syncedAt(),
        ];
    }

    /** Dernière synchronisation RÉUSSIE (ou partielle) : celle qui garantit que la copie est à jour. */
    public function syncedAt(): ?string
    {
        $t = DB::table('t_site_news_sync')->whereIn('status', ['ok', 'partial'])->orderByDesc('finished_at')->value('finished_at');

        return $t === null ? null : (new \DateTimeImmutable((string) $t, new \DateTimeZone((string) config('app.timezone'))))->format(DATE_ATOM);
    }

    private function sourceUrl(): string
    {
        return rtrim((string) config('registrar.site_news.base'), '/').'/actualites';
    }

    /** @return array<string, mixed> */
    private function summary(object $r): array
    {
        return [
            'slug' => $r->slug,
            'title' => $r->title,
            'excerpt' => $r->excerpt,
            'category' => $r->category,
            'image_url' => $r->image_url,
            'thumb_url' => $r->thumb_url ?? null,
            'author' => $r->author,
            'published_on' => $r->published_on === null ? null : substr((string) $r->published_on, 0, 10),
            'url' => $r->url,
        ];
    }
}
