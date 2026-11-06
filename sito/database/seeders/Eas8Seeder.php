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
 * Dati veri dell'EAS8 (risposte del cliente del 06/11/2026, db/modello-dati.md §3.3). Importi in centesimi.
 * Le date delle fasce sono provvisorie (da confermare con Simone) e si cambiano dal pannello.
 * Non fa nulla se l'evento esiste già.
 */
class Eas8Seeder extends Seeder
{
    public function run(): void
    {
        if (Event::where('slug', 'eas8-lisbon-2027')->exists()) {
            return;
        }

        DB::transaction(function () {
            $event = Event::create([
                'slug' => 'eas8-lisbon-2027',
                'title' => ['en' => 'EAS8 Lisbon', 'it' => 'EAS8 Lisbona'],
                'status' => EventStatus::Published,
                'timezone' => 'Europe/Lisbon',
                'starts_on' => '2027-04-08',
                'ends_on' => '2027-04-10',
                'venue_name' => 'Lisbon',
                'currency' => 'eur',
            ]);

            // Un pool da 6.000 posti per giorno: lo consumano pass, accompagnatori, espositori e omaggi.
            $dayPools = [];
            foreach (['2027-04-08', '2027-04-09', '2027-04-10'] as $i => $day) {
                $event->days()->create(['day' => $day, 'label' => ['en' => 'Day '.($i + 1), 'it' => 'Giorno '.($i + 1)]]);
                $dayPools[] = $event->capacityPools()->create(['name' => 'Day '.($i + 1).' ('.$day.')', 'capacity' => 6000])->id;
            }

            $earlyBird = $event->priceTiers()->create([
                'code' => 'early_bird', 'name' => ['en' => 'Early bird', 'it' => 'Early bird'],
                'starts_at' => '2026-11-01 00:00:00', 'ends_at' => '2027-02-01 00:00:00', 'position' => 1,
            ]);
            $event->priceTiers()->create([
                'code' => 'regular', 'name' => ['en' => 'Regular', 'it' => 'Regular'],
                'starts_at' => '2027-02-01 00:00:00', 'ends_at' => '2027-04-01 00:00:00', 'position' => 2,
            ]);
            // Spenta e senza prezzo finché il cliente non decide il prezzo last minute.
            $event->priceTiers()->create([
                'code' => 'last_minute', 'name' => ['en' => 'Last minute', 'it' => 'Last minute'],
                'starts_at' => '2027-04-01 00:00:00', 'ends_at' => '2027-04-10 18:00:00', 'position' => 3, 'active' => false,
            ]);

            // Pass intero: regular 695 € pubblico e 395 € membro (prezzi base); early bird −30% solo membri, 276,50 €.
            $pass = $event->ticketTypes()->create([
                'kind' => TicketKind::FullPass, 'channel' => TicketChannel::Public, 'position' => 1,
                'name' => ['en' => 'Full pass', 'it' => 'Pass completo'],
            ]);
            $pass->pools()->attach($dayPools);
            $pass->prices()->createMany([
                ['price_role' => PriceRole::Public, 'amount_cents' => 69500],
                ['price_role' => PriceRole::Member, 'amount_cents' => 39500],
                ['price_role' => PriceRole::Member, 'price_tier_id' => $earlyBird->id, 'amount_cents' => 27650],
            ]);

            // 10 workshop da 20 posti, 100 € per tutti (nessun prezzo membro), massimo 2 per ordine.
            for ($n = 1; $n <= 10; $n++) {
                $code = sprintf('W%02d', $n);
                $session = $event->programSessions()->create([
                    'code' => $code, 'slug' => 'workshop-'.$n, 'access' => ProgramSessionAccess::Paid,
                    'title' => ['en' => "Workshop $n", 'it' => "Workshop $n"],
                ]);
                $workshop = $event->ticketTypes()->create([
                    'kind' => TicketKind::PaidSession, 'channel' => TicketChannel::Public, 'position' => 10 + $n,
                    'program_session_id' => $session->id, 'max_per_order' => 2,
                    'name' => ['en' => "Workshop $n", 'it' => "Workshop $n"],
                ]);
                $workshop->pools()->attach($event->capacityPools()->create([
                    'name' => "Workshop $code", 'capacity' => 20, 'max_per_order' => 2,
                ]));
                $workshop->prices()->create(['price_role' => PriceRole::Public, 'amount_cents' => 10000]);
            }

            // Presenti ma spenti, senza prezzo: accompagnatore, espositore (con codice d'invito), omaggio (dal pannello).
            foreach ([
                [TicketKind::Companion, TicketChannel::Public, ['en' => 'Companion', 'it' => 'Accompagnatore'], 1],
                [TicketKind::Exhibitor, TicketChannel::Invite, ['en' => 'Exhibitor', 'it' => 'Espositore'], null],
                [TicketKind::StaffPass, TicketChannel::Panel, ['en' => 'Complimentary pass', 'it' => 'Omaggio'], null],
            ] as $i => [$kind, $channel, $name, $maxPerHost]) {
                $event->ticketTypes()->create([
                    'kind' => $kind, 'channel' => $channel, 'name' => $name, 'max_per_host' => $maxPerHost,
                    'active' => false, 'position' => 30 + $i,
                ])->pools()->attach($dayPools);
            }

            MembershipPlan::create([
                'name' => ['en' => 'Annual membership', 'it' => 'Membership annuale'],
                'amount_cents' => 20000, 'currency' => 'eur', 'billing_interval' => 'year',
            ]);
        });
    }
}
