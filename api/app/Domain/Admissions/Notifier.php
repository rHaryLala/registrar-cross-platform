<?php

namespace App\Domain\Admissions;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Mail\Message;

/**
 * Envoie les notifications aux candidats posées par `Applications::setStatus` (commande planifiée `applications:notify`, chaque minute).
 *
 * Chaque tâche est « réservée » avant l'envoi (un second passage ne la reprend pas pendant qu'elle part) ; un envoi qui échoue est retenté après 1, 5
 * puis 30 minutes, puis marqué « échec » (le registrariat peut le relancer). Si la décision a changé entre-temps, rien ne part.
 */
final class Notifier
{
    /** @return array{sent: int, failed: int, retry: int, skipped: int} */
    public function dispatchDue(?int $limit = 50): array
    {
        $result = ['sent' => 0, 'failed' => 0, 'retry' => 0, 'skipped' => 0];
        $now = Carbon::now();
        $due = DB::table('t_candidat_notification')->where('statut', 'en_attente')
            ->where(fn ($q) => $q->whereNull('prochain_essai')->orWhere('prochain_essai', '<=', $now->format('Y-m-d H:i:s')))
            ->orderBy('id')->limit($limit ?? 50)->get();

        foreach ($due as $task) {
            // Réservation : si une autre exécution l'a prise entre-temps, on passe.
            $claimed = DB::table('t_candidat_notification')->where('id', $task->id)->where('statut', 'en_attente')
                ->where(fn ($q) => $q->whereNull('prochain_essai')->orWhere('prochain_essai', '<=', $now->format('Y-m-d H:i:s')))
                ->update(['prochain_essai' => $now->copy()->addMinutes(10)->format('Y-m-d H:i:s')]);
            if ($claimed === 0) {
                continue;
            }
            $outcome = $this->handle($task);
            $result[$outcome]++;
        }

        return $result;
    }

    /** @return 'sent'|'failed'|'retry'|'skipped' */
    private function handle(object $task): string
    {
        $c = DB::table('candidat')->where('id', $task->candidat_id)->first();
        $expected = $task->decision === 'identifiants' ? 'converti' : $task->decision;   // le courriel Master part une fois la candidature convertie
        if (! $c || $c->statut !== $expected) {
            return $this->skip($task, 'Décision modifiée avant l’envoi.');
        }
        $candidate = (array) $c;

        try {
            if ($task->canal === 'email') {
                if ($task->decision === 'identifiants') {
                    $built = $this->identifiers($candidate);
                    if ($built === 'wait') {
                        // Le compte Google est en cours de création : on attend son issue pour dire au candidat si l'adresse est déjà active.
                        DB::table('t_candidat_notification')->where('id', $task->id)->update(['prochain_essai' => Carbon::now()->addMinute()->format('Y-m-d H:i:s')]);

                        return 'retry';
                    }
                    $message = $built;
                } else {
                    $message = Messages::email($candidate, $task->decision);
                }
                $to = trim((string) ($candidate['email'] ?? ''));
                if ($message === null || $to === '' || ! filter_var($to, FILTER_VALIDATE_EMAIL)) {
                    return $this->skip($task, 'Adresse email du candidat absente ou invalide.');
                }
                Mail::send([], [], function (Message $m) use ($to, $message) {
                    $m->to($to)->subject($message['subject'])->html($message['html'])->text($message['text']);
                });
                $ok = true;
                $why = null;
            } else {
                $text = Messages::sms($candidate, $task->decision);
                if ($text === null || Sms::normalize($candidate['telephone'] ?? null) === null) {
                    return $this->skip($task, 'Numéro de téléphone absent ou inexploitable.');
                }
                ['ok' => $ok, 'message' => $why] = Sms::send($candidate['telephone'], $text);
            }
        } catch (\Throwable $e) {
            $ok = false;
            $why = $task->canal === 'email' ? 'Le courriel n’a pas pu être envoyé.' : 'Le SMS n’a pas pu être envoyé.';
            report($e);
        }

        $now = Carbon::now();
        if ($ok) {
            DB::table('t_candidat_notification')->where('id', $task->id)->update(['statut' => 'envoyee', 'raison' => null, 'essais' => $task->essais + 1, 'prochain_essai' => null, 'traite_le' => $now->format('Y-m-d H:i:s')]);
            DB::table('candidat')->where('id', $task->candidat_id)->update([$task->canal === 'email' ? 'notifie_le' : 'notifie_sms_le' => $now->format('Y-m-d H:i:s')]);

            return 'sent';
        }

        $attempts = $task->essais + 1;
        $max = (int) config('registrar.admissions.max_attempts');
        if ($attempts >= $max) {
            DB::table('t_candidat_notification')->where('id', $task->id)->update(['statut' => 'echec', 'raison' => $why, 'essais' => $attempts, 'prochain_essai' => null, 'traite_le' => $now->format('Y-m-d H:i:s')]);

            return 'failed';
        }
        $wait = (int) (config('registrar.admissions.retry_minutes')[$attempts - 1] ?? 30);
        DB::table('t_candidat_notification')->where('id', $task->id)->update(['raison' => $why, 'essais' => $attempts, 'prochain_essai' => $now->copy()->addMinutes($wait)->format('Y-m-d H:i:s')]);

        return 'retry';
    }

    /**
     * Le courriel d'acceptation d'un candidat de Master : son adresse universitaire et son mot de passe viennent de la fiche créée ; la phrase « active dès
     * maintenant » dépend de l'issue du compte Google. 'wait' : le compte est encore en cours de création.
     *
     * @return array{subject: string, html: string, text: string}|string|null
     */
    private function identifiers(array $candidate): array|string|null
    {
        $student = DB::table('tbl_2024_etudiant')->where('student_id', $candidate['student_id'])->orderBy('remove')->orderBy('id')->first();
        if (! $student || trim((string) $student->student_email) === '' || trim((string) $student->password) === '') {
            return null;
        }
        $google = DB::table('t_etudiant_creation')->where('fiche_id', $student->id)->orderByDesc('id')->value('google_statut');
        if ($google === 'en_attente') {
            return 'wait';
        }

        return Messages::identifiers($candidate, (string) $student->student_email, (string) $student->password, match ($google) {'cree' => true, 'echec' => false, default => null});
    }

    /** Ne part pas : la tâche est close « ignorée », avec la raison. */
    private function skip(object $task, string $reason): string
    {
        DB::table('t_candidat_notification')->where('id', $task->id)->update(['statut' => 'ignoree', 'raison' => $reason, 'prochain_essai' => null, 'traite_le' => Carbon::now()->format('Y-m-d H:i:s')]);

        return 'skipped';
    }
}
