<?php

namespace App\Domain\News;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UriInterface;
use RuntimeException;
use Throwable;

/**
 * Synchronise la copie locale des actualités avec le site public https://zurcher.edu.mg/actualites.
 *
 * Fonctionnement : le sitemap du site (public, autorisé par son robots.txt) liste les articles et leur date de modification ;
 * on ne télécharge que les articles NOUVEAUX ou MODIFIÉS (ou tout, avec --force). Un article qui disparaît du sitemap est masqué
 * ici, jamais supprimé.
 *
 * Sécurité et robustesse :
 *  - L'hôte est VERROUILLÉ sur celui de `registrar.site_news.base` : une adresse du sitemap qui pointe ailleurs est ignorée,
 *    une redirection vers un autre hôte est refusée, aucun lien du contenu n'est jamais suivi ;
 *  - délais courts, taille de réponse plafonnée, 3 redirections au plus, en-tête générique (aucune donnée personnelle) ;
 *  - si le site est injoignable, la dernière copie reste affichée (rien n'est effacé sur une erreur) ;
 *  - garde-fou : un sitemap vide ou qui ferait disparaître plus de la moitié des articles ne masque rien.
 */
final class SiteNewsSync
{
    public function __construct(private readonly SiteArticleParser $parser) {}

    /** @return array{status: string, seen: int, created: int, updated: int, hidden: int, errors: int, message: ?string} */
    public function run(bool $force = false, bool $write = true): array
    {
        $started = Carbon::now();
        $stats = ['status' => 'ok', 'seen' => 0, 'created' => 0, 'updated' => 0, 'hidden' => 0, 'errors' => 0, 'message' => null];

        try {
            $entries = $this->sitemap();
        } catch (Throwable $e) {
            $stats['status'] = 'failed';
            $stats['message'] = 'Sitemap illisible : '.$this->short($e->getMessage());

            return $write ? $this->log($started, $stats) : $stats;
        }
        $stats['seen'] = count($entries);

        $notes = [];
        foreach ($entries as $slug => $lastmod) {
            try {
                $existing = DB::table('t_site_news')->where('slug', $slug)->first(['id', 'lastmod', 'is_visible', 'content_hash']);
                if (! $force && $existing !== null && (int) $existing->is_visible === 1 && $this->sameInstant($existing->lastmod, $lastmod)) {
                    continue;   // inchangé selon le sitemap : pas de téléchargement
                }

                $this->pause();
                $url = $this->base().'/actualites/'.$slug;
                $article = $this->parser->parse($this->get($url, 'text/html'), $url);
                if ($article === null) {
                    $stats['errors']++;
                    $notes[] = "{$slug} : page non reconnue (gabarit modifié ?)";
                    continue;
                }

                $hash = sha1(json_encode($article, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                $changed = $existing === null || $existing->content_hash !== $hash || (int) $existing->is_visible !== 1;
                if ($existing === null) {
                    $stats['created']++;
                } elseif ($changed) {
                    $stats['updated']++;
                }
                if ($write) {
                    $this->store($slug, $url, $article, $lastmod, $hash, $existing === null);
                }
            } catch (Throwable $e) {
                $stats['errors']++;
                $notes[] = "{$slug} : ".$this->short($e->getMessage());
            }
        }

        // Articles retirés du site : masqués (jamais supprimés), sauf si le sitemap paraît anormal.
        $visible = DB::table('t_site_news')->where('is_visible', 1)->pluck('slug')->all();
        $gone = array_values(array_diff($visible, array_keys($entries)));
        if ($gone !== []) {
            if ($stats['seen'] === 0 || (count($visible) >= 4 && count($gone) > count($visible) / 2)) {
                $stats['status'] = 'partial';
                $notes[] = count($gone).' article(s) absent(s) du sitemap sur '.count($visible).' : jugé anormal, rien n\'est masqué.';
            } else {
                $stats['hidden'] = count($gone);
                if ($write) {
                    DB::table('t_site_news')->whereIn('slug', $gone)->update(['is_visible' => 0, 'updated_at' => Carbon::now()]);
                }
            }
        }

        if ($stats['errors'] > 0 && $stats['status'] === 'ok') {
            $stats['status'] = 'partial';
        }
        $stats['message'] = $notes === [] ? null : $this->short(implode(' | ', $notes), 1500);

        return $write ? $this->log($started, $stats) : $stats;
    }

    // ------------------------------------------------------------------------------------------------ réseau

    /** @return array<string, ?string> slug => lastmod (ISO) des articles du sitemap, langue par défaut seulement */
    private function sitemap(): array
    {
        $xml = $this->get($this->base().'/sitemap.xml', 'xml');
        // Pas de DOCTYPE ni d'entités : un sitemap n'en a pas besoin, et c'est la porte d'entrée des attaques XML.
        if (preg_match('/<!DOCTYPE|<!ENTITY/i', $xml)) {
            throw new RuntimeException('sitemap refusé (DOCTYPE)');
        }
        $prev = libxml_use_internal_errors(true);
        $doc = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOCDATA);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        if ($doc === false) {
            throw new RuntimeException('sitemap illisible');
        }

        $host = preg_quote((string) parse_url($this->base(), PHP_URL_HOST), '#');
        $out = [];
        foreach ($doc->url ?? [] as $u) {
            $loc = trim((string) $u->loc);
            // Seulement les articles de la langue par défaut : https://hôte/actualites/{slug}, rien d'autre.
            if (preg_match('#^https://'.$host.'/actualites/([a-z0-9][a-z0-9-]{0,118})$#', $loc, $m)) {
                $out[$m[1]] = $this->iso((string) $u->lastmod);
            }
        }

        return $out;
    }

    private function get(string $url, string $kind): string
    {
        $max = (int) config('registrar.site_news.max_bytes');
        $siteCheck = fn (UriInterface $uri) => $this->parser->isSiteUrl((string) $uri);

        try {
            $response = $this->client()->withOptions([
                'allow_redirects' => [
                    'max' => 3, 'strict' => true, 'protocols' => ['https'],
                    'on_redirect' => function (RequestInterface $req, ResponseInterface $res, UriInterface $uri) use ($siteCheck) {
                        if (! $siteCheck($uri)) {
                            throw new RuntimeException('redirection vers un autre hôte refusée');
                        }
                    },
                ],
                'on_headers' => function (ResponseInterface $res) use ($max) {
                    if ((int) $res->getHeaderLine('Content-Length') > $max) {
                        throw new RuntimeException('réponse trop volumineuse');
                    }
                },
            ])->get($url);
        } catch (ConnectionException $e) {
            throw new RuntimeException('site injoignable ('.$this->short($e->getMessage(), 120).')');
        }

        /** @var Response $response */
        if (! $response->successful()) {
            throw new RuntimeException('HTTP '.$response->status());
        }
        $type = strtolower($response->header('Content-Type'));
        if ($type !== '' && ! str_contains($type, $kind === 'xml' ? 'xml' : 'html')) {
            throw new RuntimeException('type de contenu inattendu');
        }
        $body = $response->body();
        if (strlen($body) > $max) {
            throw new RuntimeException('réponse trop volumineuse');
        }

        return $body;
    }

    private function client(): \Illuminate\Http\Client\PendingRequest
    {
        return Http::withHeaders([
            // Générique : aucune donnée personnelle ni adresse de messagerie dans un en-tête envoyé à un tiers.
            'User-Agent' => (string) config('registrar.site_news.user_agent'),
            'Accept' => 'text/html,application/xml;q=0.9',
        ])->timeout((int) config('registrar.site_news.timeout'))->connectTimeout(8)->retry(1, 500, throw: false);
    }

    private function pause(): void
    {
        $ms = (int) config('registrar.site_news.delay_ms');
        if ($ms > 0) {
            usleep($ms * 1000);
        }
    }

    // ---------------------------------------------------------------------------------------------- stockage

    /** @param array<string, mixed> $a */
    private function store(string $slug, string $url, array $a, ?string $lastmod, string $hash, bool $isNew): void
    {
        $now = Carbon::now();
        $row = [
            'url' => $url, 'title' => $a['title'], 'excerpt' => $a['excerpt'], 'category' => $a['category'], 'image_url' => $a['image_url'], 'thumb_url' => $a['thumb_url'] ?? null,
            'author' => $a['author'], 'published_on' => $a['published_on'],
            'blocks' => json_encode($a['blocks'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'lastmod' => $this->local($lastmod),
            'content_hash' => $hash, 'is_visible' => 1, 'fetched_at' => $now, 'updated_at' => $now,
        ];
        if ($isNew) {
            DB::table('t_site_news')->insert($row + ['slug' => $slug, 'created_at' => $now]);
        } else {
            DB::table('t_site_news')->where('slug', $slug)->update($row);
        }
    }

    /** @param array<string, mixed> $stats @return array<string, mixed> */
    private function log(Carbon $started, array $stats): array
    {
        DB::table('t_site_news_sync')->insert([
            'started_at' => $started, 'finished_at' => Carbon::now(), 'status' => $stats['status'], 'seen' => $stats['seen'], 'created' => $stats['created'],
            'updated' => $stats['updated'], 'hidden' => $stats['hidden'], 'errors' => $stats['errors'], 'message' => $stats['message'],
        ]);

        return $stats;
    }

    // ------------------------------------------------------------------------------------------------ outils

    private function base(): string
    {
        return rtrim((string) config('registrar.site_news.base'), '/');
    }

    private function iso(string $v): ?string
    {
        try {
            return trim($v) === '' ? null : Carbon::parse($v)->toIso8601String();
        } catch (Throwable) {
            return null;
        }
    }

    private function sameInstant(mixed $stored, ?string $lastmod): bool
    {
        if ($stored === null || $lastmod === null) {
            return false;
        }

        return Carbon::parse((string) $stored)->format('Y-m-d H:i:s') === $this->local($lastmod);
    }

    /** Date de modification du sitemap (avec son décalage horaire) ramenée à l'heure de l'application : même valeur à l'écriture et à la comparaison. */
    private function local(?string $iso): ?string
    {
        return $iso === null ? null : Carbon::parse($iso)->setTimezone((string) config('app.timezone'))->format('Y-m-d H:i:s');
    }

    private function short(string $s, int $max = 300): string
    {
        $s = trim((string) preg_replace('/\s+/', ' ', $s));

        return mb_strlen($s) > $max ? mb_substr($s, 0, $max).'…' : $s;
    }
}
