<?php

namespace Tests\Feature;

use Database\Seeders\ProBuildEventSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProBuildEventSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_can_seed_the_event_repeatedly_without_creating_duplicates(): void
    {
        $this->seed(ProBuildEventSeeder::class);
        $this->seed(ProBuildEventSeeder::class);

        $this->assertDatabaseCount('events', 1);
        $this->assertDatabaseCount('event_days', 4);
        $this->assertDatabaseCount('event_registration_sequences', 1);
        $this->assertDatabaseCount('talkshows', 10);
    }
}
