"use client";

import { useQuery } from "@tanstack/react-query";
import { ArrowLeft } from "lucide-react";
import Link from "next/link";
import { useSearchParams } from "next/navigation";
import type { ReactNode } from "react";
import { AppShell } from "@/components/app-shell";
import { StatusChip } from "@/components/status-chip";
import { StudentDocuments } from "@/components/student-documents";
import { LoadError } from "@/components/teacher-common";
import { Card } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";
import { api, ApiError } from "@/lib/api";
import { directoryKeys, fileDate, fileUrl, ficheUrl, parseFileId, personName, type CourseFile, type DirectoryKind, type StudentFile, type TeacherFile } from "@/lib/directory";
import { plural } from "@/lib/format";

const BACK: Record<DirectoryKind, { href: string; label: string; title: string }> = {
  students: { href: "/etudiants/", label: "Les étudiants", title: "Fiche étudiant" },
  courses: { href: "/cours/", label: "Les cours", title: "Fiche cours" },
  teachers: { href: "/enseignants/", label: "Les enseignants", title: "Fiche enseignant" },
};

/** La fiche (lecture seule) d'un étudiant, d'un cours ou d'un enseignant. La modification reste dans l'ancienne version. */
export function DirectoryFiche({ kind }: { kind: DirectoryKind }) {
  const id = parseFileId(useSearchParams().get("id"));
  const q = useQuery({
    queryKey: directoryKeys.one(kind, id ?? 0),
    queryFn: async () => (await api<{ data: StudentFile | CourseFile | TeacherFile }>(fileUrl(kind, id as number))).data,
    enabled: id !== null,
    retry: (n, e) => !(e instanceof ApiError && e.status >= 400 && e.status < 500) && n < 1,
  });
  const back = BACK[kind];

  return (
    <AppShell title={back.title}>
      <div className="mx-auto grid w-full max-w-3xl gap-4 p-4 md:p-6">
        <Link href={back.href} className="inline-flex min-h-11 w-fit items-center gap-1.5 text-sm font-bold text-accent-text hover:underline">
          <ArrowLeft className="size-4" aria-hidden /> {back.label}
        </Link>
        {id === null && <LoadError error={new ApiError(404, "directory.not_found", "Fiche introuvable", "Adresse incomplète : ouvrez la fiche depuis la liste.")} onRetry={() => undefined} what="La fiche" />}
        {id !== null && q.isPending && (
          <div className="grid gap-3" aria-busy="true" aria-label="Chargement de la fiche">
            <Skeleton className="h-28 w-full rounded-xl" />
            <Skeleton className="h-40 w-full rounded-xl" />
          </div>
        )}
        {q.isError && <LoadError error={q.error} onRetry={() => void q.refetch()} what="La fiche" />}
        {q.data && kind === "students" && <StudentSheet f={q.data as StudentFile} />}
        {q.data && kind === "courses" && <CourseSheet f={q.data as CourseFile} />}
        {q.data && kind === "teachers" && <TeacherSheet f={q.data as TeacherFile} />}
        {q.data && (
          <p className="text-sm text-muted-foreground">Fiche en lecture seule. Pour la modifier, utilisez l’ancienne version de l’application.</p>
        )}
      </div>
    </AppShell>
  );
}

function Section({ title, children }: { title: string; children: ReactNode }) {
  return (
    <Card className="gap-3 p-4">
      <h3 className="text-lg font-extrabold tracking-tight">{title}</h3>
      {children}
    </Card>
  );
}

/** Une liste de champs : un champ vide n'est pas affiché (jamais de ligne « — » à lire). */
function Fields({ rows }: { rows: [string, ReactNode][] }) {
  const shown = rows.filter(([, v]) => v !== null && v !== undefined && v !== "");
  if (shown.length === 0) return <p className="text-sm text-muted-foreground">Rien n’est renseigné.</p>;
  return (
    <dl className="grid gap-x-6 gap-y-2 sm:grid-cols-2">
      {shown.map(([label, value]) => (
        <div key={label} className="min-w-0">
          <dt className="text-xs font-bold tracking-wide text-muted-foreground uppercase">{label}</dt>
          <dd className="break-words">{value}</dd>
        </div>
      ))}
    </dl>
  );
}

function Header({ code, title, chips }: { code: string; title: string; chips?: ReactNode }) {
  return (
    <Card className="gap-1 p-4">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <p className="font-mono text-sm font-semibold text-accent-text">{code}</p>
        <span className="flex flex-wrap gap-1.5">{chips}</span>
      </div>
      <h2 className="text-2xl leading-tight font-extrabold tracking-tight">{title}</h2>
    </Card>
  );
}

/** Comme l’ancienne fiche : 0 = Féminin, toute autre valeur = Masculin ; rien de saisi = rien d’affiché. */
function sexLabel(sex: string | number | null): string | null {
  if (sex === null || sex === "") return null;
  return String(sex) === "0" ? "Féminin" : "Masculin";
}

