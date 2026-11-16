<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Les trois tables des frais supplémentaires du barème (`data/migration_frais_perso.sql` de l'ancienne application, qui les créait aussi à la première ouverture
 * de la page). Rejouable : chaque table n'est créée que si elle manque ; une table qui existe déjà (créée par l'ancienne application) n'est jamais modifiée.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('t_frais_perso')) {
            Schema::create('t_frais_perso', function (Blueprint $t) {
                $t->increments('id');
                $t->string('libelle', 120);
                $t->tinyInteger('actif')->default(1);
                $t->integer('ordre')->default(0);
                $t->string('condition_frais', 20)->default('aucune');
                $t->string('semestres', 20)->default('');
                $t->dateTime('date_entry')->nullable();
                $t->integer('user_id')->nullable();
                $t->unique('libelle', 'uk_frais_perso_libelle');
            });
        }
        if (! Schema::hasTable('t_frais_perso_montant')) {
            Schema::create('t_frais_perso_montant', function (Blueprint $t) {
                $t->increments('id');
                $t->integer('frais_id');
                $t->integer('session_id')->default(0);
                $t->string('std_mention', 20);
                $t->integer('level');
                $t->string('std_status', 20);
                $t->integer('semester');
                $t->double('montant')->default(0);
                $t->unique(['frais_id', 'session_id', 'std_mention', 'level', 'std_status', 'semester'], 'uk_frais_perso_cle');
                $t->index(['session_id', 'std_mention', 'semester'], 'idx_frais_perso_session');
            });
        }
        if (! Schema::hasTable('t_2024_etudiant_frais_perso')) {
            Schema::create('t_2024_etudiant_frais_perso', function (Blueprint $t) {
                $t->increments('id');
                $t->string('student_id', 50);
                $t->integer('session_id');
                $t->integer('frais_id');
                $t->string('libelle', 120);
                $t->double('montant')->default(0);
                $t->tinyInteger('remove')->default(0);
                $t->dateTime('date_entry')->nullable();
                $t->integer('last_change_user_id')->nullable();
                $t->unique(['student_id', 'session_id', 'frais_id'], 'uk_etudiant_frais');
                $t->index(['student_id', 'session_id'], 'idx_etudiant_dossier');
            });
        }
    }

    public function down(): void
    {
        // Les tables peuvent venir de l'ancienne application et contenir des factures : aucune suppression ici.
    }
};
