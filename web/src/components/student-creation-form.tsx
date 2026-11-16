"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { ArrowLeft, ArrowRight, Check, Copy, RotateCcw } from "lucide-react";
import Link from "next/link";
import { useRouter, useSearchParams } from "next/navigation";
import { useEffect, useMemo, useRef, useState, type ReactNode } from "react";
import { toast } from "sonner";
import { AppShell } from "@/components/app-shell";
import { StatusChip } from "@/components/status-chip";
import { LoadError } from "@/components/teacher-common";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Skeleton } from "@/components/ui/skeleton";
import { api, ApiError } from "@/lib/api";
import { ficheUrl, parseFileId } from "@/lib/directory";
import {
  clearDraft, creationKeys, cycleOf, emptyValues, firstError, fromPrefill, googleText, loadDraft, mentionsFor, newToken, saveDraft, STEPS, suggestUrl, toPayload, tracksFor, validateStep,
  type Created, type Errors, type Options, type Prefill, type Suggestion, type Values,
} from "@/lib/student-creation";
import { cn } from "@/lib/utils";

/** Créer un étudiant, éventuellement depuis une candidature acceptée (`?candidature=12`). */
export function StudentCreationForm() {
  const applicationId = parseFileId(useSearchParams().get("candidature"));
  const router = useRouter();
  const [generation, setGeneration] = useState(0);
  // « Créer un autre étudiant » : un formulaire neuf (autre jeton, autre brouillon) ; depuis une candidature, on revient au formulaire libre.
  const another = () => {
    clearDraft();
    if (applicationId !== null) router.push("/etudiants/nouveau/");
    else setGeneration((g) => g + 1);
  };
  const options = useQuery({ queryKey: creationKeys.options, queryFn: async () => (await api<{ data: Options }>("/api/v1/staff/student-creation/options")).data, retry: 1, staleTime: 5 * 60_000 });
  const prefill = useQuery({
    queryKey: creationKeys.prefill(applicationId ?? 0),
    queryFn: async () => (await api<{ data: Prefill }>(`/api/v1/staff/student-creation/from-application/${applicationId}`)).data,
    enabled: applicationId !== null,
    retry: false,
  });

  return (
    <AppShell title="Nouvel étudiant">
      <div className="mx-auto grid w-full max-w-3xl gap-4 p-4 md:p-6">
        <Link href={applicationId !== null ? "/candidatures/" : "/etudiants/"} className="inline-flex min-h-11 w-fit items-center gap-1.5 text-sm font-bold text-accent-text hover:underline">
          <ArrowLeft className="size-4" aria-hidden /> {applicationId !== null ? "Les candidatures" : "Les étudiants"}
        </Link>
        {(options.isPending || (applicationId !== null && prefill.isPending)) && (
          <div className="grid gap-3" aria-busy="true" aria-label="Chargement du formulaire">
            <Skeleton className="h-16 w-full rounded-xl" />
            <Skeleton className="h-64 w-full rounded-xl" />
          </div>
        )}
        {options.isError && <LoadError error={options.error} onRetry={() => void options.refetch()} what="Le formulaire" />}
        {prefill.isError && <Blocked error={prefill.error} />}
        {options.data && applicationId === null && <Form key={`free-${generation}`} options={options.data} initial={loadDraft()} prefill={null} applicationId={null} onAnother={another} />}
        {options.data && applicationId !== null && prefill.data && <Form key={`app-${applicationId}`} options={options.data} initial={{ values: fromPrefill(prefill.data), step: 0 }} prefill={prefill.data} applicationId={applicationId} onAnother={another} />}
      </div>
    </AppShell>
  );
}

