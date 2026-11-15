<?php

namespace Tests\Feature\News;

use App\Domain\News\SiteNewsSync;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\MakesSiteNews;
use Tests\TestCase;

/** La synchronisation avec le site public : incrémentale, verrouillée sur l'hôte, et incapable d'effacer la copie sur une erreur. */
class SiteNewsSyncTest extends TestCase
{
    use MakesSiteNews;

    private const SITE = 'https://zurcher.edu.mg';

    /** Remplace le client HTTP par un faux tout neuf : une nouvelle « version du site », et un compteur de requêtes remis à zéro. */
    private function fake(callable $responder): void
    {
        $factory = new \Illuminate\Http\Client\Factory;
        Http::swap($factory);
        $factory->fake($responder);
    }

    /**
     * @param  array<string, ?string>  $entries  slug => lastmod
     * @param  array<string, string>  $pages  slug => html (par défaut, une page fictive)
     */
    private function fakeSite(array $entries, array $pages = [], ?string $sitemap = null): void
    {
        $this->fake(function (Request $r) use ($entries, $pages, $sitemap) {
            $url = $r->url();
            if ($url === self::SITE.'/sitemap.xml') {
                return Http::response($sitemap ?? $this->sitemapXml($entries), 200, ['Content-Type' => 'application/xml']);
            }
            if (preg_match('#^'.preg_quote(self::SITE, '#').'/actualites/([^/]+)$#', $url, $m)) {
                return array_key_exists($m[1], $entries) || isset($pages[$m[1]])
                    ? Http::response($pages[$m[1]] ?? $this->articleHtml('Titre '.$m[1]), 200, ['Content-Type' => 'text/html; charset=utf-8'])
                    : Http::response('introuvable', 404);
            }

            return Http::response('hors périmètre', 500);
        });
    }

    private function sync(bool $force = false, bool $write = true): array
    {
        return app(SiteNewsSync::class)->run($force, $write);
    }

    /** @return list<string> adresses réellement demandées */
    private function requested(): array
    {
        return Http::recorded()->map(fn ($pair) => $pair[0]->url())->values()->all();
    }

    // ------------------------------------------------------------------------------------ cas nominal

    public function test_the_first_sync_creates_every_article_and_logs_the_run(): void
    {
        $this->fakeSite(['a' => '2026-10-20T08:00:00+03:00', 'b' => '2026-10-02T10:00:00+03:00']);

        $r = $this->sync();

        $this->assertSame(['ok', 2, 2, 0, 0, 0], [$r['status'], $r['seen'], $r['created'], $r['updated'], $r['hidden'], $r['errors']]);
        $this->assertSame(['a', 'b'], DB::table('t_site_news')->orderBy('slug')->pluck('slug')->all());
        $row = DB::table('t_site_news')->where('slug', 'a')->first();
        $this->assertSame(['Titre a', 'Actualités', 'Auteur Fictif', '2026-10-20', 1], [$row->title, $row->category, $row->author, substr($row->published_on, 0, 10), (int) $row->is_visible]);
        $this->assertSame(self::SITE.'/actualites/a', $row->url);
        $this->assertSame('ok', DB::table('t_site_news_sync')->value('status'));
    }

    public function test_the_cover_variants_are_stored(): void
    {
        $srcset = '/s/c___1400.webp 1400w, /s/c___1171.webp 1171w, /s/c___685.webp 685w, /s/c___400.webp 400w';
        $this->fakeSite(['a' => '2026-10-20T08:00:00+03:00'], ['a' => $this->articleHtml('Avec couverture', '<p>x</p>', ['srcset' => $srcset])]);

        $this->sync();

        $row = DB::table('t_site_news')->where('slug', 'a')->first();
        $this->assertSame(['https://zurcher.edu.mg/s/c___1171.webp', 'https://zurcher.edu.mg/s/c___685.webp'], [$row->image_url, $row->thumb_url]);
    }

    public function test_a_second_sync_downloads_nothing_when_the_sitemap_has_not_changed(): void
    {
        $this->fakeSite(['a' => '2026-10-20T08:00:00+03:00', 'b' => '2026-10-02T10:00:00+03:00']);
        $this->sync();
        $this->fakeSite(['a' => '2026-10-20T08:00:00+03:00', 'b' => '2026-10-02T10:00:00+03:00']);   // même site, compteur remis à zéro

        $r = $this->sync();

        $this->assertSame([0, 0, 0], [$r['created'], $r['updated'], $r['errors']]);
        $this->assertSame([self::SITE.'/sitemap.xml'], $this->requested());   // le sitemap seul : aucun article retéléchargé
    }

