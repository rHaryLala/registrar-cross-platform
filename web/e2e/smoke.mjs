/**
 * Test de bout en bout : navigateur Chrome réel, site statique (`out/`) + API Laravel.
 *
 * Prérequis : API sur http://localhost:8000 (base de démonstration : php artisan db:seed --class=DemoSeeder)
 * et site sur http://localhost:3000.   Lancement : node e2e/smoke.mjs
 *
 * Pilote Chrome par le protocole DevTools (aucune dépendance à installer). Retourne un code de sortie non nul
 * si une vérification échoue.
 */
import { spawn } from "node:child_process";
import { mkdtempSync, writeFileSync, mkdirSync } from "node:fs";
import { tmpdir } from "node:os";
import { join } from "node:path";

const WEB = process.env.WEB_URL ?? "http://localhost:3000";
const OUT = process.env.E2E_OUT ?? join(tmpdir(), "registrar-e2e");
const CHROME = process.env.CHROME ?? "C:/Program Files/Google/Chrome/Application/chrome.exe";
const PASSWORD = process.env.DEMO_PASSWORD ?? "mot-de-passe-test";
mkdirSync(OUT, { recursive: true });

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const results = [];
const check = (name, ok, detail = "") => {
  results.push({ name, ok: Boolean(ok), detail });
  console.log(`${ok ? "  ok " : "  ECHEC"}  ${name}${detail ? "  — " + detail : ""}`);
};

// Chien de garde : un test qui se bloque doit échouer bruyamment, pas attendre indéfiniment.
const watchdog = setTimeout(() => { console.log("  ECHEC  Le test a dépassé 3 minutes (blocage)."); process.exit(2); }, 180000);
watchdog.unref?.();

const profile = mkdtempSync(join(tmpdir(), "registrar-e2e-profile-"));
const chrome = spawn(CHROME, ["--headless=new", "--disable-gpu", "--remote-debugging-port=9340", `--user-data-dir=${profile}`, "about:blank"], { stdio: "ignore" });

let targets;
for (let i = 0; i < 60 && !targets?.length; i++) {
  try { targets = await (await fetch("http://127.0.0.1:9340/json")).json(); } catch { await sleep(250); }
}
const ws = new WebSocket(targets.find((t) => t.type === "page").webSocketDebuggerUrl);
await new Promise((r) => (ws.onopen = r));

