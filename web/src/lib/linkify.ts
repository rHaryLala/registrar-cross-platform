export type Segment = { kind: "text" | "link"; value: string };

/** Ponctuation qui termine une phrase et ne fait pas partie de l'adresse qui la précède. */
const TRAILING = /[.,;:!?)\]}»”’'"]+$/;

/**
 * Découpe un texte brut en morceaux « texte » et « lien ». Seules les adresses http(s) deviennent des liens : `javascript:`,
 * `data:` et les autres schémas restent du texte. Le résultat est rendu par React, qui échappe tout : aucun HTML n'est jamais injecté.
 */
export function splitLinks(text: string): Segment[] {
  const out: Segment[] = [];
  const re = /https?:\/\/[^\s<>"']+/gi;
  let last = 0;
  for (const m of text.matchAll(re)) {
    const start = m.index ?? 0;
    const raw = m[0];
    const url = raw.replace(TRAILING, "");
    if (!isSafeUrl(url)) continue;
    if (start > last) out.push({ kind: "text", value: text.slice(last, start) });
    out.push({ kind: "link", value: url });
    last = start + url.length;
  }
  if (last < text.length) out.push({ kind: "text", value: text.slice(last) });
  return out.length > 0 ? out : [{ kind: "text", value: text }];
}

function isSafeUrl(value: string): boolean {
  try {
    const u = new URL(value);
    return (u.protocol === "http:" || u.protocol === "https:") && u.hostname.includes(".");
  } catch {
    return false;
  }
}
