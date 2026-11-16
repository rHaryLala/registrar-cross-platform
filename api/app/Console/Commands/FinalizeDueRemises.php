<?php

namespace App\Console\Commands;

use App\Domain\Grading\RemiseReview;
use App\Models\User;
use App\Support\AuditTrail;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Http\Request;

#[Signature('remises:finalize-due')]
#[Description('Valide les remises de notes dont le délai d\'annulation de 10 secondes est passé (onglet du registrariat fermé entre-temps).')]
class FinalizeDueRemises extends Command
{
    public function handle(RemiseReview $review, AuditTrail $audit): int
    {
        $done = $review->finalizeDue(function (int $remiseId, ?int $actorId) use ($audit) {
            // Même journal d'activité que la validation faite depuis l'écran : au nom du compte qui l'avait demandée.
            $request = Request::create('/commande/remises:finalize-due', 'POST');
            $audit->commit($request, $actorId ? User::find($actorId) : null, 'Validation des notes · validation (différée)', 200, ['remise_id' => $remiseId]);
        });
        $this->line("remises validées : $done");

        return self::SUCCESS;
    }
}