/** Candidature qui n'ouvre pas le formulaire : introuvable, non acceptée, ou déjà convertie (avec un lien vers l'étudiant). */
function Blocked({ error }: { error: unknown }) {
  const e = error instanceof ApiError ? error : null;
  const fileId = typeof e?.extra?.student_file_id === "number" ? e.extra.student_file_id : null;
  return (
    <Card role="alert" className="gap-3 border-l-4 border-l-warning p-5">
      <h2 className="text-xl font-extrabold tracking-tight">{e?.title ?? "Impossible d’ouvrir la candidature"}</h2>
      <p className="text-muted-foreground">{e?.detail ?? "Vérifiez votre connexion, puis réessayez."}</p>
      <div className="flex flex-wrap gap-2">
        {fileId !== null && <Button render={<Link href={ficheUrl("students", fileId)} />}>Ouvrir la fiche de l’étudiant</Button>}
        <Button variant="outline" render={<Link href="/candidatures/" />}>
          Revenir aux candidatures
        </Button>
        <Button variant="ghost" render={<Link href="/etudiants/nouveau/" />}>
          Créer un étudiant sans candidature
        </Button>
      </div>
    </Card>
  );
}

function Form({ options, initial, prefill, applicationId, onAnother }: { options: Options; initial: { values: Values; step: number } | null; prefill: Prefill | null; applicationId: number | null; onAnother: () => void }) {
  const qc = useQueryClient();
  const [values, setValues] = useState<Values>(() => initial?.values ?? emptyValues());
  const [step, setStep] = useState(initial?.step ?? 0);
  const [errors, setErrors] = useState<Errors>({});
  const [typedId, setTypedId] = useState<string | null>(null);
  const [typedEmail, setTypedEmail] = useState<string | null>(null);
  const [suggestion, setSuggestion] = useState<Suggestion | null>(null);
  const [created, setCreated] = useState<Created | null>(null);
  const token = useRef(newToken());
  const topRef = useRef<HTMLHeadingElement>(null);

  const set = <K extends keyof Values>(k: K, v: Values[K]) => {
    setValues((prev) => ({ ...prev, [k]: v }));
    setErrors((prev) => (prev[k] ? { ...prev, [k]: undefined } : prev));
  };

  // Le brouillon reste sur l'appareil : une page rechargée ou une coupure ne fait pas perdre la saisie.
  useEffect(() => {
    if (created === null && applicationId === null) saveDraft(values, step);
  }, [values, step, created, applicationId]);

  // Le matricule libre et l'adresse universitaire libre, proposés d'après la mention, le niveau et le nom (au repos 400 ms).
  const { mention, level, last_name: lastName, first_name: firstName } = values;
  useEffect(() => {
    if (mention === "") return;
    let cancelled = false;
    const t = window.setTimeout(() => {
      api<{ data: Suggestion }>(suggestUrl(mention, level, lastName, firstName))
        .then((r) => !cancelled && setSuggestion(r.data))
        .catch(() => !cancelled && setSuggestion(null));
    }, 400);
    return () => {
      cancelled = true;
      window.clearTimeout(t);
    };
  }, [mention, level, lastName, firstName]);

  const studentId = typedId ?? suggestion?.student_id ?? "";
  const email = typedEmail ?? suggestion?.email ?? "";
  const effective: Values = useMemo(() => ({ ...values, student_id: studentId === "00000" ? "" : studentId, email }), [values, studentId, email]);
  const tracks = tracksFor(options, values.mention, values.level);
  const mentions = mentionsFor(options, prefill?.mention ?? "");

  const create = useMutation({
    mutationFn: async () => (await api<{ data: Created }>("/api/v1/staff/students", { method: "POST", body: toPayload(effective, token.current, applicationId) })).data,
    onSuccess: async (r) => {
      clearDraft();
      setCreated((prev) => (prev?.initial_password && !r.initial_password ? prev : r)); // un second retour (même jeton) ne doit jamais effacer le mot de passe déjà affiché
      await qc.invalidateQueries({ queryKey: ["staff"] });
      window.scrollTo({ top: 0 });
    },
    onError: (e) => {
      if (e instanceof ApiError && e.fieldErrors) {
        const next: Errors = {};
        for (const [k, msgs] of Object.entries(e.fieldErrors)) next[k as keyof Values] = msgs[0];
        setErrors(next);
        setStep(0);
        toast.error("Des champs sont à corriger", { description: "Le serveur a refusé certaines valeurs : elles sont signalées dans le formulaire." });
      } else {
        toast.error(e instanceof ApiError ? e.title : "Création impossible", { description: e instanceof ApiError ? e.detail : "Vérifiez votre connexion : rien n’a été enregistré." });
      }
    },
  });

  if (created) return <Success created={created} fromApplication={applicationId !== null} onAnother={onAnother} />;

  const goNext = () => {
    const e = validateStep(step, effective);
    setErrors(e);
    const first = firstError(e);
    if (first) {
      document.getElementById(`f-${first}`)?.focus();
      return;
    }
    setStep((s) => s + 1);
    topRef.current?.focus();
  };
  const submit = () => {
    const e = { ...validateStep(0, effective), ...validateStep(1, effective) };
    setErrors(e);
    const first = firstError(e);
    if (first) {
      setStep(Object.keys(validateStep(0, effective)).length > 0 ? 0 : 1);
      toast.error("Il manque des informations", { description: "Les champs à compléter sont signalés." });
      return;
    }
    create.mutate();
  };

  return (
    <form
      noValidate
      onSubmit={(ev) => {
        ev.preventDefault();
        if (step < STEPS.length - 1) goNext();
        else submit();
      }}
      className="grid gap-4"
    >
      {prefill && (
        <p role="note" className="rounded-xl border border-accent-text/30 bg-accent/60 p-3 text-sm">
          Depuis la candidature de <strong>{prefill.application_name}</strong>. L’e-mail personnel du candidat n’est pas repris : l’adresse universitaire est dérivée du nom.
        </p>
      )}
      <nav aria-label="Étapes" className="grid gap-2">
        <ol className="flex gap-1.5">
          {STEPS.map((s, i) => (
            <li key={s.id} className="flex-1">
              <button
                type="button"
                aria-current={i === step ? "step" : undefined}
                disabled={i > step}
                onClick={() => setStep(i)}
                className={cn("h-2 w-full rounded-full transition-colors", i <= step ? "bg-primary" : "bg-muted")}
                aria-label={`Étape ${i + 1} : ${s.title}`}
              />
            </li>
          ))}
        </ol>
        <h2 ref={topRef} tabIndex={-1} className="text-2xl font-extrabold tracking-tight outline-none">
          Étape {step + 1} sur {STEPS.length} · {STEPS[step].title}
        </h2>
      </nav>

      {step === 0 && <Identity v={values} set={set} errors={errors} options={options} email={email} onEmail={setTypedEmail} suggestion={suggestion} />}
      {step === 1 && (
        <Studies v={values} set={set} errors={errors} options={options} mentions={mentions} tracks={tracks} studentId={studentId} onStudentId={setTypedId} suggestion={suggestion} onLevel={() => set("track", "")} />
      )}
      {step === 2 && <Family v={values} set={set} />}
      {step === 3 && <Review v={effective} options={options} />}

      <div className="sticky bottom-20 z-10 flex flex-wrap items-center justify-between gap-2 rounded-xl border bg-card p-3 shadow-lg md:bottom-4">
        <Button type="button" variant="outline" disabled={step === 0 || create.isPending} onClick={() => setStep((s) => s - 1)}>
          <ArrowLeft aria-hidden /> Précédent
        </Button>
        {step < STEPS.length - 1 ? (
          <Button type="submit">
            Suivant <ArrowRight aria-hidden />
          </Button>
        ) : (
          <Button type="submit" disabled={create.isPending}>
            <Check aria-hidden /> {create.isPending ? "Création…" : "Créer l’étudiant"}
          </Button>
        )}
      </div>
    </form>
  );
}

