"use client";

import gsap from "gsap";
import { Eye, EyeOff, Keyboard, RotateCcw, Shuffle, Timer, Trophy, Undo2, Move3d } from "lucide-react";
import { memo, useCallback, useEffect, useMemo, useRef, useState, useSyncExternalStore, type CSSProperties, type PointerEvent as ReactPointerEvent } from "react";
import { AppShell } from "@/components/app-shell";
import { StatusChip } from "@/components/status-chip";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import {
  FACES,
  applyMove,
  applyMoves,
  cubieMatrix3d,
  formatMoves,
  invertMove,
  isSolved,
  moveFromDrag,
  moveTurn,
  scramble,
  solvedCube,
  stickersOf,
  type Color,
  type Cube,
  type Cubie,
  type Face,
  type Move,
  type Vec,
} from "@/lib/cube";
import { formatClock } from "@/lib/format";
import { cn } from "@/lib/utils";

/** Taille d'un cubie, en pixels, dans le repère de la scène (l'ensemble est ensuite mis à l'échelle de l'écran). */
const S = 56;
const DEFAULT_VIEW = { rx: -28, ry: -38 };
const BEST_KEY = "registrar:rubik:best";

const COLORS: Record<Color, { bg: string; symbol: string; name: string }> = {
  white: { bg: "#f2f2f2", symbol: "○", name: "blanc" },
  yellow: { bg: "#ffd500", symbol: "□", name: "jaune" },
  red: { bg: "#d32f2f", symbol: "▲", name: "rouge" },
  orange: { bg: "#ff7a00", symbol: "◆", name: "orange" },
  green: { bg: "#1faa59", symbol: "●", name: "vert" },
  blue: { bg: "#1e66d0", symbol: "■", name: "bleu" },
};

const FACE_LABEL: Record<Face, string> = { U: "Haut", D: "Bas", L: "Gauche", R: "Droite", F: "Devant", B: "Derrière" };
const AXIS_NAME = ["X", "Y", "Z"] as const;

/** Transformation de chacune des six faces d'un cubie (dans son propre repère), selon la normale locale. */
function faceTransform(n: Vec): string {
  const h = S / 2;
  if (n[2] === 1) return `translateZ(${h}px)`;
  if (n[2] === -1) return `rotateY(180deg) translateZ(${h}px)`;
  if (n[0] === 1) return `rotateY(90deg) translateZ(${h}px)`;
  if (n[0] === -1) return `rotateY(-90deg) translateZ(${h}px)`;
  if (n[1] === 1) return `rotateX(-90deg) translateZ(${h}px)`;
  return `rotateX(90deg) translateZ(${h}px)`;
}

const LOCAL_FACES: Vec[] = [[0, 0, 1], [0, 0, -1], [1, 0, 0], [-1, 0, 0], [0, 1, 0], [0, -1, 0]];

function readBest(): number | null {
  try {
    const v = Number(window.localStorage.getItem(BEST_KEY));
    return Number.isFinite(v) && v > 0 ? v : null;
  } catch {
    return null;
  }
}

/** Dernier record connu de cette visite, au cas où le navigateur refuse d'écrire (navigation privée). */
let sessionBest: number | null = null;
const bestListeners = new Set<() => void>();

function writeBest(ms: number): void {
  sessionBest = ms;
  try {
    window.localStorage.setItem(BEST_KEY, String(ms));
  } catch {
    /* navigation privée : le record vit seulement pendant la visite */
  }
  bestListeners.forEach((l) => l());
}

function subscribeBest(listener: () => void): () => void {
  bestListeners.add(listener);
  window.addEventListener("storage", listener);   // un autre onglet bat le record
  return () => {
    bestListeners.delete(listener);
    window.removeEventListener("storage", listener);
  };
}

const currentBest = (): number | null => readBest() ?? sessionBest;

