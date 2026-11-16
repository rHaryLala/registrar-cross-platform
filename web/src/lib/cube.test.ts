import { describe, expect, it } from "vitest";
import {
  FACES,
  applyMoves,
  cubieMatrix3d,
  formatMoves,
  invertMove,
  moveTurn,
  invertMoves,
  isSolved,
  looksTheSame,
  moveFromDrag,
  parseMoves,
  sameCube,
  scramble,
  solvedCube,
  stickersOf,
  viewProject,
  worldNormal,
  type Cube,
  type Face,
  type Move,
} from "@/lib/cube";

const mv = (s: string): Move[] => parseMoves(s)!;
const run = (s: string, from: Cube = solvedCube()): Cube => applyMoves(from, mv(s));
/** Générateur pseudo-aléatoire reproductible (mulberry32). */
const seeded = (seed: number) => () => {
  seed = (seed + 0x6d2b79f5) | 0;
  let t = Math.imul(seed ^ (seed >>> 15), 1 | seed);
  t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t;
  return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
};

/** Où est le sticker de la face `normal` du cubie dont la position d'origine est `home` ? */
const where = (cube: Cube, home: [number, number, number], normal: [number, number, number]) => {
  const c = cube.find((x) => x.home.join() === home.join())!;
  const local = stickersOf(c).find((s) => s.normal.join() === normal.join())!.normal;
  return { pos: c.pos.join(), normal: worldNormal(c, local).join() };
};

describe("le cube résolu", () => {
  it("a 26 cubies, 54 stickers et 9 de chaque couleur", () => {
    const cube = solvedCube();
    const stickers = cube.flatMap(stickersOf);
    expect(cube).toHaveLength(26);
    expect(stickers).toHaveLength(54);
    const counts = new Map<string, number>();
    stickers.forEach((s) => counts.set(s.color, (counts.get(s.color) ?? 0) + 1));
    expect([...counts.values()]).toEqual([9, 9, 9, 9, 9, 9]);
  });
  it("est résolu, et un seul mouvement le dérésout", () => {
    expect(isSolved(solvedCube())).toBe(true);
    for (const f of FACES) expect(isSolved(run(f))).toBe(false);
  });
  it("a 8 coins (3 stickers), 12 arêtes (2) et 6 centres (1)", () => {
    const sizes = solvedCube().map((c) => stickersOf(c).length);
    expect(sizes.filter((n) => n === 3)).toHaveLength(8);
    expect(sizes.filter((n) => n === 2)).toHaveLength(12);
    expect(sizes.filter((n) => n === 1)).toHaveLength(6);
  });
});

describe("les mouvements de face (calculés par la géométrie)", () => {
  it.each(FACES)("%s : quatre fois = identité ; deux fois ≠ identité", (f) => {
    expect(sameCube(run(`${f} ${f} ${f} ${f}`), solvedCube())).toBe(true);
    expect(sameCube(run(`${f} ${f}`), solvedCube())).toBe(false);
  });
  it.each(FACES)("%s puis son inverse = identité (dans les deux ordres)", (f) => {
    expect(sameCube(run(`${f} ${f}'`), solvedCube())).toBe(true);
    expect(sameCube(run(`${f}' ${f}`), solvedCube())).toBe(true);
  });
  it.each(FACES)("%s : trois quarts de tour = un quart de tour inverse", (f) => {
    expect(sameCube(run(`${f} ${f} ${f}`), run(`${f}'`))).toBe(true);
  });
  it.each(FACES)("%s déplace exactement 20 stickers (8 de sa face + 12 des côtés) et laisse son centre", (f) => {
    const before = solvedCube();
    const after = run(f);
    const key = (cube: Cube) => cube.flatMap((c) => stickersOf(c).map((s) => `${c.id}:${c.pos.join()}:${worldNormal(c, s.normal).join()}`));
    const a = key(before);
    const b = key(after);
    expect(a.filter((k, i) => k !== b[i])).toHaveLength(20);
  });
  it("les centres ne bougent jamais (mouvements de face seulement)", () => {
    const cube = run("R U F' D L B R' U' F2".replace("F2", "F F"), solvedCube());
    const centres = cube.filter((c) => stickersOf(c).length === 1);
    centres.forEach((c) => expect(c.pos.join()).toBe(c.home.join()));
  });
});

