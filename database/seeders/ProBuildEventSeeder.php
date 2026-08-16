<?php

namespace Database\Seeders;

use App\Enums\EventStatus;
use App\Enums\TalkshowStatus;
use App\Models\Event;
use App\Models\EventDay;
use App\Models\EventRegistrationSequence;
use App\Models\Talkshow;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

class ProBuildEventSeeder extends Seeder
{
    public function run(): void
    {
        $timezone = 'Asia/Makassar';

        $event = Event::updateOrCreate(
            ['slug' => 'probuild-intim-2026'],
            [
                'name' => 'ProBuild INTIM 2026',
                'registration_prefix' => 'PBI26',
                'timezone' => $timezone,
                'starts_on' => '2026-09-24',
                'ends_on' => '2026-09-27',
                'registration_starts_at' => CarbonImmutable::parse('2026-01-01 00:00:00', $timezone),
                'registration_ends_at' => CarbonImmutable::parse('2026-09-27 17:30:00', $timezone),
                'venue' => 'Sumarecon Mutiara Makassar Convention Center (SMMCC) - Makassar',
                'address' => 'Jl. Metro Tj. Bunga No.2, Kota Makassar, Sulawesi Selatan',
                'status' => EventStatus::Published,
            ]
        );

        EventRegistrationSequence::firstOrCreate(
            ['event_id' => $event->id],
            ['next_number' => 1]
        );

        $days = collect([
            ['date' => '2026-09-24', 'label' => 'Hari 1', 'sort_order' => 1],
            ['date' => '2026-09-25', 'label' => 'Hari 2', 'sort_order' => 2],
            ['date' => '2026-09-26', 'label' => 'Hari 3', 'sort_order' => 3],
            ['date' => '2026-09-27', 'label' => 'Hari 4', 'sort_order' => 4],
        ])->mapWithKeys(function (array $day) use ($event) {
            $model = EventDay::updateOrCreate(
                ['event_id' => $event->id, 'event_date' => $day['date']],
                [
                    'label' => $day['label'],
                    'sort_order' => $day['sort_order'],
                    'check_in_starts_at' => null,
                    'check_in_ends_at' => null,
                ]
            );

            return [$day['date'] => $model];
        });

        $talkshows = [
            ['TS-01', 'Talk Show 1: Kebijakan Infrastruktur Nasional', '2026-09-24', '12:00', '13:00'],
            ['TS-02', 'Talk Show 2: Standar Konstruksi Bangunan', '2026-09-24', '13:00', '15:00'],
            ['TS-03', 'Talk Show 3: Solusi Perumahan Rakyat', '2026-09-25', '09:00', '12:00'],
            ['TS-04', 'Talk Show 4: Profesionalisme Jasa Konstruksi', '2026-09-25', '13:30', '15:30'],
            ['TS-05', 'Talk Show 5: Perencanaan Pembangunan Berkualitas', '2026-09-25', '16:00', '18:00'],
            ['TS-06', 'Talk Show 6: Peran Kontraktor Profesional', '2026-09-26', '09:00', '12:00'],
            ['TS-07', 'Talk Show 7: Pengendalian Biaya Proyek', '2026-09-26', '13:00', '15:00'],
            ['TS-08', 'Talk Show 8: Manajemen Proyek Efektif', '2026-09-26', '15:30', '17:30'],
            ['TS-09', 'Talk Show 9: Pengawasan & Keandalan Bangunan', '2026-09-27', '09:00', '12:00'],
            ['TS-10', 'Talk Show 10: Sinergi Ekosistem Konstruksi', '2026-09-27', '12:00', '15:00'],
        ];

        foreach ($talkshows as [$code, $title, $date, $start, $end]) {
            Talkshow::updateOrCreate(
                ['event_id' => $event->id, 'code' => $code],
                [
                    'event_day_id' => $days->get($date)->id,
                    'title' => $title,
                    'starts_at' => CarbonImmutable::parse("$date $start", $timezone),
                    'ends_at' => CarbonImmutable::parse("$date $end", $timezone),
                    'capacity' => null,
                    'registration_starts_at' => $event->registration_starts_at,
                    'registration_ends_at' => CarbonImmutable::parse("$date $start", $timezone),
                    'waitlist_enabled' => false,
                    'status' => TalkshowStatus::Published,
                ]
            );
        }
    }
}
