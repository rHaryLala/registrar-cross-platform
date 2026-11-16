"use client";

import { useQuery } from "@tanstack/react-query";
import { ArrowDownRight, ArrowRight, ArrowUpRight, ChevronRight, CircleCheck, ClipboardCheck, Files, Inbox, PencilLine, UserRoundX, UserRoundSearch } from "lucide-react";
import Link from "next/link";
import { useState } from "react";
import { LoadError } from "@/components/teacher-common";
import { Card } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";
import { api } from "@/lib/api";
import { accord, courseLink, dashboardKeys, dashboardUrl, nombre, share, shortYear, split, toDoText, trend, type Breakdown, type Dashboard, type ToDoItem, type Trend } from "@/lib/dashboard";
import { cn } from "@/lib/utils";

const ICONS = { reviews: ClipboardCheck, flags: UserRoundX, applications: Inbox, ungraded: PencilLine, no_teacher: UserRoundSearch, duplicate_codes: Files } as const;

/** Tableau de bord du personnel : ce qui attend quelqu'un aujourd'hui, puis les chiffres de l'année. */
export function StaffDashboard() {
  const [year, setYear] = useState<string | null>(null);
  const q = useQuery({
    queryKey: dashboardKeys.one(year),
    queryFn: async () => (await api<{ data: Dashboard }>(dashboardUrl(year))).data,
    retry: 1,
    staleTime: 30_000,
  });
  const d = q.data;

  if (q.isPending)
    return (
      <div className="grid gap-3" aria-busy="true" aria-label="Chargement du tableau de bord">
        <Skeleton className="h-24 w-full rounded-xl" />
        <Skeleton className="h-40 w-full rounded-xl" />
      </div>
    );
  if (q.isError) return <LoadError error={q.error} onRetry={() => void q.refetch()} what="Le tableau de bord" />;
  if (!d) return null;

  return (
    <div className="grid gap-6">
      {d.to_do.length > 0 || d.figures.applications !== null ? <ToDo items={d.to_do} /> : null}
      <Figures d={d} onYear={setYear} />
    </div>
  );
}

function ToDo({ items }: { items: ToDoItem[] }) {
  return (
    <section aria-labelledby="a-traiter" className="grid gap-3">
      <h3 id="a-traiter" className="flex items-center gap-2 text-xl font-extrabold tracking-tight">
        À traiter
        {items.length > 0 && <span className="rounded-full bg-primary px-2.5 py-0.5 text-sm font-bold text-primary-foreground tabular-nums">{items.length}</span>}
      </h3>
      {items.length === 0 ? (
        <Card className="flex-row items-center gap-3 p-4 text-sm text-muted-foreground">
          <CircleCheck className="size-5 shrink-0 text-success" aria-hidden />
          <p>Rien n’attend pour le moment.</p>
        </Card>
      ) : (
        <ul className="grid gap-3">
          {items.map((item) => (
            <ToDoCard key={item.key} item={item} />
          ))}
        </ul>
      )}
    </section>
  );
}

function ToDoCard({ item }: { item: ToDoItem }) {
  const Icon = ICONS[item.key];
  const { title, detail } = toDoText(item);
  const link = "link" in item ? item.link : null;
  const body = (
    <>
      <span className={cn("grid size-10 shrink-0 place-items-center rounded-lg", item.tone === "attention" ? "bg-warning/15 text-warning" : "bg-accent text-accent-text")}>
        <Icon className="size-5" aria-hidden />
      </span>
      <span className="min-w-0 flex-1">
        <span className="block text-lg leading-snug font-extrabold tracking-tight">{title}</span>
        {detail && <span className="block text-sm text-muted-foreground">{detail}</span>}
      </span>
      {link && <ChevronRight className="size-5 shrink-0 text-muted-foreground" aria-hidden />}
    </>
  );
  return (
    <li>
      <Card className="gap-0 p-0">
        {link ? (
          <Link href={link} className="flex items-center gap-3 rounded-xl p-4 transition-colors hover:bg-accent focus-visible:ring-2 focus-visible:ring-ring">
            {body}
          </Link>
        ) : (
          <div className="flex items-center gap-3 p-4">{body}</div>
        )}
        <SubList item={item} />
      </Card>
    </li>
  );
}