describe("le sens des mouvements (conventions du cubing)", () => {
  it("U (horaire vu d'en haut) envoie la rangée haute de la face avant vers la gauche", () => {
    expect(where(run("U"), [0, -1, 1], [0, 0, 1])).toEqual({ pos: "-1,-1,0", normal: "-1,0,0" });
  });
  it("R (horaire vu de droite) envoie la colonne droite de la face avant vers le haut", () => {
    expect(where(run("R"), [1, 0, 1], [0, 0, 1])).toEqual({ pos: "1,-1,0", normal: "0,-1,0" });
  });
  it("F (horaire vu de face) envoie le haut de la face avant vers la droite", () => {
    expect(where(run("F"), [0, -1, 1], [0, 0, 1])).toEqual({ pos: "1,0,1", normal: "0,0,1" });
  });
  it("D envoie la rangée basse de la face avant vers la droite (sens inverse de U)", () => {
    expect(where(run("D"), [0, 1, 1], [0, 0, 1])).toEqual({ pos: "1,1,0", normal: "1,0,0" });
  });
  it("L envoie la colonne gauche de la face avant vers le bas", () => {
    expect(where(run("L"), [-1, 0, 1], [0, 0, 1])).toEqual({ pos: "-1,1,0", normal: "0,1,0" });
  });
  it("B envoie le haut de la face arrière vers la gauche (vu de l'arrière : horaire)", () => {
    expect(where(run("B"), [0, -1, -1], [0, 0, -1])).toEqual({ pos: "-1,0,-1", normal: "0,0,-1" });
  });
});

describe("algorithmes connus (preuves que la géométrie est juste)", () => {
  const sexy = "R U R' U'";
  it("(R U R' U') répété 6 fois = identité, pas avant", () => {
    for (let n = 1; n < 6; n++) expect(sameCube(run(Array(n).fill(sexy).join(" ")), solvedCube()), `${n} fois`).toBe(false);
    expect(sameCube(run(Array(6).fill(sexy).join(" ")), solvedCube())).toBe(true);
  });
  /** Plus petit n tel que (alg)^n a l'apparence du cube résolu (centres libres de tourner sur eux-mêmes). */
  const order = (alg: string, max = 400): number => {
    let cube = solvedCube();
    for (let n = 1; n <= max; n++) {
      cube = run(alg, cube);
      if (looksTheSame(cube, solvedCube())) return n;
    }
    return -1;
  };
  it("(R U) a pour ordre 105", () => expect(order("R U")).toBe(105));
  it("(R U') a pour ordre 63", () => expect(order("R U'")).toBe(63));
  it("(R' U) a pour ordre 63 et (R' U') 105 (symétrie du cube)", () => {
    expect(order("R' U")).toBe(63);
    expect(order("R' U'")).toBe(105);
  });
  it("(F R) a pour ordre 105 (même ordre que (R U) : les trois axes sont équivalents)", () => expect(order("F R")).toBe(105));
  it("(R U R' U') a pour ordre 6 et un seul mouvement d'une face pour ordre 4", () => {
    expect(order("R U R' U'")).toBe(6);
    expect(order("R")).toBe(4);
  });
  it("le « sune » (R U R' U R U2 R') a pour ordre 6 ; un mouvement double a pour ordre 2", () => {
    expect(order("R U R' U R U U R'")).toBe(6);
    expect(order("R R")).toBe(2);
  });
  it("le commutateur (R U R' U') déplace des pièces mais jamais les centres", () => {
    const cube = run(sexy);
    expect(isSolved(cube)).toBe(false);
    cube.filter((c) => stickersOf(c).length === 1).forEach((c) => expect(c.pos.join()).toBe(c.home.join()));
  });
  it("deux faces opposées commutent (R L = L R) ; deux faces voisines non (R U ≠ U R)", () => {
    expect(sameCube(run("R L"), run("L R"))).toBe(true);
    expect(sameCube(run("U D'"), run("D' U"))).toBe(true);
    expect(sameCube(run("R U"), run("U R"))).toBe(false);
  });
});

