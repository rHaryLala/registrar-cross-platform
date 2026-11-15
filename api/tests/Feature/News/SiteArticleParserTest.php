<?php

namespace Tests\Feature\News;

use App\Domain\News\SiteArticleParser;
use Tests\Concerns\MakesSiteNews;
use Tests\TestCase;

/** L'analyse d'une page d'article du site public : métadonnées, blocs, et tout ce qu'elle doit REFUSER. */
class SiteArticleParserTest extends TestCase
{
    use MakesSiteNews;

    private const PAGE = 'https://zurcher.edu.mg/actualites/un-article';

    private function parse(string $html): ?array
    {
        return (new SiteArticleParser('https://zurcher.edu.mg'))->parse($html, self::PAGE);
    }

    private function body(string $bodyHtml): array
    {
        return $this->parse($this->articleHtml('T', $bodyHtml))['blocks'];
    }

    /** Texte brut d'une suite de morceaux (saut de ligne = « | »). */
    private function flat(array $inlines): string
    {
        return implode('', array_map(fn ($p) => isset($p['br']) ? '|' : $p['text'], $inlines));
    }

    // ------------------------------------------------------------------------------------- métadonnées

    public function test_metadata_are_extracted_from_the_site_template(): void
    {
        $a = $this->parse($this->articleHtml('Culte d’ouverture : été', '<p>Corps.</p>', ['lead' => 'Un résumé.', 'tag' => 'Actualités', 'date' => '2026-10-20', 'author' => 'Mireille S.', 'cover' => 'https://zurcher.edu.mg/storage/68/hero.webp']));

        $this->assertSame('Culte d’ouverture : été', $a['title']);
        $this->assertSame(['Un résumé.', 'Actualités', 'Mireille S.', '2026-10-20', 'https://zurcher.edu.mg/storage/68/hero.webp'], [$a['excerpt'], $a['category'], $a['author'], $a['published_on'], $a['image_url']]);
    }

    public function test_missing_optional_metadata_are_null_not_invented(): void
    {
        $a = $this->parse($this->articleHtml('T', '<p>x</p>', ['author' => null, 'date' => null, 'lead' => '', 'cover' => '']));

        $this->assertNull($a['author']);
        $this->assertNull($a['published_on']);
        $this->assertNull($a['image_url']);
    }

    public function test_an_impossible_date_is_null(): void
    {
        $this->assertNull($this->parse($this->articleHtml('T', '<p>x</p>', ['date' => '2026-02-30']))['published_on']);
        $this->assertNull($this->parse($this->articleHtml('T', '<p>x</p>', ['date' => 'demain']))['published_on']);
    }

    public function test_accents_and_entities_survive_exactly_once(): void
    {
        $a = $this->parse($this->articleHtml('Été à l&#039;Université &amp; ses amis', '<p>L&#039;étudiant &lt;b&gt;gras&lt;/b&gt; &eacute;cole</p>'));

        $this->assertSame('Été à l\'Université & ses amis', $a['title']);
        // « &lt;b&gt; » est du TEXTE : il ne devient jamais une balise, et n'est décodé qu'une fois.
        $this->assertSame('L\'étudiant <b>gras</b> école', $this->flat($a['blocks'][0]['inlines']));
    }

    // ------------------------------------------------------------------------------------------ blocs

    public function test_paragraphs_line_breaks_bold_italic_and_links(): void
    {
        $b = $this->body('<p>Ligne 1<br />Ligne 2 <strong>gras</strong> et <em>italique</em> voir <a href="https://exemple.mg/x" rel="noopener">ici</a>.</p>');

        $this->assertCount(1, $b);
        $this->assertSame('paragraph', $b[0]['type']);
        $this->assertSame('Ligne 1|Ligne 2 gras et italique voir ici.', $this->flat($b[0]['inlines']));
        $byText = collect($b[0]['inlines'])->keyBy(fn ($p) => $p['text'] ?? '#br');
        $this->assertTrue($byText['gras']['bold']);
        $this->assertTrue($byText['italique']['italic']);
        $this->assertSame('https://exemple.mg/x', $byText['ici']['href']);
    }

