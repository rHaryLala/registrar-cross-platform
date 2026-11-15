import re, csv, pathlib, collections

ROOT = pathlib.Path(__file__).resolve().parents[1]
LEGACY = pathlib.Path(r'C:/laragon/www/registrar')  # ancien dépôt
routes_php = (LEGACY / 'routes.php').read_text(encoding='utf-8')

usage = {}
for row in csv.DictReader(open(ROOT / 'docs/reference/usage-pages-brut.tsv', encoding='utf-8'), delimiter='\t'):
    k = (row['chemin'].split('?')[0]).rstrip('/') or '/'
    u = usage.setdefault(k, {'appels': 0, 'users': 0, 'niv': set(), 'post': 0, 'ms': 0})
    n = int(row['appels'])
    u['appels'] += n
    u['users'] = max(u['users'], int(row['utilisateurs']))
    u['niv'] |= set(x for x in row['niveaux'].split(',') if x)
    if row['methode'] == 'POST':
        u['post'] += n
    u['ms'] = max(u['ms'], int(row['duree_moy_ms'] or 0))

rx = re.compile(r"^\$router->(get|post|any)\('([^']+)',\s*(?:'([^']+)'|function)[^;]*;\s*(?://\s*(.*))?$", re.M)
rows = []
for m in rx.finditer(routes_php):
    verb, path, ctrl, note = m.group(1), m.group(2), m.group(3), (m.group(4) or '').strip()
    rows.append({'path': path, 'verb': verb, 'ctrl': ctrl or 'redirection', 'note': note})


def module(p):
    if p in ('/', '/formations', '/admissions', '/campus', '/contact') or p.startswith('/admissions') or p.startswith('/newsletter'):
        return 'Public (vitrine, admissions, newsletter)'
    if p in ('/login', '/logout', '/loading', '/choisir-espace', '/goodbye'):
        return 'Authentification'
    if p.startswith('/student') or p.startswith('/students'):
        return 'Étudiant'
    if p.startswith('/teacher'):
        return 'Enseignant'
    if p.startswith('/finance'):
        return 'Finance'
    if p.startswith('/inscription'):
        return 'Inscriptions'
    if p.startswith('/export') or 'topdf' in p or p == '/sheet':
        return 'Exports et impressions'
    if p.startswith('/queue'):
        return "File d'attente"
    if p.startswith('/evaluation') or p.startswith('/api/evaluation'):
        return 'Évaluations et sondages'
    if p in ('/fusion-pdf', '/qr-generator', '/retouche-photo', '/studio'):
        return 'Outils (Studio UAZ, QR, retouche, fusion PDF)'
    if p == '/messagerie':
        return 'Messagerie'
    if p.startswith('/work-education'):
        return 'Work Education'
    if p.startswith('/api/') or p in ('/manifest.webmanifest', '/espace'):
        return 'API, services et application installable'
    if p in ('/reel', '/button', '/verify'):
        return 'Divers'
    if p.startswith('/candidatures'):
        return 'Admissions (personnel)'
    return 'Registrariat et administration'


def prio(u):
    if not u:
        return 'P3 — jamais ouverte (2 mois)'
    if u['users'] >= 20 or u['appels'] >= 500:
        return 'P1 — très utilisée'
    return 'P2 — utilisée'


DONE = {
    '/': 'Fait : accueil étudiant, connexion, déconnexion (tranche 1)',
    '/login': 'Fait (tranche 1)',
    '/logout': 'Fait (tranche 1)',
    '/student/home': 'Fait : accueil étudiant (tranche 1)',
    '/student/notes': 'Fait : Mes notes (tranche 2), parité vérifiée sur 180 étudiants',
    '/student/presence': 'Fait : Mon assiduité (tranche 3), parité vérifiée : 94 registres, 0 écart',
}
for r in rows:
    r['module'] = module(r['path'])
    r['u'] = usage.get(r['path'].rstrip('/') or '/')
    r['prio'] = prio(r['u'])
    r['etat'] = DONE.get(r['path'], 'À faire')
    if r['ctrl'] == 'redirection':
        r['etat'] = 'Sans objet (redirection de compatibilité)'
        r['prio'] = '—'

