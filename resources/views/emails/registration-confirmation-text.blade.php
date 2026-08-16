Halo {{ $confirmation->participantName }},

Registrasi Anda untuk {{ $confirmation->eventName }} telah berhasil.

Nomor Registrasi:
{{ $confirmation->registrationNumber }}

Lokasi:
{{ $confirmation->eventVenue }}@if ($confirmation->eventAddress)
{{ $confirmation->eventAddress }}@endif

Tanggal Event:
{{ $confirmation->eventStartsOn }} — {{ $confirmation->eventEndsOn }}

@if ($confirmation->confirmedTalkshows !== [])
Talkshow Terkonfirmasi:
@foreach ($confirmation->confirmedTalkshows as $talkshow)
- {{ $talkshow['title'] }}
@endforeach
@endif

@if ($confirmation->waitlistedTalkshows !== [])
Talkshow dalam Waitlist:
@foreach ($confirmation->waitlistedTalkshows as $talkshow)
- {{ $talkshow['title'] }} — Waitlist #{{ $talkshow['waitlist_position'] }}
@endforeach
@endif

VIEW E-TICKET:
{{ $confirmation->ticketUrl }}

{{ $confirmation->checkInInstructions }}