const reducedMotion = () => typeof window !== "undefined" && window.matchMedia("(prefers-reduced-motion: reduce)").matches;

type Phase = "solved" | "scrambled" | "won";
type Drag = { id: number; x0: number; y0: number; mode: "orbit" | "turn"; rx0: number; ry0: number; cubie?: Cubie; normal?: Vec; done: boolean };

export function RubikGame() {
  const [cube, setCube] = useState<Cube>(() => solvedCube());
  const [view, setView] = useState(DEFAULT_VIEW);
  const [anim, setAnim] = useState<{ axis: 0 | 1 | 2; layer: -1 | 1; degrees: number } | null>(null);
  const [phase, setPhase] = useState<Phase>("solved");
  const [moves, setMoves] = useState(0);
  const [elapsed, setElapsed] = useState(0);
  // Le record vit dans localStorage : useSyncExternalStore le lit côté navigateur seulement (null pendant la préparation du site).
  const best = useSyncExternalStore(subscribeBest, currentBest, () => null);
  const [newRecord, setNewRecord] = useState(false);
  const [scrambleText, setScrambleText] = useState("");
  const [symbols, setSymbols] = useState(false);
  const [canUndo, setCanUndo] = useState(false);
  const [finish, setFinish] = useState<{ ms: number; moves: number } | null>(null);
  const [scale, setScale] = useState(1);

  // Tout ce que les rappels asynchrones (animation, clavier, glisser) doivent lire À JOUR vit dans des refs.
  const cubeRef = useRef(cube);
  const viewRef = useRef(view);
  const phaseRef = useRef<Phase>("solved");
  const movesRef = useRef(0);
  const historyRef = useRef<Move[]>([]);
  const queueRef = useRef<{ move: Move; kind: "player" | "undo" }[]>([]);
  const busyRef = useRef(false);
  const startedAtRef = useRef<number | null>(null);
  const tweenRef = useRef<gsap.core.Tween | null>(null);
  const dragRef = useRef<Drag | null>(null);
  const stageRef = useRef<HTMLDivElement>(null);
  const aliveRef = useRef(true);
  const pumpRef = useRef<() => void>(() => {});

  useEffect(() => {
    aliveRef.current = true;
    return () => {
      aliveRef.current = false;
      tweenRef.current?.kill();
    };
  }, []);

  // Échelle de la scène : le cube tient dans la largeur disponible (téléphone comme ordinateur).
  useEffect(() => {
    const el = stageRef.current;
    if (!el) return;
    const fit = () => setScale(Math.max(0.55, Math.min(1.25, el.clientWidth / 330)));
    fit();
    const ro = new ResizeObserver(fit);
    ro.observe(el);
    return () => ro.disconnect();
  }, []);

  // Chronomètre : seulement entre le premier mouvement d'un mélange et la résolution.
  const hasMoved = moves > 0;
  useEffect(() => {
    if (phase !== "scrambled" || !hasMoved || startedAtRef.current === null) return;
    const id = window.setInterval(() => {
      if (startedAtRef.current !== null) setElapsed(Date.now() - startedAtRef.current);
    }, 250);
    return () => window.clearInterval(id);
  }, [phase, hasMoved]);

  const commit = useCallback((move: Move, kind: "player" | "undo") => {
    const next = applyMove(cubeRef.current, move);
    cubeRef.current = next;
    setCube(next);
    setAnim(null);

    if (kind === "player") {
      historyRef.current.push(move);
      movesRef.current += 1;
      if (phaseRef.current === "scrambled" && startedAtRef.current === null) startedAtRef.current = Date.now();
    } else {
      historyRef.current.pop();
      movesRef.current = Math.max(0, movesRef.current - 1);
    }
    setMoves(movesRef.current);
    setCanUndo(historyRef.current.length > 0);

    // Résolution : seulement pour un cube MÉLANGÉ par le jeu puis résolu par le joueur (jamais un cube déjà résolu).
    if (phaseRef.current === "scrambled" && startedAtRef.current !== null && isSolved(next)) {
      const ms = Date.now() - startedAtRef.current;
      phaseRef.current = "won";
      startedAtRef.current = null;
      setPhase("won");
      setElapsed(ms);
      setFinish({ ms, moves: movesRef.current });
      const previous = readBest();
      if (previous === null || ms < previous) {
        writeBest(ms);
        setNewRecord(true);
      }
    }
  }, []);

  const pump = useCallback(() => {
    if (busyRef.current || !aliveRef.current) return;
    const item = queueRef.current.shift();
    if (!item) return;
    busyRef.current = true;
    const t = moveTurn(item.move);
    const finish = () => {
      busyRef.current = false;
      if (!aliveRef.current) return;
      commit(item.move, item.kind);
      pumpRef.current();   // le mouvement suivant de la file, s'il y en a un
    };
    if (reducedMotion()) {
      finish();
      return;
    }
    const progress = { v: 0 };
    // Plus la file est longue, plus on accélère : un joueur qui enchaîne les touches ne doit jamais attendre.
    const duration = queueRef.current.length > 1 ? 0.08 : queueRef.current.length === 1 ? 0.14 : 0.22;
    tweenRef.current = gsap.to(progress, {
      v: 1,
      duration,
      ease: "power2.inOut",
      onUpdate: () => setAnim({ axis: t.axis, layer: t.layer, degrees: progress.v * t.quarter * 90 }),
      onComplete: finish,
    });
  }, [commit]);
  useEffect(() => {
    pumpRef.current = pump;
  }, [pump]);

  const play = useCallback(
    (move: Move) => {
      if (phaseRef.current === "won" && queueRef.current.length > 30) return;
      queueRef.current.push({ move, kind: "player" });
      pump();
    },
    [pump],
  );

  const doScramble = useCallback(() => {
    tweenRef.current?.kill();
    queueRef.current = [];
    busyRef.current = false;
    const seq = scramble(Math.random, 25);
    const next = applyMoves(solvedCube(), seq);
    cubeRef.current = next;
    historyRef.current = [];
    movesRef.current = 0;
    startedAtRef.current = null;
    phaseRef.current = "scrambled";
    setCube(next);
    setAnim(null);
    setMoves(0);
    setElapsed(0);
    setPhase("scrambled");
    setScrambleText(formatMoves(seq));
    setCanUndo(false);
    setFinish(null);
    setNewRecord(false);
  }, []);

  const doReset = useCallback(() => {
    tweenRef.current?.kill();
    queueRef.current = [];
    busyRef.current = false;
    const next = solvedCube();
    cubeRef.current = next;
    historyRef.current = [];
    movesRef.current = 0;
    startedAtRef.current = null;
    phaseRef.current = "solved";
    viewRef.current = DEFAULT_VIEW;
    setCube(next);
    setAnim(null);
    setView(DEFAULT_VIEW);
    setMoves(0);
    setElapsed(0);
    setPhase("solved");
    setScrambleText("");
    setCanUndo(false);
    setFinish(null);
    setNewRecord(false);
  }, []);

  const doUndo = useCallback(() => {
    const last = historyRef.current[historyRef.current.length - 1];
    // On ne défait que les mouvements du joueur, et pas pendant qu'une animation est en cours.
    if (!last || busyRef.current || phaseRef.current === "won") return;
    queueRef.current.push({ move: invertMove(last), kind: "undo" });
    pump();
  }, [pump]);

  // Clavier : u d l r f b (Maj = sens inverse). Les raccourcis ne gênent ni la saisie ni les combinaisons système.
  useEffect(() => {
    const onKey = (e: KeyboardEvent) => {
      if (e.ctrlKey || e.metaKey || e.altKey) return;
      const t = e.target as HTMLElement | null;
      if (t && (t.tagName === "INPUT" || t.tagName === "TEXTAREA" || t.tagName === "SELECT" || t.isContentEditable)) return;
      const face = e.key.toUpperCase();
      if (e.key.length === 1 && (FACES as readonly string[]).includes(face)) {
        e.preventDefault();
        play({ face: face as Face, inverse: e.shiftKey });
      }
    };
    window.addEventListener("keydown", onKey);
    return () => window.removeEventListener("keydown", onKey);
  }, [play]);

  // ------------------------------------------------------------------------------------------ glisser

  const onPointerDown = (e: ReactPointerEvent<HTMLDivElement>) => {
    if (e.pointerType === "mouse" && e.button !== 0) return;
    const sticker = (e.target as HTMLElement).closest<HTMLElement>("[data-sticker]");
    let cubie: Cubie | undefined;
    let normal: Vec | undefined;
    if (sticker) {
      cubie = cubeRef.current.find((c) => c.id === Number(sticker.dataset.cubie));
      normal = [Number(sticker.dataset.nx), Number(sticker.dataset.ny), Number(sticker.dataset.nz)];
    }
    dragRef.current = {
      id: e.pointerId,
      x0: e.clientX,
      y0: e.clientY,
      mode: cubie ? "turn" : "orbit",
      rx0: viewRef.current.rx,
      ry0: viewRef.current.ry,
      cubie,
      normal,
      done: false,
    };
    e.currentTarget.setPointerCapture(e.pointerId);
  };

  const onPointerMove = (e: ReactPointerEvent<HTMLDivElement>) => {
    const d = dragRef.current;
    if (!d || d.id !== e.pointerId) return;
    const dx = e.clientX - d.x0;
    const dy = e.clientY - d.y0;
    if (d.mode === "orbit") {
      const next = { rx: Math.max(-85, Math.min(85, d.rx0 - dy / 2)), ry: d.ry0 + dx / 2 };
      viewRef.current = next;
      setView(next);
    } else if (!d.done && Math.hypot(dx, dy) > 14 && d.cubie && d.normal) {
      d.done = true;   // un glissé = un mouvement
      const m = moveFromDrag(d.cubie, d.normal, [dx, dy], viewRef.current.rx, viewRef.current.ry);
      if (m) play(m);
    }
  };

  const endDrag = (e: ReactPointerEvent<HTMLDivElement>) => {
    if (dragRef.current?.id === e.pointerId) dragRef.current = null;
  };

  // ----------------------------------------------------------------------------------------- rendu

  const cubies = useMemo(
    () =>
      cube.map((c) => {
        const spinning = anim !== null && c.pos[anim.axis] === anim.layer;
        const spin = spinning ? `rotate${AXIS_NAME[anim.axis]}(${anim.degrees}deg) ` : "";
        return { cubie: c, transform: spin + cubieMatrix3d(c, S) };
      }),
    [cube, anim],
  );

  const status =
    phase === "won" && finish
      ? `Bravo ! Résolu en ${formatClock(finish.ms)} avec ${finish.moves} mouvement${finish.moves > 1 ? "s" : ""}.${newRecord ? " Nouveau record !" : ""}`
      : phase === "scrambled"
        ? moves === 0
          ? "Cube mélangé. Le chrono démarre à votre premier mouvement."
          : "Résolvez le cube : chaque face doit retrouver une seule couleur."
        : "Cube résolu. Appuyez sur « Mélanger » pour commencer.";

  return (
    <AppShell title="Jeu">
      <div className="mx-auto grid w-full max-w-3xl gap-4 p-4 md:p-6">
        <Card className="p-0">
          <dl className="grid grid-cols-3 divide-x">
            <Stat icon={<Move3d className="size-4" aria-hidden />} label="Mouvements" value={String(moves)} />
            <Stat icon={<Timer className="size-4" aria-hidden />} label="Temps" value={formatClock(elapsed)} />
            <Stat icon={<Trophy className="size-4" aria-hidden />} label="Record" value={best === null ? "--:--" : formatClock(best)} />
          </dl>
        </Card>

        <div
          ref={stageRef}
          role="group"
          aria-label="Rubik’s Cube. Glissez sur le fond pour tourner la vue, glissez sur une face pour la pivoter. Les boutons et les touches font la même chose."
          onPointerDown={onPointerDown}
          onPointerMove={onPointerMove}
          onPointerUp={endDrag}
          onPointerCancel={endDrag}
          className="relative h-[min(92vw,380px)] touch-none overflow-hidden rounded-xl border bg-card select-none"
          style={{ cursor: "grab" }}
        >
          <div className="absolute inset-0 grid place-items-center" style={{ perspective: "900px" }}>
            <div style={{ transform: `scale(${scale})`, width: 0, height: 0 }}>
              <div style={{ transformStyle: "preserve-3d", transform: `rotateX(${view.rx}deg) rotateY(${view.ry}deg)` }}>
                {cubies.map(({ cubie, transform }) => (
                  <CubieView key={cubie.id} cubie={cubie} transform={transform} symbols={symbols} />
                ))}
              </div>
            </div>
          </div>
        </div>

        <p role="status" aria-live="polite" className={cn("flex flex-wrap items-center gap-2 text-sm", phase === "won" ? "font-bold text-success" : "text-muted-foreground")}>
          {status}
          {phase === "won" && newRecord && (
            <span aria-hidden>
              <StatusChip tone="ok">Nouveau record</StatusChip>
            </span>
          )}
        </p>

        <div className="flex flex-wrap gap-3">
          <Button size="lg" onClick={doScramble}>
            <Shuffle aria-hidden /> Mélanger
          </Button>
          <Button size="lg" variant="outline" onClick={doUndo} disabled={!canUndo || phase === "won"}>
            <Undo2 aria-hidden /> Annuler
          </Button>
          <Button size="lg" variant="outline" onClick={doReset}>
            <RotateCcw aria-hidden /> Réinitialiser
          </Button>
        </div>

        <Card className="gap-3 p-4">
          <h2 className="font-extrabold">Tourner une face</h2>
          <div role="group" aria-label="Tourner une face" className="grid grid-cols-6 gap-2">
            {FACES.map((f) => (
              <div key={f} className="grid gap-2">
                <MoveButton move={{ face: f, inverse: false }} onPlay={play} />
                <MoveButton move={{ face: f, inverse: true }} onPlay={play} />
              </div>
            ))}
          </div>
          <p className="text-xs text-muted-foreground">Chaque bouton tourne une face d’un quart de tour : ↻ dans le sens des aiguilles d’une montre, ↺ dans l’autre sens.</p>
        </Card>

        <Card className="gap-3 p-4">
          <button
            type="button"
            aria-pressed={symbols}
            onClick={() => setSymbols((s) => !s)}
            className="flex min-h-11 items-center gap-3 text-left text-sm font-bold"
          >
            {symbols ? <EyeOff className="size-4" aria-hidden /> : <Eye className="size-4" aria-hidden />}
            {symbols ? "Masquer les formes sur les couleurs" : "Afficher une forme sur chaque couleur (daltonisme)"}
          </button>

          <details className="group text-sm">
            <summary className="flex min-h-11 cursor-pointer items-center gap-3 font-bold">
              <Keyboard className="size-4" aria-hidden /> Commandes et règles
            </summary>
            <ul className="grid gap-1.5 pb-2 pl-6 text-muted-foreground [&>li]:list-disc">
              <li>Glisser sur le fond : tourner la vue. Glisser sur une face : la pivoter (une face = un quart de tour par glissé).</li>
              <li>
                Clavier : <kbd className="rounded border px-1">U</kbd> haut, <kbd className="rounded border px-1">D</kbd> bas, <kbd className="rounded border px-1">L</kbd> gauche,{" "}
                <kbd className="rounded border px-1">R</kbd> droite, <kbd className="rounded border px-1">F</kbd> devant, <kbd className="rounded border px-1">B</kbd> derrière ;{" "}
                avec <kbd className="rounded border px-1">Maj</kbd> pour l’autre sens.
              </li>
              <li>Le chrono démarre à votre premier mouvement après un mélange et s’arrête dès que chaque face a une seule couleur. Le record est gardé sur cet appareil.</li>
              <li>« Annuler » reprend votre dernier mouvement (pas le mélange).</li>
            </ul>
          </details>

          {scrambleText && (
            <details className="text-sm">
              <summary className="flex min-h-11 cursor-pointer items-center font-bold">Voir le mélange (notation)</summary>
              <p className="pb-2 font-mono text-xs break-words text-muted-foreground" data-testid="scramble">
                {scrambleText}
              </p>
              <p className="pb-2 text-xs text-muted-foreground">Un mouvement avec « &apos; » est un quart de tour anti-horaire. Rejouer ces mouvements à l’envers, dans l’ordre inverse, résout le cube.</p>
            </details>
          )}
        </Card>
      </div>
    </AppShell>
  );
}

