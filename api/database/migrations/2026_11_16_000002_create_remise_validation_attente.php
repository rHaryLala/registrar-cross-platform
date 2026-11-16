<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Validation d'une remise de notes : fenêtre d'ANNULATION de 10 secondes (docs/ETUDE-05 §4).
 *
 * « Valider » ne touche pas aux notes : il enregistre une demande ici, avec l'instant à partir duquel elle peut aboutir. Pendant ce délai le registrariat
 * peut annuler ; passé le délai, la validation réelle (écriture dans `t_2023_notes` et son historique) est faite soit par l'écran qui a demandé (il
 * attend la fin du délai), soit, onglet fermé, par la commande planifiée `remises:finalize-due`. Aucune file d'attente à faire tourner.
 *
 * AJOUT SEULEMENT : aucune table existante n'est modifiée. Une ligne par remise (clé unique) : demander deux fois est sans effet de plus.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('t_remise_validation_attente', function (Blueprint $t) {
            $t->charset('utf8mb4');
            $t->collation('utf8mb4_general_ci');
            $t->increments('id');
            $t->integer('remise_id')->unique('uq_attente_remise');
            $t->integer('demande_par')->nullable()->comment('compte qui a demandé la validation (auteur des écritures)');
            $t->dateTime('demande_le');
            $t->dateTime('valide_apres')->index('idx_attente_echeance')->comment('instant avant lequel la demande peut encore être annulée');
            $t->foreign('remise_id', 'fk_attente_remise')->references('id')->on('t_remise_notes')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('t_remise_validation_attente');
    }
};
