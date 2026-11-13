<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>SET Report</title>
<style>
    @page { margin: 28px 34px; }
    body { font-family: 'DejaVu Sans', sans-serif; font-size: 9px; color: #000; }
    .center { text-align: center; }
    .heading { font-weight: bold; font-size: 13px; margin: 0; }
    .crest { height: 52px; margin: 6px 0; }
    .title { font-weight: bold; font-size: 12px; margin: 4px 0 10px; }
    .meta td { font-weight: bold; padding: 1px 18px 1px 0; font-size: 9px; }
    table.set { width: 100%; border-collapse: collapse; margin-top: 10px; table-layout: fixed; }
    table.set td, table.set th { border: 1.5px solid #000; }
    table.set th { font-size: 8px; padding: 2px; }
    .points { font-size: 15px !important; }
    .vertical { height: 210px; padding: 0 !important; vertical-align: bottom; }
    .vertical div { width: 210px; height: 34px; transform: rotate(-90deg); transform-origin: 17px 17px; position: relative; top: 0; left: 0; font-size: 7.5px; font-weight: bold; text-align: left; line-height: 34px; white-space: nowrap; }
        .score { text-align: center; font-weight: bold; font-family: 'DejaVu Serif', serif; font-size: 11px; height: 54px; }
    .course { font-weight: bold; vertical-align: top; padding: 4px; font-size: 8.5px; }
    table.legend { width: 100%; margin-top: 6px; border-collapse: collapse; }
    table.legend td { padding: 2px; font-size: 9px; text-align: center; }
    .page-break { page-break-before: always; }
    .letterhead td { font-size: 8.5px; vertical-align: top; }
    .label { font-weight: bold; }
    ol { margin: 4px 0 0 18px; padding: 0; }
    ol li { margin-bottom: 4px; font-size: 10px; }
    .chart { border-collapse: collapse; margin: 6px auto 0; }
    .chart td { vertical-align: bottom; text-align: center; padding: 0 5px; font-size: 8px; }
    .bar { background: #0F6E56; width: 42px; margin: 0 auto; }
    .axis td { border-top: 1px solid #555; padding-top: 3px; }
    table.data { border-collapse: collapse; margin-top: 8px; }
    table.data td, table.data th { border: 1px solid #999; padding: 3px 8px; font-size: 9px; text-align: left; }
    table.data th { background: #E8F3EF; }
    .muted { color: #444; }
</style>
</head>
<body>
@php
    $questions = $summary['questions'];
    $textColor = fn ($classification) => $classification === 'improvement' ? '#fff' : '#000';
@endphp

{{-- Page 1: the SET table --}}
<div class="center">
    <p class="heading">{{ $letterhead['university'] }}</p>
    <p class="heading">{{ $letterhead['directorate'] }}</p>
    <img class="crest" src="{{ $crest }}" alt="">
    <p class="title">REPORT OF STUDENTS' EVALUATION OF TEACHING (SET)</p>
</div>

<table class="meta">
    <tr><td>SEMESTER:</td><td>{{ $semester }}</td></tr>
    <tr><td>LEVEL:</td><td>{{ $level }}</td></tr>
    <tr><td>YEAR:</td><td>{{ $year }}</td></tr>
</table>

<table class="set">
    <colgroup>
        <col style="width: 15%">
        <col style="width: 15%">
        @foreach ($questions as $question)
            <col style="width: {{ 70 / count($questions) }}%">
        @endforeach
    </colgroup>
    <tr>
        <th colspan="2">{{ $school }}</th>
        <th colspan="{{ count($questions) }}" class="points">EVALUATION POINTS</th>
    </tr>
    <tr>
        <th>COURSE ID/ COURSES</th>
        <th>LECTURER'S NAME</th>
        @foreach ($sections as $name => $span)
            <th colspan="{{ $span }}" style="{{ $span === 1 ? 'font-size: 6.5px' : '' }}">{{ $name }}</th>
        @endforeach
    </tr>
    <tr>
        <th class="vertical"><div style="margin-left: 45%">COURSE CODES/TITLES</div></th>
        <th class="vertical"><div style="margin-left: 45%">NAME OF PART-TIME/FULL-TIME LECTURERS</div></th>
        @foreach ($questions as $question)
            <th class="vertical"><div>{{ $question['number'] }}. {{ strtoupper($question['shortLabel']) }}</div></th>
        @endforeach
    </tr>
    <tr>
        <td class="course">{{ trim($courseCode.' '.$courseTitle) }}</td>
        <td class="course">{{ $lecturer }}</td>
        @foreach ($questions as $question)
            <td class="score" style="background: #{{ $colors[$question['classification']] ?? 'FFFFFF' }}; color: {{ $textColor($question['classification']) }}">{{ $question['rounded'] ?? '—' }}</td>
        @endforeach
    </tr>
</table>

<table class="legend">
    <tr>
        <td style="width: 34%; font-weight: bold">INDEX</td>
        <td></td>
        <td style="width: 18%; font-weight: bold">KEY</td>
    </tr>
    @foreach ([
        'high' => 'ABOVE '.$thresholds['high'],
        'acceptable' => 'BETWEEN '.$thresholds['acceptable'].'-'.$thresholds['high'],
        'improvement' => 'LESS THAN '.$thresholds['acceptable'],
    ] as $classification => $key)
        <tr>
            <td style="background: #{{ $colors[$classification] }}; color: {{ $textColor($classification) }}">{{ strtoupper($labels[$classification]) }}</td>
            <td></td>
            <td style="background: #{{ $colors[$classification] }}; color: {{ $textColor($classification) }}">{{ $key }}</td>
        </tr>
    @endforeach
</table>

{{-- Page 2 onwards: comments and grades --}}
<div class="page-break"></div>
<table class="letterhead" style="width: 100%">
    <tr>
        <td style="width: 33%">Tel: {{ $letterhead['phone'] }}<br>Email: {{ $letterhead['email'] }}<br>Ref. No: {{ $letterhead['reference'] }}</td>
        <td class="center" style="width: 34%">
            <img class="crest" src="{{ $crest }}" alt=""><br>
            <strong>{{ $letterhead['university'] }}</strong><br>
            <strong>{{ $letterhead['directorate'] }}</strong>
        </td>
        <td style="width: 33%; text-align: right">{!! implode('<br>', array_map('e', $letterhead['address'])) !!}</td>
    </tr>
</table>

<p class="center" style="font-weight: bold; font-size: 11px; margin: 10px 0 2px">{{ $year }} ACADEMIC YEAR</p>
<p class="center" style="font-weight: bold; font-size: 11px; margin: 0 0 10px">COMMENTS / SUGGESTIONS MADE BY STUDENTS DURING THE EVALUATION EXERCISE</p>

<p><span class="label">SCHOOL:</span> {{ $school }}</p>
<p><span class="label">NAME OF LECTURER:</span> {{ $lecturer }}</p>
<p><span class="label">COURSE CODE AND TITLE:</span> {{ trim($courseCode.' '.$courseTitle) }}</p>
<p><span class="label">LEVEL:</span> {{ $level }} &nbsp;&nbsp;&nbsp;&nbsp;&nbsp; <span class="label">SEMESTER:</span> {{ $semester }}</p>

<p class="label" style="margin-top: 10px">COMMENTS &amp; SUGGESTIONS:</p>
@if ($summary['comments'] === [])
    <p class="muted"><em>No comments were made.</em></p>
@else
    <ol>
        @foreach ($summary['comments'] as $comment)
            <li>{{ $comment }}</li>
        @endforeach
    </ol>
@endif

@if ($grades = $summary['grades'])
    @php
        $max = max(1, max(array_column($grades['bands'], 'count')));
        $ranges = \App\Support\Evaluation\SetReport::bandRanges();
    @endphp
    <div style="page-break-inside: avoid; margin-top: 14px">
        <p class="label">OVERALL GRADE GIVEN BY STUDENTS</p>
        <table class="chart">
            <tr>
                @foreach ($grades['bands'] as $band)
                    <td style="height: 150px">
                        <div style="font-weight: bold; margin-bottom: 2px">{{ $band['count'] }}</div>
                        <div class="bar" style="height: {{ max(1, round($band['count'] / $max * 130)) }}px"></div>
                    </td>
                @endforeach
            </tr>
            <tr class="axis">
                @foreach ($grades['bands'] as $band)
                    <td>{{ $band['label'] }}</td>
                @endforeach
            </tr>
        </table>

        <table class="data" style="margin-left: auto; margin-right: auto">
            <tr><th>Grade band</th><th>Score range</th><th>Frequency</th><th>Percent</th></tr>
            @foreach ($grades['bands'] as $index => $band)
                <tr>
                    <td>{{ $band['label'] }}</td>
                    <td>{{ $ranges[$index] }}</td>
                    <td>{{ $band['count'] }}</td>
                    <td>{{ number_format($band['percent'], 1) }}%</td>
                </tr>
            @endforeach
        </table>
        <p class="center muted" style="margin-top: 6px">{{ \App\Support\Evaluation\SetReport::gradeLine($grades) }}</p>
    </div>
@endif
</body>
</html>
