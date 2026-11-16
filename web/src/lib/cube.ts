/**
 * Moteur du Rubik's Cube : un MODÈLE géométrique, sans aucune dépendance à l'écran.
 *
 * Repère : celui de CSS (x vers la droite, y vers le BAS, z vers le spectateur), pour que le modèle et l'affichage 3D parlent
 * la même langue. Le cube est fait de 26 « cubies » (le centre caché n'existe pas) ; chacun a sa position `pos` dans {-1,0,1}³
 * et une orientation `rot` (matrice de rotation entière). Un mouvement de face tourne les 9 cubies d'une couche d'un quart de tour :
 * rien n'est écrit « à la main » (pas de tables de permutations), tout vient de la géométrie, donc aucune erreur de table possible.
 *
 * Quart de tour « positif » autour d'un axe = la rotation CSS `rotateX/Y/Z(+90deg)` :
 *   x : (x,y,z) → (x,−z,y)   y : (x,y,z) → (z,y,−x)   z : (x,y,z) → (−y,x,z)
 * Une face tourne « dans le sens des aiguilles d'une montre vu de l'extérieur » : +1 quart autour de son axe pour R, D, F
 * (faces du côté +) et −1 pour L, U, B (faces du côté −).
 */

export type Vec = readonly [number, number, number];
export type Mat = readonly [Vec, Vec, Vec];
export type Face = "U" | "D" | "L" | "R" | "F" | "B";

export type Cubie = { id: number; home: Vec; pos: Vec; rot: Mat };
export type Cube = readonly Cubie[];

/** Un mouvement de face : « R » (sens horaire) ou « R' » (anti-horaire). */
export type Move = { face: Face; inverse: boolean };

export const FACES: readonly Face[] = ["U", "D", "L", "R", "F", "B"];

/** Axe (0 = x, 1 = y, 2 = z), côté (+1 / −1) et couleur du cube résolu de chaque face. */
export const FACE_INFO: Record<Face, { axis: 0 | 1 | 2; side: 1 | -1; color: Color }> = {
  R: { axis: 0, side: 1, color: "red" },
  L: { axis: 0, side: -1, color: "orange" },
  D: { axis: 1, side: 1, color: "yellow" },
  U: { axis: 1, side: -1, color: "white" },
  F: { axis: 2, side: 1, color: "green" },
  B: { axis: 2, side: -1, color: "blue" },
};

export type Color = "white" | "yellow" | "red" | "orange" | "green" | "blue";

const IDENTITY: Mat = [
  [1, 0, 0],
  [0, 1, 0],
  [0, 0, 1],
];

/** Matrice du quart de tour positif autour de chaque axe (voir l'en-tête). */
const QUARTER: readonly Mat[] = [
  [
    [1, 0, 0],
    [0, 0, -1],
    [0, 1, 0],
  ],
  [
    [0, 0, 1],
    [0, 1, 0],
    [-1, 0, 0],
  ],
  [
    [0, -1, 0],
    [1, 0, 0],
    [0, 0, 1],
  ],
];

/** Matrice d'un quart de tour `quarter` (+1 ou −1) autour de l'axe. */
export function quarterMatrix(axis: 0 | 1 | 2, quarter: 1 | -1): Mat {
  const q = QUARTER[axis];
  // −1 quart = transposée de la matrice (rotation orthogonale).
  return quarter === 1 ? q : [[q[0][0], q[1][0], q[2][0]], [q[0][1], q[1][1], q[2][1]], [q[0][2], q[1][2], q[2][2]]];
}

export function mulMat(a: Mat, b: Mat): Mat {
  const r = (i: number, j: number) => a[i][0] * b[0][j] + a[i][1] * b[1][j] + a[i][2] * b[2][j];
  return [
    [r(0, 0), r(0, 1), r(0, 2)],
    [r(1, 0), r(1, 1), r(1, 2)],
    [r(2, 0), r(2, 1), r(2, 2)],
  ];
}

export function mulVec(m: Mat, v: Vec): Vec {
  // `+ 0` : jamais de « -0 » (les comparaisons strictes et les tests s'en trouvent plus simples).
  return [m[0][0] * v[0] + m[0][1] * v[1] + m[0][2] * v[2] + 0, m[1][0] * v[0] + m[1][1] * v[1] + m[1][2] * v[2] + 0, m[2][0] * v[0] + m[2][1] * v[1] + m[2][2] * v[2] + 0];
}

/** Le cube résolu : 26 cubies (le centre (0,0,0) n'a aucune face visible). */
export function solvedCube(): Cube {
  const out: Cubie[] = [];
  let id = 0;
  for (let x = -1; x <= 1; x++)
    for (let y = -1; y <= 1; y++)
      for (let z = -1; z <= 1; z++) {
        if (x === 0 && y === 0 && z === 0) continue;
        out.push({ id: id++, home: [x, y, z], pos: [x, y, z], rot: IDENTITY });
      }
  return out;
}

