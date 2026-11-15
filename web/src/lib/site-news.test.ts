import { describe, expect, it } from "vitest";
import { safeHref, safeImageSrc } from "@/lib/site-news";

describe("safeHref", () => {
  it("accepte http, https et mailto", () => {
    expect(safeHref("https://zurcher.edu.mg/x")).toBe("https://zurcher.edu.mg/x");
    expect(safeHref("http://exemple.mg")).toBe("http://exemple.mg");
    expect(safeHref("mailto:contact@zurcher.edu.mg")).toBe("mailto:contact@zurcher.edu.mg");
  });
  it("refuse tout le reste", () => {
    for (const bad of ["javascript:alert(1)", "JaVaScRiPt:alert(1)", "data:text/html,x", "vbscript:x", "ftp://x.mg", "file:///etc/passwd", "//evil.example", "/relatif", "#ancre", "", undefined]) {
      expect(safeHref(bad), String(bad)).toBeUndefined();
    }
  });
  it("refuse une adresse qui contient des espaces ou des caractères d'échappement", () => {
    expect(safeHref('https://x.mg/a b')).toBeUndefined();
    expect(safeHref('https://x.mg/"><script>')).toBeUndefined();
  });
});

describe("safeImageSrc", () => {
  it("n'accepte que https", () => {
    expect(safeImageSrc("https://zurcher.edu.mg/a.webp")).toBe("https://zurcher.edu.mg/a.webp");
    for (const bad of ["http://zurcher.edu.mg/a.webp", "data:image/svg+xml;base64,AAAA", "javascript:alert(1)", "//x.mg/a.png", "", null, undefined]) {
      expect(safeImageSrc(bad), String(bad)).toBeUndefined();
    }
  });
});
