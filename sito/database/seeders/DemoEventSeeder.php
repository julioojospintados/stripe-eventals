<?php

namespace Database\Seeders;

use App\Enums\EventStatus;
use App\Enums\PriceRole;
use App\Enums\ProgramSessionAccess;
use App\Enums\TicketChannel;
use App\Enums\TicketKind;
use App\Models\Event;
use App\Models\MembershipPlan;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Evento dimostrativo generico, per provare un'installazione nuova o più eventi insieme: due giorni, un pool per
 * giorno, una fascia early bird, pass intero (prezzo pubblico e membro), un workshop. Fuso e valuta si scelgono:
 * di default è una bozza (non prende il posto dell'evento pubblico). Non fa nulla se lo slug esiste già.
 */
class DemoEventSeeder extends Seeder
{
    public function __construct(
        public string $slug = 'demo-conference',
        public string $timezone = 'America/New_York',
        public string $currency = 'usd',
        public bool $published = false,
    ) {}

    public function run(): ?Event
    {
        if (Event::where('slug', $this->slug)->exists()) {
            return null;
        }

        return DB::transaction(function () {
            $starts = now($this->timezone)->addMonths(6)->startOfDay();
            $event = Event::create([
                'slug' => $this->slug,
                'title' => ['en' => 'Demo Conference', 'it' => 'Congresso dimostrativo'],
                'summary' => ['en' => 'A sample event to try the system.', 'it' => 'Un evento di esempio per provare il sistema.'],
                'status' => $this->published ? EventStatus::Published : EventStatus::Draft,
                'timezone' => $this->timezone,
                'currency' => $this->currency,
                'starts_on' => $starts->toDateString(),
                'ends_on' => $starts->copy()->addDay()->toDateString(),
                'venue_name' => 'Demo City',
            ]);

            $dayPools = [];
            foreach ([0, 1] as $i) {
                $day = $starts->copy()->addDays($i)->toDateString();
                $event->days()->create(['day' => $day, 'label' => ['en' => 'Day '.($i + 1), 'it' => 'Giorno '.($i + 1)]]);
                $dayPools[] = $event->capacityPools()->create(['name' => 'Day '.($i + 1), 'capacity' => 300])->id;
            }

            // Early bird da ieri a un mese prima dell'evento, a mezzanotte nel fuso dell'evento.
            $earlyBird = $event->priceTiers()->create([
                'code' => 'early_bird', 'name' => ['en' => 'Early bird', 'it' => 'Early bird'], 'position' => 1,
                'starts_at' => now($this->timezone)->subDay()->startOfDay()->utc(),
                'ends_at' => $starts->copy()->subMonth()->utc(),
            ]);

            $pass = $event->ticketTypes()->create([
                'kind' => TicketKind::FullPass, 'channel' => TicketChannel::Public, 'position' => 1,
                'name' => ['en' => 'Full pass', 'it' => 'Pass completo'],
            ]);
            $pass->pools()->attach($dayPools);
            $pass->prices()->createMany([
                ['price_role' => PriceRole::Public, 'amount_cents' => 49900],
                ['price_role' => PriceRole::Member, 'amount_cents' => 29900],
                ['price_role' => PriceRole::Public, 'price_tier_id' => $earlyBird->id, 'amount_cents' => 39900],
            ]);

            $session = $event->programSessions()->create([
                'code' => 'W01', 'slug' => 'workshop-1', 'access' => ProgramSessionAccess::Paid,
                'title' => ['en' => 'Workshop 1', 'it' => 'Workshop 1'],
            ]);
            $workshop = $event->ticketTypes()->create([
                'kind' => TicketKind::PaidSession, 'channel' => TicketChannel::Public, 'position' => 11,
                'program_session_id' => $session->id, 'max_per_order' => 2, 'name' => ['en' => 'Workshop 1', 'it' => 'Workshop 1'],
            ]);
            $workshop->pools()->attach($event->capacityPools()->create(['name' => 'Workshop W01', 'capacity' => 20, 'max_per_order' => 2]));
            $workshop->prices()->create(['price_role' => PriceRole::Public, 'amount_cents' => 9900]);

            // La membership è dell'organizzatore, non dell'evento: si crea solo se manca.
            if (! MembershipPlan::exists()) {
                MembershipPlan::create([
                    'name' => ['en' => 'Annual membership', 'it' => 'Membership annuale'],
                    'amount_cents' => 10000, 'billing_interval' => 'year',
                ]);
            }

            return $event;
        });
    }
}