// ---------------------------------------------------------------- champs

function Field({ id, label, error, hint, required, children }: { id: string; label: string; error?: string; hint?: string; required?: boolean; children: ReactNode }) {
  return (
    <div className="grid gap-1.5">
      <label htmlFor={`f-${id}`} className="text-sm font-bold">
        {label}
        {required && <span className="text-destructive"> *</span>}
      </label>
      {children}
      {hint && !error && (
        <p id={`h-${id}`} className="text-xs text-muted-foreground">
          {hint}
        </p>
      )}
      {error && (
        <p id={`e-${id}`} role="alert" className="text-sm font-semibold text-destructive">
          {error}
        </p>
      )}
    </div>
  );
}

type SetFn = <K extends keyof Values>(k: K, v: Values[K]) => void;

function Text({ k, label, v, set, errors, required, type = "text", hint, placeholder, inputMode }: { k: keyof Values; label: string; v: Values; set: SetFn; errors?: Errors; required?: boolean; type?: string; hint?: string; placeholder?: string; inputMode?: "numeric" | "tel" | "email" }) {
  return (
    <Field id={k} label={label} error={errors?.[k]} hint={hint} required={required}>
      <Input id={`f-${k}`} type={type} value={v[k]} onChange={(e) => set(k, e.target.value)} aria-invalid={errors?.[k] ? true : undefined} aria-describedby={errors?.[k] ? `e-${k}` : hint ? `h-${k}` : undefined} placeholder={placeholder} inputMode={inputMode} autoComplete="off" />
    </Field>
  );
}

