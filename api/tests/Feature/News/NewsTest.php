<?php

namespace Tests\Feature\News;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\MakesStudentData;
use Tests\TestCase;

/** Actualités : seules les annonces actives, publiées et non expirées ; épinglées d'abord ; texte brut ; images réduites. */
class NewsTest extends TestCase
{
    use MakesStudentData;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-16 10:00:00', 'Indian/Antananarivo'));
        $this->dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'annonces-test-'.bin2hex(random_bytes(4));
        mkdir($this->dir, 0777, true);
        config(['registrar.announcement_images_path' => $this->dir]);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.'/*') ?: [] as $f) {
            is_file($f) ? @unlink($f) : null;
        }
        @rmdir($this->dir);
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @param array<string, mixed> $extra */
    private function announce(string $title, array $extra = []): int
    {
        return DB::table('t_annonces')->insertGetId(array_merge([
            'title' => $title, 'content' => 'Contenu de '.$title, 'category' => 'info', 'is_pinned' => 0, 'is_active' => 1, 'publish_date' => '2026-11-01',
        ], $extra));
    }

    private function me(): User
    {
        return $this->makeStudent('5001');
    }

    private function list(?string $query = null)
    {
        return $this->actingAs($this->me(), 'web')->fromApp()->getJson('/api/v1/me/news'.($query ? "?{$query}" : ''));
    }

    private function titles(?string $query = null): array
    {
        return array_column($this->list($query)->assertOk()->json('data.items'), 'title');
    }

    // ------------------------------------------------------------------------------------- visibilité

    public function test_only_active_published_and_unexpired_announcements_are_listed(): void
    {
        $this->announce('visible', ['publish_date' => '2026-11-01']);
        $this->announce('inactive', ['is_active' => 0]);
        $this->announce('programmee demain', ['publish_date' => '2026-11-17']);
        $this->announce('publiee aujourdhui', ['publish_date' => '2026-11-16']);
        $this->announce('sans date', ['publish_date' => null]);
        $this->announce('expiree hier', ['expire_date' => '2026-11-15']);
        $this->announce('expire aujourdhui', ['expire_date' => '2026-11-16']);
        $this->announce('expire demain', ['expire_date' => '2026-11-17']);

        $titles = $this->titles();

        sort($titles);
        $this->assertSame(['expire aujourdhui', 'expire demain', 'publiee aujourdhui', 'sans date', 'visible'], $titles);
    }

    public function test_today_is_the_date_of_madagascar_not_utc(): void
    {
        // 16/11 à 01 h 00 à Madagascar = 15/11 à 22 h 00 UTC : l'annonce du 16 est déjà visible.
        Carbon::setTestNow(Carbon::parse('2026-11-16 01:00:00', 'Indian/Antananarivo'));
        $this->announce('du seize', ['publish_date' => '2026-11-16']);
        $this->announce('expiree', ['expire_date' => '2026-11-15']);

        $this->assertSame(['du seize'], $this->titles());
    }

    public function test_the_list_is_empty_but_valid_without_announcements(): void
    {
        $r = $this->list()->assertOk();

        $this->assertSame([], $r->json('data.items'));
        $this->assertSame(0, $r->json('data.total'));
        $this->assertSame(['info' => 0, 'event' => 0, 'academic' => 0, 'urgent' => 0, 'sport' => 0, 'culture' => 0], $r->json('data.counts'));
    }

    // ----------------------------------------------------------------------------------------- ordre

    public function test_pinned_first_then_most_recent_then_highest_id(): void
    {
        $this->announce('ancienne', ['publish_date' => '2026-09-01']);
        $this->announce('recente', ['publish_date' => '2026-11-10']);
        $this->announce('epinglee ancienne', ['is_pinned' => 1, 'publish_date' => '2026-01-01']);
        $this->announce('epinglee recente', ['is_pinned' => 1, 'publish_date' => '2026-10-01']);
        $this->announce('meme jour A', ['publish_date' => '2026-11-10']);
        $this->announce('sans date', ['publish_date' => null]);

        $this->assertSame(['epinglee recente', 'epinglee ancienne', 'meme jour A', 'recente', 'ancienne', 'sans date'], $this->titles());
    }

    // --------------------------------------------------------------------------------- catégories

    public function test_the_category_filter_keeps_counts_of_every_category(): void
    {
        $this->announce('a', ['category' => 'info']);
        $this->announce('b', ['category' => 'event']);
        $this->announce('c', ['category' => 'event']);
        $this->announce('d', ['category' => 'urgent', 'is_active' => 0]);   // masquée : ne compte pas

        $r = $this->list('category=event')->assertOk();

        $this->assertEqualsCanonicalizing(['b', 'c'], array_column($r->json('data.items'), 'title'));
        $this->assertSame(['info' => 1, 'event' => 2, 'academic' => 0, 'urgent' => 0, 'sport' => 0, 'culture' => 0], $r->json('data.counts'));
        $this->assertSame(3, $r->json('data.total'));
        $this->assertSame('Événement', $r->json('data.items.0.category_label'));
    }

    public function test_an_unknown_category_parameter_is_rejected(): void
    {
        $this->list('category=piratage')->assertStatus(422);
        $this->list('category[]=info')->assertStatus(422);
    }

    public function test_an_announcement_with_an_unreadable_stored_category_falls_back_to_info(): void
    {
        // Une colonne ENUM stocke « » (vide) pour une valeur invalide quand le mode strict est coupé, comme sur l'ancienne base.
        // JAMAIS d'ALTER TABLE dans un test : le DDL fait un commit implicite de MySQL et casse l'isolation.
        $mode = DB::selectOne('SELECT @@sql_mode AS m')->m;
        DB::statement("SET SESSION sql_mode = ''");
        try {
            $this->announce('bizarre', ['category' => 'divers']);
        } finally {
            DB::statement('SET SESSION sql_mode = ?', [$mode]);
        }
        $this->assertSame('', DB::table('t_annonces')->where('title', 'bizarre')->value('category'));

        $item = $this->list()->json('data.items.0');

        $this->assertSame(['info', 'Information'], [$item['category'], $item['category_label']]);
    }

    // ------------------------------------------------------------------------------------- extraits

    public function test_excerpt_is_the_given_one_or_a_cut_of_the_content(): void
    {
        $this->announce('avec extrait', ['excerpt' => '  Extrait fourni  ', 'content' => str_repeat('x', 500)]);
        $this->announce('court', ['content' => "Court\ntexte"]);
        $this->announce('long', ['content' => str_repeat('é', 200)]);

        $byTitle = collect($this->list()->json('data.items'))->keyBy('title');

        $this->assertSame('Extrait fourni', $byTitle['avec extrait']['excerpt']);
        $this->assertSame('Court texte', $byTitle['court']['excerpt']);                       // pas de « … » : rien n'a été coupé
        $this->assertSame(str_repeat('é', 140).'…', $byTitle['long']['excerpt']);            // coupé en caractères, pas en octets
    }

    public function test_the_list_never_carries_the_full_content(): void
    {
        $this->announce('x', ['content' => str_repeat('y', 300).' TEXTE-INTEGRAL-CONFIDENTIEL']);

        $this->assertStringNotContainsString('TEXTE-INTEGRAL', $this->list()->getContent());
    }

    // --------------------------------------------------------------------------------------- détail

    public function test_the_detail_returns_the_plain_text_with_real_line_breaks(): void
    {
        $id = $this->announce('d', ['content' => 'Ligne 1\\nLigne 2'."\r\n".'Ligne 3', 'author' => ' Registrariat ', 'expire_date' => '2026-12-01']);

        $d = $this->actingAs($this->me(), 'web')->fromApp()->getJson("/api/v1/me/news/{$id}")->assertOk()->json('data');

        $this->assertSame("Ligne 1\nLigne 2\nLigne 3", $d['content']);
        $this->assertSame(['Registrariat', '2026-12-01', '2026-11-01'], [$d['author'], $d['expires_on'], $d['published_on']]);
    }

    public function test_html_in_an_announcement_is_returned_as_inert_text(): void
    {
        $id = $this->announce('<b>gras</b>', ['content' => '<script>alert(1)</script>']);

        $r = $this->actingAs($this->me(), 'web')->fromApp()->getJson("/api/v1/me/news/{$id}")->assertOk();

        // L'API ne « nettoie » pas : elle renvoie le texte tel quel, et l'écran l'affiche comme du texte (jamais comme du HTML).
        $this->assertSame('<script>alert(1)</script>', $r->json('data.content'));
        $this->assertSame('application/json', $r->headers->get('Content-Type'));
    }

    public function test_a_hidden_or_unknown_announcement_is_simply_not_found(): void
    {
        $inactive = $this->announce('i', ['is_active' => 0]);
        $future = $this->announce('f', ['publish_date' => '2026-11-20']);
        $expired = $this->announce('e', ['expire_date' => '2026-11-01']);
        $u = $this->me();

        foreach ([$inactive, $future, $expired, 99999999] as $id) {
            $this->actingAs($u, 'web')->fromApp()->getJson("/api/v1/me/news/{$id}")->assertStatus(404)->assertJsonPath('code', 'news.not_found');
        }
        $this->actingAs($u, 'web')->fromApp()->getJson('/api/v1/me/news/abc')->assertStatus(404);
    }

    // ---------------------------------------------------------------------------------------- images

    private function jpeg(string $name, int $w = 2400, int $h = 1600): string
    {
        $img = imagecreatetruecolor($w, $h);
        imagefill($img, 0, 0, imagecolorallocate($img, 30, 120, 160));
        imagejpeg($img, $this->dir.DIRECTORY_SEPARATOR.$name, 80);

        return $name;
    }

    public function test_the_image_is_served_reduced_and_cacheable_with_an_etag(): void
    {
        $id = $this->announce('avec image', ['image' => $this->jpeg('a.jpg', 2400, 1600)]);
        $u = $this->me();

        $res = $this->actingAs($u, 'web')->fromApp()->get("/api/v1/me/news/{$id}/image");

        $res->assertOk();
        $this->assertSame('image/jpeg', $res->headers->get('Content-Type'));
        $this->assertStringContainsString('private', (string) $res->headers->get('Cache-Control'));
        [$w, $h, $type] = getimagesizefromstring($res->getContent());
        $this->assertSame(IMAGETYPE_JPEG, $type);
        $this->assertLessThanOrEqual(1200, $w);
        $this->assertLessThanOrEqual(800, $h);
        $this->assertEqualsWithDelta(1.5, $w / $h, 0.01);

        $etag = $res->headers->get('ETag');
        $this->assertNotEmpty($etag);
        $this->actingAs($u, 'web')->fromApp()->get("/api/v1/me/news/{$id}/image", ['If-None-Match' => $etag])->assertStatus(304);
    }

    public function test_the_summary_says_whether_there_is_an_image_without_exposing_its_name(): void
    {
        $this->announce('avec', ['image' => $this->jpeg('secret-nom.jpg', 100, 100)]);
        $this->announce('sans');

        $r = $this->list();

        $byTitle = collect($r->json('data.items'))->pluck('has_image', 'title');
        $this->assertSame([true, false], [$byTitle['avec'], $byTitle['sans']]);
        $this->assertStringNotContainsString('secret-nom', $r->getContent());
        $this->assertStringNotContainsString($this->dir, $r->getContent());
    }

    public function test_images_of_hidden_announcements_and_hostile_names_are_never_served(): void
    {
        $hidden = $this->announce('masquee', ['is_active' => 0, 'image' => $this->jpeg('h.jpg')]);
        $future = $this->announce('programmee', ['publish_date' => '2026-12-31', 'image' => $this->jpeg('f.jpg')]);
        file_put_contents(dirname($this->dir).DIRECTORY_SEPARATOR.'dehors.jpg', 'x');
        imagejpeg(imagecreatetruecolor(40, 40), dirname($this->dir).DIRECTORY_SEPARATOR.'dehors.jpg');
        $evasion = $this->announce('evasion', ['image' => '../dehors.jpg']);
        $absent = $this->announce('fichier absent', ['image' => 'absent.jpg']);
        $none = $this->announce('sans image');
        $u = $this->me();

        foreach ([$hidden, $future, $evasion, $absent, $none, 99999999] as $id) {
            $this->actingAs($u, 'web')->fromApp()->getJson("/api/v1/me/news/{$id}/image")->assertStatus(404);
        }
        @unlink(dirname($this->dir).DIRECTORY_SEPARATOR.'dehors.jpg');
    }

    // ---------------------------------------------------------------------------------------- accès

    public function test_staff_and_anonymous_are_kept_out(): void
    {
        $id = $this->announce('x');
        foreach (["/api/v1/me/news", "/api/v1/me/news/{$id}", "/api/v1/me/news/{$id}/image"] as $url) {
            $this->getJson($url)->assertStatus(401);
        }

        $staff = User::factory()->staff()->create();
        $this->actingAs($staff, 'web')->fromApp()->getJson('/api/v1/me/news')->assertStatus(403)->assertJsonPath('code', 'forbidden.student_only');
    }

    public function test_the_news_endpoints_are_read_only(): void
    {
        $id = $this->announce('x');
        $u = $this->me();

        foreach (['post', 'put', 'patch', 'delete'] as $method) {
            $this->actingAs($u, 'web')->fromApp()->json($method, "/api/v1/me/news/{$id}", ['title' => 'Pirate'])->assertStatus(405);
        }
        $this->assertSame('x', DB::table('t_annonces')->where('id', $id)->value('title'));
    }
}
