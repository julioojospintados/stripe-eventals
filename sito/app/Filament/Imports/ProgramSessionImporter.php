<?php

namespace App\Filament\Imports;

use App\Enums\PriceRole;
use App\Enums\ProgramSessionAccess;
use App\Enums\SessionFormat;
use App\Enums\SpeakerRole;
use App\Enums\TicketChannel;
use App\Enums\TicketKind;
use App\Models\ProgramSession;
use App\Models\Room;
use App\Models\Speaker;
use App\Models\TicketPrice;
use App\Models\Track;
use App\Services\ProgramException;
use App\Services\ProgramService;
use Filament\Actions\Imports\ImportColumn;
use Filament\Forms\Components\Checkbox;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * sessions.csv. Orari di Lisbona convertiti in UTC; sala occupata, sala o speaker sconosciuti e giorno fuori
 * dall'evento sono errori della riga. Le sessioni nuove arrivano non pubblicate.
 * paid=yes: alla prima importazione crea il tipo di biglietto del workshop (spento), il suo pool di posti e il prezzo
 * base pubblico; dopo, biglietto, posti e prezzo si cambiano dal pannello (Sales), non dal foglio.
 */
class ProgramSessionImporter extends ProgramImporter
{
    protected static ?string $model = ProgramSession::class;

    /** @var ProgramSession */
    protected ?Model $record;

    /** Prezzo dei workshop se l'evento non ne ha ancora uno (ticket_prices.csv, riga workshop). */
    public const DEFAULT_WORKSHOP_CENTS = 10000;

    public static function getColumns(): array
    {
        $code = 'regex:/^[A-Za-z0-9-]{1,30}$/';

        return [
            ImportColumn::make('code')->requiredMapping()->rules(['required', $code]),
            ImportColumn::make('day')->requiredMapping()->rules(['required', 'date_format:Y-m-d']),
            ImportColumn::make('start')->requiredMapping()->rules(['required', 'date_format:H:i']),
            ImportColumn::make('end')->requiredMapping()->rules(['required', 'date_format:H:i']),
            ImportColumn::make('room_code')->requiredMapping()->rules(['required', $code]),
            ImportColumn::make('track')->rules(['nullable', 'max:100']),
            ImportColumn::make('format')->requiredMapping()->rules(['required', 'in:'.implode(',', array_column(SessionFormat::cases(), 'value'))]),
            ImportColumn::make('title_en')->requiredMapping()->rules(['required', 'max:300']),
            ImportColumn::make('title_it')->rules(['nullable', 'max:300']),
            ImportColumn::make('abstract_en')->rules(['nullable', 'max:5000']),
            ImportColumn::make('abstract_it')->rules(['nullable', 'max:5000']),
            ImportColumn::make('speaker_codes')->array('|')->nestedRecursiveRules([$code]),
            ImportColumn::make('moderator_codes')->array('|')->nestedRecursiveRules([$code]),
            ImportColumn::make('paid')->requiredMapping()->rules(['required', 'in:yes,no']),
            ImportColumn::make('seats')->integer()->rules(['nullable', 'required_if:paid,yes', 'integer', 'min:1']),
            ImportColumn::make('price_eur')->castStateUsing(fn ($state) => blank($state) ? null : str_replace(',', '.', (string) $state))
                ->rules(['nullable', 'decimal:0,2', 'min:0']),
        ];
    }

    public static function getOptionsFormComponents(): array
    {
        return [
            ...parent::getOptionsFormComponents(),
            Checkbox::make('create_tracks')->label('Create the tracks that do not exist yet')
                ->helperText('Leave it off to get an error for every unknown track (e.g. a typo).'),
        ];
    }

    public function resolveRecord(): ?ProgramSession
    {
        if (self::isExample($this->data['code'] ?? '')) {
            return null;
        }

        return ProgramSession::firstOrNew(['event_id' => $this->event()->id, 'code' => $this->data['code']]);
    }

