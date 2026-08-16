<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>E-Ticket {{ $ticket['registration_number'] }} — ProBuild INTIM 2026</title>
    <style>
        @page { margin: 28px; }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            background: #f8f9fa;
            color: #3a4452;
            font-family: "DejaVu Sans", sans-serif;
            font-size: 11px;
            line-height: 1.45;
        }
        .ticket {
            border: 1px solid #e5e8ec;
            border-radius: 18px;
            background: #ffffff;
            overflow: hidden;
        }
        .header {
            padding: 20px 24px;
            background: #0d1117;
            color: #ffffff;
        }
        .brand {
            font-size: 10px;
            font-weight: bold;
            letter-spacing: 1.8px;
        }
        .title {
            margin: 9px 0 2px;
            font-size: 25px;
            line-height: 1.15;
        }
        .subtitle { color: #c8d0da; }
        .dots { float: right; margin-top: 3px; }
        .dot {
            display: inline-block;
            width: 7px;
            height: 7px;
            margin-left: 5px;
            border-radius: 7px;
        }
        .red { background: #e8303a; }
        .green { background: #2d9c6e; }
        .blue { background: #1a5fd6; }
        .orange { background: #f5a623; }
        .body { padding: 22px 24px 20px; }
        .columns { width: 100%; border-collapse: collapse; }
        .columns td { vertical-align: top; }
        .main { width: 66%; padding-right: 22px; }
        .qr-column {
            width: 34%;
            border-left: 1px dashed #cfd5dc;
            padding-left: 22px;
            text-align: center;
        }
        .eyebrow {
            margin-bottom: 4px;
            color: #7a8899;
            font-size: 8px;
            font-weight: bold;
            letter-spacing: 1px;
            text-transform: uppercase;
        }
        .registration-number {
            margin-bottom: 18px;
            color: #006837;
            font-size: 25px;
            font-weight: bold;
            letter-spacing: 1px;
        }
        .details { width: 100%; border-collapse: collapse; }
        .details td { width: 50%; padding: 0 15px 13px 0; }
        .value { color: #0d1117; font-size: 11px; font-weight: bold; }
        .location { font-size: 9px; font-weight: normal; }
        .status {
            display: inline-block;
            padding: 6px 10px;
            border-radius: 14px;
            font-size: 8px;
            font-weight: bold;
            letter-spacing: .5px;
        }
        .status-not_checked_in { background: #fff0f1; color: #8b1a1a; }
        .status-partially_checked_in { background: #fff9ed; color: #d96d00; }
        .status-checked_in { background: #edfaf4; color: #006837; }
        .qr {
            display: block;
            width: 184px;
            height: 184px;
            margin: 5px auto 8px;
        }
        .qr-note { color: #7a8899; font-size: 8px; line-height: 1.4; }
        .section {
            margin-top: 17px;
            padding-top: 15px;
            border-top: 1px solid #e5e8ec;
        }
        .section-title {
            margin: 0 0 9px;
            color: #0d1117;
            font-size: 12px;
        }
        .session {
            margin-bottom: 6px;
            padding: 7px 9px;
            border: 1px solid #e5e8ec;
            border-radius: 7px;
        }
        .session-title { color: #0d1117; font-size: 9px; font-weight: bold; }
        .session-meta { color: #7a8899; font-size: 8px; }
        .badge {
            float: right;
            padding: 3px 6px;
            border-radius: 10px;
            font-size: 7px;
            font-weight: bold;
        }
        .confirmed { background: #edfaf4; color: #006837; }
        .waitlisted { background: #fff9ed; color: #d96d00; }
        .days { width: 100%; border-collapse: separate; border-spacing: 5px; margin: 0 -5px; }
        .day {
            padding: 7px;
            border: 1px solid #e5e8ec;
            border-radius: 7px;
            text-align: center;
        }
        .day-label { color: #0d1117; font-size: 8px; font-weight: bold; }
        .day-status { margin-top: 3px; font-size: 7px; font-weight: bold; }
        .footer {
            padding: 11px 24px;
            background: #f2f4f6;
            color: #7a8899;
            font-size: 7px;
            text-align: center;
        }
    </style>
</head>
<body>
@php
    $eventStart = \Carbon\Carbon::parse($ticket['event']['starts_on'])->locale('id');
    $eventEnd = \Carbon\Carbon::parse($ticket['event']['ends_on'])->locale('id');
    $eventDate = $eventStart->isSameDay($eventEnd)
        ? $eventStart->translatedFormat('d F Y')
        : $eventStart->translatedFormat('d').'–'.$eventEnd->translatedFormat('d F Y');
    $statusLabels = [
        'not_checked_in' => 'NOT CHECKED IN',
        'partially_checked_in' => 'PARTIALLY CHECKED IN',
        'checked_in' => 'CHECKED IN',
    ];
@endphp
<div class="ticket">
    <div class="header">
        <span class="dots">
            <i class="dot red"></i><i class="dot green"></i><i class="dot blue"></i><i class="dot orange"></i>
        </span>
        <div class="brand">PROBUILD INTIM 2026</div>
        <div class="title">E-Ticket Peserta</div>
        <div class="subtitle">Building &amp; Construction Exhibition — Indonesia Timur</div>
    </div>
    <div class="body">
        <table class="columns">
            <tr>
                <td class="main">
                    <div class="eyebrow">Nomor Registrasi</div>
                    <div class="registration-number">{{ $ticket['registration_number'] }}</div>
                    <table class="details">
                        <tr>
                            <td>
                                <div class="eyebrow">Peserta</div>
                                <div class="value">{{ $ticket['participant']['full_name'] }}</div>
                            </td>
                            <td>
                                <div class="eyebrow">Status Saat Ini</div>
                                <span class="status status-{{ $ticket['check_in']['overall_status'] }}">
                                    {{ $statusLabels[$ticket['check_in']['overall_status']] }}
                                </span>
                            </td>
                        </tr>
                        <tr>
                            <td>
                                <div class="eyebrow">Tanggal Event</div>
                                <div class="value">{{ $eventDate }}</div>
                            </td>
                            <td>
                                <div class="eyebrow">Lokasi</div>
                                <div class="value">{{ $ticket['event']['venue'] }}</div>
                                @if ($ticket['event']['address'])
                                    <div class="location">{{ $ticket['event']['address'] }}</div>
                                @endif
                            </td>
                        </tr>
                    </table>
                </td>
                <td class="qr-column">
                    <img class="qr" src="{{ $ticket['qr_code']['data_uri'] }}" alt="QR Code">
                    <div class="qr-note">Tunjukkan QR Code ini kepada panitia saat check-in. Satu QR berlaku untuk seluruh hari event.</div>
                </td>
            </tr>
        </table>

        <div class="section">
            <h2 class="section-title">Pilihan Talkshow</h2>
            @forelse ($ticket['talkshows']['confirmed'] as $talkshow)
                <div class="session">
                    <span class="badge confirmed">CONFIRMED</span>
                    <div class="session-title">{{ $talkshow['title'] }}</div>
                    <div class="session-meta">
                        {{ \Carbon\Carbon::parse($talkshow['starts_at'])->timezone($ticket['event']['timezone'])->locale('id')->translatedFormat('D, d M Y · H:i') }} WITA
                        @if ($talkshow['room']) · {{ $talkshow['room'] }} @endif
                    </div>
                </div>
            @empty
                @if (count($ticket['talkshows']['waitlisted']) === 0)
                    <div class="session-meta">Tidak ada talkshow yang dipilih.</div>
                @endif
            @endforelse
            @foreach ($ticket['talkshows']['waitlisted'] as $talkshow)
                <div class="session">
                    <span class="badge waitlisted">WAITLIST #{{ $talkshow['waitlist_position'] }}</span>
                    <div class="session-title">{{ $talkshow['title'] }}</div>
                    <div class="session-meta">
                        {{ \Carbon\Carbon::parse($talkshow['starts_at'])->timezone($ticket['event']['timezone'])->locale('id')->translatedFormat('D, d M Y · H:i') }} WITA
                        @if ($talkshow['room']) · {{ $talkshow['room'] }} @endif
                    </div>
                </div>
            @endforeach
        </div>

        <div class="section">
            <h2 class="section-title">Status Kehadiran Harian</h2>
            <table class="days"><tr>
                @foreach ($ticket['check_in']['event_days'] as $day)
                    <td class="day">
                        <div class="day-label">{{ $day['label'] }}</div>
                        <div>{{ \Carbon\Carbon::parse($day['date'])->locale('id')->translatedFormat('d M') }}</div>
                        <div class="day-status" style="color: {{ $day['status'] === 'checked_in' ? '#006837' : '#8b1a1a' }}">
                            {{ $statusLabels[$day['status']] }}
                        </div>
                    </td>
                @endforeach
            </tr></table>
        </div>
    </div>
    <div class="footer">
        Status pada dokumen ini diambil dari database saat PDF dibuat. Sistem ProBuild INTIM tetap menjadi sumber data kehadiran resmi.
    </div>
</div>
</body>
</html>