function Pick({ k, label, v, set, errors, required, choices, empty }: { k: keyof Values; label: string; v: Values; set: SetFn; errors?: Errors; required?: boolean; choices: { value: string; label: string }[]; empty?: string }) {
  return (
    <Field id={k} label={label} error={errors?.[k]} required={required}>
      <select id={`f-${k}`} value={v[k]} onChange={(e) => set(k, e.target.value)} aria-invalid={errors?.[k] ? true : undefined} aria-describedby={errors?.[k] ? `e-${k}` : undefined} className="h-11 w-full rounded-lg border border-input bg-background px-3 text-base md:h-10 md:text-sm">
        {empty !== undefined && <option value="">{empty}</option>}
        {choices.map((c) => (
          <option key={c.value} value={c.value}>
            {c.label}
          </option>
        ))}
      </select>
    </Field>
  );
}

const strings = (xs: string[]) => xs.map((x) => ({ value: x, label: x }));
const LEVELS = [
  { value: "0", label: "Remise à niveau" }, { value: "1", label: "Licence 1" }, { value: "2", label: "Licence 2" }, { value: "3", label: "Licence 3" }, { value: "4", label: "Master 1" }, { value: "5", label: "Master 2" },
];

function Section({ title, children }: { title: string; children: ReactNode }) {
  return (
    <Card className="gap-4 p-4">
      <h3 className="text-lg font-extrabold tracking-tight">{title}</h3>
      <div className="grid gap-4 sm:grid-cols-2">{children}</div>
    </Card>
  );
}