    public function fillRecord(): void
    {
        $event = $this->event();
        $room = Room::where('event_id', $event->id)->where('code', $this->data['room_code'])->first()
            ?? $this->fail("Unknown room \"{$this->data['room_code']}\": import rooms.csv first.");
        $startsAt = Carbon::createFromFormat('Y-m-d H:i', "{$this->data['day']} {$this->data['start']}", $event->timezone);
        $endsAt = Carbon::createFromFormat('Y-m-d H:i', "{$this->data['day']} {$this->data['end']}", $event->timezone);
        if ($endsAt <= $startsAt) {
            $this->fail('The end time must be after the start time.');
        }

        $paid = $this->data['paid'] === 'yes';
        if (! $paid && $this->record->exists && $this->record->ticketType()->exists()) {
            $this->fail('This session is sold as a workshop: it cannot become paid=no from the file.');
        }

        $this->record->fill([
            'room_id' => $room->id,
            'track_id' => $this->track(),
            'format' => $this->data['format'],
            'title' => $this->texts('title'),
            'abstract' => $this->texts('abstract'),
            'starts_at' => $startsAt->utc(),
            'ends_at' => $endsAt->utc(),
            'access' => match (true) {
                $paid => ProgramSessionAccess::Paid,
                filled($this->data['seats'] ?? null) => ProgramSessionAccess::IncludedBooking,
                default => ProgramSessionAccess::Included,
            },
        ]);
    }

    public function saveRecord(): void
    {
        // Una transazione per riga: se gli speaker o il biglietto falliscono, la sessione non resta a metà.
        DB::transaction(function () {
            try {
                app(ProgramService::class)->save($this->record);
            } catch (ProgramException $e) {
                $this->fail($e->getMessage());
            }
            $this->syncSpeakers();
            if ($this->data['paid'] === 'yes' && ! $this->record->ticketType()->exists()) {
                $this->createWorkshopTicket();
            }
        });
    }

    private function track(): ?string
    {
        if (blank($name = $this->data['track'] ?? null)) {
            return null;
        }
        $event = $this->event();
        $track = Track::where('event_id', $event->id)->where('slug', Track::slugFor($name))->first();
        if (! $track && empty($this->options['create_tracks'])) {
            $this->fail("Unknown track \"$name\": create it in Tracks or tick «Create the tracks that do not exist yet».");
        }

        return ($track ?? Track::create(['event_id' => $event->id, 'name' => ['en' => $name]]))->id;
    }

    private function syncSpeakers(): void
    {
        $lists = [
            SpeakerRole::Speaker->value => $this->data['speaker_codes'] ?? [],
            SpeakerRole::Moderator->value => $this->data['moderator_codes'] ?? [],
        ];
        $all = array_merge(...array_values($lists));
        if (count(array_unique(array_map('strtolower', $all))) < count($all)) {
            $this->fail('A speaker appears twice (as speaker and moderator, or repeated).');
        }
        $ids = Speaker::whereIn('code', $all)->pluck('id', 'code')->mapWithKeys(fn ($id, $code) => [strtolower($code) => $id]);
        if ($missing = array_diff(array_map('strtolower', $all), $ids->keys()->all())) {
            $this->fail('Unknown speaker codes: '.implode(', ', $missing).'. Import speakers.csv first.');
        }

        $this->record->sessionSpeakers()->delete();
        $position = 0;
        foreach ($lists as $role => $codes) {
            foreach ($codes as $code) {
                $this->record->sessionSpeakers()->create(['speaker_id' => $ids[strtolower($code)], 'role' => $role, 'position' => ++$position]);
            }
        }
    }

    private function createWorkshopTicket(): void
    {
        $event = $this->event();
        $session = $this->record;
        $cents = filled($this->data['price_eur'] ?? null)
            ? (int) round((float) $this->data['price_eur'] * 100)
            : TicketPrice::whereNull('price_tier_id')->where('price_role', PriceRole::Public)
                ->whereHas('ticketType', fn ($q) => $q->where('event_id', $event->id)->where('kind', TicketKind::PaidSession))
                ->value('amount_cents') ?? self::DEFAULT_WORKSHOP_CENTS;

        $type = $event->ticketTypes()->create([
            'kind' => TicketKind::PaidSession, 'channel' => TicketChannel::Public, 'program_session_id' => $session->id,
            'name' => $session->title, 'max_per_order' => 2, 'active' => false, 'position' => 100,
        ]);
        $type->pools()->attach($event->capacityPools()->create([
            'name' => "Workshop {$session->code}", 'capacity' => $this->data['seats'], 'max_per_order' => 2,
        ]));
        $type->prices()->create(['price_role' => PriceRole::Public, 'amount_cents' => $cents]);
    }
}
