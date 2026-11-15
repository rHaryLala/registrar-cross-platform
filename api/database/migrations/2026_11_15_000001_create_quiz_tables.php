<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quiz UAZ : banque de questions, parties, réponses et réglage de confidentialité du classement.
 *
 * AJOUT SEULEMENT : aucune table existante n'est modifiée (l'ancien PHP et Moodle partagent cette base).
 * Les noms suivent ceux de l'existant (t_presence_*, t_notes_publication) ; `student_id` reprend le jeu de caractères
 * de tbl_2024_etudiant (utf8mb4_general_ci) pour que les jointures ne se heurtent pas à un conflit de collation.
 *
 * Le score d'une partie n'est JAMAIS fourni par le navigateur : il est calculé par le serveur à partir des réponses
 * enregistrées dans t_quiz_partie_question.
 */
return new class extends Migration
{
    private const COLLATION = 'utf8mb4_general_ci';

    public function up(): void
    {
        Schema::create('t_quiz_question', function (Blueprint $t) {
            $t->charset('utf8mb4');
            $t->collation(self::COLLATION);
            $t->increments('id');
            $t->string('categorie', 80);
            $t->string('question', 500);
            $t->string('bonne_reponse', 255);
            $t->string('mauvaise_1', 255);
            $t->string('mauvaise_2', 255);
            $t->string('icone', 16)->nullable();
            $t->string('indice', 500)->nullable();
            $t->boolean('actif')->default(true);
            $t->char('empreinte', 40)->unique()->comment('sha1 du texte de la question : l\'importation ne crée jamais de doublon');
            $t->timestamp('cree_le')->useCurrent();
        });

        Schema::create('t_quiz_partie', function (Blueprint $t) {
            $t->charset('utf8mb4');
            $t->collation(self::COLLATION);
            $t->id();
            $t->string('student_id', 200);
            $t->dateTime('commencee_le', 3);
            $t->dateTime('terminee_le', 3)->nullable()->comment('NULL tant que la partie n\'est pas terminée ; seules les parties terminées comptent');
            $t->dateTime('expire_le', 3);
            $t->unsignedTinyInteger('nb_questions')->default(10);
            $t->unsignedTinyInteger('score')->nullable();
            $t->unsignedInteger('duree_ms')->nullable();
            $t->index(['student_id', 'terminee_le'], 'idx_quiz_partie_joueur');
            $t->index(['terminee_le', 'score'], 'idx_quiz_partie_classement');
        });

        Schema::create('t_quiz_partie_question', function (Blueprint $t) {
            $t->charset('utf8mb4');
            $t->collation(self::COLLATION);
            $t->id();
            $t->unsignedBigInteger('partie_id');
            $t->unsignedTinyInteger('position');
            $t->unsignedInteger('question_id');
            $t->text('options')->comment('JSON : les trois réponses dans l\'ordre montré au joueur');
            $t->dateTime('posee_le', 3)->nullable();
            $t->dateTime('repondue_le', 3)->nullable();
            $t->string('reponse', 255)->nullable();
            $t->boolean('correcte')->nullable();
            $t->unique(['partie_id', 'position'], 'uq_quiz_partie_position');
            $t->foreign('partie_id', 'fk_quiz_pq_partie')->references('id')->on('t_quiz_partie')->cascadeOnDelete();
            $t->foreign('question_id', 'fk_quiz_pq_question')->references('id')->on('t_quiz_question');
        });

        Schema::create('t_quiz_joueur', function (Blueprint $t) {
            $t->charset('utf8mb4');
            $t->collation(self::COLLATION);
            $t->string('student_id', 200)->primary();
            $t->boolean('classement_visible')->default(true)->comment('0 = le joueur ne paraît pas au classement des autres');
            $t->dateTime('modifie_le')->nullable();
        });
    }

    public function down(): void
    {
        // Ordre inverse des dépendances.
        Schema::dropIfExists('t_quiz_partie_question');
        Schema::dropIfExists('t_quiz_partie');
        Schema::dropIfExists('t_quiz_joueur');
        Schema::dropIfExists('t_quiz_question');
    }
};
