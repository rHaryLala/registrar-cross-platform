"use client";

import { useQuery } from "@tanstack/react-query";
import { Eye, EyeOff, TriangleAlert } from "lucide-react";
import { useRef, useState, type KeyboardEvent, type ReactNode } from "react";
import { AppShell } from "@/components/app-shell";
import { StatusChip } from "@/components/status-chip";
import { StudentPhotoImage } from "@/components/student-photo";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";
import { useEntrance } from "@/hooks/use-entrance";
import { api, ApiError } from "@/lib/api";
import { formatDate, maskTail } from "@/lib/format";
import type { Profile } from "@/lib/profile";
import { cn } from "@/lib/utils";

const TABS = [
  { id: "identite", label: "Identité et contact" },
  { id: "academique", label: "Études" },
  { id: "famille", label: "Famille" },
  { id: "garant", label: "Garant" },
] as const;
type TabId = (typeof TABS)[number]["id"];

export function StudentProfile() {
  const scope = useRef<HTMLDivElement>(null);
  const q = useQuery({
    queryKey: ["me", "profile"],
    queryFn: async () => (await api<{ data: Profile }>("/api/v1/me/profile")).data,
    // Une fiche absente (404) ne se corrige pas en réessayant.
    retry: (count, error) => !(error instanceof ApiError && error.status === 404) && count < 2,
  });
  useEntrance(scope, "[data-enter]", q.isSuccess);

  return (
    <AppShell title="Mes informations">
      <div ref={scope} className="mx-auto grid w-full max-w-3xl gap-5 p-4 md:p-6">
        {q.isPending && <ProfileSkeleton />}
        {q.isError && <ProfileError error={q.error} onRetry={() => q.refetch()} />}
        {q.isSuccess && <ProfileContent p={q.data} />}
      </div>
    </AppShell>
  );
}

function ProfileContent({ p }: { p: Profile }) {
  const [tab, setTab] = useState<TabId>("identite");
  const tabRefs = useRef<Record<string, HTMLButtonElement | null>>({});
  const fullName = [p.first_name, p.last_name?.toUpperCase()].filter(Boolean).join(" ") || p.student_id;
  const subtitle = [p.level_label, p.academic.mention].filter(Boolean).join(" — ");

  // Clavier : flèches gauche/droite, début et fin (motif « tabs » de WAI-ARIA) ; le focus suit l'onglet choisi.
  const onKey = (e: KeyboardEvent<HTMLButtonElement>) => {
    const i = TABS.findIndex((t) => t.id === tab);
    const next = e.key === "ArrowRight" ? (i + 1) % TABS.length : e.key === "ArrowLeft" ? (i - 1 + TABS.length) % TABS.length : e.key === "Home" ? 0 : e.key === "End" ? TABS.length - 1 : -1;
    if (next < 0) return;
    e.preventDefault();
    setTab(TABS[next].id);
    tabRefs.current[TABS[next].id]?.focus();
  };

  return (
    <>
      <Card data-enter className="gap-4 p-5">
        <div className="flex items-start gap-4">
          {p.has_photo ? <StudentPhotoImage /> : <Initials first={p.first_name} last={p.last_name} />}
          <div className="min-w-0">
            <h2 className="text-xl font-extrabold tracking-tight break-words">{fullName}</h2>
            <p className="text-sm text-muted-foreground">{subtitle}</p>
            <p className="mt-1 font-mono text-sm">{p.student_id}</p>
            <div className="mt-2 flex flex-wrap gap-2">
              <StatusChip tone="info">{p.student_type}</StatusChip>
              {p.academic.school_year && <StatusChip tone="info">{p.academic.school_year}</StatusChip>}
              {p.status.suspended && <StatusChip tone="bad">Suspendu</StatusChip>}
              {p.status.withdrawn && <StatusChip tone="bad">Retrait</StatusChip>}
            </div>
          </div>
        </div>
      </Card>

      {p.status.suspended && (
        <Card data-enter role="alert" className="flex-row items-start gap-3 border-destructive p-4">
          <TriangleAlert className="mt-0.5 size-5 shrink-0 text-destructive" aria-hidden />
          <p className="text-sm leading-relaxed">
            <strong>Compte suspendu</strong> — {p.status.suspension_reason ?? "aucun motif indiqué"}. Présentez-vous au bureau de la registraire.
          </p>
        </Card>
      )}

      <div data-enter role="tablist" aria-label="Rubriques de votre fiche" className="grid grid-cols-2 gap-1 rounded-xl border p-1 sm:grid-cols-4">
        {TABS.map((t) => (
          <button
            key={t.id}
            ref={(el) => {
              tabRefs.current[t.id] = el;
            }}
            id={`tab-${t.id}`}
            type="button"
            role="tab"
            aria-selected={tab === t.id}
            aria-controls={`panel-${t.id}`}
            tabIndex={tab === t.id ? 0 : -1}
            onClick={() => setTab(t.id)}
            onKeyDown={onKey}
            className="min-h-11 rounded-lg px-3 text-sm font-bold text-muted-foreground transition-colors aria-selected:bg-accent aria-selected:text-accent-text"
          >
            {t.label}
          </button>
        ))}
      </div>

      <div id={`panel-${tab}`} role="tabpanel" aria-labelledby={`tab-${tab}`} tabIndex={0} className="grid gap-5 outline-none">
        {tab === "identite" && <IdentityTab p={p} />}
        {tab === "academique" && <AcademicTab p={p} />}
        {tab === "famille" && <FamilyTab p={p} />}
        {tab === "garant" && <SponsorTab p={p} />}
      </div>

      <p data-enter className="text-xs text-muted-foreground">
        Une information est inexacte ? Elle ne peut pas être modifiée ici : demandez la correction au bureau de la registraire.
      </p>
    </>
  );
}

