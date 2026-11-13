<?php

namespace App\Filament\Resources\ProgramSessions;

use App\Enums\ProgramSessionAccess;
use App\Enums\SessionFormat;
use App\Enums\SpeakerRole;
use App\Filament\Resources\ProgramSessions\Pages\CreateProgramSession;
use App\Filament\Resources\ProgramSessions\Pages\EditProgramSession;
use App\Filament\Resources\ProgramSessions\Pages\ListProgramSessions;
use App\Models\Event;
use App\Models\ProgramSession;
use App\Models\Room;
use App\Models\Speaker;
use App\Models\Track;
use App\Services\ProgramService;
use BackedEnum;
use Closure;
use Filament\Actions\BulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rules\Unique;
use UnitEnum;

/**
 * Sessioni del programma con speaker e interventi in ordine. Si salvano con ProgramService (sala libera, giorno
 * dell'evento); la sala occupata è anche un errore leggibile del form. Il biglietto dei workshop si gestisce in Sales.
 */
class ProgramSessionResource extends Resource
{
    protected static ?string $model = ProgramSession::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static string|UnitEnum|null $navigationGroup = 'Programme';

    protected static ?int $navigationSort = 3;

    protected static ?string $modelLabel = 'session';

    public static function form(Schema $schema): Schema
    {
        $name = fn (Room|Track $record) => $record->name['en'] ?? '';
        $partTz = fn (Get $get) => Event::timezoneOf($get('../../event_id'));

        return $schema->columns(1)->components([
            Section::make('Session')->columns(2)->schema([
                Select::make('event_id')->relationship('event', 'slug')->required()->default(fn () => Event::defaultId())->live(),
                TextInput::make('code')->required()->regex('/^[A-Za-z0-9-]{1,30}$/')
                    ->unique(ignoreRecord: true, modifyRuleUsing: fn (Unique $rule, Get $get) => $rule->where('event_id', $get('event_id')))
                    ->helperText('Key used in the CSV files, e.g. ses-001. Do not change it after the first import.'),
                TextInput::make('title.en')->label('Title (EN)')->required()->maxLength(300),
                TextInput::make('title.it')->label('Title (IT)')->maxLength(300),
                Select::make('format')->options(self::enumOptions(SessionFormat::cases()))->required()->default(SessionFormat::Lecture->value),
                Select::make('access')->options([
                    ProgramSessionAccess::Included->value => 'Included in the pass',
                    ProgramSessionAccess::IncludedBooking->value => 'Included, booking needed',
                    ProgramSessionAccess::Paid->value => 'Paid (workshop ticket in Sales)',
                ])->required()->default(ProgramSessionAccess::Included->value),
                Select::make('room_id')->relationship('room', 'code', fn (Builder $query, Get $get) => $query->where('event_id', $get('event_id'))->orderBy('position'))
                    ->getOptionLabelFromRecordUsing($name)->preload()->requiredWith('starts_at'),
                Select::make('track_id')->relationship('track', 'slug', fn (Builder $query, Get $get) => $query->where('event_id', $get('event_id'))->orderBy('position'))
                    ->getOptionLabelFromRecordUsing($name)->preload(),
                DateTimePicker::make('starts_at')->label('Starts (event time)')->timezone(fn (Get $get) => Event::timezoneOf($get('event_id')))->seconds(false)->requiredWith('room_id'),
                DateTimePicker::make('ends_at')->label('Ends (event time)')->timezone(fn (Get $get) => Event::timezoneOf($get('event_id')))->seconds(false)
                    ->after('starts_at')->requiredWith('starts_at')
                    ->rules([fn (Get $get, ?ProgramSession $record) => self::roomFree($get, $record)]),
                Textarea::make('abstract.en')->label('Abstract (EN)')->rows(5)->maxLength(5000),
                Textarea::make('abstract.it')->label('Abstract (IT)')->rows(5)->maxLength(5000),
                Toggle::make('published')->helperText('Only published sessions appear on the site.'),
            ]),
            Section::make('Speakers and moderators')->description('Drag to change the order shown on the site.')->schema([
                Repeater::make('sessionSpeakers')->hiddenLabel()->relationship()->orderColumn('position')->defaultItems(0)
                    ->columns(2)->addActionLabel('Add speaker')
                    ->schema([
                        Select::make('speaker_id')->label('Speaker')->relationship('speaker', 'last_name')
                            ->getOptionLabelFromRecordUsing(fn (Speaker $record) => $record->name().' ('.$record->code.')')
                            ->searchable(['first_name', 'last_name', 'code'])->preload()->distinct()->required(),
                        Select::make('role')->options(self::enumOptions(SpeakerRole::cases()))->required()->default(SpeakerRole::Speaker->value),
                    ]),
            ]),
            Section::make('Parts')->description('Talks inside the session, in order.')->schema([
                Repeater::make('parts')->hiddenLabel()->relationship()->orderColumn('position')->defaultItems(0)
                    ->columns(2)->collapsible()->addActionLabel('Add part')
                    ->itemLabel(fn (array $state) => $state['title']['en'] ?? null)
                    ->schema([
                        TextInput::make('title.en')->label('Title (EN)')->required()->maxLength(300),
                        TextInput::make('title.it')->label('Title (IT)')->maxLength(300),
                        DateTimePicker::make('starts_at')->label('Starts (event time)')->timezone($partTz)->seconds(false),
                        DateTimePicker::make('ends_at')->label('Ends (event time)')->timezone($partTz)->seconds(false)->after('starts_at'),
                        Select::make('speakers')->relationship('speakers', 'last_name')->multiple()->preload()
                            ->getOptionLabelFromRecordUsing(fn (Speaker $record) => $record->name())->columnSpanFull(),
                        Textarea::make('abstract.en')->label('Abstract (EN)')->rows(3)->maxLength(5000),
                        Textarea::make('abstract.it')->label('Abstract (IT)')->rows(3)->maxLength(5000),
                    ]),
            ]),
        ]);
    }