let id = 0;
const pending = new Map();
const problems = [];
let envBlips = 0;
let scriptsOff = false; // vrai pendant la vérification « sans JavaScript » : les scripts bloqués y sont voulus
ws.onmessage = (m) => {
  const d = JSON.parse(m.data);
  if (d.id && pending.has(d.id)) { pending.get(d.id)(d.result ?? { error: d.error }); pending.delete(d.id); }
  if (d.method === "Runtime.exceptionThrown" && /ChunkLoadError/.test(d.params.exceptionDetails.exception?.description ?? "")) envBlips++; // l'application se recharge seule (chunk-recovery)
  else if (d.method === "Runtime.exceptionThrown") problems.push("exception: " + (d.params.exceptionDetails.exception?.description ?? d.params.exceptionDetails.text));
  if (d.method === "Runtime.consoleAPICalled" && d.params.type === "error") {
    const msg = d.params.args.map((a) => a.value ?? a.description).join(" ");
    // « Failed to fetch » = la requête n'est pas partie (refus réseau local de Windows) ; on la compte comme tel, on ne la cache pas.
    if (/TypeError: Failed to fetch/.test(msg)) envBlips++;
    else problems.push("console.error: " + msg);
  }
  if (d.method === "Network.loadingFailed" && !d.params.canceled && !scriptsOff) {
    // ERR_NETWORK_ACCESS_DENIED = refus du système (WSAEACCES 10013) sur ce poste Windows : environnement, pas application.
    if (d.params.errorText === "net::ERR_NETWORK_ACCESS_DENIED") envBlips++;
    else problems.push("réseau: " + d.params.errorText + " " + (d.params.blockedReason ?? ""));
  }
};
const send = (method, params = {}) => new Promise((r) => { const i = ++id; pending.set(i, r); ws.send(JSON.stringify({ id: i, method, params })); });
const withTimeout = (promise, ms, label) => Promise.race([promise, new Promise((_, rej) => setTimeout(() => rej(new Error(`Délai dépassé (${ms} ms) : ${label}`)), ms))]);
const ev = async (expression) => {
  const r = await withTimeout(send("Runtime.evaluate", { expression, returnByValue: true, awaitPromise: true }), 15000, expression.slice(0, 60));
  if (r.exceptionDetails) throw new Error(r.exceptionDetails.exception?.description ?? "évaluation en échec");
  return r.result?.value;
};
const until = async (expr, label, ms = 15000) => {
  const t = Date.now();
  let retries = 0;
  while (Date.now() - t < ms) {
    if (await ev(expr).catch(() => false)) return true;
    // Comme un étudiant : si l'application affiche sa carte d'erreur réseau, on clique sur « Réessayer » (compté et affiché).
    if (retries < 3 && (await ev("(() => { const b = [...document.querySelectorAll('button')].find((x) => x.innerText.trim() === 'Réessayer'); if (b) { b.click(); return true; } return false; })()").catch(() => false))) {
      retries++;
      envBlips++;
    }
    await sleep(150);
  }
  console.log(`  (attente dépassée : ${label})`);
  return false;
};
const shot = async (name) => { const s = await send("Page.captureScreenshot", { format: "png" }); writeFileSync(join(OUT, name + ".png"), Buffer.from(s.data, "base64")); };
const hydrated = () => ev("[...document.querySelectorAll('*')].some(e => Object.keys(e).some(k => k.startsWith('__reactFiber')))").catch(() => false);
const go = async (path) => {
  for (let attempt = 1; attempt <= 4; attempt++) {
    const before = envBlips;
    await send("Page.navigate", { url: WEB + path });
    await sleep(900);
    for (let i = 0; i < 30 && !(await hydrated()); i++) await sleep(150);
    if (await hydrated()) return;
    if (envBlips === before) throw new Error("La page ne s'hydrate pas, sans refus réseau du système : vrai défaut à examiner.");
  }
  throw new Error("La page ne s'hydrate pas après 4 essais (refus réseau du système répétés).");
};
/** Clique sur « Se connecter » et attend la FIN de la requête (le bouton redevient « Se connecter », ou la page change).
 *  Une alerte d'un essai précédent peut rester affichée : elle ne prouve rien. Ne recommence que si le refus
 *  réseau de Windows a fait échouer la requête. */
const submit = async () => {
  for (let attempt = 1; attempt <= 4; attempt++) {
    const before = envBlips;
    // Le bouton reste inactif jusqu'à ce que l'application soit prête (voulu : sinon le formulaire partirait en natif) : on attend, comme un utilisateur.
    await until("!!document.querySelector('button[type=submit]') && !document.querySelector('button[type=submit]').disabled", "bouton « Se connecter » actif", 20000);
    await click("button[type=submit]");
    await sleep(300);
    await until("location.pathname === '/' || (document.querySelector('button[type=submit]')?.innerText ?? '').trim() !== 'Connexion…'", "fin de la requête", 10000);
    await sleep(250);
    const alertText = await ev("document.querySelector('[role=alert]')?.innerText ?? ''");
    if ((await path()) === "/" || !/Vérifiez votre connexion/.test(alertText) || envBlips === before) return;
  }
};
const type = async (selector, text) => {
  await ev(`(()=>{const e=document.querySelector(${JSON.stringify(selector)}); e.focus(); e.select?.();})()`);
  await send("Input.insertText", { text });
};
const click = (selector) => ev(`document.querySelector(${JSON.stringify(selector)}).click()`);
const text = () => ev("document.body.innerText");
const path = () => ev("location.pathname");

