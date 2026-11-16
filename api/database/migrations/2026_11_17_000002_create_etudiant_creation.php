<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Création d'un étudiant : jeton anti-doublon et compte Google en TÂCHE (docs/PLAN-PAGES lot 4).
 *
 * L'ancienne application appelait l'API Google Admin PENDANT la requête de création (1,4 s en moyenne, jusqu'à 8,9 s) et gardait le jeton anti-doublon dans la
 * session PHP. Ici, la création n'écrit que la fiche et pose une ligne dans cette table ; la commande planifiée `students:google-accounts` crée le compte Google
 * (trois essais espacés) et le statut — en attente, créé, déjà existant, échec — se lit sur l'écran, avec « Réessayer ».
 *
 * `jeton` (UNIQUE) : le même formulaire reçu deux fois (clics répétés, réseau lent) ne crée qu'un étudiant ; le second envoi reçoit la fiche déjà créée.
 *
 * AJOUT SEULEMENT : aucune table existante n'est modifiée.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('t_etudiant_creation', function (Blueprint $t) {
            $t->charset('utf8mb4');
            $t->collation('utf8mb4_general_ci');
            $t->increments('id');
            $t->char('jeton', 32)->unique('uq_creation_jeton')->comment('un formulaire = un étudiant');
            $t->integer('fiche_id')->comment('tbl_2024_etudiant.id');
            $t->string('student_id', 20)->index('idx_creation_matricule');
            $t->integer('candidat_id')->nullable();
            $t->string('adresse', 150)->nullable()->comment('adresse universitaire attribuée');
            $t->enum('google_statut', ['non_configure', 'en_attente', 'cree', 'existait', 'echec'])->default('en_attente');
            $t->string('google_raison', 255)->nullable();
            $t->unsignedTinyInteger('google_essais')->default(0);
            $t->dateTime('google_prochain_essai')->nullable();
            $t->integer('cree_par')->nullable();
            $t->dateTime('cree_le');
            $t->index(['google_statut', 'google_prochain_essai'], 'idx_creation_google');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('t_etudiant_creation');
    }
};
