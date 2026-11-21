@php
    $dash = fn ($v) => $v === null || $v === '' ? '—' : $v;
@endphp
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<title>Certificat de scolarité — {{ $c['student_id'] }}</title>
<style>
    @page { margin: 16mm 22mm 16mm 22mm; }
    body { font-family: "DejaVu Serif", serif; font-size: 10.5pt; color: #111; line-height: 1.55; }
    .head { text-align: center; border-bottom: 2.2pt solid #111; padding-bottom: 10pt; margin-bottom: 18pt; }
    .head img { height: 24mm; }
    .uni { font-size: 14pt; font-weight: bold; margin: 6pt 0 3pt; }
    .info { font-size: 9pt; line-height: 1.4; }
    .motto { font-size: 11pt; font-style: italic; font-weight: bold; margin-top: 7pt; }
    .title { text-align: center; font-size: 18pt; font-weight: bold; letter-spacing: 0.8pt; margin: 22pt 0 16pt; }
    .intro { text-align: center; margin-bottom: 14pt; }
    table.rows { border-collapse: collapse; width: 100%; font-size: 10pt; }
    table.rows td { padding: 2.4pt 0; vertical-align: top; }
    table.rows td.k { width: 46mm; font-style: italic; }
    table.rows td.v { font-weight: bold; }
    p.text { text-align: justify; margin: 14pt 0; text-indent: 10mm; }
    .place { text-align: right; margin-top: 22pt; font-size: 10pt; }
    .sign { text-align: center; margin-top: 18pt; page-break-inside: avoid; }
    .sign .space { height: 24mm; }
    .sign .name { font-style: italic; font-weight: bold; }
</style>
</head>
<body>

<div class="head">
    @if (! empty($logo))
        <img src="{{ $logo }}" alt="Logo">
    @endif
    <div class="uni">Université Adventiste Zurcher</div>
    <div class="info">BP 325 Antsirabe (110)<br>Tél : 034 38 180 81<br>E-mail : registraroffice@zurcher.edu.mg</div>
    <div class="motto">Préparer aujourd’hui les leaders de demain</div>
</div>

<div class="title">CERTIFICAT DE SCOLARITÉ</div>

<div class="intro">Je, soussigné(e), registraire de l’Université Adventiste Zurcher, certifie que :</div>

<table class="rows">
    <tr><td class="k">Nom et prénoms :</td><td class="v">{{ $c['name'] }}</td></tr>
    <tr><td class="k">Date de naissance :</td><td class="v">{{ $dash($c['birth_date']) }}</td></tr>
    <tr><td class="k">Lieu de naissance :</td><td class="v">{{ $dash($c['birth_place']) }}</td></tr>
    <tr><td class="k">Fils/Fille de :</td><td class="v">{{ $dash($c['father']) }}</td></tr>
    <tr><td class="k">Et de :</td><td class="v">{{ $dash($c['mother']) }}</td></tr>
    <tr><td class="k">Adresse habituelle :</td><td class="v">{{ $dash($c['address']) }}</td></tr>
</table>

<p class="text">
    est inscrit(e) dans la mention « <b>{{ $dash($c['mention']) }}</b> » de notre Institution, comme étudiant(e) régulier(ère),
    parcours « <b>{{ $dash($c['track']) }}</b> » pendant l’année universitaire <b>{{ $dash($c['year']) }}</b>.
</p>

<table class="rows">
    <tr><td class="k">Numéro d’immatriculation :</td><td class="v">{{ $c['student_id'] }}</td></tr>
</table>

<p class="text">Ce certificat lui est délivré pour valoir et servir ce que de droit.</p>

<div class="place">Sambaina, le {{ $c['issued_on'] }}</div>

<div class="sign">
    @if ($c['signer']['post'] !== '')
        <div>{{ $c['signer']['post'] }}</div>
    @endif
    <div class="space"></div>
    <div class="name">{{ $c['signer']['name'] }}</div>
</div>

</body>
</html>
