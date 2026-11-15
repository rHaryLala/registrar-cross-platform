<?php

namespace Tests\Concerns;

/** Pages d'articles FICTIVES, construites sur le gabarit du site public (aucun texte réel du site n'est copié). */
trait MakesSiteNews
{
    protected function articleHtml(string $title = 'Titre fictif', string $body = '<p>Texte fictif.</p>', array $o = []): string
    {
        $o += ['srcset' => null, 'lead' => 'Résumé fictif.', 'tag' => 'Actualités', 'date' => '2026-10-20', 'author' => 'Auteur Fictif', 'cover' => 'https://zurcher.edu.mg/storage/1/cover-hero.webp'];
        $author = $o['author'] === null ? '' : '<span>Par '.$o['author'].'</span>';
        $time = $o['date'] === null ? '' : '<time datetime="'.$o['date'].'">date</time>';

        return '<!DOCTYPE html><html lang="fr"><head><meta charset="utf-8"><title>x</title>'
            .'<meta property="og:image" content="'.$o['cover'].'"></head><body>'
            .'<header class="nws-open">'.($o['srcset'] === null ? '' : '<div class="nws-open__cover" data-nws-cover><picture><source type="image/webp" srcset="'.$o['srcset'].'"><img src="/c.jpg" alt=""></picture></div>')
            .'<p class="nws-open__tag">'.$o['tag'].'</p><h1 id="nws-title" class="nws-open__title">'.$title.'</h1>'
            .'<p class="nws-open__lead">'.$o['lead'].'</p><p class="nws-open__meta"><span>'.$time.'</span>'.$author.'<span>2 min de lecture</span></p></header>'
            .'<article class="nws-article"><div class="nws-prose" data-nws-prose><div class="prose prose-lg">'.$body.'</div></div>'
            .'<aside class="nws-share"><a href="https://www.facebook.com/sharer">Facebook</a></aside></article>'
            .'<section><h2>À lire aussi</h2><a href="https://zurcher.edu.mg/actualites/autre">Autre</a></section></body></html>';
    }

    /** @param array<string, ?string> $entries slug => lastmod */
    protected function sitemapXml(array $entries, string $base = 'https://zurcher.edu.mg'): string
    {
        $urls = '';
        foreach ($entries as $slug => $lastmod) {
            $urls .= "<url><loc>{$base}/actualites/{$slug}</loc>".($lastmod ? "<lastmod>{$lastmod}</lastmod>" : '')
                ."<xhtml:link rel=\"alternate\" hreflang=\"en\" href=\"{$base}/en/actualites/{$slug}\"/></url>\n"
                ."<url><loc>{$base}/en/actualites/{$slug}</loc></url>\n";
        }

        return '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml">'
            .'<url><loc>https://zurcher.edu.mg/actualites</loc></url>'.$urls.'<url><loc>https://zurcher.edu.mg/formations</loc></url></urlset>';
    }
}
