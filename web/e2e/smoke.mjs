/**
 * Test de bout en bout : navigateur Chrome réel, site statique (`out/`) + API Laravel.
 *
 * Prérequis : API sur http://localhost:8000 (base de démonstration : php artisan db:seed --class=DemoSeeder)
 * et site sur http://localhost:3000.   Lancement : node e2e/smoke.mjs
 *
 * Pilote Chrome par le protocole DevTools (aucune dépendance à installer). Retourne un code de sortie non nul
 * si une vérification échoue.
 */
import { execFileSync, spawn } from "node:child_process";
import { mkdtempSync, writeFileSync, mkdirSync, existsSync, readFileSync, statSync, rmSync } from "node:fs";
import { tmpdir } from "node:os";
import { join } from "node:path";

const WEB = process.env.WEB_URL ?? "http://localhost:3000";
const OUT = process.env.E2E_OUT ?? join(tmpdir(), "registrar-e2e");
const CHROME = process.env.CHROME ?? "C:/Program Files/Google/Chrome/Application/chrome.exe";
const PASSWORD = process.env.DEMO_PASSWORD ?? "mot-de-passe-test";
mkdirSync(OUT, { recursive: true });

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
// Refus réseau local intermittent de Windows (EACCES sur localhost) : un fetch de ce script qui échoue AVANT d'avoir atteint le serveur est rejoué
// (6 essais). Une réponse HTTP, même d'erreur, n'est jamais rejouée : un vrai défaut de l'application reste visible.
const nativeFetch = globalThis.fetch.bind(globalThis);
globalThis.fetch = async (...args) => {
  for (let attempt = 1; ; attempt++) {
    try { return await nativeFetch(...args); } catch (e) { if (attempt >= 6) throw e; envBlips++; await sleep(500 * attempt); }
  }
};
const results = [];
const check = (name, ok, detail = "") => {
  results.push({ name, ok: Boolean(ok), detail });
  console.log(`${ok ? "  ok " : "  ECHEC"}  ${name}${detail ? "  — " + detail : ""}`);
};

// Chien de garde : un test qui se bloque doit échouer bruyamment, pas attendre indéfiniment.
const watchdog = setTimeout(() => { console.log("  ECHEC  Le test a dépassé 15 minutes (blocage)."); process.exit(2); }, 900000);
watchdog.unref?.();

