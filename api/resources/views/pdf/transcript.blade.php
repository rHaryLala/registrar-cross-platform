@php
    use App\Domain\Student\EnrollmentRules;
    /** 12,5 · 14 · 37,5 : virgule décimale, aucun zéro inutile. */
    $n = fn ($v) => $v === null ? '--' : rtrim(rtrim(number_format((float) $v, 2, ',', "\u{00A0}"), '0'), ',');
    $student = $t['student'];
    $summary = $t['summary'];
    $cum = $t['cumulative'];
    $stateLabel = ['valid' => 'Validé', 'failed' => 'Échec', 'incomplete' => 'Incomplet'];
    $scope = collect([
        $filters['year'] ? 'année '.$filters['year'] : null,
        $filters['semester'] ? 'session n° '.$filters['semester'] : null,
        $filters['level'] !== null ? ($filters['level'] <= 3 ? 'Licence '.$filters['level'] : 'Master '.($filters['level'] - 3)) : null,
        $filters['session'] ? 'une session' : null,
    ])->filter()->implode(' · ');
@endphp
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<title>Relevé semestriel des notes — {{ $student['student_id'] }}</title>
<style>
    @page { margin: 16mm 13mm 18mm 13mm; }
    body { font-family: "DejaVu Sans", sans-serif; font-size: 8pt; color: #14171f; line-height: 1.3; }
    h1 { font-size: 14pt; margin: 0; letter-spacing: -0.2pt; }
    .sub { color: #5b6170; font-size: 8.5pt; margin-top: 1pt; }
    .bar { border-top: 2.5pt solid #00727e; margin: 5pt 0 7pt; }
    h2 { font-size: 8.5pt; margin: 9pt 0 3pt; text-transform: uppercase; letter-spacing: 0.4pt; color: #00727e; }
    table { border-collapse: collapse; width: 100%; }
    .grid td, .grid th { border: 0.6pt solid #9aa1ae; padding: 2pt 3.5pt; vertical-align: top; }
    .grid th { background: #eceef2; text-align: left; font-weight: bold; }
    .grid .head th { background: #5b6170; color: #ffffff; text-align: center; }
    .grid tfoot th, .grid tfoot td { background: #f5f6f8; }
    .r { text-align: right; white-space: nowrap; }
    .c { text-align: center; white-space: nowrap; }
    .who td { padding: 1.2pt 4pt; vertical-align: top; }
    .who td.k { color: #5b6170; width: 22%; text-align: right; }
    .box { border: 0.6pt solid #9aa1ae; padding: 4pt 6pt; }
    .grid th.avg, .grid td.avg { background: #00727e; color: #ffffff; font-weight: bold; }
    .small { font-size: 7pt; color: #5b6170; }
    .ok { color: #15803d; font-weight: bold; }
    .ko { color: #b91c1c; font-weight: bold; }
    .wait { color: #b45309; font-weight: bold; }
    .session { margin-top: 7pt; page-break-inside: avoid; }
    .nobreak { page-break-inside: avoid; }
</style>
</head>
<body>

<table><tr>
<td style="vertical-align: top">
    <h1>Relevé semestriel des notes</h1>
    <div class="sub">Université Adventiste Zurcher — Bureau de la registraire</div>
    @if ($scope !== '')
        <div class="sub">Relevé limité à : {{ $scope }}</div>
    @endif
</td>
<td style="width: 30mm; text-align: right; vertical-align: top">
    @if (! empty($photo))
        <img src="{{ $photo }}" alt="Photo" style="width: 24mm; border: 0.6pt solid #9aa1ae">
    @endif
</td>
</tr></table>
<div class="bar"></div>

<table class="who">
    <tr><td class="k">Matricule</td><td><b>{{ $student['student_id'] }}</b></td></tr>
    <tr><td class="k">Noms</td><td><b>{{ $student['name'] }}</b></td></tr>
    <tr><td class="k">Mention</td><td><b>{{ $student['mention'] ?? '—' }}</b></td></tr>
    <tr><td class="k">Parcours</td><td><b>{{ $student['track'] ?? '—' }}</b></td></tr>
    <tr><td class="k">Niveau</td><td><b>{{ $student['level_label'] ?? '—' }}</b></td></tr>
    <tr><td class="k">E-mail / contact</td><td><b>{{ $student['email'] ?? '—' }}{{ $student['phone'] ? ' / '.$student['phone'] : '' }}</b></td></tr>
    <tr><td class="k">Adresse</td><td><b>{{ $student['address'] ?? '—' }}</b></td></tr>
</table>

@if (count($t['sessions']) === 0)
    <div class="box" style="margin-top: 10pt">Aucune session trouvée pour cet étudiant{{ $scope !== '' ? ' avec ces critères' : '' }}.</div>
@endif

@foreach ($t['sessions'] as $s)
    <div class="session">
    <table class="grid">
        <thead>
            <tr class="head"><th colspan="7">@if ($s['level_label'])<b>{{ $s['level_label'] }}</b> | @endif{{ $s['session']['name'] }} — session n° {{ $s['session']['semester'] }} | année {{ $s['session']['year'] }}</th></tr>
            <tr>
                <th style="width: 13%">Sigle</th>
                <th>Titre du cours</th>
                <th style="width: 7%" class="r">Crd</th>
                <th style="width: 12%">Cat.</th>
                <th style="width: 8%" class="c">Note</th>
                <th style="width: 9%" class="r">Crd × N</th>
                <th style="width: 10%" class="c">État</th>
            </tr>
        </thead>
        <tbody>
        @foreach ($s['courses'] as $c)
            <tr>
                <td>{{ $c['code'] }}</td>
                <td>{{ $c['title'] }}</td>
                <td class="r">{{ $c['credits'] }}</td>
                <td>{{ $c['category'] === 'validation' ? 'Validation' : $c['category'] }}</td>
                <td class="c {{ $c['note'] === 'V' ? 'ok' : ($c['note'] === 'E' ? 'ko' : '') }}">{{ $c['note'] }}</td>
                <td class="r">{{ $c['points'] === null ? '--' : $n($c['points']) }}</td>
                <td class="c {{ $c['state'] === 'valid' ? 'ok' : ($c['state'] === 'failed' ? 'ko' : 'wait') }}">{{ $stateLabel[$c['state']] }}</td>
            </tr>
        @endforeach
        </tbody>
        <tfoot>
            <tr>
                <th colspan="2">{{ $s['totals']['courses'] }} cours</th>
                <th class="r">{{ $s['totals']['credits'] }}</th>
                <th></th>
                <th class="c">{{ $n($s['totals']['grades']) }}</th>
                <th class="r">{{ $n($s['totals']['points']) }}</th>
                <th></th>
            </tr>
            <tr><td colspan="4" class="r">Note de Work Education</td><td class="c">{{ $s['promotion'] && ! empty($s['promotion']['work_education']) ? $n($s['promotion']['work_education']) : '' }}</td><td colspan="2"></td></tr>
            <tr><td colspan="4" class="r">Note de participation à l’exercice de chapelle et à la semaine de prière</td><td class="c">{{ $s['promotion'] && ! empty($s['promotion']['chapel']) ? $n($s['promotion']['chapel']) : '' }}</td><td colspan="2"></td></tr>
            <tr><th colspan="4" class="r">Moyenne majeure</th><th class="c">{{ $n($s['averages']['major']) }}</th><td colspan="2"></td></tr>
            <tr><th colspan="4" class="r">Moyenne générale</th><th class="c avg">{{ $n($s['averages']['general']) }}</th><td colspan="2"></td></tr>
            <tr><td colspan="7" class="small">
                <b>Récapitulatif :</b> {{ $s['totals']['courses'] }} cours ·
                {{ $s['recap']['valid_courses'] }} validé(s) ({{ $s['recap']['valid_credits'] }} crédits) ·
                {{ $s['recap']['failed_courses'] }} échoué(s) ({{ $s['recap']['failed_credits'] }} crédits)
                @if ($s['recap']['incomplete_courses'] > 0)
                    · {{ $s['recap']['incomplete_courses'] }} incomplet(s), note non remise ({{ $s['recap']['incomplete_credits'] }} crédits)
                @endif
                · total : {{ $s['totals']['credits'] }} crédits
            </td></tr>
        </tfoot>
    </table>
    </div>
@endforeach

@if (count($t['sessions']) > 0)
    <div class="nobreak">
    <h2>Récapitulatif général</h2>
    <table class="grid">
        <tr><td style="width: 60%">Nombre total de sessions</td><td><b>{{ $summary['sessions'] }}</b></td></tr>
        <tr><td>Nombre total de cours</td><td><b>{{ $summary['courses'] }}</b></td></tr>
        <tr><td class="ok">Cours validés</td><td class="ok">{{ $summary['valid_courses'] }} cours ({{ $summary['valid_credits'] }} crédits)</td></tr>
        <tr><td class="ko">Cours échoués</td><td class="ko">{{ $summary['failed_courses'] }} cours ({{ $summary['failed_credits'] }} crédits)</td></tr>
        @if ($summary['incomplete_courses'] > 0)
            <tr><td class="wait">Cours incomplets (note non remise)</td><td class="wait">{{ $summary['incomplete_courses'] }} cours ({{ $summary['incomplete_credits'] }} crédits)</td></tr>
        @endif
        <tr><td>Total des crédits</td><td><b>{{ $summary['credits'] }} crédits</b></td></tr>
        <tr><td>Taux de réussite</td><td><b>{{ $n($summary['success_rate']) }} %</b></td></tr>
    </table>
    </div>

    <div class="nobreak">
    <h2>Moyenne cumulative</h2>
    <table class="grid">
        <tr><td style="width: 75%" class="r">Note de Work Education cumulative</td><td class="c">{{ $n($cum['work_education']) }}</td></tr>
        <tr><td class="r">Note de participation à l’exercice de chapelle et à la semaine de prière cumulative</td><td class="c">{{ $n($cum['chapel']) }}</td></tr>
        <tr><th class="r">Moyenne majeure cumulative</th><th class="c">{{ $n($cum['major']) }}</th></tr>
        <tr><th class="r">Moyenne générale cumulative</th><th class="c avg">{{ $n($cum['general']) }}</th></tr>
    </table>
    </div>

    <div class="small" style="margin-top: 6pt; border-top: 0.6pt solid #cbd5e1; padding-top: 3pt">
        <b>Légende :</b> <span class="ok">Validé</span> = note ≥ 10, ou validé sans note (V) ·
        <span class="ko">Échec</span> = note &lt; 10, ou échec sans note (E) ·
        <span class="wait">Incomplet</span> = note non encore remise par l’enseignant (NN), « -- » = en attente de notation.
        Un cours de validation n’entre pas dans les moyennes.
    </div>
@endif

<div class="nobreak" style="margin-top: 14pt">
    <div>{{ $footer['place'] }}, {{ $issued }}</div>
    <div style="height: 48pt; margin-top: 4pt">
        @if ($footer['signature'])
            <img src="{{ $footer['signature'] }}" alt="Signature" style="height: 46pt">
        @endif
    </div>
    <div style="border-top: 0.6pt solid #14171f; width: 62mm; padding-top: 2pt; font-size: 7.5pt"><em>{{ $footer['name'] }}</em></div>
    <div class="small" style="margin-top: 8pt; border-top: 0.6pt solid #14171f; padding-top: 2pt">Université Adventiste Zurcher</div>
</div>

</body>
</html>
