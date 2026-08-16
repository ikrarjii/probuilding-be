<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Konfirmasi Registrasi {{ $confirmation->eventName }}</title>
</head>
<body style="margin:0;background:#f4f6f7;color:#3a4452;font-family:Arial,sans-serif;line-height:1.6">
<div style="max-width:640px;margin:0 auto;padding:28px 16px">
    <div style="overflow:hidden;border:1px solid #e3e7ea;border-radius:20px;background:#ffffff">
        <div style="padding:24px 28px;background:#0d1117;color:#ffffff">
            <div style="font-size:12px;font-weight:700;letter-spacing:1.7px">PROBUILD INTIM 2026</div>
            <h1 style="margin:10px 0 4px;font-size:28px;line-height:1.2">Registrasi Berhasil</h1>
            <div style="color:#c8d0da">{{ $confirmation->eventName }}</div>
        </div>
        <div style="padding:28px">
            <p>Halo <strong>{{ $confirmation->participantName }}</strong>,</p>
            <p>Registrasi Anda telah berhasil. Simpan email ini dan gunakan e-ticket yang sama selama event.</p>

            <div style="margin:22px 0;padding:18px;border:1px solid #e3e7ea;border-radius:14px;background:#f8f9fa">
                <div style="font-size:11px;font-weight:700;letter-spacing:1px;color:#7a8899;text-transform:uppercase">Nomor Registrasi</div>
                <div style="margin-top:4px;color:#006837;font-size:24px;font-weight:800">{{ $confirmation->registrationNumber }}</div>
                <div style="margin-top:12px"><strong>{{ $confirmation->eventVenue }}</strong></div>
                <div style="font-size:13px;color:#657181">{{ $confirmation->eventStartsOn }} — {{ $confirmation->eventEndsOn }}</div>
                @if ($confirmation->eventAddress)
                    <div style="font-size:13px;color:#657181">{{ $confirmation->eventAddress }}</div>
                @endif
            </div>

            @if ($confirmation->confirmedTalkshows !== [])
                <h2 style="font-size:17px;color:#0d1117">Talkshow Terkonfirmasi</h2>
                <ul>
                    @foreach ($confirmation->confirmedTalkshows as $talkshow)
                        <li>{{ $talkshow['title'] }}</li>
                    @endforeach
                </ul>
            @endif

            @if ($confirmation->waitlistedTalkshows !== [])
                <h2 style="font-size:17px;color:#0d1117">Talkshow dalam Waitlist</h2>
                <ul>
                    @foreach ($confirmation->waitlistedTalkshows as $talkshow)
                        <li>{{ $talkshow['title'] }} — Waitlist #{{ $talkshow['waitlist_position'] }}</li>
                    @endforeach
                </ul>
            @endif

            <div style="margin:28px 0;text-align:center">
                <a href="{{ $confirmation->ticketUrl }}" style="display:inline-block;padding:13px 24px;border-radius:999px;background:#2d9c6e;color:#ffffff;font-weight:800;text-decoration:none">VIEW E-TICKET</a>
            </div>

            <p style="font-size:13px;color:#657181">{{ $confirmation->checkInInstructions }}</p>
        </div>
    </div>
</div>
</body>
</html>