const profile = mkdtempSync(join(tmpdir(), "registrar-e2e-profile-"));
// Les téléchargements (fiche PDF) vont dans le dossier des captures, sans boîte de dialogue : réglage écrit AVANT le lancement.
mkdirSync(join(profile, "Default"), { recursive: true });
mkdirSync(OUT, { recursive: true });
writeFileSync(join(profile, "Default", "Preferences"), JSON.stringify({ download: { default_directory: OUT.replace(/\//g, "\\"), prompt_for_download: false }, savefile: { default_directory: OUT.replace(/\//g, "\\") } }));
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
const requestUrls = new Map(); // identifiant de requête -> adresse, pour dire QUOI a échoué
ws.onmessage = (m) => {
  const d = JSON.parse(m.data);
  if (d.method === "Network.requestWillBeSent") requestUrls.set(d.params.requestId, String(d.params.request?.url ?? "").replace(/\?.*$/, ""));
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
    else problems.push("réseau: " + d.params.errorText + " " + (d.params.blockedReason ?? "") + " " + (requestUrls.get(d.params.requestId) ?? "?"));
  }
};
const send = (method, params = {}) => new Promise((r) => { const i = ++id; pending.set(i, r); ws.send(JSON.stringify({ id: i, method, params })); });
const withTimeout = (promise, ms, label) => Promise.race([promise, new Promise((_, rej) => setTimeout(() => rej(new Error(`Délai dépassé (${ms} ms) : ${label}`)), ms))]);
const ev = async (expression, attempt = 1) => {
  const r = await withTimeout(send("Runtime.evaluate", { expression, returnByValue: true, awaitPromise: true }), 45000, expression.slice(0, 60));
  if (r.exceptionDetails) {
    const description = r.exceptionDetails.exception?.description ?? "évaluation en échec";
    // Un fetch lancé DANS la page qui n'a pas atteint le serveur (« Failed to fetch » = refus réseau local de Windows, compté comme tel) est rejoué ;
    // une réponse HTTP, même d'erreur, ne l'est jamais.
    if (/TypeError: Failed to fetch/.test(description) && attempt < 3) { envBlips++; await sleep(500 * attempt); return ev(expression, attempt + 1); }
    throw new Error(description);
  }
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
check("la page de connexion se présente", /Se connecter/.test(login) && /Matricule, identifiant ou adresse e-mail/.test(login));
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
check("cinq onglets (Accueil, Notes, Présence, Inscriptions, Plus), Présence actif", tabs.liens.join(",") === "Accueil,Notes,Présence,Inscriptions,Plus" && tabs.actif === "Présence", JSON.stringify(tabs));
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

console.log(String.fromCharCode(10) + "3d. Quiz (score calculé et enregistré par le serveur)");
await go("/quiz/");
await until("/Jouer ma première partie/.test(document.body.innerText) && !document.querySelector('[aria-busy=true]') && /Tojo A\\./.test(document.body.innerText)", "quiz chargé");
await sleep(700);
const quiz0 = await text();
check("le Quiz allume l'onglet « Plus » (il y est rangé)", (await ev("document.querySelector('nav.fixed a[aria-current=page]')?.textContent.trim()")) === "Plus");
check("première visite : invitation à jouer, aucune statistique inventée", /Jouer ma première partie/.test(quiz0) && /pas encore terminé de partie/.test(quiz0));
check("classement de la semaine avec les joueurs fictifs, noms abrégés (« Prénom N. »)", /Hery R\./.test(quiz0) && /Soa R\./.test(quiz0) && /Tojo A\./.test(quiz0));
check("aucun nom de famille complet ni matricule dans le classement", !/Randria|Ravelo|Andria|DEMO-000[234]/.test(quiz0));
check("ordre : 9/10 avant 7/10 avant 5/10", quiz0.indexOf("9/10") > -1 && quiz0.indexOf("9/10") < quiz0.indexOf("7/10") && quiz0.indexOf("7/10") < quiz0.indexOf("5/10"));
check("bascule de période : « Cette semaine » enfoncée", (await ev("document.querySelector('[aria-label=\"Période du classement\"] [aria-pressed=true]')?.textContent.trim()")) === "Cette semaine");
await shot("03a-quiz-accueil-sombre");
await click("a[href='/quiz/jouer/']");
check("le lien ouvre la partie", await until("location.pathname === '/quiz/jouer/'", "navigation jouer"), await path());
await until("/Prêt à jouer/.test(document.body.innerText)", "écran de départ");
check("onglet « Plus » toujours allumé sur la sous-page du quiz", (await ev("document.querySelector('nav.fixed a[aria-current=page]')?.textContent.trim()")) === "Plus");
await ev("[...document.querySelectorAll('button')].find(b => b.innerText.trim() === 'Commencer').click()");
check("question 1 sur 10 affichée", await until("/Question 1 sur 10/.test(document.body.innerText)", "première question"));
const q1 = JSON.parse(await ev(`JSON.stringify({ options: document.querySelectorAll('main ul li button').length, fuite: /Bonne réponse|Votre choix/.test(document.body.innerText), bar: document.querySelector('[role=progressbar]')?.getAttribute('aria-valuenow') })`));
check("trois réponses proposées, et la bonne n'est PAS révélée avant de répondre", q1.options === 3 && q1.fuite === false, JSON.stringify(q1));
check("progression 0 sur 10", q1.bar === "0");
const lay3 = JSON.parse(await ev(`JSON.stringify({ scroll: document.documentElement.scrollWidth, inner: innerWidth, h1: document.querySelectorAll('h1').length, petits: [...document.querySelectorAll('main button, main a, main summary')].filter(e => e.getBoundingClientRect().width > 1 && Math.min(e.getBoundingClientRect().height, e.getBoundingClientRect().width) < 44).map(e => e.innerText.trim().slice(0, 20)) })`));
check("partie : aucun défilement horizontal, un seul h1, cibles ≥ 44 px", lay3.scroll <= lay3.inner && lay3.h1 === 1 && lay3.petits.length === 0, JSON.stringify(lay3));
await shot("03b-quiz-question-sombre");
let right = 0;
for (let n = 1; n <= 10; n++) {
  await until(`new RegExp('Question ${n} sur 10').test(document.body.innerText) && !!document.querySelector('main ul li button:not([disabled])')`, `question ${n}`, 20000);
  await sleep(1000); // le serveur refuse une réponse en moins de 0,8 s : on lit la question comme un humain
  if (n === 4) {
    // raccourci clavier : la touche « 2 » choisit la deuxième réponse
    await send("Input.dispatchKeyEvent", { type: "keyDown", key: "2", text: "2" });
    await send("Input.dispatchKeyEvent", { type: "keyUp", key: "2" });
  } else {
    await ev("document.querySelector('main ul li button').click()");
  }
  const ok = await until("/Bonne réponse !|Raté\\./.test(document.body.innerText)", `retour de la question ${n}`, 20000);
  if (!ok) break;
  if (n === 1) {
    const fb = JSON.parse(await ev(`JSON.stringify({ marque: [...document.querySelectorAll('main ul li button')].filter(b => /Bonne réponse|Votre choix/.test(b.innerText)).length, inactifs: [...document.querySelectorAll('main ul li button')].every(b => b.disabled), focus: document.activeElement?.innerText.trim() })`));
    check("après la réponse : la bonne réponse est marquée en toutes lettres, boutons inactifs, focus sur « Question suivante »", fb.marque >= 1 && fb.inactifs && fb.focus === "Question suivante", JSON.stringify(fb));
    await shot("03c-quiz-retour-sombre");
  }
  if (/Bonne réponse !/.test(await text())) right++;
  await ev("[...document.querySelectorAll('main button')].find(b => /Question suivante|Voir mon résultat/.test(b.innerText)).click()");
}
check("le résultat s'affiche", await until("/Partie terminée/.test(document.body.innerText)", "résultat", 20000));
const res = await text();
const label = await ev("document.querySelector('p[aria-label*=\"bonnes réponses\"]')?.getAttribute('aria-label') ?? ''");
check("le score final affiché = le nombre de « Bonne réponse ! » reçues (calculé par le serveur)", label === `${right} bonnes réponses sur 10`, `${label} / attendu ${right}`);
check("rang et nombre de joueurs affichés", /sur 4 joueurs cette semaine/.test(res) && /sur 4 joueurs depuis toujours/.test(res), res.slice(res.indexOf("Votre place"), res.indexOf("Votre place") + 160).replace(/\n/g, " "));
check("verdict en mots et durée", /Sans faute|Excellent|Bien joué|Pas mal|À revoir/.test(res) && /de bonnes réponses en/.test(res));
check("premier score = record personnel", /Nouveau record personnel/.test(res));
await shot("03d-quiz-resultat-sombre");
await click("a[href='/quiz/']");
await until("location.pathname === '/quiz/' && /Mes parties/.test(document.body.innerText)", "retour au quiz");
await until("/Vous/.test(document.body.innerText)", "ma ligne au classement");
const quiz1 = await text();
check("« Mes parties » : 1 partie terminée et mon meilleur score", /1\s*partie terminée/.test(quiz1) && new RegExp(`${right}/10`).test(quiz1));
check("je figure au classement avec « Fanja R. » et la pastille « Vous »", /Fanja R\./.test(quiz1) && /Vous/.test(quiz1) && !/Rakoto/.test(quiz1));
await ev("document.querySelector('input[role=switch]').click()");
check("masquage : je disparais du classement mais garde mes statistiques", await until("!/Fanja R\\./.test(document.body.innerText) && /1\\s*partie terminée/.test(document.body.innerText) && /Vous n’apparaissez pas dans ce classement/.test(document.body.innerText)", "masquage"));
await ev("document.querySelector('input[role=switch]').click()");
check("réactivation : je reviens au classement", await until("/Fanja R\\./.test(document.body.innerText)", "réaffichage"));
const lay4 = JSON.parse(await ev(`JSON.stringify({ scroll: document.documentElement.scrollWidth, inner: innerWidth, h1: document.querySelectorAll('h1').length, sansNom: [...document.querySelectorAll('a,button,summary,input')].filter(e => !(e.getAttribute('aria-label') || e.innerText.trim() || e.closest('label')?.innerText.trim())).length })`));
check("« Quiz » : aucun défilement horizontal, un seul h1, aucun contrôle sans nom", lay4.scroll <= lay4.inner && lay4.h1 === 1 && lay4.sansNom === 0, JSON.stringify(lay4));
await shot("03e-quiz-classement-sombre");
await go("/");

console.log(String.fromCharCode(10) + "3e. Mes inscriptions et fiche d'inscription");
const norm = () => ev("document.body.innerText.replace(/\\u00a0|\\u202f/g, ' ')");
await go("/inscriptions/");
await until("/Session 1/.test(document.body.innerText) && !document.querySelector('[aria-busy=true]')", "liste des inscriptions");
await sleep(600);
const en0 = await norm();
const tabsEn = JSON.parse(await ev(`JSON.stringify({ liens: [...document.querySelectorAll('nav.fixed a')].map(a => a.textContent.trim()), actif: document.querySelector('nav.fixed a[aria-current=page]')?.textContent.trim() })`));
check("cinq onglets, « Inscriptions » actif", tabsEn.liens.join(",") === "Accueil,Notes,Présence,Inscriptions,Plus" && tabsEn.actif === "Inscriptions", JSON.stringify(tabsEn));
check("année 2026 - 2027 avec ses deux sessions, niveau et nombre de cours", /année 2026 - 2027/i.test(en0) && /Session 1/.test(en0) && /Session 2/.test(en0) && /Licence 2/.test(en0) && /4 cours/.test(en0), "");
check("les fiches de Licence d'avant 2026 ne sont pas proposées (décision du registrariat)", !/année 2025 - 2026/i.test(en0));
check("avertissement « sans frais » une seule fois (session 2), pas sur la session 1", (en0.match(/ne vaut pas justificatif de paiement/g) ?? []).length === 1);
const lay5 = JSON.parse(await ev(`JSON.stringify({ scroll: document.documentElement.scrollWidth, inner: innerWidth, h1: document.querySelectorAll('h1').length, petits: [...document.querySelectorAll('main a, main button')].filter(e => e.getBoundingClientRect().width > 1 && Math.min(e.getBoundingClientRect().height, e.getBoundingClientRect().width) < 44).map(e => e.innerText.trim().slice(0, 20)), sansNom: [...document.querySelectorAll('a,button')].filter(e => !(e.getAttribute('aria-label') || e.innerText.trim())).length })`));
check("« Mes inscriptions » : aucun défilement horizontal, un seul h1, cibles ≥ 44 px, contrôles nommés", lay5.scroll <= lay5.inner && lay5.h1 === 1 && lay5.petits.length === 0 && lay5.sansNom === 0, JSON.stringify(lay5));
await shot("03f-inscriptions-sombre");

await click("a[aria-label='Ouvrir la fiche Session 1']");
check("le lien ouvre la fiche (adresse ?session=…)", await until("location.pathname === '/inscriptions/fiche/' && /^\\?session=\\d+$/.test(location.search)", "navigation fiche"), await path());
await until("/Total général/.test(document.body.innerText)", "fiche chargée");
await sleep(700);
const f = await norm();
check("identité : nom en majuscules + prénom, matricule, mention, niveau", /RAKOTO Fanja/.test(f) && /DEMO-0001/.test(f) && /Informatique/.test(f) && /Licence 2/.test(f));
check("quatre cours listés avec leur catégorie", /INF 210/.test(f) && /Structures de données/.test(f) && /ANG 102/.test(f) && /Général/.test(f) && /Majeur/.test(f));
check("le 3e laboratoire est offert, dit en toutes lettres (pas seulement barré)", (f.match(/— offert/g) ?? []).length === 1 && /Laboratoire 25 000 Ar — offert/.test(f));
check("total des cours et laboratoire facturé (deux premiers : 70 000 Ar)", /1 540 000 Ar/.test(f) && /\+ labo 70 000 Ar/.test(f));
check("frais divers : généraux, voyage, frais personnalisé (Alliance française)", /150 000 Ar/.test(f) && /Voyage d’étude/.test(f) && /Alliance française/.test(f) && /25 000 Ar/.test(f));
check("sous-total de scolarité 2 110 000 Ar et total général 2 335 000 Ar", /Sous-total\s*2 110 000 Ar/.test(f) && /Total général \(frais divers \+ frais de scolarité\)\s*2 335 000 Ar/.test(f));
check("tranche B en deux fois 50 % de 1 055 000 Ar avec leurs dates en français", /Tranche B/.test(f) && (f.match(/1 055 000 Ar/g) ?? []).length === 2 && /avant le 1er octobre 2026/.test(f) && /avant le 15 décembre 2026/.test(f));
check("résidence, cafétéria et work education affichés pour la Licence", /Interne/.test(f) && /Abonné\(e\)/.test(f) && /Entretien de la bibliothèque/.test(f));
check("aucun avertissement « frais non disponibles » sur cette fiche", !/Frais de scolarité non disponibles/.test(f));
const ph = JSON.parse(await ev(`new Promise((res) => { const i = document.querySelector('img[alt="Photo de l’étudiant"]'); if (!i) return res(JSON.stringify({ present: false })); const done = () => res(JSON.stringify({ present: true, w: i.naturalWidth, h: i.naturalHeight, vu: i.getBoundingClientRect().width })); i.complete ? done() : (i.onload = done, i.onerror = done); setTimeout(done, 5000); })`));
check("la photo de l'étudiant est affichée sur la fiche (image réellement chargée, réduite)", ph.present && ph.w > 100 && ph.w <= 420 && ph.h <= 540 && ph.vu > 40, JSON.stringify(ph));
await shot("03g2-fiche-photo-sombre");
const lay6 = JSON.parse(await ev(`JSON.stringify({ scroll: document.documentElement.scrollWidth, inner: innerWidth, h1: document.querySelectorAll('h1').length, petits: [...document.querySelectorAll('main a, main button')].filter(e => e.getBoundingClientRect().width > 1 && Math.min(e.getBoundingClientRect().height, e.getBoundingClientRect().width) < 44).map(e => e.innerText.trim().slice(0, 20)), sansNom: [...document.querySelectorAll('a,button')].filter(e => !(e.getAttribute('aria-label') || e.innerText.trim())).length })`));
check("fiche : aucun défilement horizontal, un seul h1, cibles ≥ 44 px, contrôles nommés", lay6.scroll <= lay6.inner && lay6.h1 === 1 && lay6.petits.length === 0 && lay6.sansNom === 0, JSON.stringify(lay6));
await shot("03g-fiche-sombre");

// Chrome n'écrase pas un fichier existant (il en crée un « (1) ») : on retire celui d'une exécution précédente, sinon on relirait l'ancien.
rmSync(join(OUT, "DEMO-0001-fiche-inscription-Session-1-2026-2027.pdf"), { force: true });
await ev("[...document.querySelectorAll('main button')].find(b => /Télécharger le PDF/.test(b.innerText)).click()");
check("le PDF se télécharge (message de confirmation avec le nom du fichier)", await until("/PDF téléchargé : DEMO-0001-fiche-inscription-Session-1-2026-2027\\.pdf/.test(document.body.innerText)", "téléchargement", 30000));
await sleep(800);
const pdfPath = join(OUT, "DEMO-0001-fiche-inscription-Session-1-2026-2027.pdf");
const pdfOk = existsSync(pdfPath) && readFileSync(pdfPath).subarray(0, 5).toString("latin1") === "%PDF-" && statSync(pdfPath).size > 5000;
check("le fichier reçu est un vrai PDF (en-tête %PDF-, plus de 5 Ko)", pdfOk, existsSync(pdfPath) ? String(statSync(pdfPath).size) : "absent");
check("le PDF contient la photo de l'étudiant (image intégrée)", existsSync(pdfPath) && readFileSync(pdfPath).toString("latin1").includes("/Subtype /Image"));
const direct = JSON.parse(await ev(`fetch("http://localhost:8000/api/v1/me/enrollments/" + new URLSearchParams(location.search).get("session") + "/pdf", { credentials: "include" }).then(r => JSON.stringify({ s: r.status, t: r.headers.get("content-type"), c: r.headers.get("cache-control"), d: r.headers.get("content-disposition") }))`));
check("réponse du PDF : application/pdf, jamais mis en cache, nom de fichier sûr", direct.s === 200 && direct.t === "application/pdf" && /no-store/.test(direct.c ?? "") && /^attachment; filename="[A-Za-z0-9._-]+\.pdf"$/.test(direct.d ?? ""), JSON.stringify(direct));

await go("/inscriptions/");
await until("/Session 2/.test(document.body.innerText) && !document.querySelector('[aria-busy=true]')", "liste (retour)");
await click("a[aria-label='Ouvrir la fiche Session 2']");
await until("/Frais de scolarité non disponibles/.test(document.body.innerText)", "fiche sans frais");
const g = await norm();
check("session sans ligne financière : l'absence est nommée, pas de faux total ni de faux frais", /Frais de scolarité non disponibles/.test(g) && !/Total général/.test(g) && !/Frais divers/.test(g) && /Aucun cours enregistré/.test(g));
await shot("03h-fiche-sans-frais-sombre");

await go("/inscriptions/fiche/?session=99999999");
check("session inconnue : « Fiche introuvable », rien d'autre", await until("/Fiche introuvable/.test(document.body.innerText)", "introuvable") && !/RAKOTO/.test(await text()));
await go("/inscriptions/fiche/?session=abc");
check("paramètre non numérique : « Fiche introuvable » sans appeler l'API", await until("/Fiche introuvable/.test(document.body.innerText)", "non numérique"));
await go("/");

console.log(String.fromCharCode(10) + "3f. Plus et Mes informations");
await go("/plus/");
await until("/Mes informations/.test(document.body.innerText) && /Quiz/.test(document.body.innerText)", "menu Plus");
const more = await text();
check("le menu « Plus » liste Actualités, Mes informations et Quiz, chacun avec son explication", /Actualités/.test(more) && /actualités du site de l’université/.test(more) && /Mes informations/.test(more) && /Votre fiche : identité, contact/.test(more) && /Quiz/.test(more) && /classement/.test(more));
check("« Plus » est l'onglet allumé", (await ev("document.querySelector('nav.fixed a[aria-current=page]')?.textContent.trim()")) === "Plus");
await click("a[href='/informations/']");
check("le lien ouvre Mes informations", await until("location.pathname === '/informations/'", "navigation informations"), await path());
await until("/RAKOTO/.test(document.body.innerText) && !document.querySelector('[aria-busy=true]')", "profil chargé");
await sleep(700);
const pf = await ev("document.body.innerText.replace(/\\u00a0/g, ' ')");
check("en-tête : prénom + NOM, niveau — mention, matricule, type et année", /Fanja RAKOTO/.test(pf) && /Licence 2 — Informatique/.test(pf) && /DEMO-0001/.test(pf) && /Ancien/.test(pf) && /2025-2026/.test(pf));
check("l'onglet « Plus » reste allumé sur Mes informations", (await ev("document.querySelector('nav.fixed a[aria-current=page]')?.textContent.trim()")) === "Plus");
const photoInfo = JSON.parse(await ev(`new Promise((res) => { const i = document.querySelector('img[alt="Photo de l’étudiant"]'); if (!i) return res(JSON.stringify({ present: false })); const done = () => res(JSON.stringify({ present: true, w: i.naturalWidth })); i.complete ? done() : (i.onload = done, i.onerror = done); setTimeout(done, 5000); })`));
check("la photo de l'étudiant est affichée", photoInfo.present && photoInfo.w > 100, JSON.stringify(photoInfo));
check("identité : date en français, sexe, lieu, nationalité", /12 mars 2001/.test(pf) && /Féminin/.test(pf) && /Antsirabe/.test(pf) && /Malagasy/.test(pf));
check("la date brute (2001-03-12) n'est jamais montrée", !/2001-03-12/.test(pf));
check("CIN MASQUÉ par défaut : seuls les 4 derniers chiffres sont visibles", /•••• •••• 3404/.test(pf) && !/101 202 303/.test(pf));
await click("button[aria-label='Afficher le numéro de CIN']");
check("le bouton « Afficher » révèle le numéro, et « Masquer » le recache", await until("/101 202 303 404/.test(document.body.innerText)", "CIN affiché") && (await ev("document.querySelector('button[aria-label=\"Masquer le numéro de CIN\"]') !== null")));
await click("button[aria-label='Masquer le numéro de CIN']");
check("CIN de nouveau masqué", await until("!/101 202 303/.test(document.body.innerText)", "CIN masqué"));
const links = JSON.parse(await ev(`JSON.stringify({ tel: document.querySelector('a[href^="tel:"]')?.getAttribute('href'), mail: document.querySelector('a[href^="mailto:"]')?.getAttribute('href') })`));
check("téléphone et e-mail sont des liens utilisables (tel:, mailto:)", links.tel === "tel:0340000000" && links.mail === "mailto:fanja.demo@example.test", JSON.stringify(links));
check("région retrouvée par son identifiant", /Vakinankaratra \(démo\)/.test(pf));
const tabsP = JSON.parse(await ev(`JSON.stringify({ tabs: [...document.querySelectorAll('[role=tab]')].map(t => t.textContent.trim() + ':' + t.getAttribute('aria-selected')), panel: document.querySelector('[role=tabpanel]')?.getAttribute('aria-labelledby') })`));
check("quatre onglets ARIA, « Identité et contact » sélectionné", tabsP.tabs.join("|") === "Identité et contact:true|Études:false|Famille:false|Garant:false" && tabsP.panel === "tab-identite", JSON.stringify(tabsP));
await ev("document.querySelector('[role=tab][aria-selected=true]').focus()");
await send("Input.dispatchKeyEvent", { type: "keyDown", key: "ArrowRight", code: "ArrowRight", windowsVirtualKeyCode: 39 });
await send("Input.dispatchKeyEvent", { type: "keyUp", key: "ArrowRight", code: "ArrowRight", windowsVirtualKeyCode: 39 });
check("clavier : la flèche droite passe à « Études » et y déplace le focus", await until("document.querySelector('[role=tab][aria-selected=true]')?.textContent.trim() === 'Études' && document.activeElement?.textContent.trim() === 'Études'", "flèche droite"));
const etudes = await ev("document.body.innerText");
check("Études : mention, parcours, niveau, hébergement, cantine, religion", /Génie logiciel/.test(etudes) && /Licence 2/.test(etudes) && /Interne/.test(etudes) && /Abonné\(e\)/.test(etudes) && /Adventiste/.test(etudes));
await ev("[...document.querySelectorAll('[role=tab]')].find(t => t.textContent.trim() === 'Famille').click()");
await until("/Rakoto Jean/.test(document.body.innerText)", "onglet famille");
const fam = await ev("document.body.innerText");
check("Famille : parents, professions, situation familiale", /Rakoto Jean/.test(fam) && /Enseignant/.test(fam) && /Soa Marie/.test(fam) && /Célibataire/.test(fam) && /033 11 111 11/.test(fam));
check("une valeur absente s'écrit « — » (conjoint(e))", /Conjoint\(e\)\s*—/.test(fam));
await ev("[...document.querySelectorAll('[role=tab]')].find(t => t.textContent.trim() === 'Garant').click()");
await until("/Locale \\(démo\\)/.test(document.body.innerText)", "onglet garant");
check("Garant : nom, prénom, téléphone, adresse", /Église/.test(await text()) && /032 22 222 22/.test(await text()) && /Centre-ville/.test(await text()));
check("la page dit qu'une correction se demande au registrariat (lecture seule)", /demandez la correction au bureau de la registraire/.test(await text()));
const lay7 = JSON.parse(await ev(`JSON.stringify({ scroll: document.documentElement.scrollWidth, inner: innerWidth, h1: document.querySelectorAll('h1').length, petits: [...document.querySelectorAll('main a, main button')].filter(e => e.getBoundingClientRect().width > 1 && Math.min(e.getBoundingClientRect().height, e.getBoundingClientRect().width) < 44).map(e => (e.getAttribute('aria-label') || e.innerText).trim().slice(0, 24)), sansNom: [...document.querySelectorAll('a,button')].filter(e => !(e.getAttribute('aria-label') || e.innerText.trim())).length })`));
check("Mes informations : aucun défilement horizontal, un seul h1, cibles ≥ 44 px, contrôles nommés", lay7.scroll <= lay7.inner && lay7.h1 === 1 && lay7.petits.length === 0 && lay7.sansNom === 0, JSON.stringify(lay7));
await shot("03i-informations-sombre");
await go("/");
console.log(String.fromCharCode(10) + "3g. Actualités");
await go("/plus/");
await until("/Actualités/.test(document.body.innerText)", "menu Plus (actualités)");
await click("a[href='/actualites/']");
check("« Plus » ouvre les actualités", await until("location.pathname === '/actualites/'", "navigation actualités"), await path());
await until("/Ouverture du Master/.test(document.body.innerText) && !document.querySelector('[aria-busy=true]')", "actualités du site");
await sleep(600);
const sn = await ev("document.body.innerText");
const spos = (x) => sn.indexOf(x);
check("par défaut : les actualités du SITE de l'université, la plus récente d'abord", /Ouverture du Master/.test(sn) && /Culte d’ouverture de l’année/.test(sn) && spos("Ouverture du Master") < spos("Culte d’ouverture de l’année") && spos("Culte d’ouverture de l’année") < spos("Article piégé"));
const srcSw = JSON.parse(await ev(`JSON.stringify([...document.querySelectorAll('[aria-label="Source des actualités"] button')].map(b => b.textContent.trim() + ':' + b.getAttribute('aria-pressed')))`));
check("deux sources : « Université (3) » (sélectionnée) et « Registrariat (4) »", srcSw.join("|") === "Université (3):true|Registrariat (4):false", JSON.stringify(srcSw));
const syncLink = JSON.parse(await ev(`JSON.stringify([...document.querySelectorAll('main a[href="https://zurcher.edu.mg/actualites"]')].map(a => ({ t: a.target, r: a.rel })))`));
check("la page dit qu'elle est synchronisée avec zurcher.edu.mg/actualites (lien sûr) et donne l'heure de mise à jour", syncLink.length === 1 && syncLink[0].t === "_blank" && /noopener/.test(syncLink[0].r) && /synchronisées avec/.test(sn) && /mises à jour le/.test(sn));
check("les annonces du registrariat ne sont PAS mélangées à celles du site", !/Réunion d’accueil/.test(sn) && !/Coupure d’eau/.test(sn));
const laySN = JSON.parse(await ev(`JSON.stringify({ scroll: document.documentElement.scrollWidth, inner: innerWidth, h1: document.querySelectorAll('h1').length, petits: [...document.querySelectorAll('main a, main button')].filter(e => e.getBoundingClientRect().width > 1 && Math.min(e.getBoundingClientRect().height, e.getBoundingClientRect().width) < 44).map(e => (e.getAttribute('aria-label') || e.innerText).trim().slice(0, 24)), sansNom: [...document.querySelectorAll('a,button')].filter(e => !(e.getAttribute('aria-label') || e.innerText.trim())).length })`));
check("actualités du site : aucun défilement horizontal, un seul h1, cibles ≥ 44 px, contrôles nommés", laySN.scroll <= laySN.inner && laySN.h1 === 1 && laySN.petits.length === 0 && laySN.sansNom === 0, JSON.stringify(laySN));
await shot("03j0-actualites-site-sombre");
await ev("[...document.querySelectorAll('main a')].find(a => /Ouverture du Master/.test(a.innerText)).click()");
check("le clic ouvre l'article (adresse ?slug=…)", await until("location.pathname === '/actualites/site/' && /^\\?slug=demo-ouverture-master$/.test(location.search)", "navigation article du site"), await path());
await until("/Les conditions d’admission/.test(document.body.innerText)", "article du site chargé");
await sleep(500);
const art = JSON.parse(await ev(`JSON.stringify({
  texte: document.body.innerText,
  h3: [...document.querySelectorAll('main h3')].map(h => h.textContent.trim()),
  li: [...document.querySelectorAll('main ul li')].map(l => l.textContent.trim()),
  quote: document.querySelector('main blockquote')?.textContent.trim(),
  strong: [...document.querySelectorAll('main strong')].map(x => x.textContent), em: [...document.querySelectorAll('main em')].map(x => x.textContent),
  br: document.querySelectorAll('main br').length,
  liens: [...document.querySelectorAll('main a[href^="http"]')].map(a => ({ h: a.getAttribute('href'), t: a.target, r: a.rel, tx: a.textContent.trim() })),
  h1: document.querySelectorAll('h1').length,
})`));
check("l'article du site est rendu en vrais éléments : intertitre, liste, citation, gras, italique, saut de ligne", art.h3.includes("Les conditions d’admission") && art.li.length === 2 && /Être titulaire d’une licence/.test(art.li[0]) && /Une citation du recteur/.test(art.quote) && art.strong.includes("gras") && art.em.includes("italique") && art.br >= 1, JSON.stringify({ h3: art.h3, li: art.li.length, q: !!art.quote, br: art.br }));
check("titre, auteur, date en français et rubrique de l'article", /Ouverture du Master \(démo\)/.test(art.texte) && /Service communication \(fictif\)/.test(art.texte) && /(lundi|mardi|mercredi|jeudi|vendredi|samedi|dimanche)\s+\d{1,2}\s+\w+\s+20\d\d/i.test(art.texte) && /Actualités/.test(art.texte));
const siteLinks = art.liens.filter((l) => l.tx === "le site");
check("lien du texte : nouvel onglet, noopener noreferrer nofollow", siteLinks.length === 1 && siteLinks[0].h === "https://zurcher.edu.mg/formations" && siteLinks[0].t === "_blank" && /noopener/.test(siteLinks[0].r) && /noreferrer/.test(siteLinks[0].r) && /nofollow/.test(siteLinks[0].r), JSON.stringify(siteLinks));
const original = art.liens.filter((l) => /Lire sur le site de l’université/.test(l.tx));
check("« Lire sur le site de l'université » mène à l'article public (adresse exacte)", original.length === 1 && original[0].h === "https://zurcher.edu.mg/actualites/demo-ouverture-master" && original[0].t === "_blank", JSON.stringify(original));
await shot("03j1-article-site-sombre");
await go("/actualites/site/?slug=demo-article-piege");
await until("/Des blocs hostiles|TEXTE\\./.test(document.body.innerText)", "article piégé");
await sleep(500);
const pg = JSON.parse(await ev(`JSON.stringify({
  xss: typeof window.__xss, scripts: document.querySelectorAll('main script').length,
  texte: document.body.innerText,
  liensDangereux: [...document.querySelectorAll('main a')].filter(a => /^(javascript|vbscript|data):/i.test(a.getAttribute('href') || '')).length,
  liensSurs: [...document.querySelectorAll('main a')].filter(a => a.getAttribute('href') === 'https://zurcher.edu.mg/ok').length,
  images: [...document.querySelectorAll('main img')].map(i => i.getAttribute('src')),
})`));
check("blocs HOSTILES : rien ne s'exécute, balises en texte, liens javascript:/vbscript: sans lien, image http et image javascript: absentes", pg.xss === "undefined" && pg.scripts === 0 && pg.liensDangereux === 0 && pg.liensSurs === 1 && /<script>window\.__xss = 1<\/script>/.test(pg.texte) && /lien piégé/.test(pg.texte) && pg.images.length === 0, JSON.stringify({ xss: pg.xss, dang: pg.liensDangereux, sûrs: pg.liensSurs, imgs: pg.images }));
check("le titre contenant des balises s'affiche littéralement", /Article piégé <b>gras<\/b> \(démo\)/.test(pg.texte));
await go("/actualites/site/?slug=inconnu-xyz");
check("article inconnu : « Article introuvable »", await until("/Article introuvable/.test(document.body.innerText)", "article inconnu"));
await go("/actualites/site/?slug=Pas Valide");
check("slug invalide : « Article introuvable » sans appeler l'API", await until("/Article introuvable/.test(document.body.innerText)", "slug invalide"));
const apiSite = JSON.parse(await ev(`(async () => { const r = await fetch('http://localhost:8000/api/v1/me/site-news', { credentials: 'include' }); const j = await r.json(); return JSON.stringify({ s: r.status, n: j.data.total, corps: JSON.stringify(j).includes('"blocks"'), html: /<\\/?(script|iframe|img|a) /i.test(JSON.stringify(j.data.items)) }); })()`));
check("API : la liste n'embarque aucun corps d'article", apiSite.s === 200 && apiSite.n === 3 && apiSite.corps === false, JSON.stringify(apiSite));
await go("/actualites/");
await until("/Registrariat \\(4\\)/.test(document.body.innerText)", "bouton registrariat");
await ev("[...document.querySelectorAll('[aria-label=\"Source des actualités\"] button')].find(b => /^Registrariat/.test(b.textContent.trim())).click()");
check("la source « Registrariat » affiche les annonces internes", await until("/Réunion d’accueil/.test(document.body.innerText) && !/Ouverture du Master/.test(document.body.innerText)", "onglet registrariat"));
await until("/Réunion d’accueil/.test(document.body.innerText) && !document.querySelector('[aria-busy=true]')", "liste des annonces");
await sleep(700);
const nw = await ev("document.body.innerText");
const pos = (s) => nw.indexOf(s);
check("seules les annonces VISIBLES sont listées : ni la programmée, ni l'expirée, ni la désactivée", /Réunion d’accueil/.test(nw) && /Coupure d’eau/.test(nw) && /Tournoi inter-mentions/.test(nw) && !/Annonce programmée/.test(nw) && !/Annonce expirée/.test(nw) && !/Annonce désactivée/.test(nw));
check("l'annonce épinglée vient en premier, puis les plus récentes", pos("Réunion d’accueil") > -1 && pos("Réunion d’accueil") < pos("Coupure d’eau") && pos("Coupure d’eau") < pos("Titre piégé") && pos("Titre piégé") < pos("Tournoi inter-mentions"));
check("« Épinglée », la catégorie et l'extrait fourni sont affichés en toutes lettres", /Épinglée/.test(nw) && /Événement/.test(nw) && /Urgent/.test(nw) && /La réunion d’accueil réunit tous les étudiants\./.test(nw));
check("extrait calculé : coupé avec « … » seulement quand le texte est long", /Venez nombreux\.[^\n]*…/.test(nw) && !/Le réseau sera coupé de 8 h à 12 h\.…/.test(nw));
check("l'annonce urgente n'est pas distinguée par la seule couleur (mot « Urgent » + icône)", /Urgent/.test(nw));
const chipsN = JSON.parse(await ev(`JSON.stringify([...document.querySelectorAll('[aria-label="Filtrer par catégorie"] button')].map(b => b.textContent.trim() + ':' + b.getAttribute('aria-pressed')))`));
check("filtres par catégorie avec leur nombre, « Toutes » enfoncé", chipsN[0] === "Toutes (4):true" && chipsN.some((c) => /^Urgent \(1\)/.test(c)) && chipsN.some((c) => /^Événement \(1\)/.test(c)) && !chipsN.some((c) => /Académique/.test(c)), JSON.stringify(chipsN));
await ev("[...document.querySelectorAll('[aria-label=\"Filtrer par catégorie\"] button')].find(b => /^Urgent/.test(b.textContent.trim())).click()");
check("filtrer par « Urgent » ne garde que la coupure d'eau", await until("/Coupure d’eau/.test(document.body.innerText) && !/Réunion d’accueil/.test(document.body.innerText) && !/Tournoi inter-mentions/.test(document.body.innerText)", "filtre urgent"));
await ev("[...document.querySelectorAll('[aria-label=\"Filtrer par catégorie\"] button')].find(b => /^Toutes/.test(b.textContent.trim())).click()");
await until("/Tournoi inter-mentions/.test(document.body.innerText)", "filtre toutes");
const layN = JSON.parse(await ev(`JSON.stringify({ scroll: document.documentElement.scrollWidth, inner: innerWidth, h1: document.querySelectorAll('h1').length, petits: [...document.querySelectorAll('main a, main button')].filter(e => e.getBoundingClientRect().width > 1 && Math.min(e.getBoundingClientRect().height, e.getBoundingClientRect().width) < 44).map(e => (e.getAttribute('aria-label') || e.innerText).trim().slice(0, 24)), sansNom: [...document.querySelectorAll('a,button')].filter(e => !(e.getAttribute('aria-label') || e.innerText.trim())).length })`));
check("« Actualités » : aucun défilement horizontal, un seul h1, cibles ≥ 44 px, contrôles nommés", layN.scroll <= layN.inner && layN.h1 === 1 && layN.petits.length === 0 && layN.sansNom === 0, JSON.stringify(layN));
check("l'onglet « Plus » reste allumé sur les actualités", (await ev("document.querySelector('nav.fixed a[aria-current=page]')?.textContent.trim()")) === "Plus");
await shot("03j-actualites-sombre");

await ev("[...document.querySelectorAll('main a')].find(a => /Réunion d’accueil/.test(a.innerText)).click()");
check("le clic ouvre l'annonce (adresse ?id=…)", await until("location.pathname === '/actualites/detail/' && /^\\?id=\\d+$/.test(location.search)", "navigation détail"), await path());
await until("/Programme complet/.test(document.body.innerText)", "détail chargé");
await sleep(900);
const det = await ev("document.body.innerText");
check("détail : titre, auteur, date en français complète, catégorie, épinglée, durée d'affichage", /Réunion d’accueil de la rentrée \(démo\)/.test(det) && /Démo \(fictif\)/.test(det) && /(lundi|mardi|mercredi|jeudi|vendredi|samedi|dimanche)\s+\d{1,2}\s+\w+\s+20\d\d/i.test(det) && /Événement/.test(det) && /Épinglée/.test(det) && /Affichée jusqu’au/.test(det));
check("les sauts de ligne sont respectés (le « \\n » littéral de la base devient une vraie ligne)", /Bienvenue à tous\.\nLa réunion aura lieu à la chapelle\.\nProgramme complet/.test(det) && !/\\n/.test(det));
const lk = JSON.parse(await ev(`JSON.stringify([...document.querySelectorAll('main a[href^="http"]')].map(a => ({ h: a.getAttribute('href'), t: a.target, r: a.rel })))`));
check("l'adresse du texte est un lien sûr (point final exclu, nouvel onglet, noopener)", lk.length === 1 && lk[0].h === "https://zurcher.edu.mg/rentree" && lk[0].t === "_blank" && /noopener/.test(lk[0].r) && /noreferrer/.test(lk[0].r), JSON.stringify(lk));
const imgD = JSON.parse(await ev(`new Promise((res) => { const i = document.querySelector('main img'); if (!i) return res(JSON.stringify({ present: false })); const done = () => res(JSON.stringify({ present: true, w: i.naturalWidth, h: i.naturalHeight })); i.complete ? done() : (i.onload = done, i.onerror = done); setTimeout(done, 5000); })`));
check("l'image de l'annonce est affichée, réduite à 1 200 px de large au plus (original 2 000 px)", imgD.present && imgD.w > 300 && imgD.w <= 1200 && Math.abs(imgD.w / imgD.h - 2) < 0.05, JSON.stringify(imgD));
const layD = JSON.parse(await ev(`JSON.stringify({ scroll: document.documentElement.scrollWidth, inner: innerWidth, h1: document.querySelectorAll('h1').length, petits: [...document.querySelectorAll('main a, main button')].filter(e => e.getBoundingClientRect().width > 1 && Math.min(e.getBoundingClientRect().height, e.getBoundingClientRect().width) < 44 && !/^http/.test(e.getAttribute('href') || '')).map(e => (e.getAttribute('aria-label') || e.innerText).trim().slice(0, 24)) })`));
check("détail : aucun défilement horizontal, un seul h1, cibles ≥ 44 px (hors lien dans le texte)", layD.scroll <= layD.inner && layD.h1 === 1 && layD.petits.length === 0, JSON.stringify(layD));
await shot("03k-actualite-detail-sombre");

await go("/actualites/");
await until("/Registrariat \\(4\\)/.test(document.body.innerText)", "bouton registrariat (piégée)");
await ev("[...document.querySelectorAll('[aria-label=\"Source des actualités\"] button')].find(b => /^Registrariat/.test(b.textContent.trim())).click()");
await until("/Titre piégé/.test(document.body.innerText)", "liste (piégée)");
await ev("[...document.querySelectorAll('main a')].find(a => /Titre piégé/.test(a.innerText)).click()");
await until("/window\\.__xss/.test(document.body.innerText)", "annonce piégée");
const xss = JSON.parse(await ev(`JSON.stringify({ var: typeof window.__xss, scripts: document.querySelectorAll('main script').length, imgs: document.querySelectorAll('main img[src="x"]').length, liens: [...document.querySelectorAll('main a')].filter(a => /javascript:/i.test(a.getAttribute('href') || '')).length, texte: document.body.innerText.includes('<script>window.__xss = 1</script>'), titre: document.body.innerText.includes('<b>Titre piégé</b>') })`));
check("HTML dans une annonce : affiché comme TEXTE inerte (rien ne s'exécute, aucune balise, aucun lien javascript:)", xss.var === "undefined" && xss.scripts === 0 && xss.imgs === 0 && xss.liens === 0 && xss.texte && xss.titre, JSON.stringify(xss));

await go("/actualites/detail/?id=99999999");
check("annonce inconnue : « Annonce introuvable »", await until("/Annonce introuvable/.test(document.body.innerText)", "annonce inconnue"));
await go("/actualites/detail/?id=abc");
check("paramètre non numérique : « Annonce introuvable » sans appeler l'API", await until("/Annonce introuvable/.test(document.body.innerText)", "annonce non numérique"));
const leak = JSON.parse(await ev(`(async () => { const list = (await (await fetch('http://localhost:8000/api/v1/me/news', { credentials: 'include' })).json()).data.items.map(i => i.id); const max = Math.max(...list); const out = { visibles: list.length, caches: 0, fuites: [] }; for (let id = Math.max(1, max - 12); id <= max + 3; id++) { if (list.includes(id)) continue; const r = await fetch('http://localhost:8000/api/v1/me/news/' + id, { credentials: 'include', headers: { Accept: 'application/json' } }); const im = await fetch('http://localhost:8000/api/v1/me/news/' + id + '/image', { credentials: 'include', headers: { Accept: 'application/json' } }); out.caches++; if (r.status !== 404 || im.status !== 404) out.fuites.push(id); } return JSON.stringify(out); })()`));
check("aucune annonce masquée (programmée, expirée, désactivée) n'est lisible ni par son identifiant ni par son image", leak.fuites.length === 0 && leak.caches >= 3 && leak.visibles === 4, JSON.stringify(leak));
await go("/");
console.log(String.fromCharCode(10) + "3h. Jeu : Rubik's Cube");
await go("/plus/");
await until("/Jeu/.test(document.body.innerText)", "menu Plus (jeu)");
check("le menu « Plus » propose le Jeu avec son explication", /Le Rubik’s Cube : mélangez-le, résolvez-le/.test(await text()));
await click("a[href='/jeu/']");
check("« Plus » ouvre le jeu", await until("location.pathname === '/jeu/'", "navigation jeu"), await path());
await until("document.querySelectorAll('[data-sticker]').length === 54", "54 stickers", 20000);
await sleep(500);
const rk = async () => JSON.parse(await ev(`JSON.stringify({ stats: [...document.querySelectorAll('main dd')].map(d => d.textContent.trim()), status: document.querySelector('main [role=status]')?.innerText.trim() })`));
const r0 = await rk();
check("cube résolu au départ : 54 stickers, 0 mouvement, 00:00, record « --:-- »", (await ev("document.querySelectorAll('[data-sticker]').length")) === 54 && r0.stats.join("|") === "0|00:00|--:--" && /Cube résolu/.test(r0.status), JSON.stringify(r0));
check("l'onglet « Plus » reste allumé sur le jeu", (await ev("document.querySelector('nav.fixed a[aria-current=page]')?.textContent.trim()")) === "Plus");
await shot("03l-jeu-resolu-sombre");

await ev("[...document.querySelectorAll('main button')].find(b => /Mélanger/.test(b.innerText)).click()");
await until("/Cube mélangé/.test(document.body.innerText)", "mélange");
const scr = await ev("document.querySelector('[data-testid=scramble]')?.textContent.trim() ?? ''");
const scrMoves = scr.split(/\s+/).filter(Boolean);
check("mélange de 25 quarts de tour, en notation standard, sans deux fois la même face de suite", scrMoves.length === 25 && scrMoves.every((m) => /^[UDLRFB]'?$/.test(m)) && scrMoves.every((m, i) => i === 0 || m[0] !== scrMoves[i - 1][0]), scr.slice(0, 60));
const r1 = await rk();
check("après le mélange : 0 mouvement, chrono à 00:00 (il démarre au premier mouvement)", r1.stats[0] === "0" && r1.stats[1] === "00:00" && /Cube mélangé/.test(r1.status), JSON.stringify(r1));
await shot("03m-jeu-melange-sombre");

// Résolution RÉELLE : on rejoue l'inverse du mélange, au clavier (Maj = sens inverse).
const key = async (face, inverse) => {
  const mods = inverse ? 8 : 0;
  const k = inverse ? face : face.toLowerCase();
  await send("Input.dispatchKeyEvent", { type: "keyDown", key: k, code: "Key" + face, text: k, modifiers: mods, windowsVirtualKeyCode: face.charCodeAt(0) });
  await send("Input.dispatchKeyEvent", { type: "keyUp", key: k, code: "Key" + face, modifiers: mods, windowsVirtualKeyCode: face.charCodeAt(0) });
};
const inverseSeq = [...scrMoves].reverse().map((m) => ({ face: m[0], inverse: !m.endsWith("'") }));
await key(inverseSeq[0].face, inverseSeq[0].inverse);
await until("document.querySelector('main dd')?.textContent.trim() === '1'", "premier mouvement");
check("le premier mouvement (au clavier) compte pour 1 et lance le chrono", (await rk()).stats[0] === "1");
await sleep(1300);
check("le chrono avance", (await rk()).stats[1] !== "00:00", JSON.stringify(await rk()));
for (const m of inverseSeq.slice(1)) { await key(m.face, m.inverse); await sleep(60); }
const won = await until("/Bravo ! Résolu en/.test(document.body.innerText)", "victoire", 30000);
const rw = await rk();
check("cube résolu en rejouant l'inverse du mélange : « Bravo ! Résolu en … avec 25 mouvements »", won && /avec 25 mouvements/.test(rw.status) && rw.stats[0] === "25", JSON.stringify(rw));
check("le temps du record est enregistré et affiché (premier record = « Nouveau record »)", /Nouveau record/.test(rw.status) && /^\d\d:\d\d$/.test(rw.stats[2]) && rw.stats[2] !== "--:--", JSON.stringify(rw.stats));
const stored = await ev("window.localStorage.getItem('registrar:rubik:best')");
check("le record est gardé dans le navigateur (en millisecondes, valeur raisonnable)", Number(stored) > 500 && Number(stored) < 120000, String(stored));
check("« Annuler » est désactivé après la victoire", await ev("[...document.querySelectorAll('main button')].find(b => /Annuler/.test(b.innerText)).disabled"));
await shot("03n-jeu-victoire-sombre");

// Annuler, boutons de faces, forme pour daltoniens
await ev("[...document.querySelectorAll('main button')].find(b => /Réinitialiser/.test(b.innerText)).click()");
await until("/Cube résolu/.test(document.body.innerText)", "réinitialisation");
check("« Réinitialiser » remet le cube résolu, à zéro, en gardant le record", (await rk()).stats[0] === "0" && (await rk()).stats[2] !== "--:--" && /Cube résolu/.test((await rk()).status));
await ev("document.querySelector('button[aria-label=\"Face droite, sens horaire\"]').click()");
await until("document.querySelector('main dd')?.textContent.trim() === '1'", "bouton de face");
check("un bouton de face tourne la face (1 mouvement), nommé pour les lecteurs d'écran", (await rk()).stats[0] === "1" && (await ev("!!document.querySelector('button[aria-label=\"Face haut, sens inverse\"]')")));
await ev("[...document.querySelectorAll('main button')].find(b => /Annuler/.test(b.innerText)).click()");
await until("document.querySelector('main dd')?.textContent.trim() === '0'", "annulation");
check("« Annuler » reprend le dernier mouvement (retour à 0) puis se désactive", (await rk()).stats[0] === "0" && (await ev("[...document.querySelectorAll('main button')].find(b => /Annuler/.test(b.innerText)).disabled")));
await ev("document.querySelector('button[aria-pressed]').click()");
await sleep(300);
check("le bouton daltonisme ajoute une forme sur CHAQUE sticker (54), pas seulement une couleur", (await ev("[...document.querySelectorAll('[data-sticker] > div')].filter(d => d.textContent.trim() !== '').length")) === 54);
await ev("document.querySelector('button[aria-pressed]').click()");

// Glisser à la souris : une face, puis le fond (orbite)
const target = JSON.parse(await ev(`(() => { const st = document.querySelector('main [role=group][aria-label^="Rubik"]').getBoundingClientRect(); const el = document.elementFromPoint(st.left + st.width / 2, st.top + st.height / 2); const s = el?.closest('[data-sticker]'); return JSON.stringify(s ? { x: st.left + st.width / 2, y: st.top + st.height / 2, ok: true, left: st.left, top: st.top } : { ok: false }); })()`));
check("le centre de la scène tombe sur une face du cube (glissé possible)", target.ok);
const mouse = (type, x, y, buttons) => send("Input.dispatchMouseEvent", { type, x, y, button: "left", buttons, clickCount: 1, pointerType: "mouse" });
await mouse("mousePressed", target.x + 30, target.y - 30, 1);
for (let i = 1; i <= 8; i++) { await mouse("mouseMoved", target.x + 30 + i * 9, target.y - 30, 1); await sleep(20); }
await mouse("mouseReleased", target.x + 30 + 72, target.y - 30, 0);
await sleep(600);
const afterDrag = await rk();
check("glisser sur une face la pivote (un glissé = un quart de tour)", afterDrag.stats[0] === "1", JSON.stringify(afterDrag.stats));
const pivotBefore = await ev("[...document.querySelectorAll('main [role=group] div')].find(d => /rotateX\\(/.test(d.style.transform) && d.style.transformStyle === 'preserve-3d' && !/matrix3d/.test(d.style.transform))?.style.transform");
await mouse("mousePressed", target.left + 8, target.top + 8, 1);
for (let i = 1; i <= 6; i++) { await mouse("mouseMoved", target.left + 8 + i * 12, target.top + 8 + i * 4, 1); await sleep(20); }
await mouse("mouseReleased", target.left + 80, target.top + 32, 0);
await sleep(300);
const pivotAfter = await ev("[...document.querySelectorAll('main [role=group] div')].find(d => /rotateX\\(/.test(d.style.transform) && d.style.transformStyle === 'preserve-3d' && !/matrix3d/.test(d.style.transform))?.style.transform");
check("glisser sur le fond tourne la vue (sans toucher au cube : toujours 1 mouvement)", pivotBefore && pivotAfter && pivotBefore !== pivotAfter && (await rk()).stats[0] === "1", `${pivotBefore} → ${pivotAfter}`);
const layG = JSON.parse(await ev(`JSON.stringify({ scroll: document.documentElement.scrollWidth, inner: innerWidth, h1: document.querySelectorAll('h1').length, petits: [...document.querySelectorAll('main button, main summary, main a')].filter(e => e.getBoundingClientRect().width > 1 && Math.min(e.getBoundingClientRect().height, e.getBoundingClientRect().width) < 44).map(e => (e.getAttribute('aria-label') || e.innerText).trim().slice(0, 24)), sansNom: [...document.querySelectorAll('a,button,summary')].filter(e => !(e.getAttribute('aria-label') || e.innerText.trim())).length, stage: Math.round(document.querySelector('main [role=group][aria-label^="Rubik"]').getBoundingClientRect().width) })`));
check("Jeu : aucun défilement horizontal, un seul h1, cibles ≥ 44 px, contrôles nommés", layG.scroll <= layG.inner && layG.h1 === 1 && layG.petits.length === 0 && layG.sansNom === 0, JSON.stringify(layG));
check("la zone du cube est « touch-action: none » (le glissé ne fait pas défiler la page) et nommée", (await ev("getComputedStyle(document.querySelector('main [role=group][aria-label^=\"Rubik\"]')).touchAction")) === "none" && /Glissez sur le fond/.test(await ev("document.querySelector('main [role=group][aria-label^=\"Rubik\"]').getAttribute('aria-label')")));
await shot("03o-jeu-apres-glisse-sombre");
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

console.log(String.fromCharCode(10) + "6b. Connexion avec le mot de passe de la fiche étudiante (sans Google)");
const logoutNow = async () => {
  await ev("document.querySelector('button[aria-label^=\"Se déconnecter\"]').click()");
  await until("location.pathname.startsWith('/login')", "déconnexion");
};
await type("#identifier", "DEMO-0005");
await type("#password", "Demo-Fiche-5");
await submit();
check("matricule + mot de passe de la fiche, SANS compte utilisateur : l'étudiant entre", await until("location.pathname === '/' && /Bonjour Tsiory/.test(document.body.innerText)", "connexion par la fiche"), await path());
const meFiche = JSON.parse(await ev(`fetch('http://localhost:8000/api/v1/auth/me', { credentials: 'include', headers: { Accept: 'application/json' } }).then(async r => JSON.stringify({ s: r.status, d: (await r.json()).data }))`));
check("le compte a été créé : rôle étudiant, rattaché au bon matricule", meFiche.s === 200 && meFiche.d.role === 'student' && meFiche.d.student_id === 'DEMO-0005' && meFiche.d.first_name === 'Tsiory', JSON.stringify(meFiche.d ?? {}).slice(0, 120));
await go("/informations/");
await until("/TSIORY|RASOA/.test(document.body.innerText.toUpperCase()) && !document.querySelector('[aria-busy=true]')", "profil suspendu");
await sleep(500);
const ps = await text();
check("étudiant suspendu : badges « Suspendu » et « Retrait », alerte avec le motif, aucune photo (initiales à la place)", /Suspendu/.test(ps) && /Retrait/.test(ps) && /Compte suspendu/.test(ps) && /Frais de scolarité impayés \(démo\)/.test(ps) && (await ev("document.querySelector('img[alt=\"Photo de l’étudiant\"]') === null")) && /Master 1 — Théologie/.test(ps) && /Nouveau/.test(ps), "");
check("l'alerte de suspension est annoncée (role=alert)", await ev("!!document.querySelector('[role=alert]') && /Compte suspendu/.test(document.querySelector('[role=alert]').innerText)"));
check("sexe masculin et champs vides en « — » pour une fiche presque vide", /Masculin/.test(ps) && /—/.test(ps));
await go("/");
await go(await path());
check("la session tient au rechargement (pas de déconnexion à la requête suivante)", await until("/Bonjour Tsiory/.test(document.body.innerText)", "rechargement fiche"));
await logoutNow();
await type("#identifier", " TSIORY.demo@EXAMPLE.test ");
await type("#password", "Demo-Fiche-5");
await submit();
check("par adresse e-mail (casse et espaces ignorés), avec le même mot de passe", await until("location.pathname === '/' && /Bonjour Tsiory/.test(document.body.innerText)", "connexion par e-mail"), await path());
await logoutNow();
await type("#identifier", "DEMO-0005");
await type("#password", "Demo-Fiche-X");
await submit();
const badFiche = await until("!!document.querySelector('[role=alert]')", "refus");
const badText = await ev("document.querySelector('[role=alert]')?.innerText ?? ''");
check("mauvais mot de passe : refus avec le message générique, sans révéler si le matricule existe", badFiche && /Vérifiez votre matricule/.test(badText) && !/Demo-Fiche/.test(badText) && (await path()).startsWith("/login"), badText.slice(0, 120));
await go("/login/");
await type("#identifier", "DEMO-0001");
await type("#password", "Demo-Fiche-1");
await submit();
check("un étudiant qui a déjà un compte entre aussi avec le mot de passe de sa fiche", await until("location.pathname === '/' && /Bonjour Fanja/.test(document.body.innerText)", "compte existant par la fiche"), await path());
await logoutNow();
await go("/login/");
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
await go("/inscriptions/");
check("un compte du personnel sur « /inscriptions/ » : explication claire, pas de fiches", await until("/réservée aux étudiants/.test(document.body.innerText)", "autre rôle inscriptions") && !/Session 1/.test(await text()));
await go("/informations/");
check("un compte du personnel sur « /informations/ » : explication claire, aucune donnée", await until("/réservée aux étudiants/.test(document.body.innerText)", "autre rôle informations") && !/Fanja|RAKOTO|CIN/.test(await text()));
await go("/actualites/");
check("un compte du personnel sur « /actualites/ » : explication claire, aucune annonce", await until("/réservée aux étudiants/.test(document.body.innerText)", "autre rôle actualités") && !/Réunion d’accueil/.test(await text()));
await go("/jeu/");
check("un compte du personnel sur « /jeu/ » : explication claire, pas de jeu", await until("/réservée aux étudiants/.test(document.body.innerText)", "autre rôle jeu") && (await ev("document.querySelectorAll('[data-sticker]').length")) === 0);
await go("/quiz/");
check("un compte du personnel sur « /quiz/ » : explication claire, pas de classement", await until("/réservée aux étudiants/.test(document.body.innerText)", "autre rôle quiz") && !/Tojo A\./.test(await text()));

console.log(String.fromCharCode(10) + "7b. Enseignant : registres de présence (en ligne puis hors ligne)");
{
  const API = "http://localhost:8000";
  /** Un second client, côté Node, avec sa propre session : joue « un autre appareil » qui modifie le serveur pendant que l'enseignant est hors ligne. */
  const apiAs = async (pseudo, password) => {
    const jar = {};
    const take = (res) => { for (const c of res.headers.getSetCookie?.() ?? []) { const [kv] = c.split(";"); const i = kv.indexOf("="); jar[kv.slice(0, i)] = kv.slice(i + 1); } };
    const base = { Origin: WEB, Referer: WEB + "/", Accept: "application/json" };
    take(await fetch(API + "/api/v1/auth/csrf", { headers: base }));
    const call = async (method, p, body) => {
      const res = await fetch(API + p, { method, headers: { ...base, "Content-Type": "application/json", Cookie: Object.entries(jar).map(([k, v]) => `${k}=${v}`).join("; "), "X-XSRF-TOKEN": decodeURIComponent(jar["XSRF-TOKEN"] ?? "") }, body: body ? JSON.stringify(body) : undefined });
      take(res);
      return { status: res.status, json: await res.json().catch(() => null) };
    };
    await call("POST", "/api/v1/auth/login", { identifier: pseudo, password });
    return call;
  };
  /** Dans la page : le même fetch que l'application (cookies de session), pour lire l'état réel du serveur. */
  const pageApi = (p) => ev(`fetch('${API}${p}', { credentials: 'include', headers: { Accept: 'application/json' } }).then(async r => JSON.stringify({ s: r.status, d: (await r.json()).data }))`).then(JSON.parse);
  /** Attend (jusqu'à 8 s) que l'état serveur lu par `read` satisfasse `ok` : l'écriture part après un contrôle CORS, elle n'est pas instantanée. */
  const serverState = async (read, ok) => { let last; for (let i = 0; i < 40; i++) { last = await read(); if (ok(last)) return last; await sleep(200); } return last; };
  const setValue = (sel, v) => ev(`(() => { const e = document.querySelector(${JSON.stringify(sel)}); const set = Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value').set; set.call(e, ${JSON.stringify(v)}); e.dispatchEvent(new Event('input', { bubbles: true })); e.dispatchEvent(new Event('change', { bubbles: true })); })()`);
  const clickText = (scopeSel, label) => ev(`(() => { const b = [...document.querySelector(${JSON.stringify(scopeSel)}).querySelectorAll('button')].find(x => x.innerText.trim() === ${JSON.stringify(label)}); if (!b) return false; b.click(); return true; })()`);
  /** Le bouton des changements en attente : sur téléphone il n'affiche que le nombre, son texte complet est son nom accessible. */
  const PENDING = "(document.querySelector('header button[aria-label*=\"changement\"], header button[aria-label*=\"Envoi\"]')?.getAttribute('aria-label') ?? '')";
  const rowText = (id) => ev(`document.querySelector('[data-line="${id}"]')?.innerText ?? ''`);
  /** Une commande DevTools sur une autre cible (le service worker a sa propre connexion réseau, à couper aussi). */
  const cdpOn = (wsUrl, method, params) => new Promise((resolve) => { const w = new WebSocket(wsUrl); w.onopen = () => { w.send(JSON.stringify({ id: 1, method: "Network.enable" })); w.send(JSON.stringify({ id: 2, method, params })); }; w.onmessage = (m) => { if (JSON.parse(m.data).id === 2) { w.close(); resolve(); } }; w.onerror = () => resolve(); setTimeout(resolve, 3000); });
  const setOffline = async (off) => {
    scriptsOff = off; // les requêtes qui échouent pendant la coupure sont voulues : elles ne comptent pas comme des défauts
    const conditions = { offline: off, latency: 0, downloadThroughput: -1, uploadThroughput: -1 };
    await send("Network.emulateNetworkConditions", conditions);
    const targets = await (await fetch("http://127.0.0.1:9340/json")).json();
    for (const t of targets.filter((x) => x.type === "service_worker")) await cdpOn(t.webSocketDebuggerUrl, "Network.emulateNetworkConditions", conditions);
    if (!off) await ev("window.dispatchEvent(new Event('online'))");
  };
  const localDay = (ms = Date.now()) => { const d = new Date(ms); return new Date(d.getTime() - d.getTimezoneOffset() * 60000).toISOString().slice(0, 10); };
  const today = localDay();

  await logoutNow();
  await go("/login/");
  await type("#identifier", "enseignant.demo");
  await type("#password", PASSWORD);
  await submit();
  check("l'enseignant arrive sur son accueil, avec « Présence »", await until("location.pathname === '/' && /Bonjour Hasina/.test(document.body.innerText) && /Présence/.test(document.body.innerText)", "accueil enseignant"), await path());
  const tNav = await ev("[...document.querySelectorAll('nav.fixed a')].map(a => a.textContent.trim())");
  check("la barre du bas de l'enseignant n'a que des entrées qui existent : aucun lien mort", JSON.stringify(tNav) === JSON.stringify(["Accueil", "Présence", "Notes"]), JSON.stringify(tNav));
  check("l'accueil propose la « Remise de notes » (lien vers /notes/)", /Remise de notes/.test(await text()) && (await ev("[...document.querySelectorAll('a')].some(a => /Remise de notes/.test(a.innerText) && a.getAttribute('href') === '/notes/')")));
  await shot("07b-enseignant-accueil");

  // --- liste des cours
  await click("a[href='/presence/']");
  await until("/Systèmes d’exploitation/.test(document.body.innerText) && !document.querySelector('[aria-busy=true]')", "liste des cours");
  await sleep(500);
  const courses = await text();
  check("mes cours : les deux de l'enseignant, avec étudiants, séances tenues / prévues et alerte 15 %", /DEMO 301/.test(courses) && /DEMO 302/.test(courses) && /6 étudiants/.test(courses) && /2 étudiants/.test(courses) && /3 \/ 10 séances/.test(courses) && /1 étudiant au-delà de 15 %/.test(courses), courses.slice(0, 160).replace(/\n/g, " "));
  check("le cours d'un AUTRE enseignant n'apparaît pas", !/DEMO 303|autre enseignant/.test(courses));
  await shot("07c-enseignant-cours");
  const href301 = await ev("[...document.querySelectorAll('a[href*=\"/presence/registre/\"]')].find(a => /DEMO 301/.test(a.innerText)).getAttribute('href')");
  const id301 = Number(new URL(href301, WEB).searchParams.get("cours"));

  // --- isolation : un cours d'autrui est « introuvable », indiscernable d'un cours inexistant
  const foreign = await pageApi(`/api/v1/teacher/courses/${id301 + 2}/register?year=${encodeURIComponent("2026 - 2027")}&semester=1`);
  const missing = await pageApi(`/api/v1/teacher/courses/987654/register?year=${encodeURIComponent("2026 - 2027")}&semester=1`);
  check("un cours d'un autre enseignant : 404, exactement comme un cours inexistant (rien ne fuit)", foreign.s === 404 && missing.s === 404, `${foreign.s}/${missing.s}`);

  // --- registre
  await click(`a[href='${href301}']`);
  await until("location.pathname === '/presence/registre/' && /Base des 15 %/.test(document.body.innerText) && !document.querySelector('[aria-busy=true]')", "registre");
  await sleep(600);
  const reg = await text();
  check("registre : base des 15 % pré-remplie (10 séances = 1 absence permise)", (await ev("document.querySelector('#planned').value")) === "10" && /1 absence permise ; la 2e fait dépasser 15 %/.test(reg), "");
  check("registre : 3 séances tenues, la plus récente d'abord", /Séances tenues \(3 sur 10 prévues\)/.test(reg) && reg.indexOf("21 septembre") < reg.indexOf("14 septembre") && reg.indexOf("14 septembre") < reg.indexOf("7 septembre"));
  check("registre : alerte « 1 étudiant dépasse 15 % » (déjà signalé au registrariat)", /1 étudiant dépasse 15 % d’absences/.test(reg) && /Signalé au registrariat le 14\/09\/2026/.test(reg));
  const bilan = await ev("JSON.stringify([...document.querySelectorAll('section[aria-labelledby=bilan] li')].map(li => li.innerText.replace(/\\s+/g, ' ')))");
  check("bilan : le plus absent est au-delà de 15 %, le suivant à la limite", /Andrianina Lalaina.*Au-delà de 15 %/.test(JSON.parse(bilan)[0]) && /Bezara Mirana.*À la limite des 15 %/.test(JSON.parse(bilan)[1]), JSON.parse(bilan)[0]?.slice(0, 120));
  await shot("07d-enseignant-registre");

  // --- nouvelle séance (aujourd'hui) et appel
  check("la date proposée est celle du jour, et ne peut pas être dans le futur", (await ev("document.querySelector('#date').value")) === today && (await ev("document.querySelector('#date').max")) === today);
  // Heures FIXES : l'arrivée proposée est l'heure actuelle, qui dépend du moment où le test tourne (avant 10 h du matin, « 10:00 » serait dans le futur).
  await setValue("#planned-time", "00:00");
  await setValue("#arrival", "12:00");
  check("§474 annoncé AVANT d'ouvrir : « Votre arrivée » (heure actuelle) très en retard sur le début prévu → avertissement", await until("/sans pénalité pour les étudiants/.test(document.querySelector('[role=status]')?.innerText ?? '')", "avertissement §474"));
  await setValue("#arrival", "00:05");
  check("l'avertissement disparaît quand l'arrivée est à l'heure", await until("!/sans pénalité pour les étudiants/.test(document.body.innerText)", "avertissement retiré"));
  await ev("[...document.querySelectorAll('button')].find(b => /Faire l’appel/.test(b.innerText)).click()");
  check("« Faire l'appel » ouvre la séance : liste d'appel des 6 inscrits, personne pointé", await until("location.pathname === '/presence/appel/' && document.querySelectorAll('[data-line]').length === 6 && /0 \\/ 6 pointés/.test(document.body.innerText)", "appel"), await path());
  const sid = Number(new URL(await ev("location.href")).searchParams.get("seance"));
  check("la séance a un vrai identifiant serveur", sid > 0, String(sid));
  await shot("07e-appel-vide");

  await ev("[...document.querySelectorAll('button')].find(b => /Tous présents/.test(b.innerText)).click()");
  check("« Tous présents » pointe les 6 d'un coup", await until("/6 \\/ 6 pointés/.test(document.body.innerText)", "tous présents"));

  await clickText("[data-line='DEMO-0102']", "Absent");
  check("un 2e absent pour DEMO-0102 : « Absent », et le bilan du serveur le met au-delà de 15 %", await until("/Absent/.test(document.querySelector('[data-line=\"DEMO-0102\"]')?.innerText ?? '') && /Au-delà de 15 %/.test(document.querySelector('[data-line=\"DEMO-0102\"]')?.innerText ?? '')", "bilan 0102"), (await rowText("DEMO-0102")).replace(/\n/g, " ").slice(0, 100));
  check("le franchissement des 15 % est annoncé à l'enseignant", /dépasse 15 % d’absences/.test(await ev("document.querySelector('[data-sonner-toaster]')?.innerText ?? ''")));
  const sig = await pageApi(`/api/v1/teacher/courses/${id301}/register?year=${encodeURIComponent("2026 - 2027")}&semester=1`);
  const s0102 = sig.d.students.find((s) => s.id === "DEMO-0102");
  check("le signalement au registrariat est enregistré par le serveur (une seule fois)", Boolean(s0102.flagged_at) && s0102.summary.state === "exceeded" && sig.d.exceeded === 2, JSON.stringify({ f: s0102.flagged_at, e: sig.d.exceeded }));

  await clickText("[data-line='DEMO-0103']", "Retard");
  await until("!!document.querySelector('#min-DEMO-0103')", "durée du retard");
  check("un retard sans durée compte pour 5 minutes", /Retard 5 min/.test(await rowText("DEMO-0103")));
  await type("#min-DEMO-0103", "12");
  await ev("document.querySelector('#min-DEMO-0103').blur()");
  check("12 minutes de retard comptent comme une absence (la durée décide, pas le bouton)", await until("/Retard 12 min \\(compte absence\\)/.test(document.querySelector('[data-line=\"DEMO-0103\"]')?.innerText ?? '')", "retard 12"), (await rowText("DEMO-0103")).replace(/\n/g, " ").slice(0, 100));
  await ev("(() => { const s = document.querySelector('#just-DEMO-0102'); const set = Object.getOwnPropertyDescriptor(HTMLSelectElement.prototype, 'value').set; set.call(s, 'medical'); s.dispatchEvent(new Event('change', { bubbles: true })); })()");
  const line0102 = await serverState(async () => (await pageApi(`/api/v1/teacher/sessions/${sid}`)).d.lines.find((l) => l.student_id === "DEMO-0102"), (l) => l.justification === "medical");
  check("le justificatif est enregistré sans changer le pointage", line0102.justification === "medical" && line0102.status === "absent", JSON.stringify([line0102.justification, line0102.status]));

  // appuyer de nouveau sur le bouton actif efface le pointage ; on remet ensuite « Présent »
  await clickText("[data-line='DEMO-0104']", "Présent");
  check("appuyer sur le bouton actif efface le pointage (« Non pointé »)", await until("/Non pointé/.test(document.querySelector('[data-line=\"DEMO-0104\"]')?.innerText ?? '') && /5 \\/ 6 pointés/.test(document.body.innerText)", "effacer"));
  await clickText("[data-line='DEMO-0104']", "Présent");
  await until("/6 \\/ 6 pointés/.test(document.body.innerText)", "re-pointé");

  // §474 : l'enseignant arrive 30 minutes en retard sans prévenir
  await ev("document.querySelector('details summary').click()");
  await until("!!document.querySelector('#s-arrival')", "réglages");
  await setValue("#s-arrival", "00:30");
  await ev("[...document.querySelectorAll('button')].find(b => /Enregistrer la séance/.test(b.innerText)).click()");
  check("§474 : 30 minutes de retard de l'enseignant sans message → « sans pénalité » annoncé", await until("/Sans pénalité pour les étudiants : vous avez 30 min de retard/.test(document.body.innerText)", "§474"));
  const after474 = (await serverState(async () => (await pageApi(`/api/v1/teacher/courses/${id301}/register?year=${encodeURIComponent("2026 - 2027")}&semester=1`)).d, (r) => r.students.find((x) => x.id === "DEMO-0102").summary.state === "limit"));
  check("les absences de cette séance ne comptent plus : DEMO-0102 n'est plus au-delà de 15 % (mais reste signalé)", after474.students.find((s) => s.id === "DEMO-0102").summary.state === "limit" && Boolean(after474.students.find((s) => s.id === "DEMO-0102").flagged_at), JSON.stringify(after474.students.find((s) => s.id === "DEMO-0102").summary.state));
  await shot("07f-appel-pointe");

  // --- HORS LIGNE : pointer sans réseau, puis le serveur a changé entre-temps (conflit)
  await setOffline(true);
  check("hors ligne : le bandeau « Hors ligne » apparaît", await until("/Hors ligne/.test(document.body.innerText)", "bandeau hors ligne"));
  await clickText("[data-line='DEMO-0105']", "Absent");
  await clickText("[data-line='DEMO-0106']", "Absent");
  check("hors ligne : les pointages s'affichent tout de suite et le bilan est dit « en attente »", await until("/Absent/.test(document.querySelector('[data-line=\"DEMO-0105\"]')?.innerText ?? '') && /Bilan à jour au retour du réseau/.test(document.querySelector('[data-line=\"DEMO-0105\"]')?.innerText ?? '')", "pointage hors ligne"), (await rowText("DEMO-0105")).replace(/\n/g, " ").slice(0, 120));
  check("hors ligne : « 2 changements en attente » est visible dans l'en-tête", await until("/2 changements en attente/.test(" + PENDING + ")", "chip en attente"), await ev(PENDING));
  await sleep(1200);
  // Pendant ce temps, un autre appareil change les MÊMES pointages sur le serveur (plus récents que ceux faits hors ligne).
  const other = await apiAs("enseignant.demo", PASSWORD);
  const r1 = await other("PUT", `/api/v1/teacher/sessions/${sid}/marks/DEMO-0105`, { status: "retard", minutes_late: 7 });
  const r2 = await other("PUT", `/api/v1/teacher/sessions/${sid}/marks/DEMO-0106`, { status: "retard", minutes_late: 8 });
  check("(préparation) un autre appareil a modifié les deux pointages sur le serveur", r1.status === 200 && r2.status === 200, `${r1.status}/${r2.status}`);
  await setOffline(false);
  check("retour du réseau : le serveur fait foi, les 2 changements sont « à vérifier » (rien n'est écrasé)", await until("/2 changements à vérifier/.test(" + PENDING + ")", "conflits", 25000), await ev(PENDING));
  const stillServer = (await pageApi(`/api/v1/teacher/sessions/${sid}`)).d.lines;
  check("les données du serveur sont intactes tant que l'enseignant n'a pas choisi", stillServer.find((l) => l.student_id === "DEMO-0105").status === "retard" && stillServer.find((l) => l.student_id === "DEMO-0106").minutes_late === 8);
  await ev("[...document.querySelectorAll('header button')].find(b => /à vérifier/.test(b.getAttribute('aria-label') ?? '')).click()");
  await until("!!document.querySelector('dialog[open]')", "panneau");
  const panel = await ev("document.querySelector('dialog[open]').innerText");
  check("le panneau dit ce qui s'est passé, avec les deux choix", /a été modifié depuis/.test(panel) && /Appliquer quand même/.test(panel) && /Ignorer ce changement/.test(panel), panel.replace(/\n/g, " ").slice(0, 160));
  await shot("07g-conflits");
  // 1re carte (DEMO-0105) : appliquer quand même ; la suivante (DEMO-0106) : ignorer
  await ev("[...document.querySelectorAll('dialog[open] button')].find(b => /Appliquer quand même/.test(b.innerText)).click()");
  await until("!/2 changements/.test(" + PENDING + ")", "après appliquer");
  await sleep(1500);
  await ev("[...document.querySelectorAll('dialog[open] button')].find(b => /Ignorer ce changement/.test(b.innerText))?.click()");
  check("tout est tranché : plus aucun changement en attente", await until("!/changement/.test(" + PENDING + ")", "file vide", 20000), await ev(PENDING));
  await sleep(800);
  const resolved = (await pageApi(`/api/v1/teacher/sessions/${sid}`)).d.lines;
  check("« Appliquer quand même » a écrit l'absence (DEMO-0105) ; « Ignorer » a gardé le serveur (DEMO-0106 : retard 8 min)", resolved.find((l) => l.student_id === "DEMO-0105").status === "absent" && resolved.find((l) => l.student_id === "DEMO-0106").status === "retard" && resolved.find((l) => l.student_id === "DEMO-0106").minutes_late === 8, JSON.stringify(resolved.filter((l) => /0105|0106/.test(l.student_id)).map((l) => [l.student_id, l.status, l.minutes_late])));

  // --- HORS LIGNE : ouvrir une séance sans réseau, pointer, puis tout part dans l'ordre au retour du réseau
  await go(href301);
  await until("location.pathname === '/presence/registre/' && /Base des 15 %/.test(document.body.innerText) && !document.querySelector('[aria-busy=true]')", "registre (copie)");
  await sleep(500);
  const yesterday = localDay(Date.now() - 86400000);
  check("le service worker est actif et contrôle la page (coque gardée pour le hors ligne)", await until("!!navigator.serviceWorker?.controller", "service worker actif", 20000));
  await setOffline(true);
  await send("Page.reload");
  check("hors ligne : la page se RECHARGE quand même (coque gardée par le service worker) et rouvre la copie du registre", await until("/Base des 15 %/.test(document.body.innerText) && /Vous voyez la copie gardée sur cet appareil/.test(document.body.innerText)", "rechargement hors ligne", 25000), (await path()));
  await shot("07g2-registre-hors-ligne");
  await setValue("#date", yesterday);
  await setValue("#planned-time", "14:00");
  await ev("[...document.querySelectorAll('button')].find(b => /Faire l’appel/.test(b.innerText)).click()");
  check("hors ligne : la séance s'ouvre quand même, avec la liste d'appel des 6 inscrits", await until("location.pathname === '/presence/appel/' && document.querySelectorAll('[data-line]').length === 6 && /pas encore envoyée/.test(document.body.innerText)", "séance locale"), await path());
  await clickText("[data-line='DEMO-0101']", "Absent");
  await clickText("[data-line='DEMO-0103']", "Présent");
  check("hors ligne : 2 pointages sur cette séance locale, 3 changements en attente (ouverture + 2 pointages)", await until("/2 \\/ 6 pointés/.test(document.body.innerText) && /3 changements en attente/.test(" + PENDING + ")", "séance locale pointée"), await ev(PENDING));
  await shot("07h-seance-hors-ligne");
  await setOffline(false);
  check("retour du réseau : tout part dans l'ordre, la file se vide", await until("!/changement/.test(" + PENDING + ")", "envoi", 30000));
  check("l'adresse suit : la séance a reçu son vrai identifiant serveur", await until("Number(new URL(location.href).searchParams.get('seance')) > 0", "vrai identifiant", 15000), await ev("location.search"));
  await sleep(800);
  const list = (await pageApi(`/api/v1/teacher/courses/${id301}/register?year=${encodeURIComponent("2026 - 2027")}&semester=1`)).d;
  const created = list.sessions.find((s) => s.date === yesterday && s.planned_time === "14:00");
  const createdLines = created ? (await pageApi(`/api/v1/teacher/sessions/${created.id}`)).d.lines : [];
  check("côté serveur : la séance d'hier existe UNE fois, avec les 2 pointages faits hors ligne", Boolean(created) && list.sessions.filter((s) => s.date === yesterday && s.planned_time === "14:00").length === 1 && createdLines.find((l) => l.student_id === "DEMO-0101")?.status === "absent" && createdLines.find((l) => l.student_id === "DEMO-0103")?.status === "present", JSON.stringify(created ?? null).slice(0, 100));

  // --- supprimer la séance de test (confirmation en deux temps)
  await ev("[...document.querySelectorAll('button')].find(b => /Supprimer cette séance/.test(b.innerText)).click()");
  check("supprimer demande une confirmation explicite", await until("/tous ses pointages/.test(document.body.innerText)", "confirmation"));
  await ev("[...document.querySelectorAll('button')].find(b => /Oui, supprimer la séance/.test(b.innerText)).click()");
  check("après suppression on revient au registre", await until("location.pathname === '/presence/registre/'", "retour registre"));
  const afterDelete = await serverState(async () => (await pageApi(`/api/v1/teacher/courses/${id301}/register?year=${encodeURIComponent("2026 - 2027")}&semester=1`)).d, (r) => !r.sessions.some((s) => s.date === yesterday));
  check("côté serveur : la séance d'hier a disparu, les 4 autres séances restent", !afterDelete.sessions.some((s) => s.date === yesterday) && afterDelete.sessions.length === 4, String(afterDelete.sessions.length));

  // --- mise en page et accessibilité de l'espace enseignant
  await go("/presence/");
  await until("/Systèmes d’exploitation/.test(document.body.innerText) && !document.querySelector('[aria-busy=true]')", "liste");
  const layT = JSON.parse(await ev(`JSON.stringify({ scroll: document.documentElement.scrollWidth, inner: innerWidth, h1: document.querySelectorAll('h1').length, sansNom: [...document.querySelectorAll('a,button,summary')].filter(e => !(e.getAttribute('aria-label') || e.innerText.trim())).length })`));
  check("liste des cours : aucun défilement horizontal, un seul h1, contrôles nommés", layT.scroll <= layT.inner && layT.h1 === 1 && layT.sansNom === 0, JSON.stringify(layT));
  await go(href301);
  await until("location.pathname === '/presence/registre/' && /Base des 15 %/.test(document.body.innerText) && !document.querySelector('[aria-busy=true]')", "registre final");
  const layR = JSON.parse(await ev(`JSON.stringify({ scroll: document.documentElement.scrollWidth, inner: innerWidth, petits: [...document.querySelectorAll('main button, main a, main input, main select')].map(e => (e.type === 'checkbox' && e.labels?.[0]) || e).filter(e => e.getBoundingClientRect().width > 1 && Math.min(e.getBoundingClientRect().height, e.getBoundingClientRect().width) < 40).map(e => (e.getAttribute('aria-label') || e.innerText || e.id).trim().slice(0, 24)), sansLabel: [...document.querySelectorAll('main input, main select')].filter(e => !e.labels?.length && !e.getAttribute('aria-label')).length })`));
  check("registre : aucun défilement horizontal, cibles tactiles ≥ 40 px, chaque champ a son étiquette", layR.scroll <= layR.inner && layR.petits.length === 0 && layR.sansLabel === 0, JSON.stringify(layR));

  // --- déconnexion propre (file vide : pas de confirmation) : plus rien de l'enseignant sur l'appareil
  await ev("document.querySelector('button[aria-label^=\"Se déconnecter\"]').click()");
  check("déconnexion de l'enseignant (aucun changement en attente, aucune confirmation)", await until("location.pathname.startsWith('/login')", "déconnexion enseignant"));
  const leftover = await ev("new Promise(res => { const r = indexedDB.open('registrar-offline'); r.onsuccess = () => { const db = r.result; const t = db.transaction(['ops','snapshots'], 'readonly'); let n = 0; t.objectStore('ops').count().onsuccess = e => { n += e.target.result; t.objectStore('snapshots').count().onsuccess = e2 => res(n + e2.target.result); }; }; r.onerror = () => res(-1); })");
  check("déconnexion : plus aucune copie ni file de cet enseignant sur l'appareil (appareil partagé)", leftover === 0, String(leftover));
}

console.log(String.fromCharCode(10) + "7c. Enseignant : remise de notes (colonnes libres, en ligne puis hors ligne)");
{
  const API = "http://localhost:8000";
  const pageApi = (p) => ev(`fetch('${API}${p}', { credentials: 'include', headers: { Accept: 'application/json' } }).then(async r => JSON.stringify({ s: r.status, d: (await r.json()).data }))`).then(JSON.parse);
  const serverState = async (read, ok) => { let last; for (let i = 0; i < 40; i++) { last = await read(); if (ok(last)) return last; await sleep(200); } return last; };
  const setValue = (sel, v) => ev(`(() => { const e = document.querySelector(${JSON.stringify(sel)}); const set = Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value').set; set.call(e, ${JSON.stringify(v)}); e.dispatchEvent(new Event('input', { bubbles: true })); e.dispatchEvent(new Event('change', { bubbles: true })); })()`);
  const typeCell = async (sel, v) => { await ev(`document.querySelector(${JSON.stringify(sel)}).focus()`); await setValue(sel, v); await ev(`(() => { const e = document.querySelector(${JSON.stringify(sel)}); e.blur(); e.dispatchEvent(new FocusEvent('focusout', { bubbles: true })); })()`); };
  const clickBtn = (re) => ev(`(() => { const b = [...document.querySelectorAll('main button')].find(x => ${re}.test(x.innerText) || ${re}.test(x.getAttribute('aria-label') || '')); if (!b) return false; b.click(); return true; })()`);
  const card = (id) => ev(`document.querySelector('[data-student="${id}"]')?.innerText ?? ''`);
  const PENDING = "(document.querySelector('header button[aria-label*=\"changement\"], header button[aria-label*=\"Envoi\"]')?.getAttribute('aria-label') ?? '')";
  const cdpOn = (wsUrl, method, params) => new Promise((resolve) => { const w = new WebSocket(wsUrl); w.onopen = () => { w.send(JSON.stringify({ id: 1, method: "Network.enable" })); w.send(JSON.stringify({ id: 2, method, params })); }; w.onmessage = (m) => { if (JSON.parse(m.data).id === 2) { w.close(); resolve(); } }; w.onerror = () => resolve(); setTimeout(resolve, 3000); });
  const setOffline = async (off) => {
    scriptsOff = off;
    const conditions = { offline: off, latency: 0, downloadThroughput: -1, uploadThroughput: -1 };
    await send("Network.emulateNetworkConditions", conditions);
    const targets = await (await fetch("http://127.0.0.1:9340/json")).json();
    for (const t of targets.filter((x) => x.type === "service_worker")) await cdpOn(t.webSocketDebuggerUrl, "Network.emulateNetworkConditions", conditions);
    if (!off) await ev("window.dispatchEvent(new Event('online'))");
  };
  const waitSheet = (label) => until("location.pathname === '/notes/feuille/' && !document.querySelector('[aria-busy=true]') && document.querySelectorAll('[data-student]').length > 0", label, 25000);

  await go("/login/");
  await type("#identifier", "enseignant.demo");
  await type("#password", PASSWORD);
  await submit();
  await until("location.pathname === '/' && /Bonjour Hasina/.test(document.body.innerText)", "accueil enseignant 2");
  await click("a[href='/notes/']");
  await until("/Systèmes d’exploitation/.test(document.body.innerText) && /Réseaux avancés/.test(document.body.innerText) && !document.querySelector('[aria-busy=true]')", "liste des feuilles", 25000);
  await sleep(500);
  const list = await text();
  check("remise des notes : mes deux feuilles, avec leur état (Brouillon, Renvoyée pour correction) et le motif de renvoi", /DEMO 301/.test(list) && /Brouillon/.test(list) && /DEMO 302/.test(list) && /Renvoyée pour correction/.test(list) && /Le projet de DEMO-0102 semble trop élevé/.test(list), list.slice(0, 140).replace(/\n/g, " "));
  check("le cours d'un autre enseignant n'apparaît pas", !/DEMO 303|autre enseignant/.test(list));
  await shot("07i-notes-liste");

  // --- feuille DEMO 301 : cinq colonnes par défaut, une ligne incomplète
  const href301 = await ev("[...document.querySelectorAll('a[href*=\"/notes/feuille/\"]')].find(a => /DEMO 301/.test(a.innerText)).getAttribute('href')");
  const id301 = Number(new URL(href301, WEB).searchParams.get("cours"));
  const foreign = await pageApi(`/api/v1/teacher/grading/courses/${id301 + 2}/sheet?year=${encodeURIComponent("2026 - 2027")}&semester=1`);
  check("la feuille d'un autre enseignant : 404, comme un cours inexistant", foreign.s === 404, String(foreign.s));
  await click(`a[href='${href301}']`);
  await waitSheet("feuille 301");
  await sleep(500);
  const sheet = await text();
  check("feuille : cinq colonnes par défaut (Quiz, Recherche, Autres, Examen mi-sem., Examen final), total 100 %, éditeur replié tant que le total est bon", (await ev("[...document.querySelectorAll('input[id^=col-]')].map(i => i.value).join('|')")) === "Quiz|Recherche|Autres|Examen mi-sem.|Examen final" && /Total 100 %/.test(sheet) && (await ev("document.querySelector('details.group summary')?.parentElement.open === false")));
  check("note calculée affichée avec la virgule (DEMO-0101 : 12,6 / 20) et statut spécial V pour DEMO-0105", /12,6 \/ 20/.test(await card("DEMO-0101")) && /Validé \(V\)/.test(await card("DEMO-0105")));
  check("DEMO-0104 est incomplète : la carte dit ce qui manque, le contrôle bloque la transmission", /Manque : Autres, Examen mi-sem\., Examen final/.test(await card("DEMO-0104")) && /1 étudiant\(s\) avec une composante pondérée non saisie : Ranaivo Fitia/.test(sheet) && (await ev("[...document.querySelectorAll('main button')].find(b => /Transmettre au registrariat/.test(b.innerText)).disabled")));
  check("contrôle : « 4 / 6 notes prêtes » et avertissement pour l'étudiant sans aucune note", /4 \/ 6\s*notes prêtes/.test(sheet) && /1 étudiant\(s\) sans aucune note/.test(sheet));
  check("étudiant au-delà de 15 % d'absences signalé sur la feuille (DEMO-0101)", /Absences > 15 %/.test(await card("DEMO-0101")));
  await shot("07j-feuille-notes");

  // --- saisie : une note invalide est refusée, une note valide complète la ligne
  await typeCell("#DEMO-0104-3", "25");
  check("une note hors de 0 à 20 est refusée avec un message, sans rien enregistrer", await until("/Note invalide/.test(document.querySelector('[data-sonner-toaster]')?.innerText ?? '')", "refus 25"));
  const s0104 = await pageApi(`/api/v1/teacher/grading/courses/${id301}/sheet?year=${encodeURIComponent("2026 - 2027")}&semester=1`);
  check("côté serveur : rien n'a été écrit pour cette saisie refusée", (s0104.d.lines.find((l) => l.student_id === "DEMO-0104").values["3"] ?? null) === null);
  await typeCell("#DEMO-0104-3", "12,5");
  await typeCell("#DEMO-0104-4", "9");
  await typeCell("#DEMO-0104-5", "11");
  check("la note de DEMO-0104 se calcule dès la dernière saisie : (12×10 + 10×20 + 12,5×10 + 9×25 + 11×35) / 100 = 10,55", await until("/10,55 \\/ 20/.test(document.querySelector('[data-student=\"DEMO-0104\"]')?.innerText ?? '')", "note 0104"), (await card("DEMO-0104")).replace(/\n/g, " ").slice(0, 120));
  check("plus aucun point bloquant : la transmission est possible", await until("!(document.querySelector('[role=alert]')?.innerText ?? '').includes('composante pondérée') && ![...document.querySelectorAll('main button')].find(b => /Transmettre au registrariat/.test(b.innerText)).disabled", "plus de blocage"));
  const saved = await serverState(async () => (await pageApi(`/api/v1/teacher/grading/courses/${id301}/sheet?year=${encodeURIComponent("2026 - 2027")}&semester=1`)).d.lines.find((l) => l.student_id === "DEMO-0104"), (l) => l.values["5"] === 11);
  check("côté serveur : les trois notes de DEMO-0104 sont enregistrées (virgule lue : 12,5)", saved.values["3"] === 12.5 && saved.values["4"] === 9 && saved.values["5"] === 11, JSON.stringify(saved.values));

  // --- colonnes libres : renommer, supprimer, ajouter, répartir 100 %
  await setValue("#col-s1", "Contrôle continu");
  await setValue("#pct-s2", "0");
  check("le total en cours est annoncé : il reste 20 % à répartir (sans rien enregistrer encore)", await until("/il reste 20 % à répartir/.test(document.body.innerText)", "reste 20"));
  check("« Enregistrer les colonnes » reste utilisable : on peut enregistrer un brouillon même si le total n'est pas 100 %", await ev("![...document.querySelectorAll('main button')].find(b => /Enregistrer les colonnes/.test(b.innerText)).disabled"));
  await setValue("#pct-s5", "55");
  check("total à 100 % : l'indicateur passe au vert, sans message d'erreur", await until("/Total 100 %/.test(document.body.innerText) && !/à répartir|de trop/.test(document.body.innerText)", "total 100"));
  await clickBtn(/Supprimer la colonne Recherche/);
  await clickBtn(/Ajouter une colonne/);
  await until("!!document.querySelector('input[id^=col-n]')", "nouvelle colonne");
  await setValue("input[id^=col-n]", "Oral");
  await setValue("input[id^=pct-n]", "0");
  await clickBtn(/Enregistrer les colonnes/);
  check("colonnes enregistrées : « Contrôle continu », « Autres », « Examen mi-sem. », « Examen final », « Oral » (la colonne Recherche et ses notes ont disparu)", await until("/Colonnes enregistrées/.test(document.querySelector('[data-sonner-toaster]')?.innerText ?? '')", "colonnes enregistrées"));
  const cols = await serverState(async () => (await pageApi(`/api/v1/teacher/grading/courses/${id301}/sheet?year=${encodeURIComponent("2026 - 2027")}&semester=1`)).d, (d) => d.components.some((c) => c.label === "Oral"));
  check("côté serveur : 5 colonnes choisies par l'enseignant, total 100, l'ancienne colonne « Recherche » est partie", JSON.stringify(cols.components.map((c) => [c.label, c.weight])) === JSON.stringify([["Contrôle continu", 10], ["Autres", 10], ["Examen mi-sem.", 25], ["Examen final", 55], ["Oral", 0]]) && cols.total_weight === 100, JSON.stringify(cols.components.map((c) => c.label)));
  check("les cartes des étudiants montrent les nouveaux titres, et la note suit la nouvelle pondération", await until("/Contrôle continu/.test(document.querySelector('[data-student=\"DEMO-0101\"]')?.innerText ?? '') && !/Recherche/.test(document.querySelector('[data-student=\"DEMO-0101\"]')?.innerText ?? '')", "nouveaux titres"));
  const oralSlot = cols.components.find((c) => c.label === "Oral").slot;
  check("une colonne à 0 % est grisée (ignorée dans le calcul)", await ev(`document.querySelector('#DEMO-0101-${oralSlot}').disabled`));
  await shot("07k-colonnes-libres");

  // titre en double refusé côté écran
  await setValue("#col-s3", "Autres");
  await setValue("#col-s4", "autres");
  check("deux colonnes au même titre : message clair, enregistrement impossible", await until("/Deux colonnes ont le même titre/.test(document.body.innerText) && [...document.querySelectorAll('main button')].find(b => /Enregistrer les colonnes/.test(b.innerText)).disabled", "titre en double"));
  await clickBtn(/Annuler les changements/);

  // --- HORS LIGNE : saisie sans réseau, puis envoi
  await setOffline(true);
  check("hors ligne : modifier les colonnes est refusé avec une explication (la saisie des notes reste possible)", await until("/modifier les colonnes demande le réseau/.test(document.body.innerText)", "colonnes hors ligne"));
  await typeCell("#DEMO-0106-1", "13");
  check("hors ligne : la note s'affiche tout de suite avec « à envoyer », et le compteur de changements en attente monte", await until("/à envoyer/.test(document.querySelector('[data-student=\"DEMO-0106\"]')?.innerText ?? '') && /1 changement en attente/.test(" + PENDING + ")", "saisie hors ligne"), await ev(PENDING));
  await setOffline(false);
  check("retour du réseau : la saisie part, le compteur disparaît", await until("!/changement/.test(" + PENDING + ")", "envoi", 30000));
  const off = await serverState(async () => (await pageApi(`/api/v1/teacher/grading/courses/${id301}/sheet?year=${encodeURIComponent("2026 - 2027")}&semester=1`)).d.lines.find((l) => l.student_id === "DEMO-0106"), (l) => Object.values(l.values).includes(13));
  check("côté serveur : la note saisie hors ligne est bien enregistrée", Object.values(off.values).includes(13), JSON.stringify(off.values));

  // --- DEMO-0106 n'a plus qu'une colonne remplie : la transmission reste bloquée
  check("avec une ligne incomplète (DEMO-0106), le bouton « Transmettre au registrariat » reste désactivé et le contrôle dit pourquoi", (await ev("[...document.querySelectorAll('main button')].find(b => /Transmettre au registrariat/.test(b.innerText)).disabled")) && /composante pondérée non saisie : Soloniaina Tahina/.test(await text()), "");

  // --- feuille renvoyée (colonnes libres déjà créées) : on corrige et on transmet
  await go("/notes/");
  await until("/Réseaux avancés/.test(document.body.innerText) && !document.querySelector('[aria-busy=true]')", "liste 2");
  const href302 = await ev("[...document.querySelectorAll('a[href*=\"/notes/feuille/\"]')].find(a => /DEMO 302/.test(a.innerText)).getAttribute('href')");
  await click(`a[href='${href302}']`);
  await waitSheet("feuille 302");
  await sleep(500);
  const s302 = await text();
  check("feuille renvoyée : bandeau avec le motif du registrariat, trois colonnes libres (Devoir 1, Projet, Examen), total 100 %", /Le registrariat vous a renvoyé cette feuille/.test(s302) && /Le projet de DEMO-0102 semble trop élevé/.test(s302) && (await ev("[...document.querySelectorAll('input[id^=col-]')].map(i => i.value).join('|')")) === "Devoir 1|Projet|Examen" && /Total 100 %/.test(s302));
  check("la note suit les poids choisis (DEMO-0102 : 11×0,3 + 20×0,3 + 15×0,4 = 15,3)", /15,3 \/ 20/.test(await card("DEMO-0102")));
  const id302 = Number(new URL(href302, WEB).searchParams.get("cours"));
  const slotProjet = (await pageApi(`/api/v1/teacher/grading/courses/${id302}/sheet?year=${encodeURIComponent("2026 - 2027")}&semester=1`)).d.components.find((c) => c.label === "Projet").slot;
  await typeCell(`#DEMO-0102-${slotProjet}`, "14");
  check("après correction : 11×0,3 + 14×0,3 + 15×0,4 = 13,5", await until("/13,5 \\/ 20/.test(document.querySelector('[data-student=\"DEMO-0102\"]')?.innerText ?? '')", "note corrigée"));
  await ev("[...document.querySelectorAll('main button')].find(b => /Transmettre au registrariat/.test(b.innerText)).click()");
  await until("/Vous ne pourrez plus la modifier/.test(document.body.innerText)", "confirmation 302");
  await ev("[...document.querySelectorAll('main button')].find(b => /Oui, transmettre/.test(b.innerText)).click()");
  check("transmise : le statut passe à « En attente de validation » et le bandeau dit que la feuille est en lecture seule", await until("/Feuille transmise/.test(document.body.innerText) && /lecture seule/.test(document.body.innerText) && /En attente de validation/.test(document.body.innerText)", "transmission", 25000));
  check("transmise : toutes les cases sont verrouillées, plus de bouton de transmission", await ev("[...document.querySelectorAll('main input[id^=DEMO]')].every(i => i.disabled) && ![...document.querySelectorAll('main button')].some(b => /Transmettre au registrariat/.test(b.innerText))"));
  const sub = (await pageApi(`/api/v1/teacher/grading/courses/${id302}/sheet?year=${encodeURIComponent("2026 - 2027")}&semester=1`)).d;
  check("côté serveur : statut « soumise », motif de renvoi effacé ; aucune note n'a touché au bulletin des étudiants", sub.remise.status === "soumise" && sub.remise.reject_reason === null && sub.remise.editable === false);
  const write = await ev(`fetch('${API}/api/v1/teacher/grading/courses/${id302}/cells/DEMO-0102', { method: 'PUT', credentials: 'include', headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': decodeURIComponent(document.cookie.split('; ').find(c => c.startsWith('XSRF-TOKEN=')).slice(11)) }, body: JSON.stringify({ year: '2026 - 2027', semester: 1, field: 'c1', value: 1, force: true }) }).then(r => r.status)`);
  check("même en forçant, le serveur refuse d'écrire dans une feuille transmise (409)", write === 409, String(write));
  await shot("07l-feuille-transmise");

  // --- mise en page
  const lay = JSON.parse(await ev(`JSON.stringify({ scroll: document.documentElement.scrollWidth, inner: innerWidth, h1: document.querySelectorAll('h1').length, sansNom: [...document.querySelectorAll('a,button,summary')].filter(e => !(e.getAttribute('aria-label') || e.innerText.trim())).length, sansEtiquette: [...document.querySelectorAll('main input, main select')].filter(e => !e.labels?.length && !e.getAttribute('aria-label')).length })`));
  check("feuille de notes : aucun défilement horizontal, un seul h1, contrôles nommés, chaque champ étiqueté", lay.scroll <= lay.inner && lay.h1 === 1 && lay.sansNom === 0 && lay.sansEtiquette === 0, JSON.stringify(lay));
  await ev("document.querySelector('button[aria-label^=\"Se déconnecter\"]').click()");
  check("déconnexion", await until("location.pathname.startsWith('/login')", "déconnexion notes"));
}

console.log(String.fromCharCode(10) + "7d. Registrariat : validation des remises de notes (annulable 10 s)");
{
  const API = "http://localhost:8000";
  const apiAs = async (pseudo, password) => {
    const jar = {};
    const take = (res) => { for (const c of res.headers.getSetCookie?.() ?? []) { const [kv] = c.split(";"); const i = kv.indexOf("="); jar[kv.slice(0, i)] = kv.slice(i + 1); } };
    const base = { Origin: WEB, Referer: WEB + "/", Accept: "application/json" };
    take(await fetch(API + "/api/v1/auth/csrf", { headers: base }));
    const call = async (method, p, body) => {
      const res = await fetch(API + p, { method, headers: { ...base, "Content-Type": "application/json", Cookie: Object.entries(jar).map(([k, v]) => `${k}=${v}`).join("; "), "X-XSRF-TOKEN": decodeURIComponent(jar["XSRF-TOKEN"] ?? "") }, body: body ? JSON.stringify(body) : undefined });
      take(res);
      return { status: res.status, json: await res.json().catch(() => null) };
    };
    await call("POST", "/api/v1/auth/login", { identifier: pseudo, password });
    return call;
  };
  const pageApi = (p) => ev(`fetch('${API}${p}', { credentials: 'include', headers: { Accept: 'application/json' } }).then(async r => JSON.stringify({ s: r.status, d: (await r.json()).data }))`).then(JSON.parse);
  const serverState = async (read, ok) => { let last; for (let i = 0; i < 60; i++) { last = await read(); if (ok(last)) return last; await sleep(250); } return last; };
  const clickBtn = (re) => ev(`(() => { const b = [...document.querySelectorAll('main button')].find(x => ${re}.test(x.innerText)); if (!b) return false; b.click(); return true; })()`);
  const waitReview = (label) => until("location.pathname === '/validations/feuille/' && !document.querySelector('[aria-busy=true]') && document.querySelectorAll('[data-student]').length > 0", label, 25000);

  // --- un enseignant termine la feuille DEMO 301 et la transmet (pour avoir de quoi renvoyer plus tard)
  const teacher = await apiAs("enseignant.demo", PASSWORD);
  const tc = await teacher("GET", "/api/v1/teacher/grading/courses?year=" + encodeURIComponent("2026 - 2027"));
  const c301 = tc.json.data.courses.find((c) => c.code === "DEMO 301");
  const sh301 = (await teacher("GET", `/api/v1/teacher/grading/courses/${c301.id}/sheet?year=${encodeURIComponent("2026 - 2027")}&semester=1`)).json.data;
  const slots = sh301.components.filter((c) => c.weight > 0).map((c) => c.slot);
  for (const l of sh301.lines) {
    for (const s of slots) {
      if (!l.statut_special && l.values[String(s)] == null) await teacher("PUT", `/api/v1/teacher/grading/courses/${c301.id}/cells/${l.student_id}`, { year: "2026 - 2027", semester: 1, field: `c${s}`, value: 12 });
    }
  }
  const sub301 = await teacher("POST", `/api/v1/teacher/grading/courses/${c301.id}/submit`, { year: "2026 - 2027", semester: 1 });
  check("(préparation) la feuille DEMO 301 est transmise par son enseignant", sub301.status === 200, String(sub301.status));

  await go("/login/");
  await type("#identifier", "registraire.demo");
  await type("#password", PASSWORD);
  await submit();
  check("le registrariat arrive sur son accueil avec « Validation des notes »", await until("location.pathname === '/' && /Validation des notes/.test(document.body.innerText)", "accueil registrariat"), await path());
  const nav = await ev("[...document.querySelectorAll('nav.fixed a')].map(a => a.textContent.trim())");
  check("menu du registrariat : Accueil, Validations, Assiduité, Étudiants et Plus (rien qui ne soit pas le sien : ni Présence ni Notes)", JSON.stringify(nav) === JSON.stringify(["Accueil", "Validations", "Assiduité", "Étudiants", "Plus"]), JSON.stringify(nav));
  await shot("07m-registrariat-accueil");

  await click("a[href='/validations/']");
  await until("/DEMO 30[12]/.test(document.body.innerText) && !document.querySelector('[aria-busy=true]')", "file de validation", 25000);
  await sleep(500);
  const queue = await text();
  check("file « À valider » : les deux feuilles transmises, avec leur enseignant et les notes prêtes", /DEMO 301/.test(queue) && /DEMO 302/.test(queue) && /Hasina Rasolofo/.test(queue) && /6 \/ 6\s*notes prêtes/.test(queue) && /2 \/ 2\s*notes prêtes/.test(queue), queue.slice(0, 160).replace(/\n/g, " "));
  check("les compteurs des onglets sont affichés", /À valider\s*2/.test(queue));
  await shot("07n-file-validation");
  const href302 = await ev("[...document.querySelectorAll('a[href*=\"/validations/feuille/\"]')].find(a => /DEMO 302/.test(a.innerText)).getAttribute('href')");
  const href301r = await ev("[...document.querySelectorAll('a[href*=\"/validations/feuille/\"]')].find(a => /DEMO 301/.test(a.innerText)).getAttribute('href')");
  const id302 = Number(new URL(href302, WEB).searchParams.get("id"));

  // --- contrôle de DEMO 302 : ce qui serait écrit, contre ce qui est en base
  await click(`a[href='${href302}']`);
  await waitReview("feuille 302 (registrariat)");
  await sleep(400);
  const page = await text();
  check("le contrôle dit que rien ne bloque et combien de notes changeront dans le bulletin", /Rien ne bloque la validation/.test(page) && /2\s*note\(s\) changeront/.test(page), "");
  check("chaque ligne montre la note en base et celle qui serait écrite (— → 13 ; — → 13,5)", /En base :\s*—\s*13(?!,)/.test((await ev("document.querySelector('[data-student=\"DEMO-0101\"]').innerText.replace(/\\n/g,' ')"))) && /En base :\s*—\s*13,5/.test(await ev("document.querySelector('[data-student=\"DEMO-0102\"]').innerText.replace(/\\n/g,' ')")), (await ev("document.querySelector('[data-student=\"DEMO-0102\"]').innerText.replace(/\\n/g,' ')")).slice(0, 160));
  check("les colonnes libres de l'enseignant (Devoir 1, Projet, Examen) sont montrées avec leur poids", /Devoir 1 \(30 %\)/.test(page) && /Projet \(30 %\)/.test(page) && /Examen \(40 %\)/.test(page));
  await shot("07o-controle-feuille");
  const before = await pageApi(`/api/v1/review/remises/${id302}`);
  check("avant toute validation, les notes des étudiants n'ont pas de valeur en base", before.d.lines.every((l) => l.current_grade === null));

  // --- valider, puis ANNULER dans le délai : rien n'est écrit
  await clickBtn(/Valider la feuille/);
  check("« Valider » ouvre un compte à rebours de 10 secondes, avec le bouton d'annulation", await until("/Validation dans \\d+ s/.test(document.body.innerText) && !![...document.querySelectorAll('main button')].find(b => /Annuler la validation/.test(b.innerText))", "compte à rebours"));
  check("pendant le délai, le serveur a une demande en attente mais AUCUNE note n'est écrite", (await pageApi(`/api/v1/review/remises/${id302}`)).d.pending !== null && (await pageApi(`/api/v1/review/remises/${id302}`)).d.lines.every((l) => l.current_grade === null));
  await shot("07p-compte-a-rebours");
  await sleep(1500);
  await clickBtn(/Annuler la validation/);
  check("« Annuler » dit qu'aucune note n'a été écrite, et la feuille redevient « à valider »", await until("/Validation annulée/.test(document.querySelector('[data-sonner-toaster]')?.innerText ?? '') && !!([...document.querySelectorAll('main button')].find(b => /Valider la feuille/.test(b.innerText)))", "annulation"));
  await sleep(800);
  const afterUndo = (await pageApi(`/api/v1/review/remises/${id302}`)).d;
  check("côté serveur : plus de demande, statut toujours « soumise », aucune note écrite", afterUndo.pending === null && afterUndo.status === "soumise" && afterUndo.lines.every((l) => l.current_grade === null));
  await sleep(12000);
  check("même après plus de 10 s, rien ne s'est écrit (la demande annulée ne revient pas)", (await pageApi(`/api/v1/review/remises/${id302}`)).d.status === "soumise");

  // --- valider pour de bon
  await clickBtn(/Valider la feuille/);
  await until("/Validation dans \\d+ s/.test(document.body.innerText)", "second compte à rebours");
  check("la validation aboutit toute seule à la fin du délai : « Feuille validée », statut « Validée »", await until("/Feuille validée/.test(document.querySelector('[data-sonner-toaster]')?.innerText ?? '') || /Validée/.test(document.querySelector('main')?.innerText ?? '')", "validation", 40000));
  await sleep(1000);
  const done = await serverState(async () => (await pageApi(`/api/v1/review/remises/${id302}`)).d, (d) => d.status === "validee");
  const byId = Object.fromEntries(done.lines.map((l) => [l.student_id, l.current_grade]));
  check("côté serveur : feuille validée, notes ÉCRITES dans le bulletin (DEMO-0101 : 13, DEMO-0102 : 13,5), plus de demande en attente", done.status === "validee" && Number(byId["DEMO-0101"]) === 13 && Number(byId["DEMO-0102"]) === 13.5 && done.pending === null, JSON.stringify(byId));
  check("la page montre la feuille validée, sans bouton « Valider »", await until("/Feuille validée/.test(document.body.innerText) && ![...document.querySelectorAll('main button')].some(b => /Valider la feuille|Renvoyer/.test(b.innerText))", "page validée"));
  await shot("07q-feuille-validee");

  // --- renvoyer DEMO 301 avec un motif
  await go(href301r);
  await waitReview("feuille 301 (registrariat)");
  await clickBtn(/Renvoyer à l’enseignant/);
  await until("!!document.querySelector('#reason')", "formulaire de renvoi");
  check("le renvoi exige un motif : le bouton reste inactif tant qu'il est vide", await ev("[...document.querySelectorAll('main button')].find(b => /Renvoyer la feuille/.test(b.innerText)).disabled"));
  await ev("(() => { const e = document.querySelector('#reason'); const set = Object.getOwnPropertyDescriptor(HTMLTextAreaElement.prototype, 'value').set; set.call(e, 'Le quiz de DEMO-0103 est à revérifier.'); e.dispatchEvent(new Event('input', { bubbles: true })); })()");
  await clickBtn(/Renvoyer la feuille/);
  check("la feuille est renvoyée : « Renvoyée à l'enseignant » avec le motif", await until("/Renvoyée à l’enseignant/.test(document.body.innerText) && /Le quiz de DEMO-0103 est à revérifier/.test(document.body.innerText)", "renvoi"));
  const back = await teacher("GET", `/api/v1/teacher/grading/courses/${c301.id}/sheet?year=${encodeURIComponent("2026 - 2027")}&semester=1`);
  check("l'enseignant retrouve sa feuille modifiable, avec le motif du registrariat", back.json.data.remise.status === "rejetee" && back.json.data.remise.editable === true && /à revérifier/.test(back.json.data.remise.reject_reason));
  await shot("07r-feuille-renvoyee");

  // --- les droits : un enseignant n'entre pas dans la validation, même par l'API
  const t403 = await teacher("GET", "/api/v1/review/remises");
  const t403b = await teacher("POST", `/api/v1/review/remises/${id302}/approve`);
  check("un enseignant ne peut ni lire la file ni valider, même en appelant l'API directement (403)", t403.status === 403 && t403b.status === 403, `${t403.status}/${t403b.status}`);

  const layV = JSON.parse(await ev(`JSON.stringify({ scroll: document.documentElement.scrollWidth, inner: innerWidth, h1: document.querySelectorAll('h1').length, sansNom: [...document.querySelectorAll('a,button,summary')].filter(e => !(e.getAttribute('aria-label') || e.innerText.trim())).length, sansEtiquette: [...document.querySelectorAll('main input, main select, main textarea')].filter(e => !e.labels?.length && !e.getAttribute('aria-label')).length })`));
  check("contrôle d'une feuille : aucun défilement horizontal, un seul h1, contrôles nommés", layV.scroll <= layV.inner && layV.h1 === 1 && layV.sansNom === 0 && layV.sansEtiquette === 0, JSON.stringify(layV));
  await ev("document.querySelector('button[aria-label^=\"Se déconnecter\"]').click()");
  check("déconnexion du registrariat", await until("location.pathname.startsWith('/login')", "déconnexion registrariat"));
}

console.log(String.fromCharCode(10) + "7e. Registrariat : assiduité (signalements au-delà de 15 %, retards des enseignants)");
{
  const API = "http://localhost:8000";
  const pageApi = (p, method = "GET") => ev(`fetch('${API}${p}', { method: '${method}', credentials: 'include', headers: { Accept: 'application/json', ${method === "GET" ? "" : "'X-XSRF-TOKEN': decodeURIComponent(document.cookie.split('; ').find(c => c.startsWith('XSRF-TOKEN='))?.split('=')[1] ?? '')"} } }).then(async r => JSON.stringify({ s: r.status, d: (await r.json()).data }))`).then(JSON.parse);
  const serverStateA = async (read, ok) => { let last; for (let i = 0; i < 60; i++) { last = await read(); if (ok(last)) return last; await sleep(250); } return last; };
  const waitPage = (label) => until("location.pathname === '/assiduite/' && !document.querySelector('[aria-busy=true]') && /Au-delà de 15 % d’absences/.test(document.body.innerText)", label, 25000);

  await go("/login/");
  await type("#identifier", "registraire.demo");
  await type("#password", PASSWORD);
  await submit();
  await until("location.pathname === '/' && /Assiduité/.test(document.body.innerText)", "accueil registrariat (assiduité)");
  check("l'accueil du registrariat propose « Assiduité »", await ev("!!document.querySelector('main a[href=\"/assiduite/\"]')"));
  await click("main a[href='/assiduite/']");
  await waitPage("page assiduité");
  await sleep(500);
  const before = (await pageApi("/api/v1/review/attendance")).d;
  const pending = before.flags.filter((f) => !f.processed_at);
  const text0 = await text();
  check("l'en-tête annonce le nombre de signalements à traiter, toutes années confondues (valeur du serveur)", new RegExp(`${before.pending_total} signalements? à traiter`).test(text0), text0.slice(0, 120).replace(/\n/g, " "));
  check("chaque signalement montre l'étudiant, son cours, le compte permis et son état du jour", pending.length >= 2 && pending.every((f) => text0.includes(f.student_id) && text0.includes(f.code)) && /sur \d+ permises/.test(text0));
  const inf = pending.find((f) => f.code === "INF 201");
  const infCard = await ev("[...document.querySelectorAll('main li')].find(li => /INF 201/.test(li.innerText) && /DEMO-0001/.test(li.innerText))?.innerText ?? ''");
  check("DEMO-0001 (signalé à 4 absences, une corrigée depuis) : « Revenu sous le seuil », avec le compte d'AUJOURD'HUI (3 sur 3 permises)", !!inf && inf.today.state === "limit" && /Revenu sous le seuil/.test(infCard) && /3 sur 3 permises/.test(infCard), infCard.replace(/\n/g, " ").slice(0, 200));
  check("un étudiant encore au-delà reste marqué « Toujours au-delà »", before.flags.some((f) => !f.processed_at && f.today.state === "exceeded") && /Toujours au-delà/.test(text0));
  check("le registre tenu sans séances prévues (MAT 110) est listé avec ses séances tenues", /Registres sans séances prévues/.test(text0) && /MAT 110/.test(text0) && /2\s*séances tenues/.test(text0));
  check("le retard de 25 minutes d'un enseignant (INF 201) est listé, avec « séance sans pénalité » quand aucun message n'a été envoyé", /25 min de retard/.test(text0) && /Aucun message : séance sans pénalité pour les étudiants/.test(text0));
  check("les retards sont comptés par enseignant", before.delays_by_teacher.length >= 1 && before.delays_by_teacher.every((t) => text0.includes(t.teacher)));
  check("les signalements déjà traités sont masqués par défaut", !/Traité le 20 septembre 2026/.test(text0));
  await shot("07s-assiduite");

  // --- traiter un signalement
  const target = inf;
  await ev(`(() => { const b = [...document.querySelectorAll('main button')].find(x => /Marquer comme traité/.test(x.getAttribute('aria-label') ?? '') && x.getAttribute('aria-label').includes('INF 201')); b.click(); })()`);
  check("« Traité » confirme par un message", await until("/Signalement marqué comme traité/.test(document.querySelector('[data-sonner-toaster]')?.innerText ?? '')", "toast traité"));
  const after = await serverStateA(async () => (await pageApi("/api/v1/review/attendance?processed=1")).d, (d) => d.flags.find((f) => f.id === target.id)?.processed_at);
  const row = after.flags.find((f) => f.id === target.id);
  check("côté serveur : le signalement est daté « traité » et le compteur baisse d'un", !!row?.processed_at && after.pending_total === before.pending_total - 1, JSON.stringify({ row: row?.processed_at, p: after.pending_total, b: before.pending_total }));
  check("à l'écran : la carte disparaît et l'en-tête suit", await until(`![...document.querySelectorAll('main li')].some(li => /INF 201/.test(li.innerText) && /DEMO-0001/.test(li.innerText)) && /${after.pending_total === 0 ? "Aucun signalement à traiter" : after.pending_total + " signalements? à traiter"}/.test(document.body.innerText)`, "carte retirée"));
  await shot("07t-assiduite-traite");

  // --- afficher les traités
  await click("main input[type=checkbox]");
  check("« Afficher les traités » montre aussi ceux déjà traités, datés", await until("/Traité le 20 septembre 2026/.test(document.body.innerText) && /Traité le \\d+ \\S+ 2026/.test(document.body.innerText)", "traités affichés"));
  await shot("07u-assiduite-traites");
  await click("main input[type=checkbox]");
  check("décocher les masque de nouveau", await until("!/Traité le 20 septembre 2026/.test(document.body.innerText)", "traités masqués"));

  // --- le même signalement traité une seconde fois : rien ne change
  const again = (await pageApi(`/api/v1/review/attendance/flags/${target.id}/process`, "POST")).d;
  check("traiter deux fois le même signalement ne change rien (« processed: false », première date conservée)", again.processed === false && again.processed_at === row.processed_at, JSON.stringify(again));

  const lay = JSON.parse(await ev(`JSON.stringify({ scroll: document.documentElement.scrollWidth, inner: innerWidth, h1: document.querySelectorAll('h1').length, sansNom: [...document.querySelectorAll('a,button,summary')].filter(e => !(e.getAttribute('aria-label') || e.innerText.trim())).length, sansEtiquette: [...document.querySelectorAll('main input, main select')].filter(e => !e.labels?.length && !e.getAttribute('aria-label')).length })`));
  check("assiduité : aucun défilement horizontal, un seul h1, contrôles nommés, chaque champ étiqueté", lay.scroll <= lay.inner && lay.h1 === 1 && lay.sansNom === 0 && lay.sansEtiquette === 0, JSON.stringify(lay));
  await ev("document.querySelector('button[aria-label^=\"Se déconnecter\"]').click()");
  check("déconnexion du registrariat", await until("location.pathname.startsWith('/login')", "déconnexion registrariat (assiduité)"));

  // --- les droits : un enseignant n'ouvre ni la page ni l'API
  await type("#identifier", "enseignant.demo");
  await type("#password", PASSWORD);
  await submit();
  await until("location.pathname === '/' && /Bonjour/.test(document.body.innerText) && /Présence/.test(document.body.innerText)", "accueil enseignant (assiduité)");
  check("l'enseignant n'a pas « Assiduité » dans son menu", !(await ev("[...document.querySelectorAll('nav a')].some(a => /Assiduité/.test(a.textContent))")));
  const t403 = await pageApi("/api/v1/review/attendance");
  const t403b = await pageApi(`/api/v1/review/attendance/flags/${target.id}/process`, "POST");
  check("un enseignant ne peut ni lire l'assiduité ni traiter un signalement, même en appelant l'API directement (403)", t403.s === 403 && t403b.s === 403, `${t403.s}/${t403b.s}`);
  await go("/assiduite/");
  check("la page « /assiduite/ » est expliquée à un enseignant : réservée au registrariat, aucune donnée", await until("/réservée au registrariat/.test(document.body.innerText)", "page réservée") && !/Au-delà de 15 % d’absences/.test(await text()));
  await ev("document.querySelector('button[aria-label^=\"Se déconnecter\"]').click()");
  check("déconnexion de l'enseignant", await until("location.pathname.startsWith('/login')", "déconnexion enseignant (assiduité)"));
}

console.log(String.fromCharCode(10) + "7f. Registrariat : validation groupée (cocher, valider, annuler, valider pour de bon)");
{
  const API = "http://localhost:8000";
  const YEAR = "2026 - 2027";
  const apiAs = async (pseudo, password) => {
    const jar = {};
    const take = (res) => { for (const c of res.headers.getSetCookie?.() ?? []) { const [kv] = c.split(";"); const i = kv.indexOf("="); jar[kv.slice(0, i)] = kv.slice(i + 1); } };
    const base = { Origin: WEB, Referer: WEB + "/", Accept: "application/json" };
    take(await fetch(API + "/api/v1/auth/csrf", { headers: base }));
    const call = async (method, p, body) => {
      const res = await fetch(API + p, { method, headers: { ...base, "Content-Type": "application/json", Cookie: Object.entries(jar).map(([k, v]) => `${k}=${v}`).join("; "), "X-XSRF-TOKEN": decodeURIComponent(jar["XSRF-TOKEN"] ?? "") }, body: body ? JSON.stringify(body) : undefined });
      take(res);
      return { status: res.status, json: await res.json().catch(() => null) };
    };
    await call("POST", "/api/v1/auth/login", { identifier: pseudo, password });
    return call;
  };
  const pageApi = (p) => ev(`fetch('${API}${p}', { credentials: 'include', headers: { Accept: 'application/json' } }).then(async r => JSON.stringify({ s: r.status, d: (await r.json()).data }))`).then(JSON.parse);
  const serverStateB = async (read, ok) => { let last; for (let i = 0; i < 80; i++) { last = await read(); if (ok(last)) return last; await sleep(250); } return last; };
  const clickBtn = (re) => ev(`(() => { const b = [...document.querySelectorAll('main button')].find(x => ${re}.test(x.innerText)); if (!b || b.disabled) return false; b.click(); return true; })()`);

  // --- préparation : deux feuilles complètes transmises (DEMO 301 renvoyée puis retransmise, DEMO 304 de rindra)
  const fill = async (who, code) => {
    const list = (await who("GET", "/api/v1/teacher/grading/courses?year=" + encodeURIComponent(YEAR))).json.data.courses.find((c) => c.code === code);
    const q = `year=${encodeURIComponent(YEAR)}&semester=${list.semester ?? 1}`;
    const sheet = (await who("GET", `/api/v1/teacher/grading/courses/${list.id}/sheet?${q}`)).json.data;
    for (const l of sheet.lines) {
      for (const c of sheet.components.filter((x) => x.weight > 0)) {
        if (!l.statut_special && l.values[String(c.slot)] == null) await who("PUT", `/api/v1/teacher/grading/courses/${list.id}/cells/${l.student_id}`, { year: YEAR, semester: list.semester ?? 1, field: `c${c.slot}`, value: 14 });
      }
    }
    return (await who("POST", `/api/v1/teacher/grading/courses/${list.id}/submit`, { year: YEAR, semester: list.semester ?? 1 })).status;
  };
  const t1 = await apiAs("enseignant.demo", PASSWORD);
  const t2 = await apiAs("rindra", "rindra2026");
  const s1 = await fill(t1, "DEMO 301");
  const s2 = await fill(t2, "DEMO 304");
  check("(préparation) deux feuilles complètes sont transmises (DEMO 301 et DEMO 304)", s1 === 200 && s2 === 200, `${s1}/${s2}`);

  await go("/login/");
  await type("#identifier", "registraire.demo");
  await type("#password", PASSWORD);
  await submit();
  await until("location.pathname === '/' && /Validation des notes/.test(document.body.innerText)", "accueil registrariat (groupée)");
  await click("a[href='/validations/']");
  await until("/DEMO 301/.test(document.body.innerText) && /DEMO 304/.test(document.body.innerText) && !document.querySelector('[aria-busy=true]')", "file de validation (groupée)", 25000);
  await sleep(500);
  const boxes = await ev("document.querySelectorAll('main li input[type=checkbox]').length");
  check("chaque feuille prête de l'onglet « À valider » a une case à cocher nommée", boxes >= 2 && (await ev("[...document.querySelectorAll('main li input[type=checkbox]')].every(i => /Sélectionner DEMO/.test(i.closest('label').innerText))")), String(boxes));
  check("rien n'est coché au départ : « Valider la sélection » est inactif", await ev("[...document.querySelectorAll('main button')].find(b => /Valider la sélection/.test(b.innerText))?.disabled === true"));
  await shot("07v-validation-groupee-vide");

  await clickBtn(/Tout sélectionner/);
  check("« Tout sélectionner » coche toutes les feuilles prêtes et l'annonce", await until(`document.querySelectorAll('main li input[type=checkbox]:checked').length === ${boxes} && /${boxes}\\s+sélectionnées/.test(document.body.innerText)`, "tout sélectionné"));
  await shot("07w-validation-groupee-selection");
  const ids = JSON.parse(await ev("JSON.stringify([...document.querySelectorAll('main li a[href*=\"/validations/feuille/\"]')].map(a => Number(new URL(a.href).searchParams.get('id'))))"));

  // --- valider puis ANNULER dans le délai
  await clickBtn(/Valider la sélection/);
  check("« Valider la sélection » ouvre un compte à rebours commun, avec « Annuler la validation »", await until("/Validation de\\s+\\d+\\s+feuilles\\s+dans\\s+\\d+\\s+s/.test(document.body.innerText) && !![...document.querySelectorAll('main button')].find(b => /Annuler la validation/.test(b.innerText))", "compte à rebours groupé"));
  const during = await Promise.all(ids.map((id) => pageApi(`/api/v1/review/remises/${id}`)));
  check("pendant le délai : une demande en attente par feuille, AUCUNE note écrite, cases verrouillées", during.every((r) => r.d.pending !== null && r.d.lines.every((l) => l.current_grade === null || l.current_grade === "")) && await ev("[...document.querySelectorAll('main li input[type=checkbox]')].every(i => i.disabled)"), JSON.stringify(during.map((r) => [r.s, r.d.status, r.d.pending, r.d.lines.map((l) => l.current_grade)])));
  await shot("07x-validation-groupee-compte-a-rebours");
  await sleep(1200);
  await clickBtn(/Annuler la validation/);
  check("« Annuler » dit qu'aucune note n'a été écrite", await until("/Validation annulée/.test(document.querySelector('[data-sonner-toaster]')?.innerText ?? '') && /aucune note n’a été écrite/.test(document.querySelector('[data-sonner-toaster]')?.innerText ?? '')", "annulation groupée"));
  const undone = await serverStateB(async () => Promise.all(ids.map((id) => pageApi(`/api/v1/review/remises/${id}`))), (rs) => rs.every((r) => r.d.pending === null));
  check("côté serveur : plus aucune demande, feuilles toujours « soumises », aucune note", undone.every((r) => r.d.pending === null && r.d.status === "soumise" && r.d.lines.every((l) => l.current_grade === null || l.current_grade === "")), JSON.stringify(undone.map((r) => [r.d.status, r.d.pending, r.d.lines.map((l) => l.current_grade)])));
  await sleep(11000);
  check("même après plus de 10 s, rien ne s'est validé tout seul", (await Promise.all(ids.map((id) => pageApi(`/api/v1/review/remises/${id}`)))).every((r) => r.d.status === "soumise"));
  check("la sélection est vidée et les cases sont de nouveau actives", await until("document.querySelectorAll('main li input[type=checkbox]:checked').length === 0 && [...document.querySelectorAll('main li input[type=checkbox]')].every(i => !i.disabled)", "cases réactivées"));

  // --- valider pour de bon
  await clickBtn(/Tout sélectionner/);
  await until(`document.querySelectorAll('main li input[type=checkbox]:checked').length === ${boxes}`, "re-sélection");
  await clickBtn(/Valider la sélection/);
  await until("/Validation de\\s+\\d+\\s+feuilles\\s+dans\\s+\\d+\\s+s/.test(document.body.innerText)", "second compte à rebours groupé");
  check("à la fin du délai, l'écran termine : « 2 feuilles validées »", await until(`/${boxes}\\s+feuilles\\s+validées/.test(document.querySelector('[data-sonner-toaster]')?.innerText ?? '')`, "validation groupée", 45000));
  const done = await serverStateB(async () => Promise.all(ids.map((id) => pageApi(`/api/v1/review/remises/${id}`))), (rs) => rs.every((r) => r.d.status === "validee"));
  check("côté serveur : toutes les feuilles sont validées, les notes ÉCRITES dans le bulletin, plus de demande en attente", done.every((r) => r.d.status === "validee" && r.d.pending === null && r.d.lines.some((l) => l.current_grade !== null && l.current_grade !== "")), JSON.stringify(done.map((r) => r.d.status)));
  const rindra = done.find((r) => r.d.course.code === "DEMO 304");
  check("DEMO 304 : les 3 étudiants ont leur note (14) en base", !!rindra && rindra.d.lines.length === 3 && rindra.d.lines.every((l) => Number(l.current_grade) === 14), rindra ? JSON.stringify(rindra.d.lines.map((l) => l.current_grade)) : "absente");
  check("la file n'a plus de feuille à valider et la barre disparaît", await until("!document.querySelector('main li input[type=checkbox]') && ![...document.querySelectorAll('main button')].some(b => /Valider la sélection/.test(b.innerText))", "file vidée"));
  await shot("07y-validation-groupee-terminee");

  // --- les droits
  const t403 = await t1("POST", "/api/v1/review/remises/bulk/approve", { ids });
  check("un enseignant ne peut pas valider en groupe, même en appelant l'API (403)", t403.status === 403, String(t403.status));

  const layG = JSON.parse(await ev(`JSON.stringify({ scroll: document.documentElement.scrollWidth, inner: innerWidth, h1: document.querySelectorAll('h1').length, sansNom: [...document.querySelectorAll('a,button,summary')].filter(e => !(e.getAttribute('aria-label') || e.innerText.trim())).length, sansEtiquette: [...document.querySelectorAll('main input, main select, main textarea')].filter(e => !e.labels?.length && !e.getAttribute('aria-label')).length })`));
  check("file de validation : aucun défilement horizontal, contrôles nommés, chaque case étiquetée", layG.scroll <= layG.inner && layG.sansNom === 0 && layG.sansEtiquette === 0, JSON.stringify(layG));
  await ev("document.querySelector('button[aria-label^=\"Se déconnecter\"]').click()");
  check("déconnexion du registrariat (groupée)", await until("location.pathname.startsWith('/login')", "déconnexion registrariat (groupée)"));
}

console.log(String.fromCharCode(10) + "7g. Registrariat : tableau de bord, menu « Plus », listes (étudiants, cours, enseignants) et fiches");
{
  const API = "http://localhost:8000";
  const apiAs = async (pseudo, password) => {
    const jar = {};
    const take = (res) => { for (const c of res.headers.getSetCookie?.() ?? []) { const [kv] = c.split(";"); const i = kv.indexOf("="); jar[kv.slice(0, i)] = kv.slice(i + 1); } };
    const base = { Origin: WEB, Referer: WEB + "/", Accept: "application/json" };
    take(await fetch(API + "/api/v1/auth/csrf", { headers: base }));
    const call = async (method, p, body) => {
      const res = await fetch(API + p, { method, headers: { ...base, "Content-Type": "application/json", Cookie: Object.entries(jar).map(([k, v]) => `${k}=${v}`).join("; "), "X-XSRF-TOKEN": decodeURIComponent(jar["XSRF-TOKEN"] ?? "") }, body: body ? JSON.stringify(body) : undefined });
      take(res);
      return { status: res.status, json: await res.json().catch(() => null) };
    };
    await call("POST", "/api/v1/auth/login", { identifier: pseudo, password });
    return call;
  };
  const pageApi = (p) => ev(`fetch('${API}${p}', { credentials: 'include', headers: { Accept: 'application/json' } }).then(async r => JSON.stringify({ s: r.status, d: (await r.json()).data }))`).then(JSON.parse);
  const listReady = (label) => until("!document.querySelector('[aria-busy=true]') && !!document.querySelector('main ul li a[href*=\"/fiche/\"]') || /ne correspond/.test(document.body.innerText)", label, 25000);
  const search = async (q) => {
    await ev(`(() => { const e = document.querySelector('input[type=search]'); const set = Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value').set; set.call(e, ${JSON.stringify(q)}); e.dispatchEvent(new Event('input', { bubbles: true })); })()`);
    await sleep(900);
  };
  const lay = async () => JSON.parse(await ev(`JSON.stringify({ scroll: document.documentElement.scrollWidth, inner: innerWidth, h1: document.querySelectorAll('h1').length, sansNom: [...document.querySelectorAll('a,button,summary')].filter(e => !(e.getAttribute('aria-label') || e.innerText.trim())).length, sansEtiquette: [...document.querySelectorAll('main input:not([type=checkbox]), main select')].filter(e => !e.labels?.length && !e.getAttribute('aria-label')).length })`));

  await go("/login/");
  await type("#identifier", "registraire.demo");
  await type("#password", PASSWORD);
  await submit();

  // --- tableau de bord
  await until("location.pathname === '/' && /À traiter/.test(document.body.innerText) && /Chiffres clés/.test(document.body.innerText) && !document.querySelector('[aria-busy=true]')", "tableau de bord", 25000);
  const board = (await pageApi("/api/v1/staff/dashboard")).d;
  const home = await text();
  check("le tableau de bord montre « À traiter » avec les éléments du serveur (signalements, candidatures…) et leur nombre", board.to_do.length >= 2 && board.to_do.every((i) => new RegExp(`${i.count}\\s+[^\\n]+`).test(home)) && /signalements? d’absences/.test(home) && /nouvelles? candidatures?/.test(home), home.slice(0, 200).replace(/\n/g, " "));
  check("« Chiffres clés » : l'année en cours, les inscrits et le sélecteur d'année", new RegExp(`Inscrits ${board.year.replace(" - ", "-")}`).test(home) && (await ev("!!document.querySelector('main select')")) && /Nouveaux étudiants/.test(home));
  await shot("07z-tableau-de-bord");
  const homeLay = await lay();
  check("accueil du registrariat : aucun défilement horizontal, un seul h1, contrôles nommés", homeLay.scroll <= homeLay.inner && homeLay.h1 === 1 && homeLay.sansNom === 0 && homeLay.sansEtiquette === 0, JSON.stringify(homeLay));

  // --- menu : 5 places au plus sur téléphone, le reste sous « Plus »
  const nav = await ev("[...document.querySelectorAll('nav.fixed a')].map(a => a.textContent.trim())");
  check("barre du bas : 5 entrées au plus (Accueil, Validations, Assiduité, Étudiants, Plus)", JSON.stringify(nav) === JSON.stringify(["Accueil", "Validations", "Assiduité", "Étudiants", "Plus"]), JSON.stringify(nav));
  await click("nav.fixed a[href='/plus/']");
  await until("location.pathname === '/plus/' && /Candidatures/.test(document.body.innerText)", "page Plus (personnel)");
  const plus = await text();
  check("« Plus » range les pages qui ne tiennent pas : Cours, Enseignants, Candidatures, chacune avec sa description", /Cours/.test(plus) && /Enseignants/.test(plus) && /Candidatures/.test(plus) && /Instruire les candidatures/.test(plus), plus.slice(0, 160).replace(/\n/g, " "));
  check("sur « Plus », l'onglet « Plus » reste allumé", await ev("document.querySelector('nav.fixed a[href=\"/plus/\"]').getAttribute('aria-current') === 'page'"));
  await shot("07za-plus-personnel");

  // --- étudiants : recherche, filtre, tri, fiche, retour
  await click("nav.fixed a[href='/etudiants/']");
  await listReady("liste des étudiants");
  const total0 = (await pageApi("/api/v1/staff/lists/students")).d.total;
  check("liste des étudiants : le nombre vient du serveur et chaque ligne mène à une fiche", new RegExp(`${total0.toLocaleString("fr-FR").replace(/ /g, "\\s")}\\s+étudiants`).test(await text()) && (await ev("document.querySelectorAll('main ul li a[href*=\"/etudiants/fiche/\"]').length")) >= 4);
  check("le bouton « Nouvel étudiant » est proposé au registrariat", await ev("[...document.querySelectorAll('main a')].some(a => /Nouvel étudiant/.test(a.innerText) && a.getAttribute('href') === '/etudiants/nouveau/')"));
  await search("Andrianina");
  check("rechercher « Andrianina » ne garde que les étudiants qui le portent (DEMO-0101)", await until("/DEMO-0101/.test(document.body.innerText) && !/DEMO-0102/.test(document.body.innerText) && /1 étudiant correspond/.test(document.body.innerText)", "recherche Rakoto"), (await text()).slice(0, 120).replace(/\n/g, " "));
  await shot("07zb-liste-etudiants-recherche");
  await search("");
  await until("/DEMO-0102/.test(document.body.innerText)", "recherche effacée");
  await ev("[...document.querySelectorAll('main button')].find(b => /^Filtres/.test(b.innerText.trim())).click()");
  await until("!!document.querySelector('#filtres')", "panneau de filtres");
  const gestion = await ev("(() => { const l = [...document.querySelectorAll('#filtres label')].find(x => /^Gestion/.test(x.innerText.trim())); if (!l) return false; l.querySelector('input').click(); return true; })()");
  check("filtrer par mention « Gestion » (le nombre de chaque option est affiché) ne garde que ses étudiants, avec une pastille retirable", gestion && await until("/DEMO-0105/.test(document.body.innerText) && /DEMO-0106/.test(document.body.innerText) && !/DEMO-0101/.test(document.body.innerText) && !!document.querySelector('[aria-label^=\"Retirer le filtre Mention\"]')", "filtre mention"));
  check("le parcours ne s'ouvre qu'une fois la mention choisie (ses parcours apparaissent)", await ev("[...document.querySelectorAll('#filtres fieldset')].some(f => /Parcours/.test(f.innerText) && /Gestion générale/.test(f.innerText))"));
  await shot("07zc-liste-etudiants-filtre");
  await ev("document.querySelector('[aria-label^=\"Retirer le filtre Mention\"]').click()");
  check("retirer la pastille rétablit la liste complète", await until("/DEMO-0101/.test(document.body.innerText) && /DEMO-0105/.test(document.body.innerText)", "filtre retiré"));
  await ev("(() => { const s = [...document.querySelectorAll('main select')].find(x => /Matricule/.test(x.innerText)); const set = Object.getOwnPropertyDescriptor(HTMLSelectElement.prototype, 'value').set; set.call(s, 'matricule'); s.dispatchEvent(new Event('change', { bubbles: true })); })()");
  check("trier par « Matricule » : le premier de la liste est le plus petit matricule", await until("(() => { const ids = [...document.querySelectorAll('main ul li a .font-mono')].map(e => e.innerText.trim()); return ids.length > 3 && ids[0] === [...ids].sort()[0]; })()", "tri par matricule"));
  await search("Andrianina");
  await until("/1 étudiant correspond/.test(document.body.innerText)", "recherche avant fiche");
  await click("main ul li a[href*='/etudiants/fiche/']");
  await until("location.pathname === '/etudiants/fiche/' && /Fiche en lecture seule/.test(document.body.innerText) && !document.querySelector('[aria-busy=true]')", "fiche étudiant", 25000);
  const fiche = await text();
  check("fiche étudiant : identité, études et inscriptions, en lecture seule", /DEMO-0101/.test(fiche) && /ANDRIANINA Lalaina/.test(fiche) && /Études/.test(fiche) && /Informatique/.test(fiche) && /Inscriptions aux cours/.test(fiche) && /Fiche en lecture seule/.test(fiche));
  check("la fiche ne montre ni mot de passe, ni CIN, ni visa, ni famille, ni garant", !/mot de passe|CIN|visa|Père|Mère|sponsor|garant/i.test(fiche));
  await shot("07zd-fiche-etudiant");
  const idFiche = Number(new URL(await ev("location.href")).searchParams.get("id"));
  await click("main a[href='/etudiants/']");
  check("revenir à la liste retrouve la recherche (« Andrianina ») gardée le temps de la session", await until("document.querySelector('input[type=search]')?.value === 'Andrianina' && /1 étudiant correspond/.test(document.body.innerText)", "recherche gardée"));
  const l1 = await lay();
  check("liste des étudiants : aucun défilement horizontal, un seul h1, contrôles nommés, champ étiqueté", l1.scroll <= l1.inner && l1.h1 === 1 && l1.sansNom === 0 && l1.sansEtiquette === 0, JSON.stringify(l1));

  // --- cours (via « Plus »), puis fiche du cours et de son enseignant
  await click("nav.fixed a[href='/plus/']");
  await until("location.pathname === '/plus/'", "plus");
  await click("main a[href='/cours/']");
  await listReady("liste des cours");
  await search("Rasolofo");
  check("rechercher le nom d'un ENSEIGNANT (« Rasolofo ») retrouve ses cours (DEMO 301 et DEMO 302)", await until("/DEMO 301/.test(document.body.innerText) && /DEMO 302/.test(document.body.innerText) && !/DEMO 303/.test(document.body.innerText)", "recherche enseignant"));
  const courseRow = await ev("[...document.querySelectorAll('main ul li')].find(li => /DEMO 301/.test(li.innerText)).innerText.replace(/\\n/g, ' ')");
  check("une ligne de cours montre sigle, intitulé, mention, niveau, crédits et enseignant", /Systèmes d’exploitation/.test(courseRow) && /Licence 2/.test(courseRow) && /3 crédits/.test(courseRow) && /HASINA Rasolofo|Hasina Rasolofo|RASOLOFO/i.test(courseRow), courseRow.slice(0, 160));
  await shot("07ze-liste-cours");
  await ev("[...document.querySelectorAll('main ul li a')].find(a => /DEMO 301/.test(a.innerText)).click()");
  await until("location.pathname === '/cours/fiche/' && /Enseignant/.test(document.body.innerText) && !document.querySelector('[aria-busy=true]')", "fiche cours", 25000);
  check("fiche cours : le cours, son enseignant (lien) et ses étudiants inscrits par année", /DEMO 301/.test(await text()) && /Étudiants inscrits/.test(await text()) && (await ev("!!document.querySelector('main a[href*=\"/enseignants/fiche/\"]')")));
  await click("main a[href*='/enseignants/fiche/']");
  await until("location.pathname === '/enseignants/fiche/' && /Cours \\(\\d+\\)/.test(document.body.innerText) && !document.querySelector('[aria-busy=true]')", "fiche enseignant", 25000);
  const prof = await text();
  check("fiche enseignant : coordonnées, fonction et la liste de ses cours (avec lien vers chaque fiche)", /Coordonnées/.test(prof) && /DEMO 301/.test(prof) && /DEMO 302/.test(prof) && (await ev("!!document.querySelector('main a[href*=\"/cours/fiche/\"]')")));
  check("la fiche enseignant ne montre ni groupe sanguin, ni religion, ni date de naissance", !/sanguin|Religion|Adventiste|naissance/i.test(prof));
  await shot("07zf-fiche-enseignant");

  // --- enseignants
  await go("/enseignants/");
  await listReady("liste des enseignants");
  await search("Hasina");
  check("liste des enseignants : la recherche retrouve « Hasina Rasolofo »", await until("/Hasina|HASINA/.test(document.body.innerText) && /1 enseignant correspond/.test(document.body.innerText)", "recherche enseignant"));
  const l2 = await lay();
  check("liste des enseignants : aucun défilement horizontal, un seul h1, contrôles nommés", l2.scroll <= l2.inner && l2.h1 === 1 && l2.sansNom === 0 && l2.sansEtiquette === 0, JSON.stringify(l2));

  await ev("document.querySelector('button[aria-label^=\"Se déconnecter\"]').click()");
  check("déconnexion du registrariat (listes)", await until("location.pathname.startsWith('/login')", "déconnexion listes"));

  // --- les droits : un enseignant n'entre dans aucune liste, même par l'API
  const t1 = await apiAs("enseignant.demo", PASSWORD);
  const rights = [await t1("GET", "/api/v1/staff/lists/students"), await t1("GET", "/api/v1/staff/lists/courses"), await t1("GET", "/api/v1/staff/lists/teachers"), await t1("GET", `/api/v1/staff/students/${idFiche}`), await t1("GET", "/api/v1/staff/dashboard"), await t1("GET", "/api/v1/staff/applications")];
  check("un enseignant ne peut lire ni les listes, ni une fiche, ni le tableau de bord, ni les candidatures, même en appelant l'API (403)", rights.every((r) => r.status === 403), rights.map((r) => r.status).join("/"));
  await type("#identifier", "enseignant.demo");
  await type("#password", PASSWORD);
  await submit();
  await until("location.pathname === '/' && /Présence/.test(document.body.innerText)", "accueil enseignant (listes)");
  await go("/etudiants/");
  check("la page « /etudiants/ » est expliquée à un enseignant : réservée au personnel, aucune donnée", await until("/réservée au personnel/.test(document.body.innerText)", "page étudiants réservée") && !/DEMO-0101/.test(await text()));
  await go("/cours/");
  check("la page « /cours/ » est expliquée à un enseignant : réservée au registrariat", await until("/réservée au registrariat/.test(document.body.innerText)", "page cours réservée"));
  await ev("document.querySelector('button[aria-label^=\"Se déconnecter\"]').click()");
  check("déconnexion de l'enseignant (listes)", await until("location.pathname.startsWith('/login')", "déconnexion enseignant (listes)"));
}

console.log(String.fromCharCode(10) + "7h. Registrariat : candidatures (instruction, messages au candidat, archives) et création d'un étudiant");
{
  const API = "http://localhost:8000";
  const apiAs = async (pseudo, password) => {
    const jar = {};
    const take = (res) => { for (const c of res.headers.getSetCookie?.() ?? []) { const [kv] = c.split(";"); const i = kv.indexOf("="); jar[kv.slice(0, i)] = kv.slice(i + 1); } };
    const base = { Origin: WEB, Referer: WEB + "/", Accept: "application/json" };
    take(await fetch(API + "/api/v1/auth/csrf", { headers: base }));
    const call = async (method, p, body) => {
      const res = await fetch(API + p, { method, headers: { ...base, "Content-Type": "application/json", Cookie: Object.entries(jar).map(([k, v]) => `${k}=${v}`).join("; "), "X-XSRF-TOKEN": decodeURIComponent(jar["XSRF-TOKEN"] ?? "") }, body: body ? JSON.stringify(body) : undefined });
      take(res);
      return { status: res.status, json: await res.json().catch(() => null) };
    };
    await call("POST", "/api/v1/auth/login", { identifier: pseudo, password });
    return call;
  };
  const staff = await apiAs("registraire.demo", PASSWORD);
  const serverApps = async (status = "") => (await staff("GET", "/api/v1/staff/applications" + (status ? `?status=${status}` : ""))).json.data;
  const byName = (data, name) => (data.rows ?? data).find((r) => r.name === name);
  const serverState = async (read, ok) => { let last; for (let i = 0; i < 60; i++) { last = await read(); if (ok(last)) return last; await sleep(250); } return last; };
  const runCommand = (name) => execFileSync(process.env.PHP_BIN ?? "php", ["artisan", name], { cwd: join(import.meta.dirname, "..", "..", "api"), env: { ...process.env }, encoding: "utf8" });
  const setInput = (sel, v) => ev(`(() => { const e = document.querySelector(${JSON.stringify(sel)}); const set = Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value').set; set.call(e, ${JSON.stringify(v)}); e.dispatchEvent(new Event('input', { bubbles: true })); })()`);
  const setSelect = (sel, v) => ev(`(() => { const e = document.querySelector(${JSON.stringify(sel)}); const set = Object.getOwnPropertyDescriptor(HTMLSelectElement.prototype, 'value').set; set.call(e, ${JSON.stringify(v)}); e.dispatchEvent(new Event('change', { bubbles: true })); })()`);
  const clickBtn = (re) => ev(`(() => { const b = [...document.querySelectorAll('main button')].find(x => ${re}.test(x.innerText)); if (!b || b.disabled) return false; b.click(); return true; })()`);
  const next = async () => { await ev("document.querySelector('form button[type=submit]').click()"); await sleep(350); };
  const lay = async () => JSON.parse(await ev(`JSON.stringify({ scroll: document.documentElement.scrollWidth, inner: innerWidth, h1: document.querySelectorAll('h1').length, sansNom: [...document.querySelectorAll('a,button,summary')].filter(e => !(e.getAttribute('aria-label') || e.innerText.trim())).length, sansEtiquette: [...document.querySelectorAll('main input:not([type=checkbox]), main select')].filter(e => !e.labels?.length && !e.getAttribute('aria-label')).length })`));

  await go("/login/");
  await type("#identifier", "registraire.demo");
  await type("#password", PASSWORD);
  await submit();
  await until("location.pathname === '/'", "accueil (candidatures)");

  // --- liste des candidatures
  await go("/candidatures/");
  await until("!document.querySelector('[aria-busy=true]') && /Demo Nouvelle Candidate/.test(document.body.innerText)", "liste des candidatures", 25000);
  const apps0 = await serverApps();
  const tabs = await ev("[...document.querySelectorAll('[role=tab]')].map(t => t.innerText.replace(/\\s+/g, ' ').trim())");
  check("les onglets de statut portent leurs compteurs (les archives en sont exclues)", tabs.some((t) => new RegExp(`^Toutes ${apps0.total}$`).test(t)) && tabs.some((t) => new RegExp(`^Nouveau ${apps0.counts.nouveau}$`).test(t)) && tabs.some((t) => new RegExp(`^Archivées ${apps0.archived}$`).test(t)), JSON.stringify(tabs));
  check("l'en-tête dit combien de candidatures sont nouvelles", new RegExp(`${apps0.counts.nouveau} nouvelles?`).test(await text()));
  await shot("07zg-candidatures");

  // --- instruire : accepter une candidature (les messages partent APRÈS, par tâche)
  await ev("[...document.querySelectorAll('main ul li a')].find(a => /Demo Nouvelle Candidate/.test(a.innerText)).click()");
  await until("location.pathname === '/candidatures/fiche/' && /Instruction/.test(document.body.innerText) && !document.querySelector('[aria-busy=true]')", "fiche candidature", 25000);
  const idNew = Number(new URL(await ev("location.href")).searchParams.get("id"));
  check("la fiche montre l'instruction : les autres statuts possibles, jamais « converti »", await ev("[...document.querySelectorAll('main button')].some(b => /Accepter/.test(b.innerText)) && [...document.querySelectorAll('main button')].some(b => /Refuser/.test(b.innerText)) && ![...document.querySelectorAll('main button')].some(b => /onverti/.test(b.innerText))"));
  await clickBtn(/^Accepter$/);
  await until("!!document.querySelector('[role=group][aria-label^=\"Confirmer\"]')", "confirmation");
  const confirmText = await ev("document.querySelector('[role=group][aria-label^=\"Confirmer\"]').innerText.replace(/\\s+/g, ' ')");
  check("« Accepter » demande confirmation et dit qui sera prévenu : courriel ET SMS, avec les coordonnées", /Le candidat en sera informé/.test(confirmText) && /Courriel à demo\.nouvelle\.candidate@demo\.test/.test(confirmText) && /SMS au 034 12 345 67/.test(confirmText), confirmText);
  await shot("07zh-confirmation-acceptation");
  await clickBtn(/Confirmer/);
  check("la décision est enregistrée tout de suite : « le candidat sera prévenu dans la minute »", await until("/Décision enregistrée/.test(document.querySelector('[data-sonner-toaster]')?.innerText ?? '') && /dans la minute/.test(document.querySelector('[data-sonner-toaster]')?.innerText ?? '')", "toast décision"));
  const queued = await serverState(async () => byName(await serverApps(), "Demo Nouvelle Candidate"), (r) => r.status === "accepte" && r.notifications.length === 2);
  check("côté serveur : statut « accepté », deux tâches (courriel, SMS) EN ATTENTE : rien n'est parti pendant la requête", queued.status === "accepte" && queued.notifications.every((n) => n.state === "en_attente") && !queued.notified_email_at && !queued.notified_sms_at, JSON.stringify(queued.notifications.map((n) => n.state)));
  check("la page montre « en cours d'envoi » pour chaque message", await until("/Courriel en cours d’envoi/.test(document.body.innerText) && /SMS en cours d’envoi/.test(document.body.innerText)", "envoi en cours"));
  const out = runCommand("applications:notify");
  check("la commande planifiée envoie les deux messages (journal du courriel, journal des SMS)", /envoyés : 2/.test(out), out.trim());
  const sent = await serverApps().then((a) => byName(a, "Demo Nouvelle Candidate"));
  check("côté serveur : les tâches sont « envoyées » et les dates de notification posées", sent.notifications.every((n) => n.state === "envoyee") && !!sent.notified_email_at && !!sent.notified_sms_at);
  await go(`/candidatures/fiche/?id=${idNew}`);
  check("la fiche rouverte dit « Courriel envoyé » et « SMS envoyé »", await until("/Courriel envoyé/.test(document.body.innerText) && /SMS envoyé/.test(document.body.innerText) && !document.querySelector('[aria-busy=true]')", "messages envoyés", 25000));
  check("une candidature acceptée propose « Créer l'étudiant »", (await ev("!!document.querySelector('main a[href=\"/etudiants/nouveau/?candidature=' + new URL(location.href).searchParams.get('id') + '\"]')")));
  await shot("07zi-candidature-acceptee");

  // --- un candidat sans coordonnées : la confirmation le dit, et la décision part sans message
  await go("/candidatures/");
  await until("/Demo Sansmoyen Contact/.test(document.body.innerText) && !document.querySelector('[aria-busy=true]')", "liste (sans coordonnées)");
  await ev("[...document.querySelectorAll('main ul li a')].find(a => /Demo Sansmoyen Contact/.test(a.innerText)).click()");
  await until("location.pathname === '/candidatures/fiche/' && /Instruction/.test(document.body.innerText) && !document.querySelector('[aria-busy=true]')", "fiche sans coordonnées", 25000);
  await clickBtn(/^Refuser$/);
  await until("!!document.querySelector('[role=group][aria-label^=\"Confirmer\"]')", "confirmation (refus)");
  const confirm2 = await ev("document.querySelector('[role=group][aria-label^=\"Confirmer\"]').innerText.replace(/\\s+/g, ' ')");
  check("la confirmation d'un refus dit pourquoi aucun message ne partira (pas d'adresse, numéro inexploitable)", /le candidat n’a pas laissé d’adresse/.test(confirm2) && /numéro inexploitable/.test(confirm2), confirm2);
  await clickBtn(/Confirmer/);
  check("sans message à envoyer : « Statut mis à jour » (et pas « le candidat sera prévenu »)", await until("/Statut mis à jour/.test(document.querySelector('[data-sonner-toaster]')?.innerText ?? '')", "toast statut"));
  const nobody = byName(await serverApps(), "Demo Sansmoyen Contact");
  check("côté serveur : refusé, deux tâches « ignorées » avec leur raison", nobody.status === "refuse" && nobody.notifications.every((n) => n.state === "ignoree" && !!n.reason), JSON.stringify(nobody.notifications.map((n) => n.state)));

  // --- archiver une candidature (le statut ne change pas)
  await go("/candidatures/");
  await until("/Demo Encours Candidat/.test(document.body.innerText) && !document.querySelector('[aria-busy=true]')", "liste (archiver)");
  await ev("[...document.querySelectorAll('main ul li a')].find(a => /Demo Encours Candidat/.test(a.innerText)).click()");
  await until("location.pathname === '/candidatures/fiche/' && /Instruction/.test(document.body.innerText) && !document.querySelector('[aria-busy=true]')", "fiche en cours", 25000);
  await clickBtn(/^Archiver$/);
  check("archiver confirme par un message", await until("/Candidature archivée/.test(document.querySelector('[data-sonner-toaster]')?.innerText ?? '')", "toast archive"));
  const arch = byName((await serverApps("archive")).rows, "Demo Encours Candidat");
  check("côté serveur : rangée dans les archives, le statut « en cours » est inchangé", !!arch && arch.status === "en_cours" && !!arch.archived_at);

  // --- création d'un étudiant depuis la candidature acceptée (Licence)
  await go("/candidatures/");
  await until("/Demo Acceptee Licence/.test(document.body.innerText) && !document.querySelector('[aria-busy=true]')", "liste (création)");
  await ev("[...document.querySelectorAll('main ul li a')].find(a => /Demo Acceptee Licence/.test(a.innerText)).click()");
  await until("location.pathname === '/candidatures/fiche/' && /Créer l’étudiant/.test(document.body.innerText) && !document.querySelector('[aria-busy=true]')", "fiche acceptée", 25000);
  await ev("[...document.querySelectorAll('main a')].find(a => /Créer l’étudiant/.test(a.innerText)).click()");
  await until("location.pathname === '/etudiants/nouveau/' && /Étape 1 sur 4/.test(document.body.innerText) && !document.querySelector('[aria-busy=true]')", "formulaire de création", 25000);
  check("depuis une candidature, le formulaire est pré-rempli (nom, naissance, nationalité…) sans son e-mail personnel", await ev("document.querySelector('#f-last_name').value === 'Acceptee' && document.querySelector('#f-first_name').value === 'Licence' && document.querySelector('#f-birth_date').value === '2005-03-12' && document.querySelector('#f-nationality').value === 'Malagasy'") && /Depuis la candidature de/.test(await text()) && (await ev("document.querySelector('#f-email').value")) === "");
  await shot("07zj-creation-etape1");

  // validation : la région manque
  await next();
  check("« Suivant » sans région : le champ est signalé et reçoit le focus", await until("/Choisissez la région/.test(document.body.innerText) && document.activeElement?.id === 'f-region'", "région obligatoire"));
  await setSelect("#f-region", await ev("[...document.querySelectorAll('#f-region option')].find(o => /Vakinankaratra/.test(o.textContent)).value"));
  await next();
  await until("/Étape 2 sur 4/.test(document.body.innerText)", "étape 2");
  check("étape études : mention et parcours repris de la candidature, matricule et adresse PROPOSÉS libres", await until("document.querySelector('#f-mention').value === 'INFO' && document.querySelector('#f-track').value === 'Réseaux' && /^\\d{5}$/.test(document.querySelector('#f-student_id').value)", "matricule proposé", 20000));
  const proposedId = await ev("document.querySelector('#f-student_id').value");
  await shot("07zk-creation-etape2");
  await next();
  await until("/Étape 3 sur 4/.test(document.body.innerText)", "étape 3");
  check("étape famille : père, mère et garant repris de la candidature", (await ev("document.querySelector('#f-father_name').value")) === "Papa Demo" && (await ev("document.querySelector('#f-mother_name').value")) === "Maman Demo" && (await ev("document.querySelector('#f-sponsor_last_name').value")) === "Oncle Demo");
  await next();
  await until("/Étape 4 sur 4/.test(document.body.innerText) && /Récapitulatif/.test(document.body.innerText)", "récapitulatif");
  const recap = await text();
  check("le récapitulatif dit ce qui sera attribué (matricule, adresse) et que le mot de passe n'est affiché qu'une fois", new RegExp(proposedId).test(recap) && /mot de passe initial aléatoire/.test(recap) && /Acceptee/i.test(recap));
  await shot("07zl-creation-recapitulatif");
  // double clic : un seul étudiant
  await ev("(() => { const b = document.querySelector('form button[type=submit]'); b.click(); setTimeout(() => b.click(), 30); })()");
  check("« Créer l'étudiant » : le compte rendu donne matricule, adresse universitaire et mot de passe initial (une fois)", await until("/Étudiant créé/.test(document.body.innerText) && /Mot de passe initial/.test(document.body.innerText)", "création réussie", 30000));
  const done = await text();
  const newId = (done.match(/Matricule\s+(\d+[A-Z]*)/) ?? [])[1];
  const pwd = await ev("document.querySelector('code')?.innerText ?? ''");
  check("le mot de passe initial est aléatoire (12 caractères sans ambiguïté), pas l'ancien « 1234Student2027 »", /^(?=.*[A-Z])(?=.*[a-z])(?=.*\d)[A-HJ-NP-Za-km-z2-9]{12}$/.test(pwd) && !/Student/.test(pwd), pwd.replace(/./g, "•"));
  check("le matricule attribué est celui qui était proposé, et l'adresse universitaire est dérivée du nom", newId === proposedId && /acceptee\.[a-z0-9]+@zurcher\.edu\.mg/.test(done), done.slice(0, 140).replace(/\n/g, " "));
  check("le compte Google n'est pas créé pendant la requête : son état est annoncé (ici non configuré)", /Compte Google/.test(done) && /pas configurée|en cours de création|créé/.test(done));
  check("la candidature est convertie et rangée dans les archives", /convertie et rangée dans les archives/.test(done));
  await shot("07zm-creation-reussie");
  const idCreated = await ev("Number(new URL([...document.querySelectorAll('main a')].find(a => /Ouvrir la fiche/.test(a.innerText)).href).searchParams.get('id'))");
  const list = (await staff("GET", `/api/v1/staff/lists/students?q=${newId}`)).json.data;
  check("côté serveur : UN seul étudiant créé malgré le double clic, avec ce matricule", list.total === 1 && list.rows[0].student_id === newId, `total ${list.total}`);
  const conv = byName((await serverApps("archive")).rows, "Demo Acceptee Licence");
  check("côté serveur : la candidature est « convertie », archivée, liée au matricule", !!conv && conv.status === "converti" && conv.student_id === newId && !!conv.archived_at);
  const lic = (await staff("GET", `/api/v1/staff/students/${idCreated}`)).json.data;
  check("la fiche créée porte la mention, le niveau et l'année choisis (Licence 1, Informatique)", lic.mention === "Informatique" && lic.level === 1 && lic.student_id === newId && lic.is_new === true && !JSON.stringify(lic).match(/password|motdepasse/i));
  await click("main a[href*='/etudiants/fiche/']");
  await until("location.pathname === '/etudiants/fiche/' && /Fiche en lecture seule/.test(document.body.innerText) && !document.querySelector('[aria-busy=true]')", "fiche créée", 25000);
  check("la fiche créée ne montre jamais le mot de passe", !(await text()).includes(pwd));

  // une candidature déjà convertie n'ouvre plus le formulaire : elle renvoie à l'étudiant
  await go(`/etudiants/nouveau/?candidature=${conv.id}`);
  check("rouvrir une candidature convertie explique « Cet étudiant est déjà créé » et pointe vers la fiche (pas de formulaire)", await until("/Cet étudiant est déjà créé/.test(document.body.innerText) && /Ouvrir la fiche de l’étudiant/.test(document.body.innerText) && !document.querySelector('form')", "candidature convertie"));
  await go(`/etudiants/nouveau/?candidature=${nobody.id}`);
  check("une candidature refusée n'ouvre pas de formulaire : « Candidature non acceptée », avec le retour aux candidatures", await until("/Candidature non acceptée/.test(document.body.innerText) && /a été refusée/.test(document.body.innerText) && !document.querySelector('form')", "candidature refusée"));

  // --- Master : la création pose le courriel avec l'adresse et le mot de passe (envoyé par la tâche)
  const master = byName(await serverApps(), "Demo Acceptee Master");
  const pre = (await staff("GET", `/api/v1/staff/student-creation/from-application/${master.id}`)).json.data;
  check("candidat de Master : le formulaire s'ouvre au niveau Master 1 (« is_master »)", pre.level === 4 && pre.is_master === true);
  const mastersBefore = (await staff("GET", `/api/v1/staff/applications/${master.id}`)).json.data;
  check("avant la création, l'acceptation d'un candidat de Master n'a envoyé AUCUN courriel (il partira avec l'adresse et le mot de passe)", mastersBefore.status === "accepte" && !mastersBefore.notified_email_at);
  const token = Array.from(crypto.getRandomValues(new Uint8Array(16)), (b) => b.toString(16).padStart(2, "0")).join("");
  const opts = (await staff("GET", "/api/v1/staff/student-creation/options")).json.data;
  const masterPayload = {
    token, application_id: master.id, last_name: "Testcreation", first_name: "Master Zo", sex: 1, birth_date: "1998-06-02", birth_place: "Antsirabe", nationality: "Malagasy", origin_country: "Madagascar",
    marital_status: "Célibataire", religion: "Adventiste", children: 0, region: opts.regions[0].id, address: "Lot 5", mention: "INFO", track: "Réseaux", level: 4,
    academic_year: "2026 - 2027", status: "Externe", previous_degree: "Licence en informatique", previous_degree_date: "2025-09-01",
  };
  const createdMaster = await staff("POST", "/api/v1/staff/students", masterPayload);
  check("créer l'étudiant de Master depuis sa candidature (API) : 201, avec le mot de passe rendu une fois", createdMaster.status === 201 && !!createdMaster.json?.data?.initial_password, `${createdMaster.status} ${JSON.stringify(createdMaster.json)?.slice(0, 160)}`);
  const mt = (await staff("GET", `/api/v1/staff/applications/${master.id}`)).json.data;
  check("côté serveur : candidature convertie, tâche « identifiants » EN ATTENTE (le courriel part par la tâche, après le compte Google)", mt.status === "converti" && mt.notifications.some((n) => n.decision === "identifiants" && n.state === "en_attente"), JSON.stringify(mt.notifications));
  const out2 = runCommand("applications:notify");
  check("la tâche planifiée envoie le courriel d'acceptation du Master", /envoyés : 1/.test(out2), out2.trim());
  const mt2 = (await staff("GET", `/api/v1/staff/applications/${master.id}`)).json.data;
  check("côté serveur : « Courriel d'acceptation avec l'adresse et le mot de passe » envoyé", mt2.notifications.some((n) => n.decision === "identifiants" && n.state === "envoyee") && !!mt2.notified_email_at);
  const again = await staff("POST", "/api/v1/staff/students", masterPayload);
  check("rejouer la même demande (même jeton) ne crée rien : 200, « already », et le mot de passe n'est plus rendu", again.status === 200 && again.json.data.already === true && again.json.data.initial_password === null && again.json.data.student_id === createdMaster.json.data.student_id);
  const dupe = (await staff("GET", "/api/v1/staff/lists/students?q=Testcreation")).json.data;
  check("côté serveur : un seul étudiant « Testcreation » de Master", dupe.rows.filter((r) => r.first_name === "Master Zo").length === 1);

  // --- création libre (sans candidature) : validation, brouillon, création
  await go("/etudiants/nouveau/");
  await until("/Étape 1 sur 4/.test(document.body.innerText) && !document.querySelector('[aria-busy=true]')", "formulaire libre", 25000);
  await next();
  check("« Suivant » sur un formulaire vide : chaque champ manquant est signalé avec son message, le premier prend le focus", await until("/Indiquez le nom/.test(document.body.innerText) && /Indiquez le prénom/.test(document.body.innerText) && /Choisissez la région/.test(document.body.innerText) && document.activeElement?.id === 'f-last_name'", "erreurs de validation"));
  await shot("07zn-creation-erreurs");
  await type("#f-last_name", "Testcreation");
  await sleep(300);
  await go("/etudiants/nouveau/");
  await until("/Étape 1 sur 4/.test(document.body.innerText) && !document.querySelector('[aria-busy=true]')", "formulaire rouvert");
  check("le brouillon est gardé sur l'appareil : la page rechargée retrouve le nom saisi", await until("document.querySelector('#f-last_name')?.value === 'Testcreation'", "brouillon repris"));
  await type("#f-first_name", "Libre Zo");
  await setInput("#f-birth_date", "2004-05-20");
  await type("#f-birth_place", "Antsirabe");
  await setSelect("#f-region", await ev("[...document.querySelectorAll('#f-region option')].find(o => /Analamanga/.test(o.textContent)).value"));
  await type("#f-address", "Lot 7 Antananarivo");
  await next();
  await until("/Étape 2 sur 4/.test(document.body.innerText)", "étape 2 (libre)");
  await next();
  check("étape études : sans mention ni parcours, « Suivant » les signale", await until("/Choisissez la mention/.test(document.body.innerText) && /Choisissez le parcours/.test(document.body.innerText)", "mention obligatoire"));
  const mentionsOffered = await ev("[...document.querySelectorAll('#f-mention option')].map(o => o.textContent.trim())");
  check("les mentions proposées sont les OUVERTES : « Éducation » (fermée) n'est pas offerte", mentionsOffered.includes("Informatique") && mentionsOffered.includes("Gestion") && !mentionsOffered.some((m) => /Éducation/.test(m)), JSON.stringify(mentionsOffered));
  await setSelect("#f-mention", "INFO");
  await sleep(500);
  const tracks1 = await ev("[...document.querySelectorAll('#f-track option')].map(o => o.textContent.trim())");
  check("les parcours proposés sont ceux de la mention pour la licence (pas « Intelligence artificielle », réservé au master)", tracks1.includes("Réseaux") && tracks1.includes("Développement") && !tracks1.includes("Intelligence artificielle"), JSON.stringify(tracks1));
  await setSelect("#f-level", "4");
  await sleep(300);
  const tracks2 = await ev("[...document.querySelectorAll('#f-track option')].map(o => o.textContent.trim())");
  check("en Master 1, la liste suit le niveau : « Intelligence artificielle » apparaît, « Développement » disparaît (et le choix précédent est vidé)", tracks2.includes("Intelligence artificielle") && !tracks2.includes("Développement") && (await ev("document.querySelector('#f-track').value")) === "");
  check("en master, le diplôme précédent remplace le baccalauréat", /Diplôme précédent/.test(await text()) && !/Baccalauréat obtenu/.test(await text()));
  await setSelect("#f-level", "1");
  await sleep(300);
  await setSelect("#f-track", "Développement");
  check("le matricule est proposé dès que la mention est choisie (5 chiffres de la plage Informatique, 30000 ou plus)", await until("/^3\\d{4}$/.test(document.querySelector('#f-student_id').value)", "matricule proposé (libre)", 20000));
  await next();
  await until("/Étape 3 sur 4/.test(document.body.innerText)", "étape 3 (libre)");
  await next();
  await until("/Étape 4 sur 4/.test(document.body.innerText) && /Récapitulatif/.test(document.body.innerText)", "récapitulatif (libre)");
  await ev("document.querySelector('form button[type=submit]').click()");
  check("création libre : étudiant créé", await until("/Étudiant créé/.test(document.body.innerText)", "création libre", 30000));
  const free = (await text()).match(/Matricule\s+(\d+)/)?.[1];
  const lf = (await staff("GET", `/api/v1/staff/lists/students?q=${free}`)).json.data;
  check("côté serveur : l'étudiant « Testcreation » existe, en Licence 1, parcours Développement", lf.total === 1 && lf.rows[0].last_name === "Testcreation" && lf.rows[0].track === "Développement" && lf.rows[0].level === 1);
  check("après une création réussie, le brouillon est effacé (un formulaire neuf repart vide)", await ev("sessionStorage.getItem('registrar:creation-draft')") === null);
  await ev("[...document.querySelectorAll('main button')].find(b => /Créer un autre étudiant/.test(b.innerText)).click()");
  check("« Créer un autre étudiant » ouvre un formulaire vierge", await until("/Étape 1 sur 4/.test(document.body.innerText) && document.querySelector('#f-last_name')?.value === ''", "formulaire vierge"));
  const l3 = await lay();
  check("formulaire de création : aucun défilement horizontal, un seul h1, contrôles nommés, chaque champ étiqueté", l3.scroll <= l3.inner && l3.h1 === 1 && l3.sansNom === 0 && l3.sansEtiquette === 0, JSON.stringify(l3));

  await ev("document.querySelector('button[aria-label^=\"Se déconnecter\"]').click()");
  check("déconnexion du registrariat (candidatures)", await until("location.pathname.startsWith('/login')", "déconnexion candidatures"));

  // --- les droits : un enseignant ne crée rien et ne voit aucune candidature, même par l'API
  const t2 = await apiAs("enseignant.demo", PASSWORD);
  const r403 = [await t2("POST", "/api/v1/staff/applications/" + idNew + "/status", { status: "refuse" }), await t2("POST", "/api/v1/staff/students", { token }), await t2("GET", "/api/v1/staff/student-creation/options")];
  check("un enseignant ne peut ni instruire une candidature, ni créer un étudiant, ni lire les choix du formulaire (403)", r403.every((r) => r.status === 403), r403.map((r) => r.status).join("/"));
}

console.log("\n7i. Finance : autorisation de réinscription");
{
  const API = "http://localhost:8000";
  const apiAs = async (pseudo, password) => {
    const jar = {};
    const take = (res) => { for (const c of res.headers.getSetCookie?.() ?? []) { const [kv] = c.split(";"); const i = kv.indexOf("="); jar[kv.slice(0, i)] = kv.slice(i + 1); } };
    const base = { Origin: WEB, Referer: WEB + "/", Accept: "application/json" };
    take(await fetch(API + "/api/v1/auth/csrf", { headers: base }));
    const call = async (method, p, body) => {
      const res = await fetch(API + p, { method, headers: { ...base, "Content-Type": "application/json", Cookie: Object.entries(jar).map(([k, v]) => `${k}=${v}`).join("; "), "X-XSRF-TOKEN": decodeURIComponent(jar["XSRF-TOKEN"] ?? "") }, body: body ? JSON.stringify(body) : undefined });
      take(res);
      return { status: res.status, json: await res.json().catch(() => null) };
    };
    await call("POST", "/api/v1/auth/login", { identifier: pseudo, password });
    return call;
  };
  const BASE = "/api/v1/staff/finance/reenrolment";
  const loginAs = async (pseudo) => {
    await go("/login/");
    await type("#identifier", pseudo);
    await type("#password", PASSWORD);
    await submit();
    await until("location.pathname === '/'", "accueil " + pseudo);
  };
  const open = async () => {
    await go("/finance/reinscription/");
    await until("!document.querySelector('[aria-busy=true]') && !!document.querySelector('[aria-label=\"Filtrer par état\"] button')", "page réinscription", 25000);
  };
  const tiles = async () => JSON.parse(await ev(`JSON.stringify([...document.querySelectorAll('[aria-label="Filtrer par état"] button')].map(b => ({ n: Number(b.firstElementChild.innerText.replace(/\\D/g, '')), on: b.getAttribute('aria-pressed') === 'true', label: b.innerText.replace(/\\s+/g, ' ').trim() })))`));
  const rowsOf = async () => JSON.parse(await ev(`JSON.stringify([...document.querySelectorAll('main ul.grid.gap-2 > li')].map(li => ({ id: li.querySelector('p.font-mono')?.innerText.trim(), state: li.querySelector('p.font-mono')?.nextElementSibling?.innerText.trim(), sw: [...li.querySelectorAll('[role=switch]')].map(b => ({ label: b.getAttribute('aria-label'), on: b.getAttribute('aria-checked') === 'true' })) })))`));
  const search = async (q) => {
    await ev("(() => { const e = document.querySelector('input[type=search]'); const set = Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value').set; set.call(e, " + JSON.stringify(q) + "); e.dispatchEvent(new Event('input', { bubbles: true })); })()");
    await sleep(700);
    await until("!document.querySelector('main ul.grid.gap-2.opacity-60')", "liste filtrée");
  };
  const rowButton = (id, sel) => ev(`(() => { const li = [...document.querySelectorAll('main ul.grid.gap-2 > li')].find(l => l.querySelector('p.font-mono')?.innerText.trim() === ${JSON.stringify(id)}); const b = li?.querySelector(${JSON.stringify(sel)}); if (!b) return false; b.click(); return true; })()`);
  const lay = async () => JSON.parse(await ev(`JSON.stringify({ scroll: document.documentElement.scrollWidth, inner: innerWidth, h1: document.querySelectorAll('h1').length, sansNom: [...document.querySelectorAll('a,button,input,select')].filter(e => !(e.getAttribute('aria-label') || e.innerText?.trim() || e.labels?.length || e.getAttribute('placeholder'))).length })`));
  const flagsOf = async (call, matricule) => (await call("GET", BASE + "?q=" + matricule)).json.data.rows.find((r) => r.student_id === matricule);

  // ------------------------------------------------------------------ le registraire : sa colonne seulement
  await loginAs("registraire.demo");
  const reg = await apiAs("registraire.demo", PASSWORD);
  const s0 = (await reg("GET", BASE)).json.data;
  await open();
  const t0 = await tiles();
  check("les cinq compteurs (tous, prêts, attente caisse, attente dossier, aucun) sont ceux du serveur et totalisent l'effectif",
    t0.length === 5 && t0[0].n === s0.stats.total && t0[1].n === s0.stats.prets && t0[2].n === s0.stats.attente_caisse && t0[3].n === s0.stats.attente_dossier && t0[4].n === s0.stats.aucun && t0[1].n + t0[2].n + t0[3].n + t0[4].n === t0[0].n, JSON.stringify(t0.map((t) => t.n)));
  const rights = await ev("document.querySelector('[aria-label=\"Vos droits sur cette page\"]')?.innerText.replace(/\\s+/g, ' ')");
  check("le registraire voit ses droits en tête : dossier modifiable, caisse en lecture seule", /Dossier : modifiable/.test(rights) && /Caisse : lecture seule/.test(rights), rights);
  await go("/plus/");
  check("sur téléphone, « Réinscription » est rangée sous « Plus » et reliée à la page", await until("[...document.querySelectorAll('main a')].some(a => /Réinscription/.test(a.innerText) && a.getAttribute('href') === '/finance/reinscription/')", "lien Plus → Réinscription"));
  await open();
  await search("DEMO-01");

  const r0 = await rowsOf();
  check("la recherche « DEMO-01 » garde les étudiants de démonstration (au moins les six premiers) ; chacun a son interrupteur « dossier » et AUCUN interrupteur « caisse »",
    r0.length >= 6 && r0.every((r) => r.sw.length === 1 && /^Dossier validé/.test(r.sw[0].label)), JSON.stringify(r0.map((r) => r.id + ":" + r.sw.length)));
  const byId = Object.fromEntries(r0.map((r) => [r.id, r]));
  check("états de départ : 0101 prêt, 0102 attend le dossier, 0103 attend la caisse, les autres n'ont rien",
    /Prêts/.test(byId["DEMO-0101"].state) && /dossier/.test(byId["DEMO-0102"].state) && /caisse/.test(byId["DEMO-0103"].state) && /Ni l/.test(byId["DEMO-0104"].state), JSON.stringify(r0.map((r) => r.state)));
  check("0101 et 0103 ont leur dossier validé, 0102 non", byId["DEMO-0101"].sw[0].on && byId["DEMO-0103"].sw[0].on && !byId["DEMO-0102"].sw[0].on);
  check("la trace « qui · quand » est affichée sous l'interrupteur déjà posé", /Ancien Demo · \d{2}\/\d{2}\/\d{4}/.test(await ev("document.body.innerText")) || /Registraire Demo · \d{2}\/\d{2}\/\d{4}/.test(await ev("document.body.innerText")));
  await shot("07zi-reinscription-registraire");

  // --- un clic : le dossier de 0102 est validé, l'étudiant devient « prêt », les compteurs suivent
  await rowButton("DEMO-0102", "[role=switch]");
  check("l'interrupteur passe à « validé » et l'étudiant devient « prêt »", await until("(() => { const li = [...document.querySelectorAll('main ul.grid.gap-2 > li')].find(l => l.querySelector('p.font-mono')?.innerText.trim() === 'DEMO-0102'); return li?.querySelector('[role=switch]')?.getAttribute('aria-checked') === 'true' && /Prêts/.test(li.innerText) && !li.querySelector('[role=switch][aria-busy=true]'); })()", "bascule 0102", 15000));
  await until("/Dossier validé/.test(document.querySelector('[data-sonner-toaster]')?.innerText ?? '')", "toast dossier validé", 8000);
  const f1 = await flagsOf(reg, "DEMO-0102");
  check("le serveur a enregistré : dossier validé par « Registraire Demo », étudiant « prêt »", f1.file.value === true && f1.file.by === "Registraire Demo" && f1.state === "prets", JSON.stringify(f1.file));
  const t1 = await tiles();
  check("les compteurs ont suivi sans recharger : un « prêt » de plus, un « attente du dossier » de moins, total inchangé", t1[1].n === t0[1].n + 1 && t1[3].n === t0[3].n - 1 && t1[0].n === t0[0].n, JSON.stringify(t1.map((t) => t.n)));
  const again = await reg("PUT", BASE + "/students/" + f1.id + "/file", { value: true });
  check("rejouer la même valeur ne change rien (idempotent) : l'auteur et la date d'origine restent", again.status === 200 && again.json.data.changed === false && again.json.data.row.file.at === f1.file.at);

  // --- ce que le registraire ne peut PAS faire, même par l'API
  const denied = await reg("PUT", BASE + "/students/" + f1.id + "/authorization", { value: true });
  const deniedBulk = await reg("POST", BASE + "/bulk", { field: "authorization", value: true, ids: [f1.id] });
  const deniedReset = await reg("POST", BASE + "/reset", { columns: ["file"], confirmation: "REINITIALISER" });
  check("le registraire ne peut ni autoriser (caisse), ni autoriser en groupe, ni réinitialiser, même par l'API (403)", denied.status === 403 && deniedBulk.status === 403 && deniedReset.status === 403, [denied.status, deniedBulk.status, deniedReset.status].join("/"));
  check("aucun bouton « Tout réinitialiser » pour lui", !(await ev("[...document.querySelectorAll('main button')].some(b => /Tout réinitialiser/.test(b.innerText))")));

  // --- filtrer par état avec les compteurs
  await ev("[...document.querySelectorAll('[aria-label=\"Filtrer par état\"] button')].find(b => /attente de la caisse/i.test(b.innerText)).click()");
  await until("[...document.querySelectorAll('[aria-label=\"Filtrer par état\"] button')].some(b => b.getAttribute('aria-pressed') === 'true' && /attente de la caisse/i.test(b.innerText))", "tuile choisie");
  await sleep(600);
  await until("!document.querySelector('main ul.grid.gap-2.opacity-60')", "liste filtrée par état");
  const rS = await rowsOf();
  check("la tuile « En attente de la caisse » ne laisse que ceux-là (DEMO-0103), qui est le seul de la recherche", rS.length === 1 && rS[0].id === "DEMO-0103", JSON.stringify(rS.map((r) => r.id)));
  await ev("[...document.querySelectorAll('[aria-label=\"Filtrer par état\"] button')].find(b => /attente de la caisse/i.test(b.innerText)).click()");
  await sleep(700);
  await until(`document.querySelectorAll('main ul.grid.gap-2 > li').length === ${r0.length} && !document.querySelector('main ul.grid.gap-2.opacity-60')`, "tous de retour");

  // --- action groupée puis « Annuler »
  await rowButton("DEMO-0104", "input[type=checkbox]");
  await rowButton("DEMO-0105", "input[type=checkbox]");
  check("la barre d'action groupée apparaît et dit « 2 étudiants sélectionnés »", await until("/2 étudiants sélectionnés/.test(document.querySelector('[role=region][aria-label=\"Action groupée\"]')?.innerText ?? '')", "barre groupée"));
  const barButtons = await ev("[...document.querySelectorAll('[role=region][aria-label=\"Action groupée\"] button')].map(b => b.innerText.trim())");
  check("le registraire n'a que les actions de sa colonne : « Valider le dossier » et « Retirer la validation »", barButtons.includes("Valider le dossier") && barButtons.includes("Retirer la validation") && !barButtons.includes("Autoriser"), JSON.stringify(barButtons));
  await ev("[...document.querySelectorAll('[role=region][aria-label=\"Action groupée\"] button')].find(b => b.innerText.trim() === 'Valider le dossier').click()");
  check("le groupe est appliqué : « 2 dossier(s) validé(s) »", await until("/2 dossier\\(s\\) validé\\(s\\)/.test(document.querySelector('[data-sonner-toaster]')?.innerText ?? '')", "toast groupé", 12000));
  const g1 = [await flagsOf(reg, "DEMO-0104"), await flagsOf(reg, "DEMO-0105")];
  check("côté serveur : les deux dossiers sont validés, le troisième (0106) non", g1.every((r) => r.file.value) && !(await flagsOf(reg, "DEMO-0106")).file.value);
  await ev("(() => { const b = [...document.querySelectorAll('[data-sonner-toaster] button')].find(x => /Annuler/.test(x.innerText)); b?.click(); })()");
  check("« Annuler » dans la notification remet les deux dossiers comme avant", await (async () => { for (let i = 0; i < 40; i++) { const a = [await flagsOf(reg, "DEMO-0104"), await flagsOf(reg, "DEMO-0105")]; if (a.every((r) => !r.file.value)) return true; await sleep(250); } return false; })());
  const l1 = await lay();
  check("page de réinscription : aucun défilement horizontal, un seul h1, contrôles nommés", l1.scroll <= l1.inner && l1.h1 === 1 && l1.sansNom === 0, JSON.stringify(l1));
  await ev("document.querySelector('button[aria-label^=\"Se déconnecter\"]').click()");
  await until("location.pathname.startsWith('/login')", "déconnexion registraire");

  // ------------------------------------------------------------------ la caisse : l'autre colonne
  await loginAs("ancien.demo");
  const cash = await apiAs("ancien.demo", PASSWORD);
  await open();
  await search("DEMO-01");
  const rightsC = await ev("document.querySelector('[aria-label=\"Vos droits sur cette page\"]')?.innerText.replace(/\\s+/g, ' ')");
  check("la caisse voit ses droits : caisse modifiable, dossier en lecture seule", /Caisse : modifiable/.test(rightsC) && /Dossier : lecture seule/.test(rightsC), rightsC);
  const rC = await rowsOf();
  check("chaque étudiant n'a que l'interrupteur « Réinscription autorisée »", rC.length === r0.length && rC.every((r) => r.sw.length === 1 && /^Réinscription autorisée/.test(r.sw[0].label)));
  await rowButton("DEMO-0103", "[role=switch]");
  check("la caisse autorise DEMO-0103 : il devient « prêt à se réinscrire »", await until("(() => { const li = [...document.querySelectorAll('main ul.grid.gap-2 > li')].find(l => l.querySelector('p.font-mono')?.innerText.trim() === 'DEMO-0103'); return li?.querySelector('[role=switch]')?.getAttribute('aria-checked') === 'true' && /Prêts/.test(li.innerText); })()", "autorisation 0103", 15000));
  const fc = await flagsOf(cash, "DEMO-0103");
  check("le serveur a enregistré l'auteur « Ancien Demo » et l'état « prêt »", fc.authorization.value && fc.authorization.by === "Ancien Demo" && fc.state === "prets", JSON.stringify(fc.authorization));
  const deniedC = [await cash("PUT", BASE + "/students/" + fc.id + "/file", { value: false }), await cash("GET", BASE + "/reset/preview?columns[]=file"), await cash("POST", BASE + "/reset/0123456789abcdef/undo")];
  check("la caisse ne peut ni toucher au dossier, ni voir l'aperçu de réinitialisation, ni annuler un lot (403)", deniedC.every((r) => r.status === 403), deniedC.map((r) => r.status).join("/"));
  check("aucun bouton « Tout réinitialiser » pour la caisse", !(await ev("[...document.querySelectorAll('main button')].some(b => /Tout réinitialiser/.test(b.innerText))")));
  await ev("document.querySelector('button[aria-label^=\"Se déconnecter\"]').click()");
  await until("location.pathname.startsWith('/login')", "déconnexion caisse");

  // ------------------------------------------------------------------ le superadmin : tout, et la réinitialisation annulable
  await loginAs("superadmin.demo");
  const sup = await apiAs("superadmin.demo", PASSWORD);
  await open();
  await search("DEMO-01");
  const rightsS = await ev("document.querySelector('[aria-label=\"Vos droits sur cette page\"]')?.innerText.replace(/\\s+/g, ' ')");
  check("le superadmin peut tout modifier", /Caisse : modifiable/.test(rightsS) && /Dossier : modifiable/.test(rightsS), rightsS);
  const rS2 = await rowsOf();
  check("chaque étudiant a deux interrupteurs (dossier, caisse)", rS2.length === r0.length && rS2.every((r) => r.sw.length === 2));
  const before = (await sup("GET", BASE)).json.data;
  check("le bandeau d'annulation n'existe pas tant qu'aucune réinitialisation n'a eu lieu", before.last_batch === null && !(await ev("[...document.querySelectorAll('main [role=status]')].some(s => /Réinitialisation du/.test(s.innerText))")));

  await ev("[...document.querySelectorAll('main button')].find(b => /Tout réinitialiser/.test(b.innerText)).click()");
  check("la fenêtre « Tout réinitialiser » s'ouvre", await until("document.querySelector('dialog[open]') !== null", "fenêtre ouverte"));
  const apercu = (await sup("GET", BASE + "/reset/preview?columns[]=authorization&columns[]=file")).json.data;
  check("la portée affichée est celle du serveur : autorisations, dossiers, étudiants concernés, étudiants qui ne pourront plus se réinscrire",
    await until(`(() => { const t = document.querySelector('dialog[open] p[aria-live]')?.innerText.replace(/\\D+/g, ' ').trim(); return t === ${JSON.stringify([apercu.by_field.authorization, apercu.by_field.file, apercu.students, apercu.blocked].join(" "))}; })()`, "portée", 12000), JSON.stringify(apercu));
  check("tant que la phrase n'est pas saisie, « Réinitialiser » reste inactif", await ev("[...document.querySelectorAll('dialog[open] button')].find(b => b.innerText.trim() === 'Réinitialiser').disabled"));
  await shot("07zi-reinscription-reinitialiser");
  await type("dialog[open] input:not([type=checkbox])", "oups");
  check("une mauvaise phrase ne l'active pas", await ev("[...document.querySelectorAll('dialog[open] button')].find(b => b.innerText.trim() === 'Réinitialiser').disabled"));
  await type("dialog[open] input:not([type=checkbox])", "réinitialiser");
  check("la bonne phrase (casse et accents tolérés) l'active", await until("![...document.querySelectorAll('dialog[open] button')].find(b => b.innerText.trim() === 'Réinitialiser').disabled", "bouton actif"));
  await ev("[...document.querySelectorAll('dialog[open] button')].find(b => b.innerText.trim() === 'Réinitialiser').click()");
  check("la réinitialisation aboutit : fenêtre fermée, bandeau d'annulation affiché", await until("document.querySelector('dialog[open]') === null && [...document.querySelectorAll('main [role=status]')].some(s => /Réinitialisation du/.test(s.innerText))", "bandeau", 20000));
  const after = (await sup("GET", BASE)).json.data;
  check("côté serveur : plus personne n'est prêt ni coché (tous « ni l'un ni l'autre »), un lot est annulable", after.stats.aucun === after.stats.total && after.stats.autorises === 0 && after.stats.dossiers_ok === 0 && after.last_batch?.rows === apercu.by_field.authorization + apercu.by_field.file, JSON.stringify(after.stats));
  const tA = await tiles();
  check("les compteurs de la page suivent : tout le monde est « ni l'un ni l'autre »", tA[4].n === tA[0].n && tA[1].n === 0);
  await shot("07zi-reinscription-bandeau");

  // --- annuler : tout revient exactement comme avant
  await ev("[...document.querySelectorAll('main [role=status] button')].find(b => /Annuler cette réinitialisation/.test(b.innerText)).click()");
  check("« Annuler cette réinitialisation » fait disparaître le bandeau", await until("![...document.querySelectorAll('main [role=status]')].some(s => /Réinitialisation du/.test(s.innerText))", "bandeau disparu", 20000));
  const restored = (await sup("GET", BASE)).json.data;
  check("les compteurs sont exactement ceux d'avant la réinitialisation, et plus aucun lot n'est proposé", JSON.stringify(restored.stats) === JSON.stringify(before.stats) && restored.last_batch === null, JSON.stringify(restored.stats));
  const f0101 = await flagsOf(sup, "DEMO-0101");
  check("auteur et date d'origine sont restaurés (DEMO-0101 : « Ancien Demo » pour la caisse, « Registraire Demo » pour le dossier)", f0101.authorization.by === "Ancien Demo" && f0101.file.by === "Registraire Demo" && f0101.state === "prets", JSON.stringify([f0101.authorization.by, f0101.file.by]));
  const l2 = await lay();
  check("le superadmin : aucun défilement horizontal, un seul h1, contrôles nommés", l2.scroll <= l2.inner && l2.h1 === 1 && l2.sansNom === 0, JSON.stringify(l2));

  // --- les droits pour un enseignant
  await ev("document.querySelector('button[aria-label^=\"Se déconnecter\"]').click()");
  await until("location.pathname.startsWith('/login')", "déconnexion superadmin");
  // Le frein de connexion (10 essais / 15 min / identifiant) est entamé par les sections précédentes : on le remet à zéro avant de se connecter encore comme enseignant.
  execFileSync(process.env.PHP_BIN ?? "php", ["artisan", "cache:clear"], { cwd: join(import.meta.dirname, "..", "..", "api"), env: { ...process.env }, encoding: "utf8" });
  const teacher = await apiAs("enseignant.demo", PASSWORD);
  const tr = [await teacher("GET", BASE), await teacher("PUT", BASE + "/students/1/file", { value: true }), await teacher("POST", BASE + "/bulk", { field: "file", value: true, ids: [1] })];
  check("un enseignant n'ouvre ni la liste, ni les bascules, ni l'action groupée (403)", tr.every((r) => r.status === 403), tr.map((r) => r.status).join("/"));
}

console.log("\n7j. Finance : solde de compte");
{
  const API = "http://localhost:8000";
  const apiAs = async (pseudo, password) => {
    const jar = {};
    const take = (res) => { for (const c of res.headers.getSetCookie?.() ?? []) { const [kv] = c.split(";"); const i = kv.indexOf("="); jar[kv.slice(0, i)] = kv.slice(i + 1); } };
    const base = { Origin: WEB, Referer: WEB + "/", Accept: "application/json" };
    take(await fetch(API + "/api/v1/auth/csrf", { headers: base }));
    const call = async (method, p, body) => {
      const res = await fetch(API + p, { method, headers: { ...base, "Content-Type": "application/json", Cookie: Object.entries(jar).map(([k, v]) => `${k}=${v}`).join("; "), "X-XSRF-TOKEN": decodeURIComponent(jar["XSRF-TOKEN"] ?? "") }, body: body ? JSON.stringify(body) : undefined });
      take(res);
      return { status: res.status, json: await res.json().catch(() => null) };
    };
    await call("POST", "/api/v1/auth/login", { identifier: pseudo, password });
    return call;
  };
  const BASE = "/api/v1/staff/finance/balances";
  const reg = await apiAs("registraire.demo", PASSWORD);
  const sessionId = (await reg("GET", BASE)).json.data.sessions.find((s) => /^Soldes \(démo\)/.test(s.label)).id;
  const rowsOf = async () => JSON.parse(await ev(`JSON.stringify([...document.querySelectorAll('main ul.grid.gap-2 > li')].map(li => ({ id: li.querySelector('p.font-mono')?.innerText.trim().split(/\\s+/)[0], text: li.innerText.replace(/\\s+/g, ' ').trim(), edit: !!li.querySelector('button[aria-label^="Modifier le solde"]') })))`));
  const idle = () => until("!document.querySelector('main ul.grid.gap-2.opacity-60') && !document.querySelector('[aria-busy=true]')", "liste prête", 20000);
  const tiles = async () => JSON.parse(await ev(`JSON.stringify([...document.querySelectorAll('[aria-label="Filtrer par état"] button')].map(b => ({ n: Number(b.firstElementChild.innerText.replace(/\\D/g, '')), on: b.getAttribute('aria-pressed') === 'true', label: b.innerText.replace(/\\s+/g, ' ').trim() })))`));
  const lay = async () => JSON.parse(await ev(`JSON.stringify({ scroll: document.documentElement.scrollWidth, inner: innerWidth, h1: document.querySelectorAll('h1').length, sansNom: [...document.querySelectorAll('a,button,input,select')].filter(e => !(e.getAttribute('aria-label') || e.innerText?.trim() || e.labels?.length || e.getAttribute('placeholder'))).length })`));
  const fire = (sel, v, kind = "input") => ev(`(() => { const e = document.querySelector(${JSON.stringify(sel)}); const proto = e.tagName === 'SELECT' ? HTMLSelectElement.prototype : HTMLInputElement.prototype; Object.getOwnPropertyDescriptor(proto, 'value').set.call(e, ${JSON.stringify(v)}); e.dispatchEvent(new Event(${JSON.stringify(kind)}, { bubbles: true })); })()`);
  const text0 = (s) => s.replace(/ | /g, " ");

  await go("/login/");
  await type("#identifier", "registraire.demo");
  await type("#password", PASSWORD);
  await submit();
  await until("location.pathname === '/'", "accueil registraire (soldes)");
  await go("/finance/soldes/");
  await until("!document.querySelector('[aria-busy=true]') && !!document.querySelector('select')", "page soldes", 25000);
  await fire("select", String(sessionId), "change");
  await idle();
  await sleep(500);
  await until(`document.querySelector('select').value === '${sessionId}' && document.querySelectorAll('main ul.grid.gap-2 > li').length === 5`, "cinq inscrits", 20000);
  const r0 = await rowsOf();
  check("la session « Soldes (démo) » montre ses cinq inscrits, du matricule le plus grand au plus petit", r0.map((r) => r.id).join() === "DEMO-0105,DEMO-0104,DEMO-0103,DEMO-0102,DEMO-0101", r0.map((r) => r.id).join());
  const t = (id) => text0(r0.find((r) => r.id === id).text);
  check("les soldes : dette -150 000 Ar (0101), avance +25 000 Ar (0102), nul (0103), calculé 500 000 − 200 000 = -300 000 Ar (0105)",
    /-150 000 Ar/.test(t("DEMO-0101")) && /\+25 000 Ar/.test(t("DEMO-0102")) && /0 Ar/.test(t("DEMO-0103")) && /-300 000 Ar/.test(t("DEMO-0105")), r0.map((r) => text0(r.text).slice(0, 70)).join(" | "));
  check("l'étudiant sans ligne finance (0104) est annoncé comme tel et n'a pas de bouton « Modifier »", /Sans ligne finance/.test(t("DEMO-0104")) && !r0.find((r) => r.id === "DEMO-0104").edit && r0.filter((r) => r.edit).length === 4);
  check("chaque état est dit en mots (jamais par la couleur seule)", /Débitaire/.test(t("DEMO-0101")) && /Créditaire/.test(t("DEMO-0102")) && /Soldé/.test(t("DEMO-0103")));
  const tl = await tiles();
  check("compteurs : 2 débitaires, 1 créditaire, 1 soldé, 1 sans ligne", tl.map((x) => x.n).join() === "2,1,1,1", JSON.stringify(tl.map((x) => x.n)));
  const totals = text0(await ev("document.querySelector('main dl')?.innerText.replace(/\\s+/g, ' ') ?? ''"));
  check("totaux : dettes -450 000 Ar, avances +25 000 Ar, net -425 000 Ar", /Dettes -450 000 Ar/.test(totals) && /Avances \+25 000 Ar/.test(totals) && /Net -425 000 Ar/.test(totals), totals);
  await shot("07zj-soldes");

  // --- filtrer et chercher
  await ev("document.querySelector('[aria-label=\"Filtrer par état\"] button').click()");
  await idle(); await sleep(400); await idle();
  const rD = await rowsOf();
  check("l'état « Débitaire » ne laisse que les deux dettes", rD.map((r) => r.id).sort().join() === "DEMO-0101,DEMO-0105", rD.map((r) => r.id).join());
  await ev("document.querySelector('[aria-label=\"Filtrer par état\"] button').click()");
  await idle(); await sleep(400);
  await fire("input[type=search]", "0102");
  await sleep(900); await idle();
  const rQ = await rowsOf();
  check("la recherche « 0102 » ne garde que cet étudiant", rQ.length === 1 && rQ[0].id === "DEMO-0102", rQ.map((r) => r.id).join());
  await fire("input[type=search]", "");
  await sleep(900); await idle();

  // --- corriger un solde
  await ev("(() => { const li = [...document.querySelectorAll('main ul.grid.gap-2 > li')].find(l => l.querySelector('p.font-mono')?.innerText.trim().startsWith('DEMO-0103')); li.querySelector('button[aria-label^=\"Modifier le solde\"]').click(); })()");
  check("« Modifier » ouvre le champ avec le solde enregistré", await until("document.querySelector('main form input')?.value === '0'", "éditeur ouvert"));
  await type("main form input", "abc");
  check("un montant illisible est refusé sur place : message, « Enregistrer » inactif", await until("!!document.querySelector('main form [role=alert]') && [...document.querySelectorAll('main form button')].find(b => /Enregistrer/.test(b.innerText)).disabled", "saisie invalide"));
  await type("main form input", "12 500,5");
  check("un montant lu à la française (espace, virgule) active « Enregistrer »", await until("!document.querySelector('main form [role=alert]') && ![...document.querySelectorAll('main form button')].find(b => /Enregistrer/.test(b.innerText)).disabled", "saisie valide"));
  await ev("[...document.querySelectorAll('main form button')].find(b => /Enregistrer/.test(b.innerText)).click()");
  check("l'enregistrement aboutit : « Solde enregistré » et la ligne affiche -12 501 Ar (débitaire)", await until("/Solde enregistré/.test(document.querySelector('[data-sonner-toaster]')?.innerText ?? '') && !document.querySelector('main form')", "solde enregistré", 20000));
  await idle();
  const srv = (await reg("GET", BASE + `?session=${sessionId}&q=DEMO-0103`)).json.data.rows[0];
  check("le serveur a enregistré 12 500,50 (débitaire, modifiable)", Math.abs(srv.balance - 12500.5) < 0.001 && srv.state === "debitaire" && srv.editable === true, JSON.stringify(srv));
  await until("document.querySelectorAll('main ul.grid.gap-2 > li').length === 5", "liste rechargée");
  const rAfter = await rowsOf();
  check("la ligne affiche le nouveau solde", /-12 501 Ar/.test(text0(rAfter.find((r) => r.id === "DEMO-0103").text)), text0(rAfter.find((r) => r.id === "DEMO-0103").text));
  const same = await reg("PUT", BASE + `/${sessionId}/103`, { balance: "12500,5" });
  check("réenregistrer la même valeur n'est pas une erreur : « inchangé »", same.status === 200 && same.json.data.changed === false);
  const miss = await reg("PUT", BASE + `/${sessionId}/104`, { balance: 5 });
  const bad = await reg("PUT", BASE + `/${sessionId}/103`, { balance: "beaucoup" });
  check("une ligne finance absente répond 404 (0104), un montant illisible 422", miss.status === 404 && bad.status === 422, [miss.status, bad.status].join("/"));
  const l1 = await lay();
  check("page des soldes : aucun défilement horizontal, un seul h1, contrôles nommés", l1.scroll <= l1.inner && l1.h1 === 1 && l1.sansNom === 0, JSON.stringify(l1));
  await ev("document.querySelector('button[aria-label^=\"Se déconnecter\"]').click()");
  await until("location.pathname.startsWith('/login')", "déconnexion registraire (soldes)");

  // --- les droits : la comptabilité (niveau 4) et un enseignant n'y ont pas accès
  const cash = await apiAs("ancien.demo", PASSWORD);
  // (un seul compte refusé ici : le frein de connexion de l'enseignant de démonstration, 10 essais par 15 minutes, est déjà largement entamé)
  const student = await apiAs("etudiant.demo", PASSWORD);
  const denied = [await student("GET", BASE), await student("PUT", BASE + `/${sessionId}/103`, { balance: 1 }), await student("PUT", BASE + `/${sessionId}/104`, {})];
  check("un étudiant n'ouvre ni la liste ni la correction, même par l'API (403, avant toute validation)", denied.every((r) => r.status === 403), denied.map((r) => r.status).join("/"));
  const still = (await reg("GET", BASE + `?session=${sessionId}&q=DEMO-0103`)).json.data.rows[0];
  check("et le solde n'a pas bougé", Math.abs(still.balance - 12500.5) < 0.001);
  const cashList = (await cash("GET", BASE + `?session=${sessionId}`)).json.data;
  check("la comptabilité, elle, lit la liste (5 inscrits) par l'API", cashList.rows.length === 5);
  await loginAsCash();
  async function loginAsCash() {
    await go("/login/");
    await type("#identifier", "ancien.demo");
    await type("#password", PASSWORD);
    await submit();
    await until("location.pathname === '/'", "accueil comptabilité");
    await go("/finance/soldes/");
    await until("!document.querySelector('[aria-busy=true]') && !!document.querySelector('select')", "page soldes comptabilité", 20000);
    await fire("select", String(sessionId), "change");
    check("la comptabilité ouvre la page des soldes, choisit la session et voit les boutons « Modifier »", await until("!!document.querySelector('button[aria-label^=\"Modifier le solde\"]')", "boutons Modifier (comptabilité)", 20000));
    await ev("document.querySelector('button[aria-label^=\"Se déconnecter\"]').click()");
    await until("location.pathname.startsWith('/login')", "déconnexion comptabilité");
  }
}

console.log("\n7k. Finance : échéances de paiement");
{
  const API = "http://localhost:8000";
  const apiAs = async (pseudo, password) => {
    const jar = {};
    const take = (res) => { for (const c of res.headers.getSetCookie?.() ?? []) { const [kv] = c.split(";"); const i = kv.indexOf("="); jar[kv.slice(0, i)] = kv.slice(i + 1); } };
    const base = { Origin: WEB, Referer: WEB + "/", Accept: "application/json" };
    take(await fetch(API + "/api/v1/auth/csrf", { headers: base }));
    const call = async (method, p, body) => {
      const res = await fetch(API + p, { method, headers: { ...base, "Content-Type": "application/json", Cookie: Object.entries(jar).map(([k, v]) => `${k}=${v}`).join("; "), "X-XSRF-TOKEN": decodeURIComponent(jar["XSRF-TOKEN"] ?? "") }, body: body ? JSON.stringify(body) : undefined });
      take(res);
      return { status: res.status, json: await res.json().catch(() => null) };
    };
    await call("POST", "/api/v1/auth/login", { identifier: pseudo, password });
    return call;
  };
  const BASE = "/api/v1/staff/finance/deadlines";
  const BAR = '[role=region][aria-label="Enregistrer les échéances"]';
  const reg = await apiAs("registraire.demo", PASSWORD);
  const sessionId = (await reg("GET", BASE)).json.data.sessions.find((s) => /^Soldes \(démo\)/.test(s.label)).id;
  const fire = (sel, v, kind = "input") => ev(`(() => { const e = document.querySelector(${JSON.stringify(sel)}); const proto = e.tagName === 'SELECT' ? HTMLSelectElement.prototype : HTMLInputElement.prototype; Object.getOwnPropertyDescriptor(proto, 'value').set.call(e, ${JSON.stringify(v)}); e.dispatchEvent(new Event(${JSON.stringify(kind)}, { bubbles: true })); })()`);
  const setDate = (type, rank, value) => ev(`(() => { const card = [...document.querySelectorAll('main form > div')].find(c => c.querySelector('h2')?.innerText.trim() === 'Type ${type}'); const input = card.querySelectorAll('input[type=date]')[${rank - 1}]; Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value').set.call(input, ${JSON.stringify(value)}); input.dispatchEvent(new Event('input', { bubbles: true })); })()`);
  const hintOf = async (type, rank) => (await ev(`(() => { const card = [...document.querySelectorAll('main form > div')].find(c => c.querySelector('h2')?.innerText.trim() === 'Type ${type}'); return card.querySelectorAll('input[type=date]')[${rank - 1}].closest('label').querySelector('small').innerText.replace(/\\s+/g, ' '); })()`)).replace(/[  ]/g, " ");
  const lay = async () => JSON.parse(await ev(`JSON.stringify({ scroll: document.documentElement.scrollWidth, inner: innerWidth, h1: document.querySelectorAll('h1').length, sansNom: [...document.querySelectorAll('a,button,input,select')].filter(e => !(e.getAttribute('aria-label') || e.innerText?.trim() || e.labels?.length || e.getAttribute('placeholder'))).length })`));
  const barText = () => ev(`document.querySelector('${BAR}')?.innerText.replace(/\\s+/g, ' ') ?? ''`);
  const barClick = (re) => ev(`[...document.querySelectorAll('${BAR} button')].find(b => ${re}.test(b.innerText)).click()`);
  const saveEnabled = () => ev(`(() => { const b = [...document.querySelectorAll('${BAR} button')].find(x => /Enregistrer/.test(x.innerText)); return !!b && !b.disabled; })()`);

  await go("/login/");
  await type("#identifier", "registraire.demo");
  await type("#password", PASSWORD);
  await submit();
  await until("location.pathname === '/'", "accueil registraire (échéances)");
  await go("/finance/echeances/");
  await until("!document.querySelector('[aria-busy=true]') && !!document.querySelector('select')", "page échéances", 25000);
  await fire("select", String(sessionId), "change");
  await until(`document.querySelector('select').value === '${sessionId}' && /Soldes/.test(document.querySelector('select').selectedOptions[0].innerText)`, "session choisie", 20000);
  await sleep(500);
  const heads = await ev("[...document.querySelectorAll('main form h2')].map(h => h.innerText.trim())");
  check("la session affiche les trois types du guichet (A, B, E), jamais les anciens types sans dossier ni date", heads.join() === "Type A,Type B,Type E", heads.join());
  const info = (await ev("document.querySelector('main').innerText.replace(/\\s+/g, ' ')")).replace(/[  ]/g, " ");
  check("la période, les inscriptions et l'état sont dits : « 28 sept. 2026 → 12 févr. 2027 », « Aucune inscription », « Échéances à définir »", /28 sept\. 2026 → 12 févr\. 2027/.test(info) && /Aucune inscription pour l.instant/.test(info) && /Échéances à définir/.test(info), info.slice(0, 220));
  check("sept champs de date : 1 (A) + 2 (B) + 4 (E)", (await ev("document.querySelectorAll('main form input[type=date]').length")) === 7);

  // --- l'ordre des tranches est contrôlé pendant la saisie
  await setDate("B", 1, "2026-12-11");
  await setDate("B", 2, "2026-12-11");
  check("deux tranches à la même date : message « Après la 1re tranche… » et « Enregistrer » inactif", await until(`/Après la 1re tranche/.test(document.querySelector('main').innerText) && (() => { const b = [...document.querySelectorAll('${BAR} button')].find(x => /Enregistrer/.test(x.innerText)); return !!b && b.disabled; })()`, "ordre refusé", 10000));
  check("la barre compte les erreurs", /à corriger/.test(await barText()), await barText());
  await setDate("B", 2, "2027-02-12");
  check("la date corrigée lève l'erreur et réactive « Enregistrer »", await until("!/Après la 1re tranche/.test(document.querySelector('main').innerText)", "erreur levée", 10000) && (await saveEnabled()));
  const h1 = await hintOf("B", 1);
  check("le repère dit le jour et la semaine de la session : « Vendredi, 11e semaine de la session »", /Vendredi, 11e semaine de la session/.test(h1), h1);
  await setDate("A", 1, "2027-03-01");
  const hA = await hintOf("A", 1);
  check("une date après la fin de la session est signalée : « 17 jours après la fin de la session »", /17 jours après la fin de la session/.test(hA), hA);
  await setDate("A", 1, "");
  check("la barre dit « 2 dates modifiées »", await until(`/2 dates modifiées/.test(document.querySelector('${BAR}')?.innerText ?? '')`, "barre 2 dates", 8000));
  await shot("07zk-echeances");

  // --- enregistrer
  await barClick("/Enregistrer/");
  check("l'enregistrement aboutit : « Échéances enregistrées. »", await until("/Échéances enregistrées/.test(document.querySelector('[data-sonner-toaster]')?.innerText ?? '')", "toast enregistré", 15000));
  const srv = (await reg("GET", BASE + `?session=${sessionId}`)).json.data;
  check("le serveur a les deux dates de B, aucune pour A", srv.dates.B?.["1"] === "2026-12-11" && srv.dates.B?.["2"] === "2027-02-12" && !srv.dates.A && srv.filled === 2, JSON.stringify(srv.dates));
  check("la barre disparaît et l'état devient « 2 dates sur 7 »", await until(`!document.querySelector('${BAR}') && /2 dates sur 7/.test(document.querySelector('main').innerText)`, "état mis à jour", 15000));
  check("la liste des sessions n'indique plus « — à définir » pour celle-ci", !/— à définir/.test(await ev("document.querySelector('select').selectedOptions[0].innerText")));

  // --- annuler les changements, retirer une date
  await setDate("B", 1, "2026-12-18");
  await until(`/1 date modifiée/.test(document.querySelector('${BAR}')?.innerText ?? '')`, "barre 1 date", 8000);
  await barClick("/Annuler les changements/");
  check("« Annuler les changements » remet la date enregistrée et masque la barre", await until(`!document.querySelector('${BAR}')`, "barre masquée", 8000) && (await ev("document.querySelectorAll('main form input[type=date]')[1].value")) === "2026-12-11");
  await setDate("B", 2, "");
  await barClick("/Enregistrer/");
  await until("/Échéances enregistrées/.test(document.querySelector('[data-sonner-toaster]')?.innerText ?? '')", "retrait enregistré", 15000);
  const after = (await reg("GET", BASE + `?session=${sessionId}`)).json.data;
  check("vider un champ retire la tranche côté serveur (il ne reste que la 1re tranche de B)", after.dates.B?.["1"] === "2026-12-11" && !after.dates.B?.["2"], JSON.stringify(after.dates));
  const l1 = await lay();
  check("page des échéances : aucun défilement horizontal, un seul h1, contrôles nommés", l1.scroll <= l1.inner && l1.h1 === 1 && l1.sansNom === 0, JSON.stringify(l1));
  await ev("document.querySelector('button[aria-label^=\"Se déconnecter\"]').click()");
  await until("location.pathname.startsWith('/login')", "déconnexion registraire (échéances)");

  // --- les droits : la comptabilité y accède, un étudiant non
  const cash = await apiAs("ancien.demo", PASSWORD);
  const student = await apiAs("etudiant.demo", PASSWORD);
  const okCash = [await cash("GET", BASE + `?session=${sessionId}`), await cash("PUT", BASE + `/${sessionId}`, { dates: { A: { 1: "2026-12-11" } } })];
  check("la comptabilité lit et enregistre les échéances (200)", okCash.every((r) => r.status === 200), okCash.map((r) => r.status).join("/"));
  const denied = [await student("GET", BASE), await student("PUT", BASE + `/${sessionId}`, {})];
  check("un étudiant n'ouvre ni la liste ni l'enregistrement, même par l'API (403, avant toute validation)", denied.every((r) => r.status === 403), denied.map((r) => r.status).join("/"));
  const bad = [await reg("PUT", BASE + `/${sessionId}`, { dates: { B: { 1: "2026-12-11", 2: "2026-12-11" } } }), await reg("PUT", BASE + `/${sessionId}`, { dates: { Z: { 1: "2026-12-11" } } }), await reg("PUT", BASE + "/99999999", { dates: {} })];
  check("côté serveur aussi : ordre refusé (422), type inconnu (422), session inconnue (404)", bad.map((r) => r.status).join("/") === "422/422/404", bad.map((r) => r.status).join("/"));
}

console.log("\n7l. Finance : barème par session et frais supplémentaires");
{
  const API = "http://localhost:8000";
  const apiAs = async (pseudo, password) => {
    const jar = {};
    const take = (res) => { for (const c of res.headers.getSetCookie?.() ?? []) { const [kv] = c.split(";"); const i = kv.indexOf("="); jar[kv.slice(0, i)] = kv.slice(i + 1); } };
    const base = { Origin: WEB, Referer: WEB + "/", Accept: "application/json" };
    take(await fetch(API + "/api/v1/auth/csrf", { headers: base }));
    const call = async (method, p, body) => {
      const res = await fetch(API + p, { method, headers: { ...base, "Content-Type": "application/json", Cookie: Object.entries(jar).map(([k, v]) => `${k}=${v}`).join("; "), "X-XSRF-TOKEN": decodeURIComponent(jar["XSRF-TOKEN"] ?? "") }, body: body ? JSON.stringify(body) : undefined });
      take(res);
      return { status: res.status, json: await res.json().catch(() => null) };
    };
    await call("POST", "/api/v1/auth/login", { identifier: pseudo, password });
    return call;
  };
  const BASE = "/api/v1/staff/finance/tariff";
  const YEAR = "2098 - 2099";
  const reg = await apiAs("registraire.demo", PASSWORD);
  const serverCard = async (sigle, semester = 1) => (await reg("GET", BASE + `?annee=${encodeURIComponent(YEAR)}&semestre=${semester}`)).json.data.mentions.find((m) => m.sigle === sigle);
  const fire = (sel, v, kind = "input") => ev(`(() => { const e = document.querySelector(${JSON.stringify(sel)}); const proto = e.tagName === 'SELECT' ? HTMLSelectElement.prototype : HTMLInputElement.prototype; Object.getOwnPropertyDescriptor(proto, 'value').set.call(e, ${JSON.stringify(v)}); e.dispatchEvent(new Event(${JSON.stringify(kind)}, { bubbles: true })); })()`);
  const cardOf = (label) => `[...document.querySelectorAll('main h2')].find(h => h.innerText.trim() === ${JSON.stringify(label)})?.closest('[data-slot="card"]')`;
  const setAmount = (label, field, status, value) => ev(`(() => { const card = ${cardOf(label)}; const input = [...card.querySelectorAll('input')].find(i => i.getAttribute('aria-label') === ${JSON.stringify(field + " — " + status)}); Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value').set.call(input, ${JSON.stringify(value)}); input.dispatchEvent(new Event('input', { bubbles: true })); })()`);
  const amount = (label, field, status) => ev(`(() => { const card = ${cardOf(label)}; return [...card.querySelectorAll('input')].find(i => i.getAttribute('aria-label') === ${JSON.stringify(field + " — " + status)})?.value; })()`);
  const cardText = (label) => ev(`${cardOf(label)}.innerText.replace(/\\s+/g, ' ')`);
  const cardClick = (label, re) => ev(`(() => { const card = ${cardOf(label)}; const b = [...card.querySelectorAll('button')].find(x => ${re}.test(x.innerText)); if (!b || b.disabled) return false; b.click(); return true; })()`);
  const lay = async () => JSON.parse(await ev(`JSON.stringify({ scroll: document.documentElement.scrollWidth, inner: innerWidth, h1: document.querySelectorAll('h1').length, sansNom: [...document.querySelectorAll('a,button,input,select')].filter(e => !(e.getAttribute('aria-label') || e.innerText?.trim() || e.labels?.length || e.getAttribute('placeholder'))).length })`));

  await go("/login/");
  await type("#identifier", "registraire.demo");
  await type("#password", PASSWORD);
  await submit();
  await until("location.pathname === '/'", "accueil registraire (barème)");
  await go("/finance/bareme/");
  await until("!document.querySelector('[aria-busy=true]') && !!document.querySelector('select')", "page barème", 25000);
  await ev("window.confirm = () => true;");
  await fire("select", YEAR, "change");
  await until(`document.querySelector('select').value === ${JSON.stringify(YEAR)} && [...document.querySelectorAll('main h2')].some(h => h.innerText.trim() === 'Informatique')`, "année choisie", 20000);
  const titles = await ev("[...document.querySelectorAll('main h2')].map(h => h.innerText.trim())");
  check("les filières OUVERTES ont une carte (Informatique, Gestion), pas celles qui sont fermées (Éducation)", titles.includes("Informatique") && titles.includes("Gestion") && !titles.includes("Éducation"), titles.join());
  const head = (await ev("document.querySelector('main').innerText.replace(/\\s+/g, ' ')"));
  check("la page dit que la session n'a aucun ajustement : « hérite du barème de base »", /Aucun ajustement : hérite du barème de base/.test(head));
  check("la carte d'une filière sans ajustement dit « Hérite du barème de base »", /Hérite du barème de base/.test(await cardText("Informatique")));
  check("les montants du barème de base s'affichent (Licence 1, interne : frais généraux 100000, écolage 20000)", (await amount("Informatique", "Frais généraux / semestre", "Interne")) === "100000" && (await amount("Informatique", "Écolage (multiplié par le nombre de crédits)", "Interne")) === "20000", await amount("Informatique", "Frais généraux / semestre", "Interne"));
  check("au semestre 1, ni frais de graduation ni frais de costume pour Informatique", !/graduation|costume/i.test(await cardText("Informatique")));
  await shot("07zl-bareme");

  // --- ajuster une filière : la surcharge naît de la base
  await setAmount("Informatique", "Frais généraux / semestre", "Interne", "abc");
  check("un montant illisible est signalé, et « Enregistrer » est désactivé", await until(`/un nombre positif ou nul est attendu/.test((${cardOf("Informatique")})?.innerText ?? '') && (() => { const b = [...(${cardOf("Informatique")}).querySelectorAll('button')].find(x => /^Enregistrer$/.test(x.innerText)); return !!b && b.disabled; })()`, "montant invalide", 8000));
  await setAmount("Informatique", "Frais généraux / semestre", "Interne", "120 000");
  check("la carte compte « 1 montant modifié » et propose d'enregistrer", await until(`/1 montant modifié/.test((${cardOf("Informatique")})?.innerText ?? '')`, "1 modifié", 8000));
  await cardClick("Informatique", "/^Enregistrer$/");
  check("l'enregistrement aboutit : « enregistré (1 ligne(s), dont 1 nouvelle(s)) »", await until("/enregistré \\(1 ligne\\(s\\), dont 1 nouvelle\\(s\\)\\)/.test(document.querySelector('[data-sonner-toaster]')?.innerText ?? '')", "toast enregistré", 15000));
  const info = await serverCard("INFO");
  const cell = info.grid["1|Interne"];
  check("côté serveur : la surcharge existe, 120 000 pour les frais généraux, et l'écolage (non transmis) est HÉRITÉ de la base (20 000, pas 0)", cell.source === "session" && cell.values.frais_generaux === 120000 && cell.values.ecolage === 20000 && info.adjusted === true, JSON.stringify(cell));
  check("l'autre statut (Externe), non modifié, reste celui de la base", info.grid["1|Externe"].source === "base" && info.grid["1|Externe"].values.frais_generaux === 90000);
  check("la page dit « Tarif propre à cette session » et « 1 / n filière(s) ajustée(s) »", await until("/Tarif propre à cette session/.test(document.body.innerText) && /1 \\/ \\d+ filières? ajustées?/.test(document.body.innerText)", "tarif propre", 15000));

  // --- appliquer les mêmes montants à une autre filière
  await setAmount("Informatique", "Écolage (multiplié par le nombre de crédits)", "Externe", "21000");
  await until(`/1 montant modifié/.test((${cardOf("Informatique")})?.innerText ?? '')`, "modifié (externe)", 8000);
  await ev(`(() => { const card = ${cardOf("Informatique")}; card.querySelector('details summary').click(); })()`);
  await ev(`(() => { const card = ${cardOf("Informatique")}; const box = [...card.querySelectorAll('details label')].find(l => /Gestion/.test(l.innerText)).querySelector('input'); box.click(); })()`);
  check("« Appliquer aussi à… » propose les autres filières ouvertes et compte la sélection", /Appliquer aussi à d’autres filières \(1\)/.test(await cardText("Informatique")));
  await cardClick("Informatique", "/Enregistrer et appliquer/");
  check("l'application à deux filières est confirmée : « appliqué à 2 filières »", await until("/appliqué à 2 filières/.test(document.querySelector('[data-sonner-toaster]')?.innerText ?? '')", "toast appliqué", 15000));
  const gest = await serverCard("GEST");
  check("la filière Gestion a reçu le même écolage externe (21 000) en surcharge, le reste hérité de sa base", gest.adjusted === true && gest.grid["1|Externe"].source === "session" && gest.grid["1|Externe"].values.ecolage === 21000 && gest.grid["1|Externe"].values.frais_generaux === 85000, JSON.stringify(gest.grid["1|Externe"]));

  // --- retour à la base, puis reprise de la base
  await until(`/Tarif propre à cette session/.test((${cardOf("Gestion")})?.innerText ?? '')`, "carte Gestion propre", 15000);
  await cardClick("Gestion", "/Revenir à la base/");
  check("« Revenir à la base » supprime le tarif propre de Gestion (le serveur le confirme)", await until("/supprimée/.test(document.querySelector('[data-sonner-toaster]')?.innerText ?? '')", "toast supprimé", 15000) && (await serverCard("GEST")).adjusted === false);
  await until(`/Hérite du barème de base/.test((${cardOf("Gestion")})?.innerText ?? '')`, "Gestion hérite de nouveau", 15000);
  await cardClick("Gestion", "/Reprendre la base/");
  check("« Reprendre la base » recrée les lignes propres à la session à partir de la base", await until("/reprise/.test(document.querySelector('[data-sonner-toaster]')?.innerText ?? '')", "toast repris", 15000) && (await serverCard("GEST")).adjusted === true);

  // --- le semestre 2 montre la graduation, et le barème de base se modifie sans surcharge
  await ev("[...document.querySelectorAll('[role=tab]')].find(b => /Deuxième semestre/.test(b.innerText)).click()");
  check("au semestre 2, le frais de graduation apparaît", await until(`/Frais de graduation/.test((${cardOf("Informatique")})?.innerText ?? '')`, "graduation S2", 15000));
  check("le semestre 2 n'a aucun ajustement et hérite de la base", /Aucun ajustement : hérite du barème de base/.test(await ev("document.querySelector('main').innerText.replace(/\\s+/g, ' ')")));

  // --- frais supplémentaires
  await ev("[...document.querySelectorAll('main details')].find(d => /Frais supplémentaires/.test(d.querySelector('summary').innerText)).querySelector('summary').click()");
  await fire("input[aria-label='Nom du frais à ajouter']", "Démo Photocopies");
  await ev("[...document.querySelectorAll('main details form button')].find(b => /Ajouter/.test(b.innerText)).click()");
  check("un frais supplémentaire s'ajoute : confirmation et ligne dans la liste", await until("/Frais ajouté/.test(document.querySelector('[data-sonner-toaster]')?.innerText ?? '') && /Démo Photocopies/.test(document.querySelector('main details').innerText)", "frais ajouté", 15000));
  const fee = (await reg("GET", BASE + `?annee=${encodeURIComponent(YEAR)}&semestre=1`)).json.data.extra_fees.find((f) => f.label === "Démo Photocopies");
  check("côté serveur : le frais est actif, facturé à « aucune » condition, tous les semestres", fee && fee.active === true && fee.condition === "aucune" && fee.semesters === "", JSON.stringify(fee));
  await ev("[...document.querySelectorAll('[role=tab]')].find(b => /Premier semestre/.test(b.innerText)).click()");
  await until(`/Démo Photocopies/.test((${cardOf("Informatique")})?.innerText ?? '')`, "ligne du frais dans la carte", 15000);
  await setAmount("Informatique", "Démo Photocopies", "Interne", "5000");
  await until(`/1 montant modifié/.test((${cardOf("Informatique")})?.innerText ?? '')`, "frais modifié", 8000);
  await cardClick("Informatique", "/^Enregistrer$/");
  check("le montant du frais s'enregistre pour la session (« 1 montant(s) de frais supplémentaires »)", await until("/1 montant\\(s\\) de frais supplémentaires/.test(document.querySelector('[data-sonner-toaster]')?.innerText ?? '')", "toast frais", 15000));
  const withFee = await serverCard("INFO");
  check("côté serveur : 5 000 pour le frais, niveau 1 interne, propre à la session", withFee.extra[fee.id]["1|Interne"].amount === 5000 && withFee.extra[fee.id]["1|Interne"].inherited === false, JSON.stringify(withFee.extra[fee.id]["1|Interne"]));
  // régler le frais : semestre 2 seulement, puis le désactiver
  await ev("(() => { const d = [...document.querySelectorAll('main details')].find(x => /Frais supplémentaires/.test(x.querySelector('summary').textContent)); if (d && !d.open) d.querySelector('summary').click(); })()");
  await until("[...document.querySelectorAll('main details li')].some(l => /Démo Photocopies/.test(l.textContent))", "frais dans la liste", 15000);
  await ev(`(() => { const li = [...document.querySelectorAll('main details li')].find(l => /Démo Photocopies/.test(l.textContent)); const boxes = [...li.querySelectorAll('input[type=checkbox]')]; boxes[1].click(); })()`);
  await ev(`(() => { const li = [...document.querySelectorAll('main details li')].find(l => /Démo Photocopies/.test(l.textContent)); [...li.querySelectorAll('button')].find(b => /Appliquer/.test(b.innerText)).click(); })()`);
  check("le réglage « semestre 2 seulement » est enregistré", await until("/Réglage enregistré/.test(document.querySelector('[data-sonner-toaster]')?.innerText ?? '')", "réglage", 15000) && (await reg("GET", BASE)).json.data.extra_fees.find((f) => f.id === fee.id).semesters === "2");
  await ev(`(() => { const li = [...document.querySelectorAll('main details li')].find(l => /Démo Photocopies/.test(l.textContent)); li.querySelector('button[aria-label^="Désactiver"]').click(); })()`);
  check("désactiver le frais le garde dans la liste, marqué « désactivé » (montants et historique conservés)", await until("/désactivé/.test(document.querySelector('main details')?.innerText ?? '') && /Frais désactivé/.test(document.querySelector('[data-sonner-toaster]')?.innerText ?? '')", "frais désactivé", 15000));
  const l1 = await lay();
  check("page du barème : aucun défilement horizontal, un seul h1, contrôles nommés", l1.scroll <= l1.inner && l1.h1 === 1 && l1.sansNom === 0, JSON.stringify(l1));
  await ev("document.querySelector('button[aria-label^=\"Se déconnecter\"]').click()");
  await until("location.pathname.startsWith('/login')", "déconnexion registraire (barème)");

  // --- les droits : niveaux 1 à 3
  const cash = await apiAs("ancien.demo", PASSWORD);
  const student = await apiAs("etudiant.demo", PASSWORD);
  const denied = [await cash("GET", BASE), await cash("PUT", BASE, { session_id: 0 }), await student("GET", BASE), await student("POST", BASE + "/reset", {}), await student("PATCH", "/api/v1/staff/finance/extra-fees/1", {})];
  check("la comptabilité et un étudiant n'ouvrent ni le barème ni ses écritures, même par l'API (403, avant toute validation)", denied.every((r) => r.status === 403), denied.map((r) => r.status).join("/"));
  const bad = [await reg("PUT", BASE, { session_id: 0, mention: "INFO", semester: 1, amounts: { "1|Interne": { ecolage: -5 } } }), await reg("PUT", BASE, { session_id: 0, mention: "ZZZ", semester: 1, amounts: { "1|Interne": { ecolage: 5 } } }), await reg("POST", BASE + "/reset", { session_id: 0 })];
  check("côté serveur : montant négatif (422), filière inconnue (422), suppression du barème de base (422)", bad.map((r) => r.status).join("/") === "422/422/422", bad.map((r) => r.status).join("/"));
}


console.log("\n7m. Documents : fiche d'inscription du personnel (PDF serveur)");
{
  const API = "http://localhost:8000";
  const apiAs = async (pseudo, password) => {
    const jar = {};
    const take = (res) => { for (const c of res.headers.getSetCookie?.() ?? []) { const [kv] = c.split(";"); const i = kv.indexOf("="); jar[kv.slice(0, i)] = kv.slice(i + 1); } };
    const base = { Origin: WEB, Referer: WEB + "/", Accept: "application/json" };
    take(await fetch(API + "/api/v1/auth/csrf", { headers: base }));
    const call = async (method, p, body) => {
      const res = await fetch(API + p, { method, headers: { ...base, "Content-Type": "application/json", Cookie: Object.entries(jar).map(([k, v]) => `${k}=${v}`).join("; "), "X-XSRF-TOKEN": decodeURIComponent(jar["XSRF-TOKEN"] ?? "") }, body: body ? JSON.stringify(body) : undefined });
      take(res);
      return { status: res.status, json: await res.json().catch(() => null) };
    };
    await call("POST", "/api/v1/auth/login", { identifier: pseudo, password });
    return call;
  };
  const reg = await apiAs("registraire.demo", PASSWORD);
  const row = (await reg("GET", "/api/v1/staff/lists/students?q=DEMO-0001")).json.data.rows.find((r) => r.student_id === "DEMO-0001");
  check("le registraire retrouve l'étudiant de démonstration dans la liste (identifiant de ligne)", !!row?.id, JSON.stringify(row));
  const rowId = row.id;
  const listed = await reg("GET", `/api/v1/staff/students/${rowId}/enrollment-sheets`);
  check("l'API liste les inscriptions de l'étudiant (session, niveau, nombre de cours, frais)", listed.status === 200 && listed.json.data.student_id === "DEMO-0001" && listed.json.data.sessions.map((s) => s.session_name).join() === "Session 2,Session 1" && listed.json.data.sessions.every((s) => s.year === "2026 - 2027"), JSON.stringify(listed.json).slice(0, 200));

  await go("/login/");
  await type("#identifier", "registraire.demo");
  await type("#password", PASSWORD);
  await submit();
  await until("location.pathname === '/'", "accueil registraire (documents)");
  await go(`/etudiants/fiche/?id=${rowId}`);
  await until("document.querySelectorAll('[data-slot=documents] li').length >= 2", "bloc Documents (fiches d'inscription chargées)", 25000);
  const docs = await ev("document.querySelector('[data-slot=documents]').innerText.replace(/\\s+/g, ' ')");
  check("la fiche de l'étudiant a un bloc « Documents » : l'année, la session et le nombre de cours", /Documents/.test(docs) && /2026 - 2027/.test(docs) && /Session 1/.test(docs) && /Session 2/.test(docs) && /cours/.test(docs), docs.slice(0, 200));

  rmSync(join(OUT, "DEMO-0001-fiche-inscription-Session-1-2026-2027.pdf"), { force: true });
  await ev("[...document.querySelectorAll('[data-slot=documents] button')].find(b => /Session 1 2026 - 2027/.test(b.getAttribute('aria-label') ?? '')).click()");
  check("le PDF se télécharge depuis la fiche (message avec le nom du fichier)", await until("/PDF téléchargé : DEMO-0001-fiche-inscription-Session-1-2026-2027\.pdf/.test(document.querySelector('[data-slot=documents]').innerText)", "téléchargement du personnel", 30000));
  await sleep(500);
  const staffPdf = join(OUT, "DEMO-0001-fiche-inscription-Session-1-2026-2027.pdf");
  check("le fichier reçu est un vrai PDF (en-tête %PDF-, plus de 5 Ko, photo intégrée)", existsSync(staffPdf) && readFileSync(staffPdf).subarray(0, 5).toString("latin1") === "%PDF-" && statSync(staffPdf).size > 5000 && readFileSync(staffPdf).toString("latin1").includes("/Subtype /Image"), existsSync(staffPdf) ? String(statSync(staffPdf).size) : "absent");
  await shot("07m-documents-personnel");
  const lay = JSON.parse(await ev(`JSON.stringify({ scroll: document.documentElement.scrollWidth, inner: innerWidth, h1: document.querySelectorAll('h1').length, sansNom: [...document.querySelectorAll('a,button,input,select')].filter(e => !(e.getAttribute('aria-label') || e.innerText || e.title || '').trim()).length })`));
  check("fiche étudiant avec documents : aucun défilement horizontal, un seul h1, contrôles nommés", lay.scroll <= lay.inner && lay.h1 === 1 && lay.sansNom === 0, JSON.stringify(lay));
  // --- relevé semestriel des notes (toutes les sessions, publiées ou non)
  const tOpts = await reg("GET", `/api/v1/staff/students/${rowId}/transcript`);
  check("l'API liste les sessions avec notes, y compris la session NON publiée que l'étudiant ne voit pas", tOpts.status === 200 && tOpts.json.data.sessions.length === 2 && tOpts.json.data.sessions.map((s) => s.session_name).join() === "Session 1,Session 2", JSON.stringify(tOpts.json).slice(0, 220));
  // Chrome demande une autorisation pour un second téléchargement automatique sur la même page : on recharge, comme le ferait un second clic de l'utilisateur.
  await go(`/etudiants/fiche/?id=${rowId}`);
  await until("!!document.querySelector('[data-slot=transcript] select')", "sélecteur du relevé", 20000);
  const choices = await ev("[...document.querySelectorAll('[data-slot=transcript] select option')].map(o => o.textContent.trim())");
  check("le sélecteur propose tout l'historique puis chaque session avec son nombre de cours", choices[0] === "Tout l’historique (2 sessions)" && choices.some((c) => /^Session 1 · 2025-2026 \(5 cours\)$/.test(c)) && choices.some((c) => /^Session 2 · 2025-2026 \(1 cours\)$/.test(c)), JSON.stringify(choices));
  rmSync(join(OUT, "DEMO-0001-releve-semestriel.pdf"), { force: true });
  await ev("[...document.querySelectorAll('[data-slot=transcript] button')].find(b => /Télécharger le relevé/.test(b.getAttribute('aria-label') ?? '')).click()");
  check("le relevé se télécharge depuis la fiche (message avec le nom du fichier)", await until("/PDF téléchargé : DEMO-0001-releve-semestriel\\.pdf/.test(document.querySelector('[data-slot=transcript]').innerText)", "téléchargement du relevé", 30000));
  await sleep(500);
  const trPdf = join(OUT, "DEMO-0001-releve-semestriel.pdf");
  check("le relevé reçu est un vrai PDF (en-tête %PDF-, plus de 3 Ko, photo intégrée)", existsSync(trPdf) && readFileSync(trPdf).subarray(0, 5).toString("latin1") === "%PDF-" && statSync(trPdf).size > 3000 && readFileSync(trPdf).toString("latin1").includes("/Subtype /Image"), existsSync(trPdf) ? String(statSync(trPdf).size) : "absent");
  const trYear = await reg("GET", `/api/v1/staff/students/${rowId}/transcript/pdf?year=2025-2026`);
  check("le relevé d'une année est servi (200)", trYear.status === 200);
  const trBad = [await reg("GET", `/api/v1/staff/students/${rowId}/transcript/pdf?year=2O25`), await reg("GET", `/api/v1/staff/students/${rowId}/transcript/pdf?semester=9`), await reg("GET", `/api/v1/staff/students/999999/transcript/pdf`)];
  check("côté serveur : année illisible (422), semestre hors limites (422), étudiant inconnu (404)", trBad.map((r) => r.status).join("/") === "422/422/404", trBad.map((r) => r.status).join("/"));
  await shot("07n-releve-semestriel");
  await ev("document.querySelector('button[aria-label^=\"Se déconnecter\"]').click()");
  await until("location.pathname.startsWith('/login')", "déconnexion registraire (documents)");

  // --- les droits : niveaux 1 à 4 ; le reste est refusé par le serveur, avant toute recherche
  const cash = await apiAs("ancien.demo", PASSWORD);
  const sup = await apiAs("superadmin.demo", PASSWORD);
  const student = await apiAs("etudiant.demo", PASSWORD);
  const url = `/api/v1/staff/students/${rowId}/enrollment-sheets`;
  const sessionId = listed.json.data.sessions[0].session_id;
  check("la comptabilité (niveau 4) et le superadmin ouvrent la liste et le PDF", (await cash("GET", url)).status === 200 && (await sup("GET", url)).status === 200);
  const denied = [await student("GET", url), await student("GET", `${url}/${sessionId}/pdf`), await student("GET", `/api/v1/staff/students/999999/enrollment-sheets`)];
  check("un étudiant n'ouvre ni la liste ni le PDF, même par l'API (403 avant toute recherche : même réponse pour un étudiant inexistant)", denied.every((r) => r.status === 403 && r.json?.code === "forbidden.documents"), denied.map((r) => r.status).join("/"));
  const missing = [await reg("GET", "/api/v1/staff/students/999999/enrollment-sheets"), await reg("GET", `${url}/999999/pdf`)];
  check("étudiant ou session inconnus : 404", missing.every((r) => r.status === 404), missing.map((r) => r.status).join("/"));
}

console.log("\n8. Journal de la console et du réseau");
check("aucune erreur JavaScript ni requête en échec", problems.length === 0, problems.slice(0, 3).join(" | "));

ws.close(); chrome.kill();
const failed = results.filter((r) => !r.ok);
console.log(`\n${results.length - failed.length}/${results.length} vérifications réussies. Captures : ${OUT}`);
process.exit(failed.length ? 1 : 0);
