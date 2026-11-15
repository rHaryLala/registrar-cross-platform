<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('quiz:import-questions {--file= : fichier JSON (par défaut : database/data/quiz-questions.json)}')]
#[Description('Importe la banque de questions du Quiz UAZ. Idempotent : une question déjà présente est mise à jour, jamais dupliquée ; son état actif/inactif est conservé.')]
class ImportQuizQuestions extends Command
{
    public function handle(): int
    {
        $file = $this->option('file') ?: database_path('data/quiz-questions.json');
        if (! is_file($file)) {
            $this->error("Fichier introuvable : {$file}");

            return self::FAILURE;
        }

        $items = json_decode((string) file_get_contents($file), true);
        if (! is_array($items)) {
            $this->error('JSON invalide.');

            return self::FAILURE;
        }

        $created = $updated = 0;
        foreach ($items as $i => $q) {
            if (count($q['mauvaises_reponses'] ?? []) !== 2 || empty($q['question']) || empty($q['bonne_reponse']) || empty($q['empreinte'])) {
                $this->error('Question #'.($i + 1).' invalide : 1 bonne et exactement 2 mauvaises réponses sont requises.');

                return self::FAILURE;
            }

            $row = [
                'categorie' => $q['categorie'], 'question' => $q['question'], 'bonne_reponse' => $q['bonne_reponse'],
                'mauvaise_1' => $q['mauvaises_reponses'][0], 'mauvaise_2' => $q['mauvaises_reponses'][1],
                'icone' => $q['icone'] ?? null, 'indice' => $q['indice'] ?? null,
            ];

            $exists = DB::table('t_quiz_question')->where('empreinte', $q['empreinte'])->exists();
            if ($exists) {
                DB::table('t_quiz_question')->where('empreinte', $q['empreinte'])->update($row); // `actif` n'est pas touché
                $updated++;
            } else {
                DB::table('t_quiz_question')->insert($row + ['empreinte' => $q['empreinte'], 'actif' => 1]);
                $created++;
            }
        }

        $this->info("Questions : {$created} créée(s), {$updated} mise(s) à jour.");

        return self::SUCCESS;
    }
}