    public function test_the_lastmod_offset_is_normalised_so_unchanged_articles_are_not_refetched(): void
    {
        // Même instant écrit avec deux décalages différents : ce n'est PAS un changement.
        $this->fakeSite(['a' => '2026-10-20T08:00:00+03:00']);
        $this->sync();
        $this->fakeSite(['a' => '2026-10-20T05:00:00+00:00']);

        $this->sync();

        $this->assertNotContains(self::SITE.'/actualites/a', $this->requested());
    }

    public function test_a_changed_lastmod_refetches_and_updates_only_when_the_content_really_changed(): void
    {
        $this->fakeSite(['a' => '2026-10-20T08:00:00+03:00'], ['a' => $this->articleHtml('Version 1')]);
        $this->sync();

        // Nouvelle date de modification, MÊME contenu : retéléchargé, mais pas compté comme modifié.
        $this->fakeSite(['a' => '2026-10-21T08:00:00+03:00'], ['a' => $this->articleHtml('Version 1')]);
        $same = $this->sync();
        $this->assertSame(0, $same['updated']);

        // Nouvelle date, contenu modifié : mis à jour.
        $this->fakeSite(['a' => '2026-10-22T08:00:00+03:00'], ['a' => $this->articleHtml('Version 2')]);
        $changed = $this->sync();
        $this->assertSame(1, $changed['updated']);
        $this->assertSame('Version 2', DB::table('t_site_news')->where('slug', 'a')->value('title'));
        $this->assertSame(1, DB::table('t_site_news')->count());   // jamais de doublon
    }

    public function test_force_redownloads_everything(): void
    {
        $this->fakeSite(['a' => '2026-10-20T08:00:00+03:00', 'b' => '2026-10-02T10:00:00+03:00']);
        $this->sync();
        $this->fakeSite(['a' => '2026-10-20T08:00:00+03:00', 'b' => '2026-10-02T10:00:00+03:00']);

        $this->sync(force: true);

        $this->assertContains(self::SITE.'/actualites/a', $this->requested());
        $this->assertContains(self::SITE.'/actualites/b', $this->requested());
    }

    // ---------------------------------------------------------------------------- articles retirés

    public function test_an_article_removed_from_the_site_is_hidden_never_deleted_and_returns_when_it_comes_back(): void
    {
        $e = ['a' => '2026-10-20T08:00:00+03:00', 'b' => '2026-10-02T10:00:00+03:00', 'c' => '2026-09-01T10:00:00+03:00'];
        $this->fakeSite($e);
        $this->sync();

        $this->fakeSite(['a' => $e['a'], 'b' => $e['b']]);
        $r = $this->sync();
        $this->assertSame(1, $r['hidden']);
        $this->assertSame(0, (int) DB::table('t_site_news')->where('slug', 'c')->value('is_visible'));
        $this->assertSame(3, DB::table('t_site_news')->count());   // conservé

        $this->fakeSite($e);
        $this->sync();
        $this->assertSame(1, (int) DB::table('t_site_news')->where('slug', 'c')->value('is_visible'));
    }

    public function test_an_empty_sitemap_hides_nothing(): void
    {
        $this->fakeSite(['a' => '2026-10-20T08:00:00+03:00', 'b' => '2026-10-02T10:00:00+03:00']);
        $this->sync();

        $this->fakeSite([]);
        $r = $this->sync();

        $this->assertSame(['partial', 0], [$r['status'], $r['hidden']]);
        $this->assertSame(2, DB::table('t_site_news')->where('is_visible', 1)->count());
    }

    public function test_a_sitemap_that_would_hide_more_than_half_is_judged_abnormal(): void
    {
        $e = [];
        foreach (range(1, 6) as $i) {
            $e["art-{$i}"] = '2026-10-0'.$i.'T08:00:00+03:00';
        }
        $this->fakeSite($e);
        $this->sync();

        $this->fakeSite(['art-1' => $e['art-1']]);   // 5 sur 6 disparaissent d'un coup : suspect
        $r = $this->sync();

        $this->assertSame(['partial', 0], [$r['status'], $r['hidden']]);
        $this->assertSame(6, DB::table('t_site_news')->where('is_visible', 1)->count());
        $this->assertStringContainsString('anormal', (string) $r['message']);
    }