function IdentityTab({ p }: { p: Profile }) {
  const i = p.identity;
  return (
    <>
      <Section title="Identité">
        <Field label="Prénom" value={p.first_name} />
        <Field label="Nom" value={p.last_name} />
        <Field label="Date de naissance" value={i.birth_date ? formatDate(i.birth_date) : null} />
        <Field label="Lieu de naissance" value={i.birth_place} />
        <Field label="Sexe" value={i.sex} />
        <Field label="Nationalité" value={i.nationality} />
        <Field label="Pays d’origine" value={i.origin_country} wide />
      </Section>
      <Section title="Pièce d’identité (CIN)">
        <CinNumber value={p.id_document.cin_number} />
        <Field label="Date de délivrance" value={p.id_document.cin_date ? formatDate(p.id_document.cin_date) : null} />
        <Field label="Région de délivrance" value={p.id_document.cin_region} wide />
      </Section>
      <Section title="Contact">
        <Field label="Téléphone" value={p.contact.phone} href={p.contact.phone ? `tel:${p.contact.phone.replace(/[^\d+]/g, "")}` : undefined} />
        <Field label="Adresse e-mail" value={p.contact.email} href={p.contact.email ? `mailto:${p.contact.email}` : undefined} />
        <Field label="Adresse" value={p.contact.address} wide />
        <Field label="Région" value={p.contact.region} wide />
      </Section>
    </>
  );
}

function AcademicTab({ p }: { p: Profile }) {
  const a = p.academic;
  return (
    <Section title="Parcours académique">
      <Field label="Mention" value={a.mention} wide />
      <Field label="Parcours" value={a.track} wide />
      <Field label="Niveau" value={a.level_label} />
      <Field label="Année scolaire" value={a.school_year} />
      <Field label="Type d’étudiant" value={p.student_type} />
      <Field label="Hébergement" value={a.residence} />
      <Field label="Cantine" value={a.cafeteria ? "Abonné(e)" : "Non abonné(e)"} />
      <Field label="Religion" value={a.religion} />
    </Section>
  );
}

function FamilyTab({ p }: { p: Profile }) {
  const f = p.family;
  const c = p.civil;
  return (
    <>
      <Section title="Parents">
        <Field label="Père" value={f.father_name} />
        <Field label="Profession du père" value={f.father_job} />
        <Field label="Mère" value={f.mother_name} />
        <Field label="Profession de la mère" value={f.mother_job} />
        <Field label="Téléphone des parents" value={f.parents_phone} href={f.parents_phone ? `tel:${f.parents_phone.replace(/[^\d+]/g, "")}` : undefined} />
        <Field label="Adresse des parents" value={f.parents_address} wide />
      </Section>
      <Section title="Situation familiale">
        <Field label="Situation" value={c.situation} />
        <Field label="Conjoint(e)" value={c.spouse} />
        <Field label="Enfants" value={c.children} />
      </Section>
    </>
  );
}

