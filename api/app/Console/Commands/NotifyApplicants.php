<?php

namespace App\Console\Commands;

use App\Domain\Admissions\Notifier;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('applications:notify')]
#[Description('Envoie aux candidats les courriels et SMS de décision (acceptation, refus) posés par le registrariat.')]
class NotifyApplicants extends Command
{
    public function handle(Notifier $notifier): int
    {
        $r = $notifier->dispatchDue();
        $this->line("envoyés : {$r['sent']} · à retenter : {$r['retry']} · échecs : {$r['failed']} · ignorés : {$r['skipped']}");

        return self::SUCCESS;
    }
}
