<?php

namespace App\Domain\News;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;

/**
 * Extrait un article de la page HTML publique de https://zurcher.edu.mg/actualites/{slug}.
 *
 * FONCTION PURE (aucun réseau, aucune base) : elle reçoit du HTML et rend des données assainies.
 *
 * Le HTML du site n'est JAMAIS conservé ni réinjecté : le corps est converti en BLOCS (paragraphe, titre, liste, citation,
 * image) par une liste blanche. Tout ce qui n'y figure pas perd sa balise mais garde son texte ; `script`, `style`, `iframe`,
 * `object`, `embed`, `svg`, `form` disparaissent avec leur contenu. Un lien n'est gardé que s'il est http(s) ou mailto ;
 * une image que si elle est en https ET sur le site lui-même (aucune image tierce : ni pistage, ni contenu mixte).
 *
 * Repères du gabarit actuel du site (relevé du 15/11/2026 sur les 18 articles) : titre `h1#nws-title`, résumé
 * `.nws-open__lead`, rubrique `.nws-open__tag`, date `time[datetime]` et auteur « Par … » dans `.nws-open__meta`,
 * corps dans `[data-nws-prose]`, image de couverture `og:image`. Si le gabarit change, l'analyse échoue PROPREMENT (null) :
 * la copie existante reste affichée.
 */
final class SiteArticleParser
{
    private const SKIP = ['script', 'style', 'iframe', 'object', 'embed', 'svg', 'form', 'noscript', 'template', 'button', 'input', 'select', 'textarea', 'canvas', 'audio', 'video'];

    private const MAX_BLOCKS = 400;

    private const MAX_ITEMS = 100;

    public function __construct(private readonly string $siteBase) {}

    /**
     * @return array{title: string, excerpt: ?string, category: ?string, author: ?string, published_on: ?string, image_url: ?string, thumb_url: ?string, blocks: list<array<string, mixed>>}|null
     */
    public function parse(string $html, string $pageUrl): ?array
    {
        $dom = $this->load($html);
        if ($dom === null) {
            return null;
        }
        $x = new DOMXPath($dom);

        $title = $this->text($x->query('//h1[@id="nws-title"]')->item(0)) ?? $this->text($x->query('//article//h1 | //h1')->item(0));
        $container = $x->query('//*[@data-nws-prose]')->item(0);
        if ($title === null || $container === null) {
            return null;   // gabarit inconnu : on ne devine pas
        }
        $inner = $x->query('.//div[contains(concat(" ", normalize-space(@class), " "), " prose ")]', $container)->item(0) ?? $container;

        $blocks = [];
        $this->blocks($inner, $blocks, $pageUrl);
        if ($blocks === []) {
            return null;   // un article sans corps lisible n'est pas une page d'article
        }

        [$cover, $thumb] = $this->coverImages($x, $pageUrl);

        return [
            'title' => mb_substr($title, 0, 255),
            'excerpt' => $this->limit($this->text($x->query('//*[contains(concat(" ", normalize-space(@class), " "), " nws-open__lead ")]')->item(0)) ?? $this->meta($x, 'og:description'), 600),
            'category' => $this->limit($this->text($x->query('//*[contains(concat(" ", normalize-space(@class), " "), " nws-open__tag ")]')->item(0)), 80),
            'author' => $this->author($x),
            'published_on' => $this->date($x),
            'image_url' => $cover,
            'thumb_url' => $thumb,
            'blocks' => $blocks,
        ];
    }

    /** L'adresse est-elle une image du site lui-même, en https ? (même règle pour la couverture et les images du corps) */
    public function safeImage(?string $src, string $pageUrl): ?string
    {
        $src = trim((string) $src);
        if ($src === '' || str_starts_with($src, 'data:') || preg_match('/[\x00-\x20"\'<>\\\\]/', $src)) {
            return null;
        }
        $abs = $this->absolute($src, $pageUrl);

        return $abs !== null && $this->isSiteUrl($abs) && ! str_contains($abs, '@') ? $abs : null;
    }

    public function isSiteUrl(string $url): bool
    {
        $p = parse_url($url);
        $base = parse_url($this->siteBase);

        // https, MÊME hôte exactement, sans identifiants (« https://zurcher.edu.mg@autre.site ») ni port inhabituel.
        return is_array($p) && is_array($base) && ($p['scheme'] ?? '') === 'https' && isset($p['host'], $base['host'])
            && strtolower($p['host']) === strtolower($base['host']) && ! isset($p['user']) && ! isset($p['port']);
    }