known = {(r['path'].rstrip('/') or '/') for r in rows}
hors = sorted([(k, v) for k, v in usage.items() if k not in known and not k.startswith('/_next')], key=lambda kv: -kv[1]['appels'])

LV = {'1': 'superadmin', '2': 'admin', '3': 'registraire', '4': 'compta', '5': 'média', '6': 'chef mention', '7': 'enseignant', '8': 'étudiant', '9': 'work ed.'}


def roles(u):
    return ', '.join(LV.get(x, x) for x in sorted(u['niv'], key=int)) if u and u['niv'] else '—'


out = ["# Inventaire des pages de l'ancienne application", '',
       f"*Généré le 14/11/2026 à partir de `routes.php` ({len(rows)} routes déclarées), du menu par rôle (`init/menubar.php`) et du journal d'audit de production (15/09 → 14/11/2026). Aucune donnée personnelle : comptages seulement.*", '',
       "**Priorité** : P1 = au moins 20 utilisateurs ou 500 appels en deux mois ; P2 = ouverte au moins une fois ; P3 = jamais ouverte (reste servie par l'ancien PHP tant que personne ne la demande). **Rappel** : 1 152 fichiers de pages existent dans `src/` ; seules ~74 adresses ont été ouvertes.", '']
by = collections.defaultdict(list)
for r in rows:
    by[r['module']].append(r)
tot = collections.Counter(r['prio'] for r in rows if r['ctrl'] != 'redirection')
order = sorted(by, key=lambda m: -sum(1 for r in by[m] if r['prio'].startswith('P1')))

out += ['## Synthèse', '', '| Module | Routes | P1 | P2 | P3 | Fait |', '|---|---|---|---|---|---|']
for mod in order:
    rs = [r for r in by[mod] if r['ctrl'] != 'redirection']
    if not rs:
        continue
    out.append(f"| {mod} | {len(rs)} | {sum(r['prio'].startswith('P1') for r in rs)} | {sum(r['prio'].startswith('P2') for r in rs)} | {sum(r['prio'].startswith('P3') for r in rs)} | {sum(r['etat'].startswith('Fait') for r in rs)} |")
out += ['', f"Total : {sum(tot.values())} routes (hors redirections) : P1 = {tot['P1 — très utilisée']}, P2 = {tot['P2 — utilisée']}, P3 = {tot['P3 — jamais ouverte (2 mois)']}.", '']

for mod in order:
    rs = sorted([r for r in by[mod] if r['ctrl'] != 'redirection'], key=lambda r: -(r['u']['appels'] if r['u'] else 0))
    if not rs:
        continue
    out += ['', f'## {mod}', '', "| Adresse | Priorité | Appels | Utilisateurs | Rôles qui l'ouvrent | Contrôleur | État |", '|---|---|---|---|---|---|---|']
    for r in rs:
        u = r['u']
        note = f" — {r['note'][:70]}" if r['note'] else ''
        out.append(f"| `{r['path']}`{note} | {r['prio']} | {u['appels'] if u else 0} | {u['users'] if u else 0} | {roles(u)} | {r['ctrl']} | {r['etat']} |")

out += ['', '## Adresses ouvertes mais absentes de `routes.php` (fichiers appelés directement)', '', '| Adresse | Appels | Utilisateurs | Rôles | Durée moy. (ms) |', '|---|---|---|---|---|']
for k, v in hors[:45]:
    out.append(f"| `{k}` | {v['appels']} | {v['users']} | {roles(v)} | {v['ms']} |")

(ROOT / 'docs/INVENTAIRE-PAGES.md').write_text('\n'.join(out) + '\n', encoding='utf-8')
print(f"{len(rows)} routes | P1={tot['P1 — très utilisée']} P2={tot['P2 — utilisée']} P3={tot['P3 — jamais ouverte (2 mois)']} | hors routes.php utilisées: {len(hors)}")
for mod in order[:7]:
    top = sorted([x for x in by[mod] if x['ctrl'] != 'redirection'], key=lambda r: -(r['u']['appels'] if r['u'] else 0))[:4]
    print(' ', mod, '->', [(r['path'], r['u']['appels'] if r['u'] else 0) for r in top])
