<?php

namespace App\Console\Commands;

use App\Domain\News\SiteNewsSync;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('news:sync {--force : retélécharger tous les articles, même inchangés} {--dry-run : lire le site et dire ce qui changerait, sans rien écrire}')]
#[Description('Synchronise les actualités avec le site public de l\'université (zurcher.edu.mg/actualites).')]
class SyncSiteNews extends Command
{
    public function handle(SiteNewsSync $sync): int
    {
        $dry = (bool) $this->option('dry-run');
        $r = $sync->run((bool) $this->option('force'), write: ! $dry);

        $this->line(sprintf(
            '%sstatut : %s | listés : %d | nouveaux : %d | modifiés : %d | masqués : %d | erreurs : %d',
            $dry ? '[essai à blanc] ' : '', $r['status'], $r['seen'], $r['created'], $r['updated'], $r['hidden'], $r['errors'],
        ));
        if ($r['message'] !== null) {
            $this->warn($r['message']);
        }

        // Échec = code de sortie non nul (le planificateur et la supervision le voient) ; « partiel » reste un succès : la copie existante sert.
        return $r['status'] === 'failed' ? self::FAILURE : self::SUCCESS;
    }
}
