<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Copie locale des actualités du site public de l'université (https://zurcher.edu.mg/actualites), tenue à jour par
 * `php artisan news:sync`. AJOUT SEULEMENT : aucune table existante n'est modifiée.
 *
 * Le corps est stocké en BLOCS JSON déjà assainis (paragraphes, titres, listes, images), jamais en HTML brut : l'application
 * n'injecte aucun HTML venu d'ailleurs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('t_site_news', function (Blueprint $t) {
            $t->charset('utf8mb4');
            $t->collation('utf8mb4_unicode_ci');
            $t->id();
            $t->string('slug', 120)->unique();
            $t->string('url', 300);
            $t->string('title', 255);
            $t->text('excerpt')->nullable();
            $t->string('category', 80)->nullable();
            $t->string('image_url', 500)->nullable();
            $t->string('author', 120)->nullable();
            $t->date('published_on')->nullable();
            $t->longText('blocks')->comment('JSON : corps de l\'article en blocs assainis');
            $t->dateTime('lastmod')->nullable()->comment('date de modification annoncée par le sitemap du site');
            $t->char('content_hash', 40)->comment('sha1 du contenu extrait : détecte un vrai changement');
            $t->boolean('is_visible')->default(true)->comment('0 = retiré du site (sitemap) : masqué ici, jamais supprimé');
            $t->dateTime('fetched_at');
            $t->timestamps();
            $t->index(['is_visible', 'published_on'], 'idx_site_news_liste');
        });

        Schema::create('t_site_news_sync', function (Blueprint $t) {
            $t->charset('utf8mb4');
            $t->collation('utf8mb4_unicode_ci');
            $t->id();
            $t->dateTime('started_at');
            $t->dateTime('finished_at')->nullable();
            $t->string('status', 20)->comment('ok | partial | failed');
            $t->unsignedSmallInteger('seen')->default(0)->comment('articles listés par le sitemap');
            $t->unsignedSmallInteger('created')->default(0);
            $t->unsignedSmallInteger('updated')->default(0);
            $t->unsignedSmallInteger('hidden')->default(0);
            $t->unsignedSmallInteger('errors')->default(0);
            $t->text('message')->nullable();
            $t->index('started_at', 'idx_site_news_sync_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('t_site_news_sync');
        Schema::dropIfExists('t_site_news');
    }
};
