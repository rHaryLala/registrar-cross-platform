<?php

namespace App\Console\Commands;

use App\Domain\Students\GoogleAdmin;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

#[Signature('students:google-accounts')]
#[Description('Crée les comptes Google Workspace des étudiants créés (trois essais espacés), sans jamais bloquer la création de l\'étudiant.')]
class CreateGoogleAccounts extends Command
{
    public function handle(): int
    {
        $now = Carbon::now();
        $stamp = $now->format('Y-m-d H:i:s');
        $result = ['created' => 0, 'existed' => 0, 'failed' => 0, 'retry' => 0];

        $due = DB::table('t_etudiant_creation')->where('google_statut', 'en_attente')
            ->where(fn ($q) => $q->whereNull('google_prochain_essai')->orWhere('google_prochain_essai', '<=', $stamp))->orderBy('id')->limit(30)->get();

        foreach ($due as $task) {
            // Réservation : une autre exécution ne la reprend pas pendant qu'elle part.
            $claimed = DB::table('t_etudiant_creation')->where('id', $task->id)->where('google_statut', 'en_attente')
                ->where(fn ($q) => $q->whereNull('google_prochain_essai')->orWhere('google_prochain_essai', '<=', $stamp))
                ->update(['google_prochain_essai' => $now->copy()->addMinutes(10)->format('Y-m-d H:i:s')]);
            if ($claimed === 0) {
                continue;
            }
            $student = DB::table('tbl_2024_etudiant')->where('id', $task->fiche_id)->first();
            if (! $student || ! $task->adresse) {
                DB::table('t_etudiant_creation')->where('id', $task->id)->update(['google_statut' => 'echec', 'google_raison' => 'Fiche ou adresse introuvable.', 'google_prochain_essai' => null]);
                $result['failed']++;

                continue;
            }
            $r = GoogleAdmin::createStudent([
                'address' => $task->adresse, 'last_name' => (string) $student->student_nom, 'first_name' => (string) $student->student_prenom, 'password' => (string) $student->password,
                'student_id' => (string) $student->student_id, 'mention' => (string) $student->etude_envisage, 'track' => (string) $student->etude_option,
                'phone' => (string) $student->student_tel, 'home' => (string) $student->student_adresse,
            ]);
            $attempts = $task->google_essais + 1;
            if ($r['created'] || $r['existed']) {
                DB::table('t_etudiant_creation')->where('id', $task->id)->update(['google_statut' => $r['created'] ? 'cree' : 'existait', 'google_raison' => $r['reason'], 'google_essais' => $attempts, 'google_prochain_essai' => null]);
                $result[$r['created'] ? 'created' : 'existed']++;
            } elseif ($attempts >= (int) config('registrar.google_admin.max_attempts')) {
                DB::table('t_etudiant_creation')->where('id', $task->id)->update(['google_statut' => 'echec', 'google_raison' => $r['reason'], 'google_essais' => $attempts, 'google_prochain_essai' => null]);
                $result['failed']++;
            } else {
                $wait = (int) (config('registrar.google_admin.retry_minutes')[$attempts - 1] ?? 30);
                DB::table('t_etudiant_creation')->where('id', $task->id)->update(['google_raison' => $r['reason'], 'google_essais' => $attempts, 'google_prochain_essai' => $now->copy()->addMinutes($wait)->format('Y-m-d H:i:s')]);
                $result['retry']++;
            }
        }
        $this->line("créés : {$result['created']} · déjà existants : {$result['existed']} · à retenter : {$result['retry']} · échecs : {$result['failed']}");

        return self::SUCCESS;
    }
}
