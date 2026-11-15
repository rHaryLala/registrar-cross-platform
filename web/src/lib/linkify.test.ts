import { describe, expect, it } from "vitest";
import { splitLinks } from "@/lib/linkify";

describe("splitLinks", () => {
  it("laisse un texte sans adresse tel quel", () => {
    expect(splitLinks("Bonjour tout le monde")).toEqual([{ kind: "text", value: "Bonjour tout le monde" }]);
    expect(splitLinks("")).toEqual([{ kind: "text", value: "" }]);
  });

  it("transforme une adresse http(s) en lien", () => {
    expect(splitLinks("Voir https://zurcher.edu.mg/inscription pour plus")).toEqual([
      { kind: "text", value: "Voir " },
      { kind: "link", value: "https://zurcher.edu.mg/inscription" },
      { kind: "text", value: " pour plus" },
    ]);
  });

  it("n'emporte pas la ponctuation de fin de phrase", () => {
    const s = splitLinks("Rendez-vous sur https://exemple.mg/a, puis (https://exemple.mg/b). Merci !");
    expect(s.filter((x) => x.kind === "link").map((x) => x.value)).toEqual(["https://exemple.mg/a", "https://exemple.mg/b"]);
    expect(s.map((x) => x.value).join("")).toBe("Rendez-vous sur https://exemple.mg/a, puis (https://exemple.mg/b). Merci !");
  });

  it("ne reconstitue jamais autre chose que le texte d'origine", () => {
    const t = "a http://x.mg/1 b https://y.mg/2? c\nligne https://z.mg/3";
    expect(splitLinks(t).map((x) => x.value).join("")).toBe(t);
  });

  it("n'accepte que http et https", () => {
    for (const bad of ["javascript:alert(1)", "data:text/html,<script>1</script>", "ftp://exemple.mg/x", "file:///etc/passwd", "vbscript:x"]) {
      expect(splitLinks(bad).every((x) => x.kind === "text"), bad).toBe(true);
    }
  });

  it("une adresse sans domaine n'est pas un lien", () => {
    expect(splitLinks("http://localhost et https://intranet").every((x) => x.kind === "text")).toBe(true);
  });

  it("garde le HTML comme du texte inerte", () => {
    const s = splitLinks('<a href="javascript:alert(1)">cliquez</a> et <script>x</script>');
    expect(s.every((x) => x.kind === "text")).toBe(true);
  });

  it("gère plusieurs liens et des sauts de ligne", () => {
    const s = splitLinks("https://a.mg\nhttps://b.mg");
    expect(s.filter((x) => x.kind === "link")).toHaveLength(2);
  });
});
