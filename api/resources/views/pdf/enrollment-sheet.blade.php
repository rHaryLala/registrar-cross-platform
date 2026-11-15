@php
    use App\Domain\Student\EnrollmentRules;
    /** 1 234 567 Ar : espaces insécables, pour qu'un montant ne soit jamais coupé en fin de ligne. */
    $ar = fn ($n) => number_format((float) $n, 0, '', "\u{00A0}")."\u{00A0}Ar";
    $credits = fn ($n) => rtrim(rtrim(number_format((float) $n, 1, ',', ''), '0'), ',');
    $fees = $s['fees'];
    $student = $s['student'];
    $totals = $s['course_totals'];
@endphp
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<title>Fiche d’inscription — {{ $s['session']['name'] }} {{ $s['session']['year'] }}</title>
<style>
    @page { margin: 18mm 14mm 16mm 14mm; }
    body { font-family: "DejaVu Sans", sans-serif; font-size: 8.5pt; color: #14171f; line-height: 1.35; }
    h1 { font-size: 15pt; margin: 0; letter-spacing: -0.2pt; }
    .sub { color: #5b6170; font-size: 9pt; margin-top: 2pt; }
    .bar { border-top: 2.5pt solid #00727e; margin: 6pt 0 8pt; }
    h2 { font-size: 9pt; margin: 10pt 0 3pt; text-transform: uppercase; letter-spacing: 0.4pt; color: #00727e; }
    table { border-collapse: collapse; width: 100%; }
    .grid td, .grid th { border: 0.6pt solid #9aa1ae; padding: 2.5pt 4pt; vertical-align: top; }
    .grid th { background: #eceef2; text-align: left; font-weight: bold; }
    .grid tfoot th, .grid tfoot td { background: #eceef2; font-weight: bold; }
    .r { text-align: right; white-space: nowrap; }
    .c { text-align: center; }
    .who td { padding: 1.5pt 4pt; vertical-align: top; }
    .who td.k { color: #5b6170; width: 24%; text-align: right; }
    .box { border: 0.6pt solid #9aa1ae; padding: 4pt 6pt; }
    .warn { border: 0.8pt solid #b45309; background: #fffbeb; color: #7c2d12; padding: 5pt 7pt; margin: 6pt 0; }
    .total { background: #14171f; color: #ffffff; padding: 6pt 9pt; margin-top: 6pt; }
    .total .t { font-size: 8pt; font-weight: bold; }
    .total .v { font-size: 14pt; font-weight: bold; }
    .free { text-decoration: line-through; color: #b45309; }
    .small { font-size: 7.5pt; color: #5b6170; }
    .sig td { height: 40pt; vertical-align: bottom; }
    .sig td.l { border-top: 0.6pt solid #14171f; text-align: center; font-size: 7pt; vertical-align: top; padding-top: 2pt; }
    .nobreak { page-break-inside: avoid; }
</style>
</head>
<body>

<h1>Fiche d’inscription</h1>
<div class="sub">{{ $s['session']['name'] }} · {{ $s['session']['year'] }} — Université Adventiste Zurcher</div>
<div class="bar"></div>

<table><tr>
<td style="vertical-align: top">
<table class="who">
    <tr><td class="k">Matricule</td><td><b>{{ $student['student_id'] }}</b></td></tr>
    <tr><td class="k">Nom</td><td><b>{{ $student['name'] }}</b></td></tr>
    <tr><td class="k">Mention</td><td><b>{{ $student['mention'] ?? '—' }}</b></td></tr>
    <tr><td class="k">Parcours</td><td><b>{{ $student['track'] ?? '—' }}</b></td></tr>
    <tr><td class="k">Niveau</td><td><b>{{ $student['level_label'] ?? '—' }}</b></td></tr>
    <tr><td class="k">E-mail / contact</td><td><b>{{ $student['email'] ?? '—' }}{{ $student['phone'] ? ' / '.$student['phone'] : '' }}</b></td></tr>
    <tr><td class="k">Adresse</td><td><b>{{ $student['address'] ?? '—' }}</b></td></tr>
    @if ($student['sponsor'])
        <tr><td class="k">Boursier(e) par</td><td><b>{{ $student['sponsor'] }}</b></td></tr>
    @endif
</table>
</td>
<td style="width: 34mm; vertical-align: top; text-align: right">
    @if (! empty($photo))
        <img src="{{ $photo }}" alt="Photo" style="width: 30mm; border: 0.6pt solid #9aa1ae">
    @else
        <div style="width: 30mm; height: 38mm; border: 0.6pt dashed #9aa1ae; color: #9aa1ae; text-align: center; padding-top: 15mm; font-size: 7pt; margin-left: 4mm">Photo</div>
    @endif
</td>
</tr></table>

<h2>Cours ajoutés</h2>
@if (count($s['courses']) === 0)
    <div class="box">Aucun cours enregistré pour cette session.</div>
@else
<table class="grid">
    <thead>
        <tr>
            <th style="width: 15%">Sigle</th>
            <th>Titre du cours</th>
            <th style="width: 9%" class="r">Crédits</th>
            <th style="width: 14%">Catégorie</th>
            <th style="width: 15%" class="r">Coût</th>
            <th style="width: 13%" class="r">Labo</th>
        </tr>
    </thead>
    <tbody>
    @foreach ($s['courses'] as $c)
        <tr>
            <td>{{ $c['code'] }}</td>
            <td>{{ $c['title'] }}</td>
            <td class="r">{{ $credits($c['credits']) }}</td>
            <td>{{ $c['category'] }}</td>
            <td class="r">{{ $ar($c['cost']) }}</td>
            <td class="r">
                @if ($c['lab'])
                    <span class="{{ $c['lab']['counted'] ? '' : 'free' }}">{{ $ar($c['lab']['cost']) }}</span>
                @endif
            </td>
        </tr>
    @endforeach
    </tbody>
    <tfoot>
        <tr>
            <th colspan="2">{{ $totals['count'] }} {{ $totals['count'] > 1 ? 'cours' : 'cours' }}</th>
            <th class="r">{{ $credits($totals['credits']) }}</th>
            <th></th>
            <th class="r">{{ $ar($totals['cost']) }}</th>
            <th class="r">{{ $ar($totals['lab']) }}</th>
        </tr>
    </tfoot>
</table>
<div class="small" style="margin-top:2pt">Laboratoire : seuls les deux premiers cours à laboratoire sont facturés ; les suivants sont offerts (barrés).</div>
@endif

@if (! $s['is_master'])
    <div style="margin-top:5pt">
        Résidence : <b>{{ $student['residence'] ?? '—' }}</b> &nbsp;·&nbsp; Cafétéria : <b>{{ $student['cafeteria'] ? 'abonné(e)' : 'non abonné(e)' }}</b>
    </div>
@endif

@if ($fees === null)
    <div class="warn nobreak">
        <b>Frais de scolarité non disponibles</b> — aucune ligne financière n’existe pour « {{ $s['session']['name'] }} · {{ $s['session']['year'] }} ».
        Cette fiche ne peut pas servir de justificatif de paiement.
    </div>
@else
    <table style="margin-top:8pt" class="nobreak">
        <tr>
            <td style="width: 50%; vertical-align: top; padding-right: 6pt">
                <div class="small" style="margin-bottom:2pt"><b>NB :</b> les frais généraux doivent être payés le jour de l’inscription.</div>
                <table class="grid">
                    <thead><tr><th colspan="2" class="c">Frais divers</th></tr></thead>
                    <tbody>
                    @foreach ($fees['general_rows'] as $row)
                        <tr><td>{{ $row['label'] }}</td><td class="r">{{ $ar($row['amount']) }}</td></tr>
                    @endforeach
                    </tbody>
                </table>

                @if ($fees['tuition'])
                    <table class="grid" style="margin-top:6pt">
                        <thead><tr><th colspan="2" class="c">Frais de scolarité</th></tr></thead>
                        <tbody>
                        @foreach ($fees['tuition']['rows'] as $row)
                            <tr><td>{{ $row['label'] }}</td><td class="r">{{ $ar($row['amount']) }}</td></tr>
                        @endforeach
                        </tbody>
                        <tfoot><tr><th>Sous-total frais de scolarité</th><th class="r">{{ $ar($fees['tuition']['subtotal']) }}</th></tr></tfoot>
                    </table>
                @endif
            </td>
            <td style="width: 50%; vertical-align: top; padding-left: 6pt">
                @if ($fees['payment'])
                    <b>Mode de paiement choisi :</b>
                    <table class="grid" style="margin-top:2pt">
                        <thead>
                            <tr><th colspan="3" class="c">{{ $ar($fees['tuition']['subtotal']) }}</th></tr>
                            <tr><th colspan="3" class="c">{{ $fees['payment']['mode'] ? 'Tranche '.$fees['payment']['mode'].' — '.$fees['payment']['mode_label'] : 'Mode de paiement non encore choisi' }}</th></tr>
                            @if (count($fees['payment']['installments']))
                                <tr><th>Pourcentage</th><th class="r">Montant</th><th>Date de paiement</th></tr>
                            @endif
                        </thead>
                        <tbody>
                        @foreach ($fees['payment']['installments'] as $i)
                            <tr>
                                <td>{{ $i['percent'] }} %</td>
                                <td class="r">{{ $ar($i['amount']) }}</td>
                                <td>{{ $i['due_label'] ?? 'à confirmer' }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                @endif
                <div class="total">
                    <div class="t">Total général ({{ $fees['grand_total_includes_tuition'] ? 'frais divers + frais de scolarité' : 'frais divers' }})</div>
                    <div class="v">{{ $ar($fees['grand_total']) }}</div>
                </div>
            </td>
        </tr>
    </table>
@endif

<div class="nobreak">
    <div class="bar" style="margin-top:10pt"></div>
    @if (! $s['is_master'])
        <div style="margin-bottom:4pt"><b>Work education :</b> {{ $s['work_education'] ?? '—' }}</div>
    @endif
    <b>ENGAGEMENT</b>
    <p style="font-size: 7.5pt; margin: 2pt 0 0">
        Je soussigné(e) {{ $student['name'] }}<br>
        m’engage, durant mon séjour à l’Université Adventiste Zurcher, à maintenir en tout temps une conduite et une attitude exemplaires, en harmonie avec la
        philosophie chrétienne de cette institution qui m’accueille ; à contribuer positivement à la vie de l’université et à vivre en tout temps en conformité
        avec ses principes et règlements. Le non-respect de cet engagement pourrait entraîner une sanction, voire un renvoi temporaire.
    </p>
</div>

<div class="nobreak" style="margin-top:8pt">
    <div class="small">Sambaina, le {{ EnrollmentRules::dateLabel($s['issued_on']) }} — <b>Approuvée par :</b></div>
    <table class="sig">
        <tr><td></td><td style="width:8pt"></td><td></td><td style="width:8pt"></td><td></td><td style="width:8pt"></td><td></td><td style="width:8pt"></td><td></td></tr>
        <tr>
            <td class="l">Étudiant(e)</td><td></td>
            <td class="l">Chef de mention</td><td></td>
            <td class="l">Vice-recteur aux affaires financières / Contrôleur</td><td></td>
            <td class="l">Vice-recteur aux affaires estudiantines</td><td></td>
            <td class="l">Registraire</td>
        </tr>
    </table>
</div>

</body>
</html>