    // ----------------------------------------------------------------------------------------------- blocs

    /** @param list<array<string, mixed>> $out */
    private function blocks(DOMNode $parent, array &$out, string $pageUrl): void
    {
        foreach ($parent->childNodes as $node) {
            if (count($out) >= self::MAX_BLOCKS) {
                return;
            }
            if ($node instanceof DOMElement) {
                $name = strtolower($node->nodeName);
                if (in_array($name, self::SKIP, true)) {
                    continue;
                }
                match (true) {
                    $name === 'p' => $this->paragraph($node, $out, $pageUrl),
                    (bool) preg_match('/^h[1-6]$/', $name) => $this->heading($node, $out, $pageUrl),
                    $name === 'ul', $name === 'ol' => $this->list($node, $out, $pageUrl),
                    $name === 'blockquote' => $this->quote($node, $out, $pageUrl),
                    $name === 'img' => $this->image($node, $out, $pageUrl),
                    $name === 'br', $name === 'hr' => null,
                    // figure, picture, div, section, aside… : on descend, on ne garde que ce qu'on sait afficher
                    default => $this->blocks($node, $out, $pageUrl),
                };
            } elseif ($node->nodeType === XML_TEXT_NODE && trim($node->textContent) !== '') {
                $inline = $this->inline($node->parentNode, $pageUrl, onlyText: $node);
                $this->push($out, ['type' => 'paragraph', 'inlines' => $inline]);
            }
        }
    }

    /** @param list<array<string, mixed>> $out */
    private function paragraph(DOMElement $e, array &$out, string $pageUrl): void
    {
        // Un paragraphe peut contenir une image : on la sort en bloc propre.
        $inlines = $this->inline($e, $pageUrl);
        $this->push($out, ['type' => 'paragraph', 'inlines' => $inlines]);
        foreach ($e->getElementsByTagName('img') as $img) {
            $this->image($img, $out, $pageUrl);
        }
    }

    /** @param list<array<string, mixed>> $out */
    private function heading(DOMElement $e, array &$out, string $pageUrl): void
    {
        $level = min(4, max(2, (int) substr($e->nodeName, 1)));   // h1 est le titre de la page : h2 à h4 dans le corps
        $this->push($out, ['type' => 'heading', 'level' => $level, 'inlines' => $this->inline($e, $pageUrl)]);
    }

    /** @param list<array<string, mixed>> $out */
    private function quote(DOMElement $e, array &$out, string $pageUrl): void
    {
        $this->push($out, ['type' => 'quote', 'inlines' => $this->inline($e, $pageUrl)]);
    }

    /** @param list<array<string, mixed>> $out */
    private function list(DOMElement $e, array &$out, string $pageUrl): void
    {
        $items = [];
        foreach ($e->childNodes as $li) {
            if ($li instanceof DOMElement && strtolower($li->nodeName) === 'li' && count($items) < self::MAX_ITEMS) {
                $inl = $this->inline($li, $pageUrl);
                if ($inl !== []) {
                    $items[] = $inl;
                }
            }
        }
        if ($items !== []) {
            $out[] = ['type' => 'list', 'ordered' => strtolower($e->nodeName) === 'ol', 'items' => $items];
        }
    }

    /** @param list<array<string, mixed>> $out */
    private function image(DOMElement $e, array &$out, string $pageUrl): void
    {
        $src = $this->safeImage($e->getAttribute('src'), $pageUrl);
        if ($src === null) {
            return;
        }
        $alt = $this->limit(trim((string) preg_replace('/\s+/u', ' ', $e->getAttribute('alt'))), 300);
        $out[] = ['type' => 'image', 'src' => $src, 'alt' => $alt ?? '', 'width' => $this->dimension($e->getAttribute('width')), 'height' => $this->dimension($e->getAttribute('height'))];
    }

    /** @param list<array<string, mixed>> $out @param array<string, mixed> $block */
    private function push(array &$out, array $block): void
    {
        if (($block['inlines'] ?? []) !== []) {
            $out[] = $block;
        }
    }

    // ---------------------------------------------------------------------------------------------- inline

    /**
     * @return list<array<string, mixed>> morceaux { text, bold?, italic?, href? } ou { br: true }
     */
    private function inline(?DOMNode $node, string $pageUrl, ?DOMNode $onlyText = null): array
    {
        $out = [];
        if ($onlyText !== null) {
            $this->addText($out, $onlyText->textContent, false, false, null);
        } elseif ($node !== null) {
            $this->walk($node, $out, false, false, null, $pageUrl, 0);
        }

        return $this->tidy($out);
    }

