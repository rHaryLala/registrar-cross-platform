/**
 * Lance Vitest depuis le chemin « réel » du dossier.
 *
 * Sous Windows, « c:\… » et « C:\… » sont le même dossier mais deux modules différents pour Vite : Vitest se
 * chargeait en double (« Vitest failed to find the runner »). Les terminaux Git Bash et VS Code donnent souvent
 * « c:\ » en minuscule ; on repart donc de la forme canonique du chemin.
 */
import { spawnSync } from "node:child_process";
import { realpathSync } from "node:fs";
import { join } from "node:path";

const root = realpathSync.native(process.cwd());
const bin = join(root, "node_modules", "vitest", "vitest.mjs");
const result = spawnSync(process.execPath, [bin, ...(process.argv.slice(2).length ? process.argv.slice(2) : ["run"])], { cwd: root, stdio: "inherit" });
process.exit(result.status ?? 1);