    public function test_headings_are_clamped_between_h2_and_h4(): void
    {
        $b = $this->body('<h1>Un</h1><h2>Deux</h2><h3>Trois</h3><h4>Quatre</h4><h5>Cinq</h5><h6>Six</h6>');

        $this->assertSame([2, 2, 3, 4, 4, 4], array_column($b, 'level'));
    }

    public function test_lists_ordered_and_unordered(): void
    {
        $b = $this->body('<ul><li>a</li><li>b <em>x</em></li></ul><ol><li>un</li></ol>');

        $this->assertSame(['list', 'list'], array_column($b, 'type'));
        $this->assertFalse($b[0]['ordered']);
        $this->assertTrue($b[1]['ordered']);
        $this->assertSame(['a', 'b x'], array_map(fn ($i) => $this->flat($i), $b[0]['items']));
    }

    public function test_blockquote_and_nested_containers_are_walked(): void
    {
        $b = $this->body('<div><section><p>Dans un div</p></section></div><blockquote>Citation</blockquote>');

        $this->assertSame(['paragraph', 'quote'], array_column($b, 'type'));
    }

    public function test_empty_and_nbsp_only_paragraphs_are_dropped_and_edges_are_trimmed(): void
    {
        $b = $this->body("<p>&nbsp;</p><p> </p><p><br /><br />Texte<br /> <br /></p><p></p>");

        $this->assertCount(1, $b);
        $this->assertSame('Texte', $this->flat($b[0]['inlines']));
    }

    public function test_images_come_out_as_blocks_with_absolute_site_addresses(): void
    {
        $b = $this->body('<figure><picture><source srcset="/x.webp 1x"><img src="/storage/87/img-hero.webp" width="1080" height="1350" alt="  Une   image  "></picture></figure>');

        $this->assertSame([['type' => 'image', 'src' => 'https://zurcher.edu.mg/storage/87/img-hero.webp', 'alt' => 'Une image', 'width' => 1080, 'height' => 1350]], $b);
    }

    public function test_only_images_hosted_on_the_site_over_https_are_kept(): void
    {
        $b = $this->body(
            '<img src="https://zurcher.edu.mg/ok.jpg" alt="ok">'
            .'<img src="http://zurcher.edu.mg/pas-https.jpg" alt="x">'
            .'<img src="https://tracker.example.com/pixel.gif" alt="x">'
            .'<img src="https://zurcher.edu.mg.evil.example/x.jpg" alt="x">'
            .'<img src="https://zurcher.edu.mg@evil.example/x.jpg" alt="x">'
            .'<img src="//evil.example/x.jpg" alt="x">'
            .'<img src="data:image/svg+xml;base64,PHN2Zz4=" alt="x">'
            .'<img src="javascript:alert(1)" alt="x">'
            .'<img src="https://zurcher.edu.mg:8443/x.jpg" alt="x">'
        );

        $this->assertSame(['https://zurcher.edu.mg/ok.jpg'], array_column($b, 'src'));
    }

    public function test_the_cover_comes_in_a_medium_size_and_a_thumbnail_chosen_among_the_sites_own_variants(): void
    {
        $srcset = '/s/c___hero_2000_1334.webp 2000w, /s/c___hero_1400_934.webp 1400w, /s/c___hero_1171_781.webp 1171w, /s/c___hero_979_653.webp 979w, '
            .'/s/c___hero_685_457.webp 685w, /s/c___hero_573_382.webp 573w, /s/c___hero_400_267.webp 400w, data:image/svg+xml;base64,PHN2Zz4= 32w';

        $a = $this->parse($this->articleHtml('T', '<p>x</p>', ['srcset' => $srcset, 'cover' => 'https://zurcher.edu.mg/s/c-hero.webp']));

        $this->assertSame('https://zurcher.edu.mg/s/c___hero_1171_781.webp', $a['image_url']);   // la plus petite d'au moins 1 100 px
        $this->assertSame('https://zurcher.edu.mg/s/c___hero_685_457.webp', $a['thumb_url']);      // la plus petite d'au moins 600 px
    }

