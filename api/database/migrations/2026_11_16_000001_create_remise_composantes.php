<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Remise de notes : colonnes LIBRES (nombre, titre et pondération de chaque composante choisis par l'enseignant ; seul le total, 100 %, est imposé).
 *
 * L'ancienne feuille a cinq colonnes fixes (quiz, recherche, autres, mi-semestre, final : `t_remise_notes.poids_*` et `t_remise_notes_ligne`).
 * Ces deux tables ajoutent la structure libre SANS toucher aux existantes (l'ancien PHP, qui valide encore les feuilles, les lit toujours) :
 *  - `t_remise_notes_composante` : les colonnes d'UNE remise (`slot` = identité stable, jamais réutilisée ; `position` = ordre d'affichage) ;
 *  - `t_remise_notes_valeur`     : la note d'un étudiant dans une colonne.
 * Tant qu'une remise n'a aucune ligne ici, sa structure est celle des cinq colonnes fixes. Les cinq premières colonnes sont recopiées dans les
 * colonnes historiques à chaque écriture : l'ancienne validation reste exacte pour une feuille de cinq colonnes au plus, et BLOQUE (total ≠ 100 %)
 * au-delà plutôt que de calculer une note fausse.
 */
return new class extends Migration
{
    private const COLLATION = 'utf8mb4_general_ci';

    public function up(): void
    {
        Schema::create('t_remise_notes_composante', function (Blueprint $t) {
            $t->charset('utf8mb4');
            $t->collation(self::COLLATION);
            $t->increments('id');
            $t->integer('remise_id');
            $t->unsignedSmallInteger('slot')->comment('identité stable de la colonne dans la remise (jamais réutilisée)');
            $t->unsignedSmallInteger('position')->comment('ordre d\'affichage');
            $t->string('libelle', 40);
            $t->decimal('poids', 5, 2)->default(0);
            $t->unique(['remise_id', 'slot'], 'uq_composante_slot');
            $t->index('remise_id', 'idx_composante_remise');
            $t->foreign('remise_id', 'fk_composante_remise')->references('id')->on('t_remise_notes')->cascadeOnDelete();
        });

        Schema::create('t_remise_notes_valeur', function (Blueprint $t) {
            $t->charset('utf8mb4');
            $t->collation(self::COLLATION);
            $t->increments('id');
            $t->integer('ligne_id');
            $t->unsignedSmallInteger('slot');
            $t->decimal('valeur', 5, 2)->nullable();
            $t->dateTime('modifie_le')->nullable();
            $t->unique(['ligne_id', 'slot'], 'uq_valeur_ligne_slot');
            $t->foreign('ligne_id', 'fk_valeur_ligne')->references('id')->on('t_remise_notes_ligne')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('t_remise_notes_valeur');
        Schema::dropIfExists('t_remise_notes_composante');
    }
};
