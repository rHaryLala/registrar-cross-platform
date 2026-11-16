<?php

namespace App\Domain\Grading;

use Illuminate\Support\Facades\DB;

/**
 * Les colonnes d'une remise de notes et les notes de ses lignes, lues de la même façon par l'enseignant (saisie) et par le registrariat (validation).
 *
 * Une remise qui a des lignes dans `t_remise_notes_composante` suit cette structure libre ; sinon c'est celle de l'ancienne feuille (cinq colonnes fixes,
 * slots 1 à 5, lues dans `t_remise_notes.poids_*` et `t_remise_notes_ligne`).
 */
final class GradingLayout
{
    /** @return list<array{slot: int, label: string, weight: float}> */
    public static function of(?object $remise): array
    {
        if ($remise) {
            $rows = DB::table('t_remise_notes_composante')->where('remise_id', $remise->id)->orderBy('position')->orderBy('id')->get();
            if ($rows->isNotEmpty()) {
                return $rows->map(fn ($r) => ['slot' => (int) $r->slot, 'label' => (string) $r->libelle, 'weight' => (float) $r->poids])->all();
            }
            $weights = [];
            foreach (GradingRules::COMPONENTS as $meta) {
                $weights[$meta['weight']] = (float) $remise->{$meta['weight']};
            }

            return GradingRules::legacyComponents($weights);
        }

        return GradingRules::legacyComponents(GradingRules::DEFAULT_WEIGHTS);
    }

    /** La remise a-t-elle une structure libre enregistrée ? */
    public static function isCustom(object $remise): bool
    {
        return DB::table('t_remise_notes_composante')->where('remise_id', $remise->id)->exists();
    }
}