function StudentSheet({ f }: { f: StudentFile }) {
  const s = f.status;
  return (
    <>
      <Header
        code={f.student_id}
        title={personName(f.last_name, f.first_name) || "Sans nom"}
        chips={
          <>
            {f.is_new && <StatusChip tone="info">Nouveau</StatusChip>}
            {s.suspended && <StatusChip tone="bad">Suspendu</StatusChip>}
            {s.left_university && <StatusChip tone="warn">A quitté l’université</StatusChip>}
            {s.graduated && <StatusChip tone="ok">Diplômé</StatusChip>}
          </>
        }
      />
      <Section title="Études">
        <Fields rows={[["Mention", f.mention], ["Parcours", f.track], ["Niveau", f.level_label], ["Année scolaire", f.year], ["Dossier complet", s.file_complete ? "Oui" : null], ["Réinscription autorisée", s.reenrolment_authorised ? "Oui" : null]]} />
      </Section>
      <Section title="Identité et contact">
        <Fields rows={[["Sexe", sexLabel(f.sex)], ["Né(e) le", fileDate(f.birth_date)], ["Lieu de naissance", f.birth_place], ["Nationalité", f.nationality], ["E-mail", f.email], ["Téléphone", f.phone], ["Adresse", f.address]]} />
      </Section>
      <StudentDocuments studentRowId={f.id} />
      <Section title="Inscriptions aux cours">
        {f.enrolments.length === 0 ? (
          <p className="text-sm text-muted-foreground">Aucun cours enregistré.</p>
        ) : (
          <ul className="grid gap-1">
            {f.enrolments.map((e) => (
              <li key={e.year} className="flex justify-between gap-3">
                <span className="font-semibold">{e.year}</span>
                <span className="tabular-nums text-muted-foreground">{plural(e.courses, "cours", "cours")}</span>
              </li>
            ))}
          </ul>
        )}
      </Section>
    </>
  );
}

function CourseSheet({ f }: { f: CourseFile }) {
  return (
    <>
      <Header code={f.code} title={f.title ?? "Sans intitulé"} chips={!f.active ? <StatusChip tone="warn">Inactif</StatusChip> : undefined} />
      <Section title="Le cours">
        <Fields
          rows={[
            ["Intitulé en anglais", f.title_english], ["Mention", f.mention], ["Parcours", f.track], ["Niveau", f.level_label], ["Semestre", f.semester ? `Semestre ${f.semester}` : null],
            ["Crédits", f.credits === null || f.credits === "" ? null : String(f.credits)], ["Catégorie", f.category], ["Laboratoire", f.lab ? "Oui" : null],
          ]}
        />
      </Section>
      <Section title="Enseignant">
        {f.teacher ? (
          <Link href={ficheUrl("teachers", f.teacher.id)} className="inline-flex min-h-11 w-fit items-center font-bold text-accent-text hover:underline">
            {f.teacher.name}
          </Link>
        ) : (
          <p className="text-sm text-muted-foreground">Aucun enseignant désigné.</p>
        )}
      </Section>
      <Section title="Étudiants inscrits">
        {f.enrolments.length === 0 ? (
          <p className="text-sm text-muted-foreground">Aucun étudiant inscrit.</p>
        ) : (
          <ul className="grid gap-1">
            {f.enrolments.map((e) => (
              <li key={e.year} className="flex justify-between gap-3">
                <span className="font-semibold">{e.year}</span>
                <span className="tabular-nums text-muted-foreground">{plural(e.students, "étudiant")}</span>
              </li>
            ))}
          </ul>
        )}
      </Section>
    </>
  );
}

function TeacherSheet({ f }: { f: TeacherFile }) {
  return (
    <>
      <Header code={`Enseignant n° ${f.id}`} title={personName(f.name, f.last_name) || "Sans nom"} />
      <Section title="Coordonnées">
        <Fields rows={[["Sexe", sexLabel(f.sex)], ["E-mail", f.email], ["Téléphone", f.phone], ["Adresse", f.address]]} />
      </Section>
      <Section title="Fonction">
        <Fields rows={[["Diplôme", f.diploma], ["Poste", f.position], ["Catégorie", f.category]]} />
      </Section>
      <Section title={`Cours (${f.courses.length})`}>
        {f.courses.length === 0 ? (
          <p className="text-sm text-muted-foreground">Aucun cours attribué.</p>
        ) : (
          <ul className="grid gap-1">
            {f.courses.map((c) => (
              <li key={c.id}>
                <Link href={ficheUrl("courses", c.id)} className="flex min-h-11 flex-wrap items-center justify-between gap-x-3 rounded-lg px-1 hover:bg-accent">
                  <span>
                    <span className="font-mono text-sm font-semibold text-accent-text">{c.code}</span> {c.title}
                  </span>
                  <span className="text-sm text-muted-foreground">
                    {c.level_label}
                    {c.semester ? ` · Semestre ${c.semester}` : ""}
                  </span>
                </Link>
              </li>
            ))}
          </ul>
        )}
      </Section>
    </>
  );
}