describe("invariants après un mélange", () => {
  it("garde 9 stickers de chaque couleur et les 6 centres à leur place", () => {
    for (let seed = 1; seed <= 20; seed++) {
      const cube = applyMoves(solvedCube(), scramble(seeded(seed), 30));
      const counts = new Map<string, number>();
      cube.flatMap((c) => stickersOf(c).map((s) => s.color)).forEach((c) => counts.set(c, (counts.get(c) ?? 0) + 1));
      expect([...counts.values()]).toEqual([9, 9, 9, 9, 9, 9]);
      cube.filter((c) => stickersOf(c).length === 1).forEach((c) => expect(c.pos.join()).toBe(c.home.join()));
    }
  });
  it("garde les positions dans {-1,0,1} et les rotations orthogonales entières", () => {
    const cube = applyMoves(solvedCube(), scramble(seeded(7), 40));
    cube.forEach((c) => {
      c.pos.forEach((p) => expect([-1, 0, 1]).toContain(p));
      const flat = c.rot.flat();
      flat.forEach((v) => expect([-1, 0, 1]).toContain(v));
      c.rot.forEach((row) => expect(row.reduce((s, v) => s + Math.abs(v), 0)).toBe(1));   // une seule composante non nulle par ligne
    });
  });
  it("les 26 cubies restent distincts (jamais deux au même endroit)", () => {
    const cube = applyMoves(solvedCube(), scramble(seeded(3), 50));
    expect(new Set(cube.map((c) => c.pos.join())).size).toBe(26);
  });
  it("la somme des torsions de coins reste nulle modulo 3 (le cube reste résoluble)", () => {
    // Une torsion de coin = position de son sticker « haut/bas » (axe y d'origine) après les mouvements : 0 si sur U/D, sinon 1 ou 2.
    for (let seed = 1; seed <= 10; seed++) {
      const cube = applyMoves(solvedCube(), scramble(seeded(seed), 35));
      let twist = 0;
      for (const c of cube.filter((x) => stickersOf(x).length === 3)) {
        const yWorld = worldNormal(c, [0, c.home[1], 0]);                  // où est allé le sticker haut/bas de ce coin ?
        const ax = yWorld.findIndex((v) => v !== 0);                      // 1 = encore sur U/D (torsion 0)
        // Torsion 1 ou 2 selon le sens, calculé de façon canonique : (axe − 1) mod 3 avec le signe de l'orientation du coin.
        const sign = c.pos[0] * c.pos[1] * c.pos[2];                       // chiralité du coin à sa position actuelle
        const t = ax === 1 ? 0 : (((ax === 0 ? 1 : 2) * (sign > 0 ? 1 : -1)) % 3 + 3) % 3;
        twist += t;
      }
      expect(twist % 3).toBe(0);
    }
  });
});

describe("notation", () => {
  it("formate et relit un mélange sans perte", () => {
    const m = scramble(seeded(11), 25);
    expect(parseMoves(formatMoves(m))).toEqual(m);
  });
  it("refuse une notation invalide (jamais un résultat partiel)", () => {
    for (const bad of ["X", "R2", "R''", "r", "R U Z", "R-"]) expect(parseMoves(bad), bad).toBeNull();
    expect(parseMoves("")).toEqual([]);
    expect(parseMoves("  R   U'  ")).toEqual(mv("R U'"));
  });
  it("l'inverse d'une suite la défait exactement", () => {
    const m = scramble(seeded(5), 30);
    expect(sameCube(applyMoves(applyMoves(solvedCube(), m), invertMoves(m)), solvedCube())).toBe(true);
    expect(invertMove({ face: "R", inverse: false })).toEqual({ face: "R", inverse: true });
  });
});

describe("le mélange", () => {
  it("fait la longueur demandée, jamais deux fois la même face de suite, et ne rend jamais un cube résolu", () => {
    for (let seed = 1; seed <= 50; seed++) {
      const m = scramble(seeded(seed), 25);
      expect(m).toHaveLength(25);
      m.slice(1).forEach((x, i) => expect(x.face).not.toBe(m[i].face));
      expect(isSolved(applyMoves(solvedCube(), m))).toBe(false);
    }
  });
  it("est reproductible avec la même graine et varie avec une autre", () => {
    expect(scramble(seeded(42), 25)).toEqual(scramble(seeded(42), 25));
    expect(scramble(seeded(42), 25)).not.toEqual(scramble(seeded(43), 25));
  });
  it("refuse un mélange qui laisserait le cube résolu et en tire un autre (R L R' L' commute et se défait)", () => {
    // Un générateur truqué qui choisit R, L, R', L' : quatre mouvements valides (jamais la même face de suite) qui ANNULENT tout.
    const forced = [0.5, 0.9, 0.41, 0.9, 0.41, 0.1, 0.41, 0.1];
    let i = 0;
    const rng = () => (i < forced.length ? forced[i++] : 0.05);
    const m = scramble(rng, 4);
    expect(formatMoves(m)).not.toBe("R L R' L'");
    expect(isSolved(applyMoves(solvedCube(), m))).toBe(false);
    // Et le même générateur, sans la précaution, aurait bien donné un cube résolu :
    expect(isSolved(run("R L R' L'"))).toBe(true);
  });
  it("utilise les six faces sur un mélange assez long", () => {
    const faces = new Set(scramble(seeded(9), 60).map((m) => m.face));
    expect(faces.size).toBe(6);
  });
  it("se défait en rejouant l'inverse : c'est la « solution » du mélange", () => {
    const m = scramble(seeded(8), 25);
    const solved = applyMoves(applyMoves(solvedCube(), m), invertMoves(m));
    expect(isSolved(solved)).toBe(true);
  });
  it("est résolu quand on rejoue le mélange puis son inverse, même à mi-chemin du côté opposé", () => {
    const m = scramble(seeded(13), 25);
    const half = applyMoves(solvedCube(), m.slice(0, 12));
    expect(isSolved(applyMoves(half, invertMoves(m.slice(0, 12))))).toBe(true);
  });
});

