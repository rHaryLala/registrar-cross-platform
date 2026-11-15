import { describe, expect, it } from "vitest";
import { filenameFromDisposition } from "@/lib/download";

describe("filenameFromDisposition", () => {
  it("lit le nom entre guillemets", () => {
    expect(filenameFromDisposition('attachment; filename="1234-fiche-inscription-Session-1-2026-2027.pdf"', "x.pdf")).toBe("1234-fiche-inscription-Session-1-2026-2027.pdf");
  });
  it("lit un nom sans guillemets", () => {
    expect(filenameFromDisposition("attachment; filename=fiche.pdf", "x.pdf")).toBe("fiche.pdf");
  });
  it("retombe sur le nom par défaut sans en-tête", () => {
    expect(filenameFromDisposition(null, "defaut.pdf")).toBe("defaut.pdf");
    expect(filenameFromDisposition("attachment", "defaut.pdf")).toBe("defaut.pdf");
  });
  it("ne laisse jamais passer un chemin ni un caractère douteux", () => {
    expect(filenameFromDisposition('attachment; filename="../../secret.pdf"', "defaut.pdf")).toBe("secret.pdf");
    expect(filenameFromDisposition('attachment; filename="a b<c>.pdf"', "defaut.pdf")).toBe("defaut.pdf");
  });
});