function Identity({ v, set, errors, options, email, onEmail, suggestion }: { v: Values; set: SetFn; errors: Errors; options: Options; email: string; onEmail: (s: string) => void; suggestion: Suggestion | null }) {
  const regions = options.regions.map((r) => ({ value: String(r.id), label: r.name }));
  return (
    <>
      <Section title="Identité">
        <Text k="last_name" label="Nom" v={v} set={set} errors={errors} required />
        <Text k="first_name" label="Prénom" v={v} set={set} errors={errors} required />
        <Pick k="sex" label="Genre" v={v} set={set} choices={[{ value: "1", label: "Masculin" }, { value: "0", label: "Féminin" }]} />
        <Text k="birth_date" label="Date de naissance" type="date" v={v} set={set} errors={errors} required />
        <Text k="birth_place" label="Lieu de naissance" v={v} set={set} errors={errors} required />
        <Text k="nationality" label="Nationalité" v={v} set={set} errors={errors} required />
        <Pick k="marital_status" label="État civil" v={v} set={set} choices={strings(options.marital)} />
        <Text k="spouse_name" label="Nom du conjoint ou de la conjointe" v={v} set={set} errors={errors} />
        <Text k="children" label="Nombre d’enfants" type="number" inputMode="numeric" v={v} set={set} errors={errors} />
        <Pick k="religion" label="Religion" v={v} set={set} choices={strings(options.religions)} />
      </Section>
      <Section title="Pièces">
        <Text k="cin_number" label="Numéro de CIN" v={v} set={set} errors={errors} />
        <Text k="cin_date" label="CIN délivrée le" type="date" v={v} set={set} errors={errors} />
        <Pick k="cin_region" label="Région de délivrance de la CIN" v={v} set={set} choices={regions} empty="Non renseignée" />
        <Text k="visa_number" label="Numéro de visa" v={v} set={set} errors={errors} />
      </Section>
      <Section title="Contact">
        <Text k="phone" label="Téléphone" type="tel" inputMode="tel" placeholder="034 00 000 00" v={v} set={set} errors={errors} />
        <Field id="email" label="Adresse universitaire" error={errors.email} hint={suggestion?.email ? (suggestion.email === suggestion.email_usual ? "Proposée d’après le nom ; vous pouvez la modifier." : `${suggestion.email_usual} était déjà prise : celle-ci est libre.`) : "Choisissez d’abord la mention (étape suivante) pour la voir proposée, ou saisissez-la."}>
          <Input id="f-email" type="email" inputMode="email" value={email} onChange={(e) => onEmail(e.target.value)} aria-invalid={errors.email ? true : undefined} placeholder="nom.pre@zurcher.edu.mg" autoComplete="off" />
        </Field>
        <Text k="origin_country" label="Pays d’origine" v={v} set={set} errors={errors} required />
        <Pick k="region" label="Région" v={v} set={set} errors={errors} required choices={regions} empty="Choisir…" />
        <div className="sm:col-span-2">
          <Text k="address" label="Adresse actuelle" v={v} set={set} errors={errors} required />
        </div>
      </Section>
    </>
  );
}

function Studies({ v, set, errors, options, mentions, tracks, studentId, onStudentId, suggestion, onLevel }: {
  v: Values; set: SetFn; errors: Errors; options: Options; mentions: Options["mentions"]; tracks: Options["tracks"]; studentId: string; onStudentId: (s: string) => void; suggestion: Suggestion | null; onLevel: () => void;
}) {
  const master = cycleOf(v.level) === "M";
  return (
    <>
      <Section title="Études">
        <Pick k="mention" label="Mention" v={v} set={(k, val) => { set(k, val); set("track", ""); }} errors={errors} required choices={mentions.map((m) => ({ value: m.code, label: m.open ? m.label : `${m.label} (fermée)` }))} empty="Choisir…" />
        <Pick k="level" label="Niveau" v={v} set={(k, val) => { set(k, val); onLevel(); }} choices={LEVELS} />
        <Field id="track" label="Parcours" error={errors.track} required hint={v.mention === "" ? "Choisissez d’abord la mention." : tracks.length === 0 ? "Aucun parcours pour cette mention à ce niveau." : undefined}>
          <select id="f-track" value={v.track} onChange={(e) => set("track", e.target.value)} disabled={tracks.length === 0} aria-invalid={errors.track ? true : undefined} className="h-11 w-full rounded-lg border border-input bg-background px-3 text-base disabled:opacity-60 md:h-10 md:text-sm">
            <option value="">Choisir…</option>
            {tracks.map((t) => (
              <option key={t.code} value={t.label}>
                {t.label}
              </option>
            ))}
          </select>
        </Field>
        <Pick k="academic_year" label="Année universitaire" v={v} set={set} errors={errors} required choices={strings(options.years)} />
        <Pick k="status" label="Hébergement" v={v} set={set} choices={strings(options.statuses)} />
        <Pick k="is_new" label="Type d’étudiant" v={v} set={set} choices={[{ value: "true", label: "Nouvel étudiant" }, { value: "false", label: "Ancien étudiant" }]} />
        <Pick k="cafeteria" label="Abonné à la cafétéria" v={v} set={set} choices={[{ value: "0", label: "Non" }, { value: "1", label: "Oui" }]} />
        <Pick k="size" label="Taille (T-shirt)" v={v} set={set} choices={strings(options.sizes)} empty="Non renseignée" />
        <Field id="student_id" label="Matricule" error={errors.student_id} hint={suggestion ? "Prochain matricule libre de la mention. S’il est pris entre-temps, le suivant est attribué." : "Proposé dès que la mention est choisie."}>
          <Input id="f-student_id" value={studentId} onChange={(e) => onStudentId(e.target.value)} aria-invalid={errors.student_id ? true : undefined} className="font-mono" autoComplete="off" />
        </Field>
      </Section>
      <Section title={master ? "Diplôme précédent" : "Baccalauréat"}>
        {master ? (
          <>
            <Text k="previous_degree" label="Diplôme précédent" v={v} set={set} errors={errors} />
            <Text k="previous_degree_date" label="Obtenu le" type="date" v={v} set={set} errors={errors} />
          </>
        ) : (
          <>
            <Text k="bacc_date" label="Baccalauréat obtenu le" type="date" v={v} set={set} errors={errors} />
            <Pick k="bacc_series" label="Série" v={v} set={set} errors={errors} choices={strings(options.series)} empty="Non renseignée" />
          </>
        )}
      </Section>
    </>
  );
}