describe("l'affichage", () => {
  it("la matrice CSS d'un cubie à sa place d'origine est une simple translation", () => {
    const c = solvedCube().find((x) => x.home.join() === "1,-1,1")!;
    expect(cubieMatrix3d(c, 60)).toBe("matrix3d(1,0,0,0,0,1,0,0,0,0,1,0,60,-60,60,1)");
  });
  it("la matrice CSS suit la rotation : après R, le cubie avant-droite-haut est devenu haut-droite-arrière", () => {
    const c = run("R").find((x) => x.home.join() === "1,0,1")!;
    expect(c.pos.join()).toBe("1,-1,0");
    // rotateX(+90°) : (x,y,z) → (x,−z,y) — la matrice porte cette rotation puis la translation.
    expect(cubieMatrix3d(c, 10)).toBe("matrix3d(1,0,0,0,0,0,1,0,0,-1,0,0,10,-10,0,1)");
  });
  it("aucune matrice ne contient « -0 » ni de valeur non numérique", () => {
    applyMoves(solvedCube(), scramble(seeded(2), 30)).forEach((c) => expect(cubieMatrix3d(c, 60)).not.toMatch(/-0(?![.\d])|NaN|undefined/));
  });
  it("rotateX(90°) envoie y vers z et z vers −y (comme CSS)", () => {
    const a = viewProject([0, 1, 0], 90, 0);
    expect(a[1]).toBeCloseTo(0);
    expect(a[2]).toBeCloseTo(1);
    const b = viewProject([0, 0, 1], 90, 0);
    expect(b[1]).toBeCloseTo(-1);
    expect(b[2]).toBeCloseTo(0);
  });
  it("la projection de la vue identité garde x et y ; la vue pivotée de 90° en Y envoie z vers x", () => {
    expect(viewProject([1, 2, 3], 0, 0)).toEqual([1, 2, 3]);
    const p = viewProject([0, 0, 1], 0, 90);
    expect(p[0]).toBeCloseTo(1);
    expect(p[2]).toBeCloseTo(0);
  });
});