function SubList({ item }: { item: ToDoItem }) {
  if (item.key === "ungraded")
    return (
      <ul className="divide-y border-t">
        {item.list.map((c) => (
          <Row key={`${c.course_id}-${c.code}`} href={courseLink(c.course_id)} value={String(c.count)} valueLabel="notes">
            <span className="font-mono text-sm font-semibold text-accent-text">{c.code}</span> {c.title}
            {c.teacher && <span className="text-muted-foreground"> — {c.teacher}</span>}
          </Row>
        ))}
      </ul>
    );
  if (item.key === "no_teacher")
    return (
      <ul className="divide-y border-t">
        {item.list.map((c) => (
          <Row key={`${c.course_id}-${c.session}`} href={courseLink(c.course_id)}>
            <span className="font-mono text-sm font-semibold text-accent-text">{c.code}</span> {c.title} <span className="text-muted-foreground">({c.session})</span>
          </Row>
        ))}
      </ul>
    );
  if (item.key === "duplicate_codes")
    return (
      <ul className="divide-y border-t">
        {item.list.map((c) => (
          <Row key={c.code} href={courseLink(c.course_ids[0] ?? null)} value={String(c.course_ids.length)} valueLabel="cours">
            <span className="font-mono text-sm font-semibold text-accent-text">{c.code}</span> <span className="text-muted-foreground">· cours {c.course_ids.join(", ")}</span>
          </Row>
        ))}
      </ul>
    );
  return null;
}

function Row({ href, value, valueLabel, children }: { href: string | null; value?: string; valueLabel?: string; children: React.ReactNode }) {
  const inner = (
    <>
      <span className="min-w-0 flex-1 text-sm">{children}</span>
      {value && (
        <b className="shrink-0 tabular-nums">
          {value}
          <span className="sr-only"> {valueLabel}</span>
        </b>
      )}
    </>
  );
  return <li>{href ? <Link href={href} className="flex min-h-11 items-center gap-3 px-4 py-2 transition-colors hover:bg-accent">{inner}</Link> : <div className="flex min-h-11 items-center gap-3 px-4 py-2">{inner}</div>}</li>;
}

// ---------------------------------------------------------------- chiffres clés

function TrendBadge({ t, versus }: { t: Trend; versus: string }) {
  const Icon = t.sign === "up" ? ArrowUpRight : t.sign === "down" ? ArrowDownRight : ArrowRight;
  return (
    <span className="inline-flex items-center gap-1 text-sm font-semibold text-muted-foreground">
      <Icon className="size-4" aria-hidden />
      <span className="tabular-nums">{t.text}</span> {versus}
    </span>
  );
}