/** Tourne la couche `layer` (−1, 0 ou 1) de l'axe `axis` d'un quart de tour. */
export function turnLayer(cube: Cube, axis: 0 | 1 | 2, layer: -1 | 0 | 1, quarter: 1 | -1): Cube {
  const q = quarterMatrix(axis, quarter);
  return cube.map((c) => (c.pos[axis] === layer ? { ...c, pos: mulVec(q, c.pos), rot: mulMat(q, c.rot) } : c));
}

/** Les trois paramètres d'un mouvement de face : axe, couche et quart de tour. */
export function moveTurn(m: Move): { axis: 0 | 1 | 2; layer: -1 | 1; quarter: 1 | -1 } {
  const { axis, side } = FACE_INFO[m.face];
  // Sens horaire vu de l'extérieur : +1 quart pour les faces du côté +, −1 pour celles du côté − ; l'inverse change le signe.
  const clockwise = side;
  return { axis, layer: side, quarter: (m.inverse ? -clockwise : clockwise) as 1 | -1 };
}

export function applyMove(cube: Cube, m: Move): Cube {
  const t = moveTurn(m);
  return turnLayer(cube, t.axis, t.layer, t.quarter);
}

export function applyMoves(cube: Cube, moves: readonly Move[]): Cube {
  return moves.reduce(applyMove, cube);
}

export function invertMove(m: Move): Move {
  return { face: m.face, inverse: !m.inverse };
}

export function invertMoves(moves: readonly Move[]): Move[] {
  return [...moves].reverse().map(invertMove);
}

export const formatMove = (m: Move): string => m.face + (m.inverse ? "'" : "");

export function formatMoves(moves: readonly Move[]): string {
  return moves.map(formatMove).join(" ");
}