describe("le glissé du doigt sur un sticker", () => {
  const front = (pos: [number, number, number]) => ({ cubie: solvedCube().find((c) => c.home.join() === pos.join())!, normal: [0, 0, 1] as [number, number, number] });

  it("vue de face : glisser vers la droite la rangée haute de la face avant = U' (la face avant va vers la droite)", () => {
    const { cubie, normal } = front([0, -1, 1]);
    expect(moveFromDrag(cubie, normal, [50, 0], 0, 0)).toEqual({ face: "U", inverse: true });
    expect(moveFromDrag(cubie, normal, [-50, 0], 0, 0)).toEqual({ face: "U", inverse: false });
  });
  it("vue de face : glisser vers le haut la colonne droite = R (elle monte), vers le bas = R'", () => {
    const { cubie, normal } = front([1, 0, 1]);
    expect(moveFromDrag(cubie, normal, [0, -50], 0, 0)).toEqual({ face: "R", inverse: false });
    expect(moveFromDrag(cubie, normal, [0, 50], 0, 0)).toEqual({ face: "R", inverse: true });
  });
  it("la colonne gauche : vers le bas = L, vers le haut = L'", () => {
    const { cubie, normal } = front([-1, 0, 1]);
    expect(moveFromDrag(cubie, normal, [0, 50], 0, 0)).toEqual({ face: "L", inverse: false });
  });
  it("la rangée basse : vers la droite = D, vers la gauche = D'", () => {
    const { cubie, normal } = front([0, 1, 1]);
    expect(moveFromDrag(cubie, normal, [50, 0], 0, 0)).toEqual({ face: "D", inverse: false });
  });
  it("un glissé en diagonale choisit l'axe le plus proche de la direction", () => {
    const { cubie, normal } = front([1, -1, 1]);                       // coin haut-droit : U ou R
    expect(moveFromDrag(cubie, normal, [50, -10], 0, 0)?.face).toBe("U");
    expect(moveFromDrag(cubie, normal, [10, -50], 0, 0)?.face).toBe("R");
  });
  it("le mouvement choisi déplace d'abord le sticker dans le sens du doigt, dans toutes les vues", () => {
    // Vérification INDÉPENDANTE du code : on tourne le point d'un petit angle par la formule de rotation continue (cos / sin),
    // et on regarde de quel côté il part à l'écran.
    const rotate = (axis: 0 | 1 | 2, a: number, v: readonly [number, number, number]): [number, number, number] => {
      const c = Math.cos(a), s = Math.sin(a);
      if (axis === 0) return [v[0], v[1] * c - v[2] * s, v[1] * s + v[2] * c];
      if (axis === 1) return [v[0] * c + v[2] * s, v[1], -v[0] * s + v[2] * c];
      return [v[0] * c - v[1] * s, v[0] * s + v[1] * c, v[2]];
    };
    let checked = 0;
    for (const [rx, ry] of [[0, 0], [-30, -40], [25, 70], [-15, 140], [-35, -135], [60, 200], [-60, 20]]) {
      const cube = solvedCube();
      for (const c of cube) for (const st of stickersOf(c)) {
        for (const drag of [[60, 0], [-60, 0], [0, 60], [0, -60], [40, 40], [-40, 30]] as const) {
          const m = moveFromDrag(c, st.normal, drag, rx, ry);
          if (!m) continue;
          const t = moveTurn(m);
          const centre: [number, number, number] = [c.pos[0] + st.normal[0] / 2, c.pos[1] + st.normal[1] / 2, c.pos[2] + st.normal[2] / 2];
          const eps = 0.001 * t.quarter;
          const a = viewProject(centre, rx, ry);
          const b = viewProject(rotate(t.axis, eps, centre), rx, ry);
          const moved = [b[0] - a[0], b[1] - a[1]];
          expect(moved[0] * drag[0] + moved[1] * drag[1], `vue ${rx},${ry} sticker ${c.pos}/${st.normal} glissé ${drag}`).toBeGreaterThan(0);
          checked++;
        }
      }
    }
    expect(checked).toBeGreaterThan(1000);   // le test a vraiment examiné beaucoup de cas
  });
  it("un glissé sur une face ne la fait jamais pivoter sur elle-même : seules les faces VOISINES tournent", () => {
    const cube = solvedCube();
    for (const [rx, ry] of [[0, 0], [-30, -40], [25, 70], [-35, -135]]) {
      for (const c of cube) for (const st of stickersOf(c)) {
        for (const drag of [[60, 0], [0, 60], [40, 40], [-40, 40], [-60, -10]] as const) {
          const m = moveFromDrag(c, st.normal, drag, rx, ry);
          if (!m) continue;
          const turned = moveTurn(m).axis;
          const normalAxis = st.normal.findIndex((v) => v !== 0);
          expect(turned, `sticker ${c.pos}/${st.normal}`).not.toBe(normalAxis);
        }
      }
    }
  });
  it("une rangée du milieu ne tourne rien (seules les faces tournent)", () => {
    const { cubie, normal } = front([0, 0, 1]);                          // centre de la face avant
    expect(moveFromDrag(cubie, normal, [50, 0], 0, 0)).toBeNull();
    expect(moveFromDrag(cubie, normal, [0, 50], 0, 0)).toBeNull();
    const edge = front([1, 0, 1]);                                       // arête milieu droite : glisser à l'horizontale = couche du milieu
    expect(moveFromDrag(edge.cubie, edge.normal, [50, 0], 0, 0)).toBeNull();
  });
  it("un glissé nul ne fait rien", () => {
    const { cubie, normal } = front([1, -1, 1]);
    expect(moveFromDrag(cubie, normal, [0, 0], 0, 0)).toBeNull();
  });
  it("fonctionne sur toutes les faces visibles dans la vue par défaut", () => {
    const cube = solvedCube();
    for (const c of cube) for (const s of stickersOf(c)) {
      const m = moveFromDrag(c, s.normal, [40, 25], -30, -40);
      if (m) expect(FACES).toContain(m.face as Face);
    }
  });
});