    // ------------------------------------------------------------------------------------- erreurs

    public function test_an_unreachable_sitemap_fails_cleanly_and_leaves_the_copy_untouched(): void
    {
        $this->fakeSite(['a' => '2026-10-20T08:00:00+03:00']);
        $this->sync();

        $this->fake(fn () => Http::response('Erreur', 500));
        $r = $this->sync();

        $this->assertSame('failed', $r['status']);
        $this->assertSame(1, DB::table('t_site_news')->where('is_visible', 1)->count());
        $this->assertSame('failed', DB::table('t_site_news_sync')->orderByDesc('id')->value('status'));
    }

    public function test_a_connection_error_is_a_clean_failure_not_an_exception(): void
    {
        $this->fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('cURL error 6: Could not resolve host'));

        $r = $this->sync();

        $this->assertSame('failed', $r['status']);
        $this->assertStringContainsString('injoignable', (string) $r['message']);
    }

    public function test_one_failing_article_does_not_stop_the_others(): void
    {
        $this->fakeSite(
            ['bon' => '2026-10-20T08:00:00+03:00', 'casse' => '2026-10-19T08:00:00+03:00', 'bon2' => '2026-10-18T08:00:00+03:00'],
            ['casse' => '<html><body>gabarit inconnu</body></html>'],
        );

        $r = $this->sync();

        $this->assertSame(['partial', 2, 1], [$r['status'], $r['created'], $r['errors']]);
        $this->assertSame(['bon', 'bon2'], DB::table('t_site_news')->orderBy('slug')->pluck('slug')->all());
        $this->assertStringContainsString('casse', (string) $r['message']);
    }

    public function test_an_article_whose_template_changed_keeps_its_previous_copy(): void
    {
        $this->fakeSite(['a' => '2026-10-20T08:00:00+03:00'], ['a' => $this->articleHtml('Bonne version')]);
        $this->sync();

        $this->fakeSite(['a' => '2026-10-21T08:00:00+03:00'], ['a' => '<html><body><h1>Refonte du site</h1></body></html>']);
        $r = $this->sync();

        $this->assertSame(1, $r['errors']);
        $this->assertSame('Bonne version', DB::table('t_site_news')->where('slug', 'a')->value('title'));
        $this->assertSame(1, (int) DB::table('t_site_news')->where('slug', 'a')->value('is_visible'));
    }

    // ----------------------------------------------------------------------- périmètre et sécurité

    public function test_only_default_language_articles_of_the_site_host_with_clean_slugs_are_taken(): void
    {
        $xml = '<?xml version="1.0"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'
            .'<url><loc>https://zurcher.edu.mg/actualites/valide-1</loc></url>'
            .'<url><loc>https://zurcher.edu.mg/en/actualites/traduit</loc></url>'
            .'<url><loc>https://zurcher.edu.mg/mg/actualites/traduit</loc></url>'
            .'<url><loc>https://evil.example/actualites/etranger</loc></url>'
            .'<url><loc>https://zurcher.edu.mg.evil.example/actualites/sosie</loc></url>'
            .'<url><loc>http://zurcher.edu.mg/actualites/pas-https</loc></url>'
            .'<url><loc>https://zurcher.edu.mg/actualites/Majuscules</loc></url>'
            .'<url><loc>https://zurcher.edu.mg/actualites/a.b</loc></url>'
            .'<url><loc>https://zurcher.edu.mg/actualites/../admin</loc></url>'
            .'<url><loc>https://zurcher.edu.mg/actualites/sous/dossier</loc></url>'
            .'<url><loc>https://zurcher.edu.mg/actualites?page=2</loc></url>'
            .'<url><loc>https://zurcher.edu.mg/admin/secret</loc></url></urlset>';
        $this->fakeSite(['valide-1' => null], [], $xml);

        $r = $this->sync();

        $this->assertSame(1, $r['seen']);
        $this->assertSame(['valide-1'], DB::table('t_site_news')->pluck('slug')->all());
        foreach ($this->requested() as $url) {
            $this->assertStringStartsWith(self::SITE.'/', $url, 'requête hors du site : '.$url);
            $this->assertStringNotContainsString('evil', $url);
            $this->assertStringNotContainsString('/admin', $url);
        }
    }

    public function test_a_sitemap_with_a_doctype_or_entities_is_refused(): void
    {
        $xml = '<?xml version="1.0"?><!DOCTYPE urlset [<!ENTITY x SYSTEM "file:///etc/passwd">]><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"><url><loc>https://zurcher.edu.mg/actualites/&x;</loc></url></urlset>';
        $this->fakeSite([], [], $xml);

        $r = $this->sync();

        $this->assertSame('failed', $r['status']);
        $this->assertSame(0, DB::table('t_site_news')->count());
    }

    public function test_a_redirect_to_another_host_is_refused(): void
    {
        $this->fake(function (Request $r) {
            if ($r->url() === self::SITE.'/sitemap.xml') {
                return Http::response($this->sitemapXml(['a' => '2026-10-20T08:00:00+03:00']), 200, ['Content-Type' => 'application/xml']);
            }

            return Http::response('', 302, ['Location' => 'https://evil.example/piege']);
        });

        $r = $this->sync();

        $this->assertSame(1, $r['errors']);
        $this->assertSame(0, DB::table('t_site_news')->count());
        $this->assertNotContains('https://evil.example/piege', $this->requested());
    }

    public function test_non_html_and_oversized_answers_are_refused(): void
    {
        $this->fake(function (Request $r) {
            if ($r->url() === self::SITE.'/sitemap.xml') {
                return Http::response($this->sitemapXml(['pdf' => '2026-10-20T08:00:00+03:00', 'gros' => '2026-10-19T08:00:00+03:00']), 200, ['Content-Type' => 'application/xml']);
            }

            return str_ends_with($r->url(), '/pdf')
                ? Http::response($this->articleHtml('Valide mais mal étiqueté'), 200, ['Content-Type' => 'application/pdf'])   // un article PARFAIT servi avec un autre type
                : Http::response($this->articleHtml().str_repeat(' ', 3 * 1024 * 1024 + 10), 200, ['Content-Type' => 'text/html']);
        });

        $r = $this->sync();

        $this->assertSame(2, $r['errors']);
        $this->assertSame(0, DB::table('t_site_news')->count());
    }

    public function test_requests_carry_a_generic_user_agent_and_no_personal_data(): void
    {
        $this->fakeSite(['a' => '2026-10-20T08:00:00+03:00']);

        $this->sync();

        Http::assertSent(function (Request $r) {
            $ua = $r->header('User-Agent')[0] ?? '';

            return str_contains($ua, 'RegistrarSync') && ! str_contains($ua, '@') && $r->header('Cookie') === [] && $r->header('Authorization') === [];
        });
    }

    public function test_a_dry_run_writes_nothing(): void
    {
        $this->fakeSite(['a' => '2026-10-20T08:00:00+03:00']);

        $r = $this->sync(write: false);

        $this->assertSame(1, $r['created']);
        $this->assertSame(0, DB::table('t_site_news')->count());
        $this->assertSame(0, DB::table('t_site_news_sync')->count());
    }

    public function test_the_artisan_command_reports_and_signals_failure_with_its_exit_code(): void
    {
        $this->fakeSite(['a' => '2026-10-20T08:00:00+03:00']);
        $this->artisan('news:sync')->expectsOutputToContain('listés : 1')->assertSuccessful();

        $this->fake(fn () => Http::response('', 503));
        $this->artisan('news:sync')->assertFailed();

        $this->fakeSite(['a' => '2026-10-20T08:00:00+03:00']);
        $this->artisan('news:sync', ['--dry-run' => true])->expectsOutputToContain('essai à blanc')->assertSuccessful();
    }

    public function test_the_sync_is_scheduled_without_overlap(): void
    {
        $events = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())->filter(fn ($e) => str_contains($e->command, 'news:sync'));

        $this->assertCount(1, $events);
        $this->assertTrue($events->first()->withoutOverlapping);
        $this->assertSame('*/30 * * * *', $events->first()->expression);
    }
}