    /** @param list<array<string, mixed>> $out */
    private function walk(DOMNode $node, array &$out, bool $bold, bool $italic, ?string $href, string $pageUrl, int $depth): void
    {
        if ($depth > 12) {
            return;
        }
        foreach ($node->childNodes as $c) {
            if ($c->nodeType === XML_TEXT_NODE || $c->nodeType === XML_CDATA_SECTION_NODE) {
                $this->addText($out, $c->textContent, $bold, $italic, $href);
            } elseif ($c instanceof DOMElement) {
                $n = strtolower($c->nodeName);
                if (in_array($n, self::SKIP, true) || $n === 'img' || $n === 'picture' || $n === 'figure') {
                    continue;   // les images sortent en blocs à part
                }
                if ($n === 'br') {
                    $out[] = ['br' => true];
                    continue;
                }
                $b = $bold || in_array($n, ['strong', 'b'], true);
                $i = $italic || in_array($n, ['em', 'i'], true);
                $h = $href;
                if ($n === 'a') {
                    $h = $this->safeHref($c->getAttribute('href'), $pageUrl) ?? $href;
                }
                $this->walk($c, $out, $b, $i, $h, $pageUrl, $depth + 1);
            }
        }
    }

    /** @param list<array<string, mixed>> $out */
    private function addText(array &$out, string $text, bool $bold, bool $italic, ?string $href): void
    {
        $text = str_replace("\u{00A0}", ' ', $text);
        $text = (string) preg_replace('/[ \t\r\n\f]+/u', ' ', $text);
        if ($text === '') {
            return;
        }
        $piece = ['text' => $text];
        if ($bold) {
            $piece['bold'] = true;
        }
        if ($italic) {
            $piece['italic'] = true;
        }
        if ($href !== null) {
            $piece['href'] = $href;
        }
        $out[] = $piece;
    }

    /**
     * Nettoie la suite : espaces de début/fin de ligne retirés, sauts de ligne en tête/fin retirés, doublons de sauts réduits,
     * et rien si tout est vide (paragraphe d'espaces insécables).
     *
     * @param  list<array<string, mixed>>  $inlines
     * @return list<array<string, mixed>>
     */
    private function tidy(array $inlines): array
    {
        $res = [];
        foreach ($inlines as $p) {
            if (isset($p['br'])) {
                if ($res !== [] && ! isset($res[array_key_last($res)]['br'])) {
                    $res[] = $p;
                }
                continue;
            }
            $prev = $res === [] ? null : $res[array_key_last($res)];
            if (($prev === null || isset($prev['br'])) && isset($p['text'])) {
                $p['text'] = ltrim($p['text']);   // début de ligne
            }
            if ($p['text'] !== '') {
                $res[] = $p;
            }
        }
        while ($res !== [] && isset($res[array_key_last($res)]['br'])) {
            array_pop($res);
        }
        if ($res !== []) {
            $last = array_key_last($res);
            $res[$last]['text'] = rtrim($res[$last]['text']);
            if ($res[$last]['text'] === '') {
                array_pop($res);
            }
        }
        // Un espace juste avant un saut de ligne n'a pas de sens.
        foreach ($res as $k => $p) {
            if (isset($res[$k + 1]['br']) && isset($p['text'])) {
                $res[$k]['text'] = rtrim($p['text']);
            }
        }

        return array_values(array_filter($res, fn ($p) => isset($p['br']) || ($p['text'] ?? '') !== ''));
    }

    private function safeHref(string $href, string $pageUrl): ?string
    {
        $href = trim($href);
        if ($href === '' || preg_match('/[\x00-\x1f"\'<>\\\\]/', $href) || str_starts_with($href, '#')) {
            return null;
        }
        if (preg_match('/^mailto:[^\s@]+@[^\s@]+\.[^\s@]+$/i', $href)) {
            return $href;
        }
        $abs = $this->absolute($href, $pageUrl);
        if ($abs === null) {
            return null;
        }
        $p = parse_url($abs);

        return is_array($p) && in_array($p['scheme'] ?? '', ['http', 'https'], true) && str_contains((string) ($p['host'] ?? ''), '.') && ! isset($p['user']) ? $abs : null;
    }