    /** Errore leggibile se la sala è già occupata in quell'orario (lo stesso controllo di ProgramService::save). */
    public static function roomFree(Get $get, ?ProgramSession $record): Closure
    {
        return function (string $attribute, $value, Closure $fail) use ($get, $record) {
            if (! $get('room_id') || ! $get('starts_at') || ! $value) {
                return;
            }
            // $get dà lo stato già convertito in UTC dal DateTimePicker (timezone): si usa per entrambi gli orari.
            $probe = new ProgramSession([
                'room_id' => $get('room_id'),
                'starts_at' => Carbon::parse($get('starts_at'), 'UTC'),
                'ends_at' => Carbon::parse($get('ends_at'), 'UTC'),
            ]);
            if ($record) {
                $probe->setAttribute('id', $record->getKey());
                $probe->exists = true;
            }
            $service = app(ProgramService::class);
            if ($other = $service->overlap($probe)) {
                $fail($service->overlapMessage($other));
            }
        };
    }

    /**
     * @param  array<SessionFormat|SpeakerRole>  $cases
     * @return array<string, string>
     */
    private static function enumOptions(array $cases): array
    {
        return collect($cases)->mapWithKeys(fn ($case) => [$case->value => ucfirst($case->value)])->all();
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('starts_at')
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['event', 'room', 'track', 'ticketType']))
            ->columns([
                TextColumn::make('event.slug')->label('Event')->toggleable(),
                TextColumn::make('starts_at')->label('Starts')->dateTime('D d/m H:i', fn (ProgramSession $record) => $record->event->timezone)->sortable(),
                TextColumn::make('ends_at')->label('Ends')->dateTime('H:i', fn (ProgramSession $record) => $record->event->timezone),
                TextColumn::make('room.name.en')->label('Room'),
                TextColumn::make('code')->searchable(),
                TextColumn::make('title.en')->label('Title')->limit(60)->wrap()
                    ->searchable(query: fn (Builder $query, string $search) => $query->where('title->en', 'like', "%$search%")),
                TextColumn::make('format')->badge(),
                TextColumn::make('track.name.en')->label('Track')->toggleable(),
                TextColumn::make('ticketType.name.en')->label('Workshop ticket')->toggleable(),
                IconColumn::make('published')->boolean(),
            ])
            ->filters([
                SelectFilter::make('event_day_id')->label('Day')->relationship('eventDay', 'day')
                    ->getOptionLabelFromRecordUsing(fn ($record) => $record->day->format('D d/m/Y')),
                SelectFilter::make('room_id')->label('Room')->relationship('room', 'code')
                    ->getOptionLabelFromRecordUsing(fn (Room $record) => $record->name['en'] ?? $record->code),
                TernaryFilter::make('published'),
            ])
            ->recordActions([EditAction::make()])
            ->toolbarActions([
                BulkAction::make('publish')->label('Publish')->icon(Heroicon::OutlinedEye)
                    ->authorize('update', ProgramSession::class)
                    ->action(fn (Collection $records) => ProgramSession::whereKey($records->modelKeys())->update(['published' => true])),
                BulkAction::make('unpublish')->label('Unpublish')->icon(Heroicon::OutlinedEyeSlash)
                    ->authorize('update', ProgramSession::class)
                    ->action(fn (Collection $records) => ProgramSession::whereKey($records->modelKeys())->update(['published' => false])),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProgramSessions::route('/'),
            'create' => CreateProgramSession::route('/create'),
            'edit' => EditProgramSession::route('/{record}/edit'),
        ];
    }
}