await send("Page.enable"); await send("Runtime.enable"); await send("Network.enable");
await send("Emulation.setDeviceMetricsOverride", { width: 390, height: 844, deviceScaleFactor: 2, mobile: true });

console.log("\n1. Visiteur non connecté");
await go("/");
check("« / » renvoie vers la connexion", await until("location.pathname.startsWith('/login')", "redirection"), await path());
const login = await text();
check("la page de connexion se présente", /Se connecter/.test(login) && /Identifiant ou adresse e-mail/.test(login));
check("Google non configuré : message clair, pas de bouton cassé", /connexion Google n’est pas activée/.test(login));
await go("/notes/");
check("« /notes/ » sans connexion renvoie vers la connexion", await until("location.pathname.startsWith('/login')", "garde notes"), await path());

console.log(String.fromCharCode(10) + "1b. Formulaire de connexion avant que le JavaScript soit prêt");
scriptsOff = true;
await send("Emulation.setScriptExecutionDisabled", { value: true });
await send("Page.navigate", { url: WEB + "/login/" });
await sleep(1500);
const sansJs = JSON.parse(await ev(`JSON.stringify({ methode: document.querySelector('form')?.method, bouton: document.querySelector('button[type=submit]')?.disabled })`));
check("sans JavaScript : bouton inactif et formulaire en POST", sansJs.methode === "post" && sansJs.bouton === true, JSON.stringify(sansJs));
await ev(`(() => { document.querySelector('#identifier').value = 'etudiant.demo'; document.querySelector('#password').value = 'secret-test-123'; document.querySelector('form').requestSubmit(); })()`);
await sleep(1500);
const urlApres = await ev("location.href");
check("un envoi forcé sans JavaScript ne met JAMAIS les identifiants dans l'adresse", !/secret-test-123|identifier=|password=|etudiant\.demo/.test(urlApres), urlApres);
await send("Emulation.setScriptExecutionDisabled", { value: false });
await go("/login/");
scriptsOff = false;
await shot("01-connexion-sombre");

console.log("\n2. Mauvais mot de passe");
await type("#identifier", "etudiant.demo");
await type("#password", "pas-le-bon");
await submit();
check("l'erreur s'affiche (role=alert)", await until("!!document.querySelector('[role=alert]')", "alerte"));
const alert = await ev("document.querySelector('[role=alert]')?.innerText ?? ''");
check("le message dit quoi faire", /incorrect|Vérifiez/.test(alert), alert.slice(0, 80));
check("le focus va à l'alerte", await ev("document.activeElement?.getAttribute('role') === 'alert'"));
check("on reste sur la connexion", (await path()).startsWith("/login"));
check("le mot de passe saisi n'apparaît pas dans la page", !(await text()).includes("pas-le-bon"));

console.log("\n3. Connexion étudiant");
await type("#password", PASSWORD);
await submit();
check("arrivée sur l'accueil", await until("location.pathname === '/' && /Bonjour Fanja/.test(document.body.innerText)", "accueil"), await path());
await until("/Nouveautés/.test(document.body.innerText) && !document.querySelector('[aria-busy=true]')", "données");
await sleep(900);
const home = await text();
check("bonjour personnalisé + matricule", /Bonjour Fanja/.test(home) && /DEMO-0001/.test(home));
check("moyenne pondérée affichée (12,96)", /12,96/.test(home));
check("notes publiées visibles", /Algorithmique II/.test(home) && /Base de données/.test(home) && /Anglais/.test(home));
check("note chiffrée avec virgule française", /14,5/.test(home));
check("note « validée » rendue en mot (« Validé »)", /Validé/.test(home));
check("le code brut « -2 » n'est jamais affiché (l'année 2025-2026 ne compte pas)", !/(^|[^0-9])-2([^0-9]|$)/.test(home));
check("NOTE MASQUÉE ABSENTE de la page (confidentialité)", !/Compilation/.test(home) && !/\b19\b/.test(home));
check("session en attente annoncée", /En attente de publication/.test(home) && /Session 2/.test(home));
await shot("02-accueil-etudiant-sombre");

