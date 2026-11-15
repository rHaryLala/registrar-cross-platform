<?php

namespace Tests\Feature\News;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\MakesStudentData;
use Tests\TestCase;

/** Ce que l'étudiant lit : la copie locale des actualités du site, sans appel réseau, sans HTML. */
class SiteNewsEndpointTest extends TestCase
{
    use MakesStudentData;

    /** @param array<string, mixed> $extra */
    private function putArticle(string $slug, array $extra = []): int
    {
        return DB::table('t_site_news')->insertGetId(array_merge([
            'slug' => $slug, 'url' => 'https://zurcher.edu.mg/actualites/'.$slug, 'title' => 'Titre '.$slug, 'excerpt' => 'Résumé '.$slug,
            'category' => 'Actualités', 'image_url' => 'https://zurcher.edu.mg/storage/1/cover.webp', 'author' => 'Auteur', 'published_on' => '2026-10-20',
            'blocks' => json_encode([['type' => 'paragraph', 'inlines' => [['text' => 'Corps de '.$slug]]]]), 'lastmod' => '2026-10-20 08:00:00',
            'content_hash' => sha1($slug), 'is_visible' => 1, 'fetched_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ], $extra));
    }

    private function fetch(string $url)
    {
        return $this->actingAs($this->makeStudent('6001'), 'web')->fromApp()->getJson($url);
    }

    public function test_the_list_is_most_recent_first_with_summaries_only(): void
    {
        $this->putArticle('ancien', ['published_on' => '2026-09-01']);
        $this->putArticle('recent', ['published_on' => '2026-10-20']);
        $this->putArticle('milieu', ['published_on' => '2026-10-05']);

        $r = $this->fetch('/api/v1/me/site-news')->assertOk();

        $this->assertSame(['recent', 'milieu', 'ancien'], array_column($r->json('data.items'), 'slug'));
        $this->assertSame(3, $r->json('data.total'));
        $this->assertSame('https://zurcher.edu.mg/actualites', $r->json('data.source_url'));
        $this->assertArrayNotHasKey('blocks', $r->json('data.items.0'));   // le corps n'est pas dans la liste
        $this->assertStringNotContainsString('Corps de', $r->getContent());
    }

    public function test_the_list_exposes_the_thumbnail_and_the_detail_the_medium_image(): void
    {
        $this->putArticle('img', ['image_url' => 'https://zurcher.edu.mg/s/moyenne.webp', 'thumb_url' => 'https://zurcher.edu.mg/s/mini.webp']);

        $this->assertSame(['https://zurcher.edu.mg/s/mini.webp', 'https://zurcher.edu.mg/s/moyenne.webp'], [
            $this->fetch('/api/v1/me/site-news')->json('data.items.0.thumb_url'),
            $this->fetch('/api/v1/me/site-news/img')->json('data.image_url'),
        ]);
    }

    public function test_same_day_articles_are_ordered_by_modification_then_id(): void
    {
        $this->putArticle('a', ['published_on' => '2026-10-20', 'lastmod' => '2026-10-20 08:00:00']);
        $this->putArticle('b', ['published_on' => '2026-10-20', 'lastmod' => '2026-10-20 18:00:00']);

        $this->assertSame(['b', 'a'], array_column($this->fetch('/api/v1/me/site-news')->json('data.items'), 'slug'));
    }

    public function test_articles_removed_from_the_site_are_not_served_by_list_or_by_slug(): void
    {
        $this->putArticle('visible');
        $this->putArticle('retire', ['is_visible' => 0]);

        $this->assertSame(['visible'], array_column($this->fetch('/api/v1/me/site-news')->json('data.items'), 'slug'));
        $this->fetch('/api/v1/me/site-news/retire')->assertStatus(404)->assertJsonPath('code', 'news.not_found');
        $this->fetch('/api/v1/me/site-news/visible')->assertOk();
    }

    public function test_the_detail_carries_the_blocks_and_the_public_address(): void
    {
        $this->putArticle('detail', ['blocks' => json_encode([['type' => 'heading', 'level' => 2, 'inlines' => [['text' => 'Sous-titre']]], ['type' => 'paragraph', 'inlines' => [['text' => 'Un '], ['text' => 'lien', 'href' => 'https://exemple.mg']]]])]);

        $d = $this->fetch('/api/v1/me/site-news/detail')->assertOk()->json('data');

        $this->assertSame(['heading', 'paragraph'], array_column($d['blocks'], 'type'));
        $this->assertSame('https://zurcher.edu.mg/actualites/detail', $d['url']);
        $this->assertSame(['Titre detail', 'Auteur', '2026-10-20'], [$d['title'], $d['author'], $d['published_on']]);
    }

    public function test_a_corrupt_stored_body_gives_an_empty_body_not_an_error(): void
    {
        $this->putArticle('casse', ['blocks' => '{pas du json']);

        $this->fetch('/api/v1/me/site-news/casse')->assertOk()->assertJsonPath('data.blocks', []);
    }

    public function test_unknown_or_malformed_slugs_are_not_found(): void
    {
        $this->putArticle('ok');

        $this->fetch('/api/v1/me/site-news/inconnu')->assertStatus(404);
        foreach (['Majuscules', 'a.b', 'a_b', '-debut', '..%2Fadmin', str_repeat('a', 130)] as $slug) {
            $this->fetch('/api/v1/me/site-news/'.$slug)->assertStatus(404);
        }
    }

    public function test_the_synchronisation_date_is_the_last_successful_run_only(): void
    {
        $this->putArticle('x');
        $run = fn (string $status, string $at) => DB::table('t_site_news_sync')->insert(['started_at' => $at, 'finished_at' => $at, 'status' => $status]);
        $run('ok', '2026-11-15 08:00:00');
        $run('failed', '2026-11-15 12:00:00');   // un échec plus récent ne rend pas la copie « plus à jour »

        $synced = $this->fetch('/api/v1/me/site-news')->json('data.synced_at');

        $this->assertStringStartsWith('2026-11-15T08:00:00', $synced);
        $this->assertStringContainsString('+03:00', $synced);   // heure de Madagascar
    }

    public function test_the_synchronisation_date_is_null_before_any_run(): void
    {
        $this->assertNull($this->fetch('/api/v1/me/site-news')->json('data.synced_at'));
    }

    public function test_an_empty_copy_is_a_valid_empty_list(): void
    {
        $r = $this->fetch('/api/v1/me/site-news')->assertOk();

        $this->assertSame([[], 0], [$r->json('data.items'), $r->json('data.total')]);
    }

    public function test_the_endpoints_never_call_the_site(): void
    {
        \Illuminate\Support\Facades\Http::preventStrayRequests();
        $this->putArticle('x');

        $this->fetch('/api/v1/me/site-news')->assertOk();
        $this->fetch('/api/v1/me/site-news/x')->assertOk();
        $this->assertTrue(true);
    }

    public function test_staff_and_anonymous_are_kept_out_and_the_endpoints_are_read_only(): void
    {
        $this->putArticle('x');
        $this->getJson('/api/v1/me/site-news')->assertStatus(401);
        $this->getJson('/api/v1/me/site-news/x')->assertStatus(401);

        $staff = User::factory()->staff()->create();
        $this->actingAs($staff, 'web')->fromApp()->getJson('/api/v1/me/site-news')->assertStatus(403)->assertJsonPath('code', 'forbidden.student_only');

        $u = $this->makeStudent('6002', 'Autre', 'Etudiant');
        foreach (['post', 'put', 'patch', 'delete'] as $method) {
            $this->actingAs($u, 'web')->fromApp()->json($method, '/api/v1/me/site-news/x', ['title' => 'Pirate'])->assertStatus(405);
        }
        $this->assertSame('Titre x', DB::table('t_site_news')->where('slug', 'x')->value('title'));
    }
}