function Family({ v, set }: { v: Values; set: SetFn }) {
  return (
    <>
      <Section title="Parents">
        <Text k="father_name" label="Père" v={v} set={set} />
        <Text k="father_job" label="Profession du père" v={v} set={set} />
        <Text k="mother_name" label="Mère" v={v} set={set} />
        <Text k="mother_job" label="Profession de la mère" v={v} set={set} />
        <Text k="parent_phone" label="Téléphone des parents" type="tel" inputMode="tel" v={v} set={set} />
        <Text k="parent_address" label="Adresse des parents" v={v} set={set} />
      </Section>
      <Section title="Garant (sponsor)">
        <Text k="sponsor_last_name" label="Nom" v={v} set={set} />
        <Text k="sponsor_first_name" label="Prénom" v={v} set={set} />
        <Text k="sponsor_phone" label="Téléphone" type="tel" inputMode="tel" v={v} set={set} />
        <Text k="sponsor_address" label="Adresse" v={v} set={set} />
      </Section>
    </>
  );
}

function Review({ v, options }: { v: Values; options: Options }) {
  const mention = options.mentions.find((m) => m.code === v.mention)?.label ?? v.mention;
  const level = LEVELS.find((l) => l.value === v.level)?.label ?? v.level;
  const rows: [string, string][] = [
    ["Nom", [v.last_name.toUpperCase(), v.first_name].filter(Boolean).join(" ")], ["Né(e) le", `${v.birth_date} à ${v.birth_place}`], ["Mention", mention], ["Parcours", v.track], ["Niveau", level],
    ["Année universitaire", v.academic_year], ["Matricule", v.student_id || "attribué à la création"], ["Adresse universitaire", v.email || "attribuée à la création"], ["Hébergement", v.status],
  ];
  return (
    <Card className="gap-3 p-4">
      <h3 className="text-lg font-extrabold tracking-tight">Récapitulatif</h3>
      <dl className="grid gap-x-6 gap-y-2 sm:grid-cols-2">
        {rows.map(([label, value]) => (
          <div key={label} className="min-w-0">
            <dt className="text-xs font-bold tracking-wide text-muted-foreground uppercase">{label}</dt>
            <dd className="break-words">{value}</dd>
          </div>
        ))}
      </dl>
      <p className="text-sm text-muted-foreground">
        À la création, l’étudiant reçoit un matricule libre, une adresse universitaire unique et un mot de passe initial aléatoire, affiché une seule fois. Le compte Google est créé juste après,
        sans vous faire attendre.
      </p>
    </Card>
  );
}