console.log(String.fromCharCode(10) + "3b. Navigation et « Mes notes »");
const nav = JSON.parse(await ev(`JSON.stringify({ barre: !!document.querySelector('nav.fixed') && getComputedStyle(document.querySelector('nav.fixed')).display !== 'none', liens: [...document.querySelectorAll('nav[aria-label="Navigation principale"] a')].map(a => a.textContent.trim()), actif: document.querySelector('nav.fixed a[aria-current=page]')?.textContent.trim() })`));
check("barre d'onglets du bas visible sur téléphone", nav.barre);
check("onglets Accueil et Notes (aucun lien mort)", nav.liens.includes("Accueil") && nav.liens.includes("Notes"), nav.liens.join(","));
check("l'onglet actif est marqué (aria-current)", nav.actif === "Accueil", String(nav.actif));
await ev(`[...document.querySelectorAll('nav.fixed a')].find(a => a.textContent.trim() === 'Notes').click()`);
check("le clic sur « Notes » ouvre la page", await until("location.pathname === '/notes/'", "navigation notes"), await path());
await until("/moyenne cumulative/i.test(document.body.innerText) && !document.querySelector('[aria-busy=true]')", "notes chargées");
await sleep(900);
const notes = await text();
check("moyenne cumulative 12,96", /12,96/.test(notes));
check("les cours de la session publiée sont listés", /Algorithmique II/.test(notes) && /Mathématiques/.test(notes) && /Anglais/.test(notes));
check("état « Validé » en toutes lettres pour le cours validé", /Validé/.test(notes));
check("moyenne de la session affichée", /Moyenne générale/.test(notes));
check("NOTE MASQUÉE ABSENTE de « Mes notes » (confidentialité)", !/Compilation/.test(notes));
check("la session masquée est annoncée, sans ses notes", /En attente de publication/.test(notes) && /Session 2/.test(notes));
const navNotes = await ev("document.querySelector('nav.fixed a[aria-current=page]')?.textContent.trim()");
check("l'onglet actif suit la page (Notes)", navNotes === "Notes", String(navNotes));
const lay = JSON.parse(await ev(`JSON.stringify({ scroll: document.documentElement.scrollWidth, inner: innerWidth, h1: document.querySelectorAll('h1').length, sansNom: [...document.querySelectorAll('a,button')].filter(e => !(e.getAttribute('aria-label') || e.innerText.trim())).length })`));
check("« Mes notes » : aucun défilement horizontal, un seul h1, aucun contrôle sans nom", lay.scroll <= lay.inner && lay.h1 === 1 && lay.sansNom === 0, JSON.stringify(lay));
await shot("02b-mes-notes-sombre");
check("aucun faux zéro de promotion (« — » quand il n'y a aucune donnée)", !/(^|\s)0,0(\s|$)/.test(notes) && /—/.test(notes));

// Ordinateur : la navigation passe dans l'en-tête, la barre du bas disparaît.
await send("Emulation.setDeviceMetricsOverride", { width: 1280, height: 900, deviceScaleFactor: 1, mobile: false });
await sleep(700);
const desk = JSON.parse(await ev(`JSON.stringify({ scroll: document.documentElement.scrollWidth, inner: innerWidth, barre: getComputedStyle(document.querySelector('nav.fixed')).display, liensEntete: [...document.querySelectorAll('header nav a')].filter(a => a.getBoundingClientRect().width > 0).map(a => a.textContent.trim()) })`));
check("ordinateur : barre du bas masquée, liens dans l'en-tête", desk.barre === "none" && desk.liensEntete.includes("Accueil") && desk.liensEntete.includes("Notes"), JSON.stringify(desk));
check("ordinateur : aucun défilement horizontal", desk.scroll <= desk.inner, `${desk.scroll} / ${desk.inner}`);
await shot("02c-mes-notes-ordinateur");
await send("Emulation.setDeviceMetricsOverride", { width: 390, height: 844, deviceScaleFactor: 2, mobile: true });
await sleep(400);
await go("/notes/"); // chargement direct de l'adresse (avec la relance en cas de refus réseau du système)
check("« /notes/ » rechargée directement : toujours connecté, notes affichées", await until("/moyenne cumulative/i.test(document.body.innerText)", "rechargement notes"));

