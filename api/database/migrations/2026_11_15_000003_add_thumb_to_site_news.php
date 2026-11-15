<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Miniature de la couverture (~685 px) pour la liste : une liste de 18 articles ne doit pas télécharger 18 images de 2 000 px. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('t_site_news', function (Blueprint $t) {
            $t->string('thumb_url', 500)->nullable()->after('image_url');
        });
    }

    public function down(): void
    {
        Schema::table('t_site_news', function (Blueprint $t) {
            $t->dropColumn('thumb_url');
        });
    }
};