// ---------------------------------------------------------------- réussite et compte Google

function Success({ created, fromApplication, onAnother }: { created: Created; fromApplication: boolean; onAnother: () => void }) {
  const qc = useQueryClient();
  const google = useQuery({
    queryKey: creationKeys.google(created.id),
    queryFn: async () => (await api<{ data: { status: Created["google"]["status"]; reason: string | null } }>(`/api/v1/staff/students/${created.id}/google`)).data,
    initialData: created.google,
    refetchInterval: (q) => (q.state.data?.status === "en_attente" ? 5000 : false),
  });
  const retry = useMutation({
    mutationFn: async () => api(`/api/v1/staff/students/${created.id}/google/retry`, { method: "POST" }),
    onSuccess: () => qc.invalidateQueries({ queryKey: creationKeys.google(created.id) }),
    onError: (e) => toast.error("Relance impossible", { description: e instanceof ApiError ? e.detail : "Vérifiez votre connexion." }),
  });
  const g = googleText(google.data);
  const copy = async () => {
    try {
      await navigator.clipboard.writeText(created.initial_password ?? "");
      toast.success("Mot de passe copié");
    } catch {
      toast.error("Copie impossible", { description: "Sélectionnez le mot de passe et copiez-le à la main." });
    }
  };

  return (
    <div className="grid gap-4">
      <Card className="gap-3 p-5">
        <StatusChip tone="ok">{created.already ? "Déjà créé" : "Étudiant créé"}</StatusChip>
        <h2 className="text-2xl font-extrabold tracking-tight">Matricule <span className="font-mono">{created.student_id}</span></h2>
        {created.email && (
          <p>
            Adresse universitaire : <span className="font-mono font-semibold">{created.email}</span>
          </p>
        )}
        {created.email_notice && <p className="text-sm font-semibold text-warning">{created.email_notice}</p>}
        {created.initial_password ? (
          <div className="grid gap-1 rounded-xl border border-warning/50 bg-warning/10 p-3">
            <p className="text-sm font-bold">Mot de passe initial (affiché une seule fois)</p>
            <div className="flex flex-wrap items-center gap-2">
              <code className="rounded-md bg-background px-2 py-1 font-mono text-lg font-bold tracking-wide select-all">{created.initial_password}</code>
              <Button type="button" variant="outline" onClick={() => void copy()}>
                <Copy aria-hidden /> Copier
              </Button>
            </div>
            <p className="text-xs text-muted-foreground">Remettez-le à l’étudiant : il ne sera plus jamais affiché dans l’application.</p>
          </div>
        ) : (
          <p className="text-sm text-muted-foreground">Cette création avait déjà été enregistrée : le mot de passe initial n’est plus affiché.</p>
        )}
        {fromApplication && created.application_converted && <p className="text-sm font-semibold text-success">La candidature est convertie et rangée dans les archives.</p>}
      </Card>

      <Card className="gap-2 p-4">
        <h3 className="text-lg font-extrabold tracking-tight">Compte Google</h3>
        <p className={cn("text-sm", g.tone === "ok" ? "text-success" : g.tone === "bad" ? "font-semibold text-destructive" : g.tone === "warn" ? "text-warning" : "text-muted-foreground")} role="status" aria-live="polite">
          {g.label}
        </p>
        {g.retry && (
          <div>
            <Button variant="outline" disabled={retry.isPending} onClick={() => retry.mutate()}>
              <RotateCcw aria-hidden /> Réessayer
            </Button>
          </div>
        )}
      </Card>

      <div className="flex flex-wrap gap-2">
        <Button render={<Link href={ficheUrl("students", created.id)} />}>Ouvrir la fiche</Button>
        <Button variant="outline" onClick={onAnother}>
          Créer un autre étudiant
        </Button>
      </div>
    </div>
  );
}
