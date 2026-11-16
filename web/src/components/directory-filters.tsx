"use client";

import { X } from "lucide-react";
import { Card } from "@/components/ui/card";
import { activeChips, removeFilter, toggleFilter, type Facet, type Query } from "@/lib/directory";

/** Les filtres d'une liste : un groupe de cases par filtre, avec le nombre de lignes que chaque choix donnerait. */
export function FilterPanel({ facets, update }: { facets: Record<string, Facet>; update: (change: (q: Query) => Query) => void }) {
  return (
    <Card id="filtres" className="gap-4 p-4">
      {Object.entries(facets).map(([key, facet]) => (
        <fieldset key={key} className="grid gap-1">
          <legend className="mb-1 font-extrabold">{facet.label}</legend>
          {facet.options.length === 0 && <p className="text-sm text-muted-foreground">{facet.help ?? "Aucun choix."}</p>}
          <div className="flex flex-wrap gap-x-4 gap-y-1">
            {facet.options.map((o) => (
              <label key={o.value} className="flex min-h-11 items-center gap-2 text-sm md:min-h-9">
                <input type="checkbox" checked={o.on} onChange={() => update((q) => toggleFilter(q, key, o.value))} className="size-5 accent-[var(--primary)]" />
                <span>{o.label === "" ? "Non renseigné" : o.label}</span>
                <span className="text-muted-foreground tabular-nums">({o.count.toLocaleString("fr-FR")})</span>
              </label>
            ))}
          </div>
        </fieldset>
      ))}
    </Card>
  );
}

/** Les filtres actifs en pastilles retirables, et « Retirer les filtres » dès qu'il y en a plusieurs. */
export function FilterChips({ query, facets, update }: { query: Query; facets: Record<string, Facet>; update: (change: (q: Query) => Query) => void }) {
  const chips = activeChips(query, facets);
  if (chips.length === 0) return null;
  return (
    <ul aria-label="Filtres actifs" className="flex flex-wrap gap-2">
      {chips.map((c) => (
        <li key={`${c.key}:${c.value}`}>
          <button
            type="button"
            onClick={() => update((q) => toggleFilter(q, c.key, c.value))}
            aria-label={`Retirer le filtre ${c.group} : ${c.label}`}
            className="inline-flex min-h-9 items-center gap-1.5 rounded-full bg-accent px-3 text-sm font-semibold text-accent-text hover:bg-accent/70"
          >
            {c.label} <X className="size-3.5" aria-hidden />
          </button>
        </li>
      ))}
      {chips.length > 1 && (
        <li>
          <button type="button" onClick={() => update((q) => Object.keys(q.filters).reduce(removeFilter, q))} className="min-h-9 px-2 text-sm font-bold text-muted-foreground hover:underline">
            Retirer les filtres
          </button>
        </li>
      )}
    </ul>
  );
}