console.log(String.fromCharCode(10) + "3c. Présence");
await go("/presence/");
await until("/INF 201/.test(document.body.innerText) && !document.querySelector('[aria-busy=true]')", "présence chargée");
await sleep(900);
const pres = await text();
const tabs = JSON.parse(await ev(`JSON.stringify({ liens: [...document.querySelectorAll('nav.fixed a')].map(a => a.textContent.trim()), actif: document.querySelector('nav.fixed a[aria-current=page]')?.textContent.trim() })`));
check("trois onglets (Accueil, Notes, Présence), Présence actif", tabs.liens.join(",") === "Accueil,Notes,Présence" && tabs.actif === "Présence", JSON.stringify(tabs));
check("alerte « à la limite des 15 % » pour INF 201", /INF 201\s*:\s*vous êtes à la limite des 15 %/.test(pres));
check("absences comptées / permises : 3 / 3", /3\s*\/\s*3/.test(pres) && /absences comptées \/ permises/.test(pres));
check("état en toutes lettres (À la limite des 15 %) et seuil non calculé pour MAT 110", /À la limite des 15 %/.test(pres) && /Seuil non calculé/.test(pres) && /pas encore indiqué le nombre de séances prévues/.test(pres));
const gauge = JSON.parse(await ev(`JSON.stringify({ now: document.querySelector('[role=progressbar]')?.getAttribute('aria-valuenow'), nom: document.querySelector('[role=progressbar]')?.getAttribute('aria-label') })`));
check("la jauge est un vrai progressbar nommé (75 %, 3 sur 3 permises)", gauge.now === "75" && /3 absence\(s\) comptée\(s\) sur 3 permise\(s\)/.test(gauge.nom ?? ""), JSON.stringify(gauge));
await shot("02d-presence-sombre"); // état par défaut : blocs repliés
await ev("document.querySelectorAll('details').forEach(d => { d.open = true; })");
await sleep(200);
const open = await text();
check("séance sans pénalité signalée (§474)", /non compté \(§474\)/.test(open));
check("retard de 12 min = absence, avec certificat médical", /Retard 12 min \(compte absence\)/.test(open) && /certificat médical/.test(open));
check("dates en français avec jour de la semaine (Mercredi 16 septembre 2026)", /Mercredi 16 septembre 2026/.test(open));
const first = open.indexOf("16 septembre 2026"), last = open.indexOf("7 septembre 2026");
check("séances les plus récentes d'abord", first > -1 && last > -1 && first < last, `${first} < ${last}`);
check("règles du Bulletin académique consultables (§472 à §476)", /§472 à §476/.test(open) && /deux retards comptent comme une absence/.test(open));
const lay2 = JSON.parse(await ev(`JSON.stringify({ scroll: document.documentElement.scrollWidth, inner: innerWidth, h1: document.querySelectorAll('h1').length, sansNom: [...document.querySelectorAll('a,button,summary')].filter(e => !(e.getAttribute('aria-label') || e.innerText.trim())).length })`));
check("« Présence » : aucun défilement horizontal, un seul h1, aucun contrôle sans nom", lay2.scroll <= lay2.inner && lay2.h1 === 1 && lay2.sansNom === 0, JSON.stringify(lay2));
await shot("02e-presence-detail-sombre");
await go("/");
await go("/");