function Figures({ d, onYear }: { d: Dashboard; onYear: (y: string) => void }) {
  const f = d.figures;
  const c = f.current;
  const t = d.year;
  const prev = shortYear(d.previous);
  const compareSameDate = d.is_current && f.same_date !== null;
  const versus = compareSameDate ? trend(c.enrolled, f.same_date ?? 0) : trend(c.enrolled, f.before.enrolled);
  const max = Math.max(1, ...f.mentions.map((m) => Math.max(m.count, m.before)));

  return (
    <section aria-labelledby="chiffres" className="grid gap-3">
      <div className="flex flex-wrap items-end justify-between gap-3">
        <h3 id="chiffres" className="text-xl font-extrabold tracking-tight">
          Chiffres clés
        </h3>
        <label className="grid gap-1 text-sm font-semibold">
          Année scolaire
          <select value={d.year} onChange={(e) => onYear(e.target.value)} className="h-11 rounded-lg border border-input bg-background px-3 text-base md:h-10 md:text-sm">
            {d.years.map((y) => (
              <option key={y} value={y}>
                {y}
              </option>
            ))}
          </select>
        </label>
      </div>

      <div className="grid gap-3 sm:grid-cols-2">
        <Card className="gap-1 p-4">
          <p className="text-sm font-bold text-muted-foreground">Inscrits {shortYear(t)}</p>
          <p className="text-4xl font-extrabold tracking-tight tabular-nums">{nombre(c.enrolled)}</p>
          <TrendBadge t={versus} versus={compareSameDate ? `à la même date en ${prev}` : `par rapport à ${prev}`} />
          {d.is_current && <p className="text-xs text-muted-foreground">Toute l’année {prev} : {nombre(f.before.enrolled)}</p>}
        </Card>
        <Card className="gap-1 p-4">
          <p className="text-sm font-bold text-muted-foreground">Nouveaux étudiants</p>
          <p className="text-4xl font-extrabold tracking-tight tabular-nums">{nombre(c.new)}</p>
          <p className="text-sm text-muted-foreground">
            {share(c.new, c.enrolled)} % des inscrits · <TrendBadge t={trend(c.new, f.before.new)} versus={`par rapport à ${prev}`} />
          </p>
        </Card>
        {f.applications && (
          <Card className="gap-1 p-4 sm:col-span-2">
            <p className="text-sm font-bold text-muted-foreground">Candidatures reçues ({shortYear(t)})</p>
            <p className="text-3xl font-extrabold tracking-tight tabular-nums">
              {nombre(f.applications.received)} <span className="text-base font-semibold text-muted-foreground">dont {accord(f.applications.accepted, "acceptée", "acceptées")}</span>
            </p>
            <TrendBadge t={trend(f.applications.received, f.applications.before)} versus={`par rapport à ${prev}`} />
          </Card>
        )}
      </div>

      {c.enrolled > 0 ? (
        <Card className="gap-5 p-4">
          <h4 className="text-lg font-extrabold tracking-tight">Répartition des inscrits</h4>
          <Part title="Cycle" total={c.enrolled} parts={split(c.enrolled, [["Licence", c.bachelor, "a"], ["Master", c.master, "b"]])} />
          <Part title="Sexe" total={c.enrolled} parts={split(c.enrolled, [["Femmes", c.women, "a"], ["Hommes", c.men, "b"]])} />
          <Part title="Hébergement" total={c.enrolled} parts={split(c.enrolled, [["Interne", c.boarding, "a"], ["Bungalow", c.bungalow, "b"], ["Externe", c.external, "c"]])} />
        </Card>
      ) : (
        <Card className="p-4 text-sm text-muted-foreground">Aucun inscrit en {shortYear(t)} pour le moment.</Card>
      )}

      {f.mentions.length > 0 && (
        <Card className="gap-3 p-4">
          <div>
            <h4 className="text-lg font-extrabold tracking-tight">Inscrits par mention</h4>
            <p className="text-sm text-muted-foreground">
              {shortYear(t)} · barre claire et chiffre gris : {d.is_current ? `toute l’année ${prev}` : prev}
            </p>
          </div>
          <ul className="grid gap-3">
            {f.mentions.map((m) => (
              <li key={m.code} className="grid gap-1">
                <div className="flex items-baseline justify-between gap-3">
                  <span className="min-w-0 font-semibold">{m.label}</span>
                  <span className="shrink-0 tabular-nums">
                    <b>{nombre(m.count)}</b> <span className="text-muted-foreground">/ {nombre(m.before)}</span>
                  </span>
                </div>
                <div className="relative h-2 rounded-full bg-muted" aria-hidden>
                  <div className="absolute inset-y-0 left-0 rounded-full bg-accent-text/30" style={{ width: `${(m.before / max) * 100}%` }} />
                  <div className="absolute inset-y-0 left-0 rounded-full bg-primary" style={{ width: `${(m.count / max) * 100}%` }} />
                </div>
              </li>
            ))}
          </ul>
        </Card>
      )}
    </section>
  );
}

const TONE: Record<string, string> = { a: "bg-primary", b: "bg-accent-text", c: "bg-success", x: "bg-muted-foreground/50" };

function Part({ title, total, parts }: { title: string; total: number; parts: ReturnType<typeof split> }) {
  if (parts.length === 0) return null;
  return (
    <div className="grid gap-1.5">
      <p className="text-sm font-bold text-muted-foreground">{title}</p>
      <div className="flex h-3 overflow-hidden rounded-full bg-muted" role="img" aria-label={`${title} : ${parts.map((p) => `${p.label} ${p.pct} %`).join(", ")}`}>
        {parts.map((p) => (
          <div key={p.label} className={TONE[p.tone] ?? "bg-muted-foreground"} style={{ flexGrow: p.n }} />
        ))}
      </div>
      <ul className="flex flex-wrap gap-x-4 gap-y-1 text-sm">
        {parts.map((p) => (
          <li key={p.label} className="flex items-center gap-1.5">
            <span aria-hidden className={cn("size-2.5 rounded-full", TONE[p.tone] ?? "bg-muted-foreground")} />
            {p.label} <b className="tabular-nums">{nombre(p.n)}</b>
            <span className="text-muted-foreground tabular-nums">({share(p.n, total)} %)</span>
          </li>
        ))}
      </ul>
    </div>
  );
}

export type { Breakdown };
