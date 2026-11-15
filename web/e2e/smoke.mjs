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
import { mkdtempSync, writeFileSync, mkdirSync, existsSync, readFileSync, statSync } from "node:fs";
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
const watchdog = setTimeout(() => { console.log("  ECHEC  Le test a dépassé 6 minutes (blocage)."); process.exit(2); }, 360000);
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
const leak = JSON.parse(await ev(`(async () => { const list = (await (await fetch('http://localhost:8000/api/v1/me/news', { credentials: 'include' })).json()).data.items.map(i => i.id); const max = Math.max(...list); const out = { visibles: list.length, caches: 0, fuites: [] }; for (let id = 1; id <= max + 3; id++) { if (list.includes(id)) continue; const r = await fetch('http://localhost:8000/api/v1/me/news/' + id, { credentials: 'include', headers: { Accept: 'application/json' } }); const im = await fetch('http://localhost:8000/api/v1/me/news/' + id + '/image', { credentials: 'include', headers: { Accept: 'application/json' } }); out.caches++; if (r.status !== 404 || im.status !== 404) out.fuites.push(id); } return JSON.stringify(out); })()`));
check("aucune annonce masquée (programmée, expirée, désactivée) n'est lisible ni par son identifiant ni par son image", leak.fuites.length === 0 && leak.caches >= 3 && leak.visibles === 4, JSON.stringify(leak));
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
await go("/quiz/");
check("un compte du personnel sur « /quiz/ » : explication claire, pas de classement", await until("/réservée aux étudiants/.test(document.body.innerText)", "autre rôle quiz") && !/Tojo A\./.test(await text()));

console.log("\n8. Journal de la console et du réseau");
check("aucune erreur JavaScript ni requête en échec", problems.length === 0, problems.slice(0, 3).join(" | "));

ws.close(); chrome.kill();
const failed = results.filter((r) => !r.ok);
console.log(`\n${results.length - failed.length}/${results.length} vérifications réussies. Captures : ${OUT}`);
process.exit(failed.length ? 1 : 0);