    public function test_a_data_placeholder_with_commas_never_becomes_an_image_candidate(): void
    {
        // Le morceau après la virgule du placeholder se présente comme le PLUS GRAND candidat : coupé au mauvais endroit, il serait choisi.
        $srcset = '/s/c___400.webp 400w, data:image/svg+xml;base64,PCFET0NUWVBFIHN2ZyBQVUJMSUMg,Zm9v 9999w';

        $a = $this->parse($this->articleHtml('T', '<p>x</p>', ['srcset' => $srcset]));

        $this->assertSame('https://zurcher.edu.mg/s/c___400.webp', $a['image_url']);
        $this->assertStringNotContainsString('Zm9v', (string) json_encode($a));
        $this->assertStringNotContainsString('PCFET0NUWVBFIHN2Z', (string) json_encode($a));
    }

    public function test_responsive_variants_follow_the_same_host_rule(): void
    {
        $srcset = 'https://evil.example/a_700.webp 700w, http://zurcher.edu.mg/b_1200.webp 1200w, //evil.example/c_1200.webp 1200w';

        $a = $this->parse($this->articleHtml('T', '<p>x</p>', ['srcset' => $srcset, 'cover' => 'https://zurcher.edu.mg/og.webp']));

        $this->assertSame('https://zurcher.edu.mg/og.webp', $a['image_url']);   // aucune variante acceptable : retombe sur og:image
        $this->assertNull($a['thumb_url']);
    }

    public function test_without_variants_the_cover_is_the_og_image_and_there_is_no_thumbnail(): void
    {
        $a = $this->parse($this->articleHtml('T', '<p>x</p>', ['cover' => 'https://zurcher.edu.mg/og.webp']));

        $this->assertSame(['https://zurcher.edu.mg/og.webp', null], [$a['image_url'], $a['thumb_url']]);
    }

    public function test_the_cover_image_follows_the_same_rule(): void
    {
        $this->assertNull($this->parse($this->articleHtml('T', '<p>x</p>', ['cover' => 'https://evil.example/c.jpg']))['image_url']);
        $this->assertNull($this->parse($this->articleHtml('T', '<p>x</p>', ['cover' => 'javascript:alert(1)']))['image_url']);
        $this->assertSame('https://zurcher.edu.mg/c.jpg', $this->parse($this->articleHtml('T', '<p>x</p>', ['cover' => '/c.jpg']))['image_url']);
    }

    // --------------------------------------------------------------------------------------- hostile

    public function test_scripts_styles_iframes_and_forms_vanish_with_their_content(): void
    {
        $b = $this->body(
            '<p>Avant</p><script>window.pirate=1</script><style>body{display:none}</style><iframe src="https://evil.example"></iframe>'
            .'<object data="x"></object><form action="https://evil.example"><input name="mdp"></form><noscript>noscript</noscript>'
            .'<p>Après<script>alert(2)</script></p><embed src="https://evil.example/e.swf">'
        );

        $json = json_encode($b);
        $this->assertSame(['Avant', 'Après'], array_map(fn ($x) => $this->flat($x['inlines']), $b));
        foreach (['pirate', 'display', 'evil', 'alert', 'noscript', 'mdp'] as $needle) {
            $this->assertStringNotContainsString($needle, $json, $needle);
        }
    }

    public function test_text_inside_an_iframe_or_other_embedded_content_is_dropped(): void
    {
        $b = $this->body('<p>Avant</p><iframe>texte-dans-iframe</iframe><object>texte-dans-object</object><p>Après</p>');

        $this->assertSame(['Avant', 'Après'], array_map(fn ($x) => $this->flat($x['inlines']), $b));
    }

    public function test_a_mailto_must_be_a_real_address(): void
    {
        $b = $this->body('<p><a href="mailto:pas-un-courriel">m1</a> <a href="mailto:">m2</a> <a href="mailto:a b@x.mg">m3</a> <a href="mailto:ok@zurcher.edu.mg">m4</a></p>');

        $this->assertSame(['m4' => 'mailto:ok@zurcher.edu.mg'], collect($b[0]['inlines'])->whereNotNull('href')->pluck('href', 'text')->all());
    }

