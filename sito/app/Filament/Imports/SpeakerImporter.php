<?php

namespace App\Filament\Imports;

use App\Models\Speaker;
use App\Services\SpeakerPhoto;
use Filament\Actions\Imports\ImportColumn;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/**
 * speakers.csv. Le foto si copiano prima in storage/app/private/speaker-photos/ (disco privato) con il nome di
 * photo_file: l'import le ritaglia, toglie i dati EXIF e pubblica la copia. L'email resta privata.
 */
class SpeakerImporter extends ProgramImporter
{
    protected static ?string $model = Speaker::class;

    /** @var Speaker */
    protected ?Model $record;

    public static function getColumns(): array
    {
        return [
            ImportColumn::make('code')->requiredMapping()->rules(['required', 'regex:/^[A-Za-z0-9-]{1,30}$/']),
            ImportColumn::make('honorific')->rules(['nullable', 'max:20']),
            ImportColumn::make('first_name')->requiredMapping()->rules(['required', 'max:100']),
            ImportColumn::make('last_name')->requiredMapping()->rules(['required', 'max:100']),
            ImportColumn::make('affiliation')->rules(['nullable', 'max:200']),
            ImportColumn::make('country')->rules(['nullable', 'alpha', 'size:2']),
            ImportColumn::make('bio_en')->requiredMapping()->rules(['required', 'max:3000']),
            ImportColumn::make('bio_it')->rules(['nullable', 'max:3000']),
            ImportColumn::make('photo_file')->rules(['nullable', 'regex:/^[A-Za-z0-9_-]+\.(jpe?g|png)$/i']),
            ImportColumn::make('website')->rules(['nullable', 'url:https,http', 'max:500']),
            ImportColumn::make('other_links')->array('|')->nestedRecursiveRules(['url:https,http', 'max:500']),
            ImportColumn::make('email')->rules(['nullable', 'email', 'max:255']),
        ];
    }

    public function resolveRecord(): ?Speaker
    {
        if (self::isExample($this->data['code'] ?? '')) {
            return null;
        }

        return Speaker::firstOrNew(['code' => $this->data['code']]);
    }

    public function fillRecord(): void
    {
        $this->record->fill([
            'honorific' => $this->data['honorific'] ?? null,
            'first_name' => $this->data['first_name'],
            'last_name' => $this->data['last_name'],
            'affiliation' => $this->data['affiliation'] ?? null,
            'country' => $this->data['country'] ?? null,
            'bio' => $this->texts('bio'),
            'website' => $this->data['website'] ?? null,
            'links' => array_values($this->data['other_links'] ?? []) ?: null,
            'email' => $this->data['email'] ?? null,
        ]);

        if (filled($file = $this->data['photo_file'] ?? null)) {
            $path = SpeakerPhoto::IMPORT_DIR.'/'.$file;
            if (! Storage::disk('local')->exists($path)) {
                $this->fail("Photo \"$file\" not found: copy it to storage/app/private/".SpeakerPhoto::IMPORT_DIR.'/ or empty photo_file.');
            }
            $this->record->photo_path = app(SpeakerPhoto::class)->store((string) Storage::disk('local')->get($path));
        }
    }
}
