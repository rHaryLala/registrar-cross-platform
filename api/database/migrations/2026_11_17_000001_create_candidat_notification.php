<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Notifications aux candidats (e-mail, SMS), en TÂCHES (docs/PLAN-PAGES lot 4).
 *
 * L'ancienne application envoyait le courriel puis le SMS PENDANT la requête de changement de statut : la page attendait le serveur de messagerie et
 * l'opérateur. Ici, changer le statut n'enregistre que la décision et pose une tâche par canal ; la commande planifiée `applications:notify`
 * (chaque minute, même `schedule:run` que le reste) les envoie, avec trois essais espacés. Rien à faire tourner de plus (pas de queue worker).
 *
 * `identifiants` : le courriel d'acceptation d'un candidat de MASTER, avec son adresse universitaire et son mot de passe, posé à la création de l'étudiant.
 *
 * AJOUT SEULEMENT : `candidat` n'est pas modifiée (`notifie_le` et `notifie_sms_le` y sont toujours posés à l'envoi, comme avant).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('t_candidat_notification', function (Blueprint $t) {
            $t->charset('utf8mb4');
            $t->collation('utf8mb4_general_ci');
            $t->increments('id');
            $t->integer('candidat_id')->index('idx_notif_candidat');
            $t->enum('canal', ['email', 'sms']);
            $t->enum('decision', ['accepte', 'refuse', 'identifiants']);
            $t->enum('statut', ['en_attente', 'envoyee', 'echec', 'ignoree'])->default('en_attente');
            $t->string('raison', 255)->nullable()->comment('pourquoi ignorée, ou dernière erreur');
            $t->unsignedTinyInteger('essais')->default(0);
            $t->dateTime('prochain_essai')->nullable()->comment('pas avant : espace les nouveaux essais');
            $t->dateTime('cree_le');
            $t->dateTime('traite_le')->nullable();
            $t->index(['statut', 'prochain_essai'], 'idx_notif_a_envoyer');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('t_candidat_notification');
    }
};
