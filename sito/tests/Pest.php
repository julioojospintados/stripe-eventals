<?php

use App\Enums\TicketKind;
use App\Models\Event;
use App\Models\Membership;
use App\Models\Order;
use App\Models\TicketType;
use App\Models\User;
use App\Services\CheckoutService;
use Database\Seeders\Eas8Seeder;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/*
| Tutti i test girano su MariaDB/MySQL (database eas8_test, vedi phpunit.xml). Feature: migrate:fresh una volta, poi
| ogni test in una transazione annullata alla fine. Concurrency: dati confermati davvero (tabelle svuotate prima di
| ogni test), perché i processi separati devono vederli e aspettarsi a vicenda sui lock.
*/

pest()->extend(TestCase::class)->use(RefreshDatabase::class)->in('Feature');
pest()->extend(TestCase::class)->use(DatabaseTruncation::class)->in('Concurrency');

/** Salta il test se il trigger non c'è (hosting con DB_TRIGGERS_OPTIONAL=true): la regola resta da provare nel Service. */
function requireTrigger(string $name): void
{
    $exists = DB::table('information_schema.triggers')
        ->where('trigger_schema', DB::getDatabaseName())
        ->where('trigger_name', $name)
        ->exists();

    if (! $exists) {
        test()->markTestSkipped("Trigger $name assente (DB_TRIGGERS_OPTIONAL=true).");
    }
}

/** L'EAS8 con i dati veri del seeder: oggi (06/11/2026) è attiva l'early bird. */
function eas8(): Event
{
    (new Eas8Seeder)->run();

    return Event::where('slug', 'eas8-lisbon-2027')->firstOrFail();
}

function passType(): TicketType
{
    return eas8()->ticketTypes()->where('kind', TicketKind::FullPass)->firstOrFail();
}

function workshop(int $n = 1): TicketType
{
    return eas8()->ticketTypes()->where('kind', TicketKind::PaidSession)->where('position', 10 + $n)->firstOrFail();
}

function member(array $attributes = []): User
{
    $user = User::factory()->create($attributes);
    Membership::factory()->create(['user_id' => $user->id]);

    return $user;
}

/** Riga del carrello: un tipo e i suoi nominativi (utenti o array name/email). */
function line(TicketType $type, User|array ...$attendees): array
{
    return [
        'ticket_type_id' => $type->id,
        'attendees' => array_map(fn ($a) => $a instanceof User ? ['name' => $a->name, 'email' => $a->email] : $a, $attendees),
    ];
}

function reserve(User $user, array $lines, ?string $code = null): Order
{
    return app(CheckoutService::class)->reserve($user, eas8(), $lines, $code);
}

/** Ordine pending con la sua Checkout Session finta. */
function reserveAndCheckout(User $user, array $lines, ?string $code = null): Order
{
    $order = reserve($user, $lines, $code);
    app(CheckoutService::class)->startCheckout($order);

    return $order->refresh();
}

function stripeEvent(string $type, array $object): array
{
    return [
        'id' => 'evt_'.Str::random(16), 'object' => 'event', 'type' => $type, 'livemode' => false,
        'created' => now()->timestamp, 'api_version' => '2026-10-28.endive', 'data' => ['object' => $object],
    ];
}

/** POST al webhook con la firma di Stripe calcolata col segreto di prova. */
function postWebhook(array $event, string $secret = 'whsec_test_secret'): TestResponse
{
    $payload = json_encode($event);
    $ts = time();
    $signature = hash_hmac('sha256', "$ts.$payload", $secret);

    return test()->call('POST', '/stripe/webhook', [], [], [], [
        'HTTP_STRIPE_SIGNATURE' => "t=$ts,v1=$signature", 'CONTENT_TYPE' => 'application/json',
    ], $payload);
}
