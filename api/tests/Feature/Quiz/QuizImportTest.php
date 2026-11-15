<?php

namespace Tests\Feature\Quiz;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class QuizImportTest extends TestCase
{
    public function test_import_is_idempotent_and_keeps_the_active_flag(): void
    {
        $this->artisan('quiz:import-questions')->assertSuccessful();
        $count = DB::table('t_quiz_question')->count();
        $this->assertGreaterThanOrEqual(50, $count);

        // Une question désactivée à la main le reste après une nouvelle importation.
        $id = DB::table('t_quiz_question')->value('id');
        DB::table('t_quiz_question')->where('id', $id)->update(['actif' => 0, 'indice' => 'modifié à la main']);

        $this->artisan('quiz:import-questions')->assertSuccessful();

        $this->assertSame($count, DB::table('t_quiz_question')->count());
        $row = DB::table('t_quiz_question')->find($id);
        $this->assertSame(0, (int) $row->actif);
        $this->assertNotSame('modifié à la main', $row->indice); // le contenu, lui, est resynchronisé avec le fichier
    }

    public function test_every_imported_question_has_one_right_answer_and_two_distinct_wrong_ones(): void
    {
        $this->artisan('quiz:import-questions')->assertSuccessful();

        foreach (DB::table('t_quiz_question')->get() as $q) {
            $set = [$q->bonne_reponse, $q->mauvaise_1, $q->mauvaise_2];
            $this->assertCount(3, array_unique($set), "Réponses en double : {$q->question}");
            $this->assertNotSame('', trim($q->question));
        }
    }

    public function test_an_invalid_file_is_refused_without_writing_anything(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'quiz');
        file_put_contents($file, json_encode([['categorie' => 'x', 'question' => 'Q ?', 'bonne_reponse' => 'a', 'mauvaises_reponses' => ['b'], 'empreinte' => 'abc']]));

        $this->artisan('quiz:import-questions', ['--file' => $file])->assertFailed();
        $this->assertSame(0, DB::table('t_quiz_question')->count());
        unlink($file);

        $this->artisan('quiz:import-questions', ['--file' => '/n/existe/pas.json'])->assertFailed();
    }
}
