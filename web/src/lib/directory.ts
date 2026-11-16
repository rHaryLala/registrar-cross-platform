/**
 * Listes du registrariat (étudiants, cours, enseignants) : formes des réponses de l'API (/api/v1/staff/…) et fonctions pures qui construisent
 * la requête (recherche, filtres, tri). Le serveur applique la requête ENTIÈRE à chaque appel : trier ne retire pas un filtre.
 */

export type DirectoryKind = "students" | "courses" | "teachers";

export type FacetOption = { value: string; label: string; count: number; on: boolean };
export type Facet = { label: string; options: FacetOption[]; help: string | null };

export type StudentRow = {
  id: number;
  student_id: string;
  last_name: string | null;
  first_name: string | null;
  mention: string | null;
  track: string | null;
  year: string | null;
  level: number | null;
  level_label: string;
  is_new: boolean;
};
export type CourseRow = {
  id: number;
  code: string;
  title: string | null;
  mention: string | null;
  track: string | null;
  credits: number | string | null;
  category: string;
  level: number | null;
  level_label: string;
  semester: number | null;
  teacher: string | null;
};
export type TeacherRow = { id: number; name: string | null; last_name: string | null; address: string | null; phone: string | null; email: string | null };
export type RowOf<K extends DirectoryKind> = K extends "students" ? StudentRow : K extends "courses" ? CourseRow : TeacherRow;

export type DirectoryPage<K extends DirectoryKind = DirectoryKind> = {
  list: K;
  total: number;
  shown: number;
  remaining: number;
  per_page: number;
  rows: RowOf<K>[];
  facets: Record<string, Facet>;
  state: { q: string; filters: Record<string, string[]>; sort: string; direction: "asc" | "desc"; page: number };
  sorts: Record<string, { label: string; direction: "asc" | "desc" }>;
  notice: string | null;
};

/** Ce que l'utilisateur a demandé : la page (« la suite ») s'ajoute à part. */
export type Query = { q: string; filters: Record<string, string[]>; sort: string | null; direction: "asc" | "desc" | null };

export const EMPTY_QUERY: Query = { q: "", filters: {}, sort: null, direction: null };

export const directoryKeys = {
  list: (kind: DirectoryKind, query: Query) => ["staff", "list", kind, query] as const,
  one: (kind: DirectoryKind, id: number) => ["staff", "one", kind, id] as const,
};

/** Les paramètres d'une page de liste (recherche, filtres, tri, page) : seul ce qui est demandé est envoyé. */
export function queryString(query: Query, page = 0): string {
  const p = new URLSearchParams();
  if (query.q.trim() !== "") p.set("q", query.q.trim());
  for (const [key, values] of Object.entries(query.filters)) for (const v of values) p.append(`${key}[]`, v);
  if (query.sort) p.set("tri", query.sort);
  if (query.direction) p.set("sens", query.direction);
  if (page > 0) p.set("page", String(page));
  const qs = p.toString();
  return qs ? `?${qs}` : "";
}

/** L'adresse de l'API pour une page de la liste. */
export function directoryUrl(kind: DirectoryKind, query: Query, page = 0): string {
  return `/api/v1/staff/lists/${kind}${queryString(query, page)}`;
}

/** Les filtres qui n'ont de sens qu'une fois un autre choisi (le parcours suit la mention). */
const DEPENDS_ON: Record<string, string> = { parcours: "mention" };

/** Coche ou décoche une valeur d'un filtre. Retirer la dernière mention retire aussi le parcours qui en dépendait. */
export function toggleFilter(query: Query, key: string, value: string): Query {
  const current = query.filters[key] ?? [];
  const next = current.includes(value) ? current.filter((v) => v !== value) : [...current, value];
  const filters = { ...query.filters };
  if (next.length > 0) filters[key] = next;
  else delete filters[key];
  for (const [dependent, parent] of Object.entries(DEPENDS_ON)) if (parent === key && next.length === 0) delete filters[dependent];
  return { ...query, filters };
}

export function removeFilter(query: Query, key: string): Query {
  const filters = { ...query.filters };
  delete filters[key];
  for (const [dependent, parent] of Object.entries(DEPENDS_ON)) if (parent === key) delete filters[dependent];
  return { ...query, filters };
}

/** Choisit un tri ; le même tri choisi deux fois inverse le sens. */
export function chooseSort(query: Query, sort: string, current: { sort: string; direction: "asc" | "desc" }, defaults: DirectoryPage["sorts"]): Query {
  if (current.sort === sort) return { ...query, sort, direction: current.direction === "asc" ? "desc" : "asc" };
  return { ...query, sort, direction: defaults[sort]?.direction ?? "asc" };
}

export function flipDirection(query: Query, current: { sort: string; direction: "asc" | "desc" }): Query {
  return { ...query, sort: current.sort, direction: current.direction === "asc" ? "desc" : "asc" };
}

export function activeFilterCount(query: Query): number {
  return Object.values(query.filters).reduce((n, v) => n + v.length, 0);
}

/** La recherche ou un filtre est-il actif ? (le tri seul ne compte pas) */
export function isFiltered(query: Query): boolean {
  return query.q.trim() !== "" || activeFilterCount(query) > 0;
}

