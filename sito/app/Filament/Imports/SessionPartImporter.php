<?php

namespace App\Filament\Imports;

use App\Models\ProgramSession;
use App\Models\SessionPart;
use App\Models\Speaker;
use Filament\Actions\Imports\ImportColumn;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** session_parts.csv: la chiave è (session_code, position); gli orari stanno dentro quelli della sessione. */
class SessionPartImporter extends ProgramImporter
{
    protected static ?string $model = SessionPart::class;

    /** @var SessionPart */
    protected ?Model $record;

    public static function getColumns(): array
    {
        $code = 'regex:/^[A-Za-z0-9-]{1,30}$/';

        return [
            ImportColumn::make('session_code')->requiredMapping()->rules(['required', $code]),
            ImportColumn::make('position')->requiredMapping()->integer()->rules(['required', 'integer', 'between:1,999']),
            ImportColumn::make('start')->rules(['nullable', 'required_with:end', 'date_format:H:i']),
            ImportColumn::make('end')->rules(['nullable', 'required_with:start', 'date_format:H:i']),
            ImportColumn::make('title_en')->requiredMapping()->rules(['required', 'max:300']),
            ImportColumn::make('title_it')->rules(['nullable', 'max:300']),
            ImportColumn::make('abstract_en')->rules(['nullable', 'max:5000']),
            ImportColumn::make('abstract_it')->rules(['nullable', 'max:5000']),
            ImportColumn::make('speaker_codes')->array('|')->nestedRecursiveRules([$code]),
        ];
    }

    public function resolveRecord(): ?SessionPart
    {
        if (self::isExample($this->data['session_code'] ?? '')) {
            return null;
        }
        $session = ProgramSession::where('event_id', $this->event()->id)->where('code', $this->data['session_code'])->first()
            ?? $this->fail("Unknown session \"{$this->data['session_code']}\": import sessions.csv first.");

        return SessionPart::firstOrNew(['program_session_id' => $session->id, 'position' => $this->data['position']]);
    }

    public function fillRecord(): void
    {
        $session = $this->record->programSession;
        $startsAt = $endsAt = null;
        if (filled($this->data['start'] ?? null)) {
            if (! $session->starts_at || ! $session->ends_at) {
                $this->fail('The session has no times yet.');
            }
            $tz = $this->event()->timezone;
            $day = $session->starts_at->copy()->timezone($tz)->toDateString();
            $startsAt = Carbon::createFromFormat('Y-m-d H:i', "$day {$this->data['start']}", $tz)->utc();
            $endsAt = Carbon::createFromFormat('Y-m-d H:i', "$day {$this->data['end']}", $tz)->utc();
            if ($endsAt <= $startsAt || $startsAt < $session->starts_at || $endsAt > $session->ends_at) {
                $this->fail('The part times must be in order and within the session times.');
            }
        }

        $this->record->fill([
            'title' => $this->texts('title'),
            'abstract' => $this->texts('abstract'),
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
        ]);
    }

    public function saveRecord(): void
    {
        $codes = $this->data['speaker_codes'] ?? [];
        $ids = Speaker::whereIn('code', $codes)->pluck('id', 'code')->mapWithKeys(fn ($id, $code) => [strtolower($code) => $id]);
        if ($missing = array_diff(array_map('strtolower', $codes), $ids->keys()->all())) {
            $this->fail('Unknown speaker codes: '.implode(', ', $missing).'. Import speakers.csv first.');
        }

        DB::transaction(function () use ($codes, $ids) {
            $this->record->save();
            $this->record->speakers()->sync(collect(array_values($codes))
                ->mapWithKeys(fn ($code, $i) => [$ids[strtolower($code)] => ['position' => $i + 1]])->all());
        });
    }
}