    private function absolute(string $url, string $pageUrl): ?string
    {
        if (preg_match('#^https?://#i', $url)) {
            return $url;
        }
        if (str_starts_with($url, '//')) {
            return 'https:'.$url;
        }
        if (preg_match('/^[a-z][a-z0-9+.-]*:/i', $url)) {
            return null;   // javascript:, data:, ftp:, file: …
        }
        $base = parse_url($pageUrl);
        if (! is_array($base) || ! isset($base['scheme'], $base['host'])) {
            return null;
        }
        $origin = $base['scheme'].'://'.$base['host'];
        if (str_starts_with($url, '/')) {
            return $origin.$url;
        }

        return $origin.rtrim(dirname($base['path'] ?? '/'), '/').'/'.$url;
    }

    // ------------------------------------------------------------------------------------------- métadonnées

    /**
     * Couverture : l'image « moyenne » (~1 170 px, pour l'article) et la miniature (~685 px, pour la liste), choisies parmi les variantes
     * responsives que le site publie lui-même (<source srcset>). À défaut, l'image `og:image` (2 000 px) sans miniature.
     * Toutes passent par safeImage : https et site seulement.
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function coverImages(DOMXPath $x, string $pageUrl): array
    {
        $candidates = [];   // largeur => adresse
        foreach ($x->query('//*[@data-nws-cover]//source/@srcset') as $attr) {
            // Candidats séparés par « virgule + espace » : un placeholder data: contient des virgules SANS espace, il ne doit pas être coupé.
            foreach (preg_split('/,\s+/', (string) $attr->nodeValue) ?: [] as $part) {
                if (! str_contains($part, 'data:') && preg_match('/^\s*(\S+)\s+(\d{2,5})w\s*$/', $part, $m) && ($url = $this->safeImage($m[1], $pageUrl)) !== null) {
                    $candidates[(int) $m[2]] = $url;
                }
            }
        }
        ksort($candidates);
        $pick = function (int $atLeast) use ($candidates): ?string {
            foreach ($candidates as $w => $url) {
                if ($w >= $atLeast) {
                    return $url;   // la plus petite qui est assez grande
                }
            }

            return $candidates === [] ? null : end($candidates);
        };

        $fallback = $this->safeImage($this->meta($x, 'og:image'), $pageUrl);

        return [$pick(1100) ?? $fallback, $pick(600)];
    }

    private function author(DOMXPath $x): ?string
    {
        foreach ($x->query('//*[contains(concat(" ", normalize-space(@class), " "), " nws-open__meta ")]//span') as $s) {
            if (preg_match('/^\s*Par\s+(.+)$/u', trim($s->textContent), $m)) {
                return $this->limit(trim((string) preg_replace('/\s+/u', ' ', $m[1])), 120);
            }
        }

        return null;
    }

    private function date(DOMXPath $x): ?string
    {
        $t = $x->query('//*[contains(concat(" ", normalize-space(@class), " "), " nws-open__meta ")]//time/@datetime')->item(0);
        $v = $t === null ? '' : trim($t->nodeValue);
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $v, $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return "{$m[1]}-{$m[2]}-{$m[3]}";
        }

        return null;
    }

    private function meta(DOMXPath $x, string $property): ?string
    {
        $n = $x->query('//meta[@property="'.$property.'" or @name="'.$property.'"]/@content')->item(0);
        $v = $n === null ? '' : trim($n->nodeValue);

        return $v === '' ? null : $v;
    }

    private function text(?DOMNode $n): ?string
    {
        if ($n === null) {
            return null;
        }
        $t = trim((string) preg_replace('/\s+/u', ' ', str_replace("\u{00A0}", ' ', $n->textContent)));

        return $t === '' ? null : $t;
    }

    private function limit(?string $s, int $max): ?string
    {
        if ($s === null || $s === '') {
            return null;
        }

        return mb_strlen($s) > $max ? rtrim(mb_substr($s, 0, $max)).'…' : $s;
    }

    private function dimension(string $v): ?int
    {
        return ctype_digit($v) && (int) $v > 0 && (int) $v <= 10000 ? (int) $v : null;
    }

    private function load(string $html): ?DOMDocument
    {
        if (trim($html) === '' || strlen($html) > 6 * 1024 * 1024) {
            return null;
        }
        $dom = new DOMDocument;
        $prev = libxml_use_internal_errors(true);
        // L'en-tête XML force l'UTF-8 (sans lui, libxml lirait du Latin-1 et abîmerait les accents).
        $ok = $dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        return $ok ? $dom : null;
    }
}
