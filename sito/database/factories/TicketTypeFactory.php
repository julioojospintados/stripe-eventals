<?php

namespace Database\Factories;

use App\Enums\TicketChannel;
use App\Enums\TicketKind;
use App\Models\Event;
use App\Models\ProgramSession;
use Illuminate\Database\Eloquent\Factories\Factory;

class TicketTypeFactory extends Factory
{
    public function definition(): array
    {
        return [
            'event_id' => Event::factory(),
            'kind' => TicketKind::FullPass,
            'channel' => TicketChannel::Public,
            'name' => ['en' => 'Full pass', 'it' => 'Pass completo'],
            'max_per_order' => 4,
        ];
    }

    /** Workshop: tipo paid_session con la sua sessione dello stesso evento. */
    public function workshop(): static
    {
        return $this->state([
            'kind' => TicketKind::PaidSession,
            'name' => ['en' => 'Workshop'],
            'program_session_id' => fn (array $a) => ProgramSession::factory()->create(['event_id' => $a['event_id']]),
        ]);
    }

    public function companion(): static
    {
        return $this->state(['kind' => TicketKind::Companion, 'name' => ['en' => 'Companion'], 'max_per_host' => 1]);
    }

    public function staffPass(): static
    {
        return $this->state(['kind' => TicketKind::StaffPass, 'channel' => TicketChannel::Panel, 'name' => ['en' => 'Staff pass']]);
    }
}