function SponsorTab({ p }: { p: Profile }) {
  const s = p.sponsor;
  const none = !s.last_name && !s.first_name && !s.phone && !s.address;
  return (
    <Section title="Garant financier">
      {none ? (
        <p className="col-span-2 text-sm text-muted-foreground">Aucun garant financier n’est enregistré sur votre fiche.</p>
      ) : (
        <>
          <Field label="Nom" value={s.last_name} />
          <Field label="Prénom" value={s.first_name} />
          <Field label="Téléphone" value={s.phone} href={s.phone ? `tel:${s.phone.replace(/[^\d+]/g, "")}` : undefined} />
          <Field label="Adresse" value={s.address} wide />
        </>
      )}
    </Section>
  );
}

function Section({ title, children }: { title: string; children: ReactNode }) {
  return (
    <Card data-enter className="gap-0 p-0">
      <h3 className="border-b p-4 font-extrabold">{title}</h3>
      <dl className="grid grid-cols-2 gap-x-4 gap-y-4 p-4 text-sm">{children}</dl>
    </Card>
  );
}

function Field({ label, value, wide, href }: { label: string; value: string | null; wide?: boolean; href?: string }) {
  return (
    <div className={cn("min-w-0", wide && "col-span-2")}>
      <dt className="text-xs text-muted-foreground">{label}</dt>
      <dd className="font-bold break-words">
        {value === null ? (
          <span className="font-normal text-muted-foreground" aria-label="non renseigné">
            —
          </span>
        ) : href ? (
          <a href={href} className="inline-flex min-h-11 items-center text-accent-text underline-offset-4 hover:underline">
            {value}
          </a>
        ) : (
          value
        )}
      </dd>
    </div>
  );
}

/** Le numéro de CIN est caché par défaut (écran vu par-dessus l'épaule, capture d'écran) : un bouton le montre à la demande. */
function CinNumber({ value }: { value: string | null }) {
  const [shown, setShown] = useState(false);
  return (
    <div className="col-span-2 min-w-0">
      <dt className="text-xs text-muted-foreground">Numéro de CIN</dt>
      <dd className="flex items-center gap-2 font-bold">
        {value === null ? (
          <span className="font-normal text-muted-foreground" aria-label="non renseigné">
            —
          </span>
        ) : (
          <>
            <span className="font-mono break-all">{shown ? value : maskTail(value)}</span>
            <Button type="button" variant="ghost" size="icon" aria-pressed={shown} aria-label={shown ? "Masquer le numéro de CIN" : "Afficher le numéro de CIN"} onClick={() => setShown((s) => !s)}>
              {shown ? <EyeOff aria-hidden /> : <Eye aria-hidden />}
            </Button>
          </>
        )}
      </dd>
    </div>
  );
}

function Initials({ first, last }: { first: string | null; last: string | null }) {
  const letters = `${first?.[0] ?? ""}${last?.[0] ?? ""}`.toUpperCase() || "?";
  return (
    <div aria-hidden className="grid h-27 w-21 shrink-0 place-items-center rounded-lg border bg-muted text-2xl font-extrabold text-muted-foreground">
      {letters}
    </div>
  );
}

function ProfileSkeleton() {
  return (
    <div className="grid gap-5" aria-busy="true" aria-label="Chargement de votre fiche">
      <Skeleton className="h-40 w-full rounded-xl" />
      <Skeleton className="h-12 w-full rounded-xl" />
      <Skeleton className="h-64 w-full rounded-xl" />
    </div>
  );
}

function ProfileError({ error, onRetry }: { error: Error; onRetry: () => void }) {
  const offline = error instanceof ApiError && error.isOffline;
  const missing = error instanceof ApiError && error.status === 404;
  return (
    <Card role="alert" className="gap-3 p-4">
      <p className="font-bold">{offline ? "Vous êtes hors ligne" : missing ? "Fiche introuvable" : "Votre fiche n’a pas pu se charger"}</p>
      <p className="text-muted-foreground">{error instanceof ApiError && error.detail ? error.detail : "Réessayez dans un instant ; si cela continue, contactez le registrariat."}</p>
      {!missing && (
        <div>
          <Button onClick={onRetry}>Réessayer</Button>
        </div>
      )}
    </Card>
  );
}