/** Les filtres actifs, avec le libellé de l'option, pour les afficher en pastilles retirables. */
export function activeChips(query: Query, facets: Record<string, Facet>): { key: string; value: string; label: string; group: string }[] {
  const chips: { key: string; value: string; label: string; group: string }[] = [];
  for (const [key, values] of Object.entries(query.filters)) {
    const facet = facets[key];
    for (const value of values) chips.push({ key, value, label: facet?.options.find((o) => o.value === value)?.label ?? (value === "" ? "Non renseigné" : value), group: facet?.label ?? key });
  }
  return chips;
}

const NOUNS: Record<DirectoryKind, [string, string]> = { students: ["étudiant", "étudiants"], courses: ["cours", "cours"], teachers: ["enseignant", "enseignants"] };

/** « 2 280 étudiants », « 1 cours », « Aucun enseignant » (espaces insécables dans les milliers). */
export function countText(kind: DirectoryKind, total: number): string {
  const [one, many] = NOUNS[kind];
  if (total === 0) return `Aucun ${one}`;
  return `${total.toLocaleString("fr-FR")} ${total < 2 ? one : many}`;
}

/** « Afficher la suite (80 restants) ». */
export function moreText(remaining: number, perPage: number): string {
  const next = Math.min(remaining, perPage);
  return `Afficher la suite (${next.toLocaleString("fr-FR")}${remaining > next ? ` sur ${remaining.toLocaleString("fr-FR")}` : ""} de plus)`;
}

const REMEMBER = "registrar:list:";

/** Garde la recherche d'une liste le temps de la session : on revient de la fiche là où on était. */
export function rememberQuery(kind: string, query: Query): void {
  try {
    sessionStorage.setItem(REMEMBER + kind, JSON.stringify(query));
  } catch {
    /* stockage indisponible : la liste fonctionne sans */
  }
}

export function recalledQuery(kind: string): Query {
  try {
    const raw = sessionStorage.getItem(REMEMBER + kind);
    if (!raw) return EMPTY_QUERY;
    const v = JSON.parse(raw) as Partial<Query>;
    const filters: Record<string, string[]> = {};
    for (const [k, vals] of Object.entries(v.filters ?? {})) if (Array.isArray(vals)) filters[k] = vals.filter((x): x is string => typeof x === "string");
    return {
      q: typeof v.q === "string" ? v.q : "",
      filters,
      sort: typeof v.sort === "string" ? v.sort : null,
      direction: v.direction === "asc" || v.direction === "desc" ? v.direction : null,
    };
  } catch {
    return EMPTY_QUERY;
  }
}

export function ficheUrl(kind: DirectoryKind, id: number): string {
  return `/${kind === "students" ? "etudiants" : kind === "courses" ? "cours" : "enseignants"}/fiche/?id=${id}`;
}

/** « NOM Prénom » : le nom en majuscules, comme dans l'ancienne liste. */
export function personName(last: string | null, first: string | null): string {
  return [last ? last.toLocaleUpperCase("fr-FR") : null, first].filter((x) => x && x.trim() !== "").join(" ").trim();
}

// ---------------------------------------------------------------- fiches (lecture seule)

export type StudentFile = {
  id: number;
  student_id: string;
  last_name: string | null;
  first_name: string | null;
  sex: string | null;
  birth_date: string | null;
  birth_place: string | null;
  nationality: string | null;
  email: string | null;
  phone: string | null;
  address: string | null;
  mention: string | null;
  track: string | null;
  year: string | null;
  level: number | null;
  level_label: string;
  is_new: boolean;
  status: { suspended: boolean; left_university: boolean; graduated: boolean; file_complete: boolean; reenrolment_authorised: boolean };
  enrolments: { year: string; courses: number }[];
};
export type CourseFile = {
  id: number;
  code: string;
  title: string | null;
  title_english: string | null;
  mention: string | null;
  track: string | null;
  credits: number | string | null;
  category: string;
  level: number | null;
  level_label: string;
  semester: number | null;
  lab: boolean;
  active: boolean;
  teacher: { id: number; name: string } | null;
  enrolments: { year: string; students: number }[];
};
export type TeacherFile = {
  id: number;
  name: string | null;
  last_name: string | null;
  sex: number | string | null;
  diploma: string | null;
  position: string | null;
  category: string | null;
  email: string | null;
  phone: string | null;
  address: string | null;
  courses: { id: number; code: string; title: string | null; level_label: string; semester: number | null }[];
};

export function fileUrl(kind: DirectoryKind, id: number): string {
  return `/api/v1/staff/${kind}/${id}`;
}

/** L'id d'une fiche lu dans l'adresse (`?id=12`) : un entier positif, sinon null. */
export function parseFileId(raw: string | null): number | null {
  if (raw === null || !/^\d{1,9}$/.test(raw)) return null;
  const n = Number(raw);
  return n > 0 ? n : null;
}

/** « 2001-03-12 » → « 12 mars 2001 » (lue en date locale) ; autre chose reste tel quel. */
export function fileDate(iso: string | null): string | null {
  if (!iso) return null;
  const m = /^(\d{4})-(\d{2})-(\d{2})/.exec(iso);
  if (!m || m[1] === "0000") return iso;
  const d = new Date(Number(m[1]), Number(m[2]) - 1, Number(m[3]));
  return d.getMonth() === Number(m[2]) - 1 ? d.toLocaleDateString("fr-FR", { day: "numeric", month: "long", year: "numeric" }) : iso;
}