    public function test_dangerous_links_become_plain_text(): void
    {
        $b = $this->body(
            '<p><a href="javascript:alert(1)">js</a> <a href="JaVaScRiPt:alert(1)">js2</a> <a href="data:text/html,x">data</a> <a href="vbscript:x">vb</a> '
            .'<a href="ftp://x.mg/f">ftp</a> <a href="file:///etc/passwd">file</a> <a href="#ancre">ancre</a> <a href="https://u:p@evil.example/">creds</a> '
            .'<a href="mailto:contact@zurcher.edu.mg">mail</a> <a href="/formations">relatif</a> <a href="http://localhost/x">local</a></p>'
        );

        $links = collect($b[0]['inlines'])->whereNotNull('href')->pluck('href', 'text')->all();

        $this->assertSame(['mail' => 'mailto:contact@zurcher.edu.mg', 'relatif' => 'https://zurcher.edu.mg/formations'], $links);
        $this->assertStringContainsString('js js2 data vb ftp file ancre creds', $this->flat($b[0]['inlines']));
    }

    public function test_event_handler_attributes_never_reach_the_output(): void
    {
        $b = $this->body('<p onclick="x()" style="color:red" class="a" data-x="1">Texte</p><img src="/a.jpg" onerror="alert(1)" alt="a" onload="y()">');

        $json = json_encode($b);
        $this->assertStringNotContainsString('onclick', $json);
        $this->assertStringNotContainsString('onerror', $json);
        $this->assertStringNotContainsString('style', $json);
        $this->assertSame(['paragraph', 'image'], array_column($b, 'type'));
    }

    public function test_unknown_tags_lose_their_markup_but_keep_their_text(): void
    {
        $b = $this->body('<p><span class="x">Un <mark>mot</mark> <u>souligné</u> <custom-tag>perso</custom-tag></span></p>');

        $this->assertSame('Un mot souligné perso', $this->flat($b[0]['inlines']));
        $this->assertStringNotContainsString('<', json_encode($b[0]));
    }

    public function test_the_output_never_contains_html_markup(): void
    {
        $b = $this->body('<p>a <b>b</b> <i>c</i> <a href="https://x.mg">d</a></p><ul><li><strong>e</strong></li></ul><h2><em>f</em></h2>');

        $this->assertDoesNotMatchRegularExpression('/<\/?[a-z][^>]*>/i', json_encode($b, JSON_UNESCAPED_UNICODE));
    }

    // ---------------------------------------------------------------------------- gabarit inconnu

    public function test_a_page_that_is_not_an_article_is_refused_not_guessed(): void
    {
        $this->assertNull($this->parse('<html><body><h1>Autre page</h1><p>texte</p></body></html>'));
        $this->assertNull($this->parse(''));
        $this->assertNull($this->parse('   '));
        $this->assertNull($this->parse('pas du html du tout'));
    }

    public function test_an_article_without_a_readable_body_is_refused(): void
    {
        $this->assertNull($this->parse($this->articleHtml('T', '')));
        $this->assertNull($this->parse($this->articleHtml('T', '<p>&nbsp;</p><script>x</script>')));
    }

    public function test_a_huge_document_is_refused(): void
    {
        $this->assertNull($this->parse($this->articleHtml('T', '<p>'.str_repeat('x', 6 * 1024 * 1024).'</p>')));
    }

    public function test_the_number_of_blocks_is_capped(): void
    {
        $a = $this->parse($this->articleHtml('T', str_repeat('<p>x</p>', 1000)));

        $this->assertCount(400, $a['blocks']);
    }

    public function test_title_excerpt_and_author_are_length_limited(): void
    {
        $a = $this->parse($this->articleHtml(str_repeat('T', 400), '<p>x</p>', ['lead' => str_repeat('r', 900), 'author' => str_repeat('A', 300)]));

        $this->assertSame(255, mb_strlen($a['title']));
        $this->assertLessThanOrEqual(601, mb_strlen($a['excerpt']));
        $this->assertLessThanOrEqual(121, mb_strlen($a['author']));
    }

    public function test_a_text_node_at_the_top_level_becomes_a_paragraph(): void
    {
        $b = $this->body('Texte nu <strong>gras</strong>');

        $this->assertSame('paragraph', $b[0]['type']);
        $this->assertStringContainsString('Texte nu', $this->flat($b[0]['inlines']));
    }
}