console.log("\n4. Session et mise en page");
await go(await path()); // rechargement : chargement direct de la même adresse (avec relance si le réseau local refuse)
check("après rechargement : toujours connecté", await until("/Bonjour Fanja/.test(document.body.innerText)", "rechargement"));
const layout = await ev(`(() => {
  const small = [...document.querySelectorAll('button, a[href], input')].filter(e => e.getBoundingClientRect().width > 1 && !e.classList.contains('sr-only'))
    .map(e => ({ n: (e.getAttribute('aria-label') || e.innerText || e.id || e.tagName).trim().slice(0, 30), h: Math.round(e.getBoundingClientRect().height), w: Math.round(e.getBoundingClientRect().width) }))
    .filter(e => Math.min(e.h, e.w) < 44);
  const unnamed = [...document.querySelectorAll('button')].filter(b => !(b.getAttribute('aria-label') || b.innerText.trim()));
  return JSON.stringify({ scroll: document.documentElement.scrollWidth, inner: innerWidth, small, unnamed: unnamed.length, lang: document.documentElement.lang, hasMain: !!document.querySelector('main#contenu'), h1: document.querySelectorAll('h1').length });
})()`);
const L = JSON.parse(layout);
check("aucun défilement horizontal à 390 px", L.scroll <= L.inner, `${L.scroll} / ${L.inner}`);
check("cibles tactiles ≥ 44 px", L.small.length === 0, L.small.map((s) => `${s.n} ${s.w}x${s.h}`).join("; "));
check("tout bouton a un nom accessible", L.unnamed === 0);
check("langue du document = fr, un <main>, un seul <h1>", L.lang === "fr" && L.hasMain && L.h1 === 1);

console.log("\n5. Thème clair");
await ev("localStorage.setItem('theme','light')"); await go(await path()); // rechargement : chargement direct de la même adresse (avec relance si le réseau local refuse)
await until("/Bonjour Fanja/.test(document.body.innerText)", "clair");
await sleep(700);
check("la classe « dark » est retirée", await ev("!document.documentElement.classList.contains('dark')"));
await shot("03-accueil-etudiant-clair");

console.log("\n6. Déconnexion");
await ev("document.querySelector('button[aria-label^=\"Se déconnecter\"]').click()");
check("retour à la connexion", await until("location.pathname.startsWith('/login')", "déconnexion"));
let afterLogout = "ERR";
for (let i = 0; i < 4 && String(afterLogout).startsWith("ERR"); i++) {
  afterLogout = await ev(`fetch('http://localhost:8000/api/v1/me/home', { credentials: 'include', headers: { Accept: 'application/json' }, signal: AbortSignal.timeout(8000) }).then(r => r.status).catch(e => 'ERR ' + e.name)`);
  if (String(afterLogout).startsWith("ERR")) envBlips++;
}
check("l'API refuse l'accueil après déconnexion (401)", afterLogout === 401, String(afterLogout));
await go("/");
check("« / » renvoie de nouveau vers la connexion", await until("location.pathname.startsWith('/login')", "garde"));

console.log("\n7. Compte du personnel et ancien mot de passe");
await type("#identifier", "ancien.demo");
await type("#password", PASSWORD);
await submit();
check("connexion avec un compte à l'ancien format de mot de passe", await until("location.pathname === '/' && /arrive bientôt/.test(document.body.innerText)", "personnel"));
const staff = await text();
check("page d'attente honnête pour un autre rôle (pas de fausses données)", /arrive bientôt/.test(staff) && !/Moyenne/.test(staff));
await shot("04-espace-personnel");
await go("/notes/");
check("un compte du personnel sur « /notes/ » : explication claire, pas de données", await until("/réservée aux étudiants/.test(document.body.innerText)", "autre rôle") && !/moyenne cumulative/i.test(await text()));
await go("/presence/");
check("un compte du personnel sur « /presence/ » : explication claire, pas de données", await until("/réservée aux étudiants/.test(document.body.innerText)", "autre rôle présence") && !/INF 201/.test(await text()));

console.log("\n8. Journal de la console et du réseau");
check("aucune erreur JavaScript ni requête en échec", problems.length === 0, problems.slice(0, 3).join(" | "));

ws.close(); chrome.kill();
const failed = results.filter((r) => !r.ok);
console.log(`\n${results.length - failed.length}/${results.length} vérifications réussies. Captures : ${OUT}`);
process.exit(failed.length ? 1 : 0);