/** « R U' F » → mouvements ; une notation invalide donne null (jamais un demi-résultat). */
export function parseMoves(text: string): Move[] | null {
  const out: Move[] = [];
  for (const token of text.trim().split(/\s+/).filter(Boolean)) {
    const m = /^([UDLRFB])('?)$/.exec(token);
    if (!m) return null;
    out.push({ face: m[1] as Face, inverse: m[2] === "'" });
  }
  return out;
}

/** Les stickers d'un cubie : sa normale LOCALE (dans son propre repère) et sa couleur. */
export function stickersOf(c: Cubie): { normal: Vec; color: Color }[] {
  const out: { normal: Vec; color: Color }[] = [];
  for (const axis of [0, 1, 2] as const) {
    const side = c.home[axis];
    if (side === 0) continue;
    const normal: [number, number, number] = [0, 0, 0];
    normal[axis] = side;
    const face = FACES.find((f) => FACE_INFO[f].axis === axis && FACE_INFO[f].side === side)!;
    out.push({ normal, color: FACE_INFO[face].color });
  }
  return out;
}

/** Normale d'un sticker dans le MONDE (après les rotations du cubie). */
export const worldNormal = (c: Cubie, local: Vec): Vec => mulVec(c.rot, local);

/** Résolu = chacune des six faces est d'une seule couleur (quelle que soit l'orientation d'ensemble du cube). */
export function isSolved(cube: Cube): boolean {
  const seen = new Map<string, Color>();
  for (const c of cube) {
    for (const s of stickersOf(c)) {
      const key = worldNormal(c, s.normal).join(",");
      const known = seen.get(key);
      if (known === undefined) seen.set(key, s.color);
      else if (known !== s.color) return false;
    }
  }
  return true;
}

/**
 * Deux cubes ont-ils le MÊME ASPECT ? On compare ce qu'on voit : la couleur de chaque sticker à chaque place. Un centre qui a tourné sur
 * lui-même n'est pas un changement (il n'a qu'une couleur) : après (R U)×105, tout est revenu sauf, éventuellement, cette rotation invisible.
 */
export function looksTheSame(a: Cube, b: Cube): boolean {
  const view = (cube: Cube) =>
    cube
      .flatMap((c) => stickersOf(c).map((s) => `${c.pos.join()}|${worldNormal(c, s.normal).join()}=${s.color}`))
      .sort()
      .join(";");
  return view(a) === view(b);
}

/** Deux cubes identiques (positions ET orientations, y compris celle des centres) ? */
export function sameCube(a: Cube, b: Cube): boolean {
  return a.length === b.length && a.every((c, i) => c.id === b[i].id && c.pos.join() === b[i].pos.join() && c.rot.flat().join() === b[i].rot.flat().join());
}

/**
 * Un mélange de `length` quarts de tour : jamais deux fois la même face de suite (un quart de tour annulerait ou doublerait le
 * précédent), et jamais un cube déjà résolu. `rng` renvoie un nombre dans [0, 1[ : injecté pour des tests reproductibles.
 */
export function scramble(rng: () => number, length = 25): Move[] {
  for (let attempt = 0; attempt < 20; attempt++) {
    const moves: Move[] = [];
    let last: Face | null = null;
    for (let i = 0; i < length; i++) {
      const pool = FACES.filter((f) => f !== last);
      const face = pool[Math.min(pool.length - 1, Math.floor(rng() * pool.length))];
      moves.push({ face, inverse: rng() < 0.5 });
      last = face;
    }
    if (!isSolved(applyMoves(solvedCube(), moves))) return moves;
  }
  // Pratiquement inatteignable ; on garantit quand même un cube non résolu.
  return [{ face: "R", inverse: false }, { face: "U", inverse: false }];
}

// ------------------------------------------------------------------------------------------- affichage

/** Matrice CSS `matrix3d(...)` d'un cubie : orientation `rot`, puis translation `pos × size` (colonnes d'abord, comme CSS). */
export function cubieMatrix3d(c: Cubie, size: number): string {
  const r = c.rot;
  const n = (v: number) => (Math.abs(v) < 1e-9 ? 0 : v);
  const t = c.pos.map((p) => n(p * size));
  return `matrix3d(${r[0][0]},${r[1][0]},${r[2][0]},0,${r[0][1]},${r[1][1]},${r[2][1]},0,${r[0][2]},${r[1][2]},${r[2][2]},0,${t[0]},${t[1]},${t[2]},1)`;
}

/** Projette un vecteur du MONDE à l'écran pour une vue `rotateX(rx) rotateY(ry)` (degrés) : x vers la droite, y vers le bas, z vers le spectateur. */
export function viewProject(v: Vec, rx: number, ry: number): Vec {
  const a = (rx * Math.PI) / 180;
  const b = (ry * Math.PI) / 180;
  // CSS : rotateX(a) rotateY(b) s'applique au point comme Rx · Ry · p.
  const x1 = v[0] * Math.cos(b) + v[2] * Math.sin(b);
  const y1 = v[1];
  const z1 = -v[0] * Math.sin(b) + v[2] * Math.cos(b);
  return [x1, y1 * Math.cos(a) - z1 * Math.sin(a), y1 * Math.sin(a) + z1 * Math.cos(a)];
}

/**
 * Le mouvement de face voulu par un glissé du doigt sur un sticker.
 *
 * `cubie` et `localNormal` désignent le sticker touché ; `drag` est le déplacement à l'écran (pixels, y vers le bas) ; `rx`, `ry` la vue.
 * Parmi les deux axes perpendiculaires à la face touchée, on retient celui dont le quart de tour déplace le sticker dans la direction
 * la plus proche du glissé. Seules les couches EXTÉRIEURES tournent (une face) : un glissé sur une rangée du milieu ne fait rien (null).
 */
export function moveFromDrag(cubie: Cubie, localNormal: Vec, drag: readonly [number, number], rx: number, ry: number): Move | null {
  const n = worldNormal(cubie, localNormal);
  const len = Math.hypot(drag[0], drag[1]);
  if (len < 1e-6) return null;
  const centre: Vec = [cubie.pos[0] + n[0] / 2, cubie.pos[1] + n[1] / 2, cubie.pos[2] + n[2] / 2];

  let best: { axis: 0 | 1 | 2; quarter: 1 | -1; score: number } | null = null;
  for (const axis of [0, 1, 2] as const) {
    if (n[axis] !== 0) continue;                       // l'axe de la face touchée ne la fait que pivoter sur elle-même
    // Le doigt suit la VITESSE INITIALE du sticker (pas son déplacement final, qui peut changer de sens en vue oblique) :
    // pour le quart de tour positif autour de l'axe e, elle vaut e × centre.
    const e: [number, number, number] = [0, 0, 0];
    e[axis] = 1;
    const velocity: Vec = [e[1] * centre[2] - e[2] * centre[1], e[2] * centre[0] - e[0] * centre[2], e[0] * centre[1] - e[1] * centre[0]];
    const moved = viewProject(velocity, rx, ry);
    const mag = Math.hypot(moved[0], moved[1]);
    if (mag < 1e-3) continue;                          // axe vu de bout : sa direction à l'écran n'a pas de sens
    const dot = (moved[0] * drag[0] + moved[1] * drag[1]) / (mag * len);
    if (best === null || Math.abs(dot) > Math.abs(best.score)) best = { axis, quarter: dot >= 0 ? 1 : -1, score: dot };
  }
  if (best === null) return null;

  const layer = cubie.pos[best.axis];
  if (layer === 0) return null;                        // rangée du milieu : pas une face
  const face = FACES.find((f) => FACE_INFO[f].axis === best.axis && FACE_INFO[f].side === layer)!;
  const clockwise = FACE_INFO[face].side;              // quart « horaire » de cette face
  return { face, inverse: best.quarter !== clockwise };
}