function Stat({ icon, label, value }: { icon: React.ReactNode; label: string; value: string }) {
  return (
    <div className="grid gap-0.5 px-3 py-3 text-center">
      <dt className="flex items-center justify-center gap-1.5 text-xs text-muted-foreground">
        {icon} {label}
      </dt>
      <dd className="text-xl font-extrabold tabular-nums">{value}</dd>
    </div>
  );
}

function MoveButton({ move, onPlay }: { move: Move; onPlay: (m: Move) => void }) {
  const dir = move.inverse ? "sens inverse" : "sens horaire";
  return (
    <button
      type="button"
      aria-label={`Face ${FACE_LABEL[move.face].toLowerCase()}, ${dir}`}
      title={`${move.face}${move.inverse ? "'" : ""}`}
      onClick={() => onPlay(move)}
      className="grid min-h-11 place-items-center rounded-lg border text-sm font-bold transition-colors hover:bg-accent focus-visible:bg-accent active:bg-accent"
    >
      <span aria-hidden>
        {move.face}
        {move.inverse ? "↺" : "↻"}
      </span>
    </button>
  );
}

const CubieView = memo(function CubieView({ cubie, transform, symbols }: { cubie: Cubie; transform: string; symbols: boolean }) {
  const stickers = stickersOf(cubie);
  const style: CSSProperties = { position: "absolute", left: -S / 2, top: -S / 2, width: S, height: S, transformStyle: "preserve-3d", transform };
  return (
    <div style={style}>
      {LOCAL_FACES.map((n) => {
        const sticker = stickers.find((s) => s.normal.join() === n.join());
        return (
          <div
            key={n.join()}
            data-sticker={sticker ? "" : undefined}
            data-cubie={sticker ? cubie.id : undefined}
            data-nx={sticker ? n[0] : undefined}
            data-ny={sticker ? n[1] : undefined}
            data-nz={sticker ? n[2] : undefined}
            style={{ position: "absolute", inset: 0, background: "#0b0d12", transform: faceTransform(n), backfaceVisibility: "hidden" }}
          >
            {sticker && (
              <div
                style={{
                  position: "absolute",
                  inset: 4,
                  borderRadius: 8,
                  background: COLORS[sticker.color].bg,
                  display: "grid",
                  placeItems: "center",
                  color: "rgba(0,0,0,.65)",
                  fontSize: 22,
                  fontWeight: 800,
                  pointerEvents: "none",
                }}
                title={COLORS[sticker.color].name}
              >
                {symbols ? COLORS[sticker.color].symbol : null}
              </div>
            )}
          </div>
        );
      })}
    </div>
  );
});
