<?php

namespace App\Filament\Resources\Speakers;

use App\Filament\Resources\Speakers\Pages\ManageSpeakers;
use App\Models\Speaker;
use App\Services\SpeakerPhoto;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Speaker e moderatori. La foto caricata qui si ritaglia quadrata e si ricodifica senza EXIF al salvataggio (model
 * Speaker); l'email non compare mai nelle pagine pubbliche.
 */
class SpeakerResource extends Resource
{
    protected static ?string $model = Speaker::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMicrophone;

    protected static string|UnitEnum|null $navigationGroup = 'Programme';

    protected static ?int $navigationSort = 4;

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextInput::make('code')->required()->regex('/^[A-Za-z0-9-]{1,30}$/')->unique(ignoreRecord: true)
                ->helperText('Key used in sessions.csv, e.g. spk-001. Do not change it after the first import.'),
            TextInput::make('honorific')->maxLength(20)->helperText('Dr., Prof.'),
            TextInput::make('first_name')->required()->maxLength(100),
            TextInput::make('last_name')->required()->maxLength(100),
            TextInput::make('affiliation')->maxLength(200),
            TextInput::make('country')->length(2)->alpha()->helperText('ISO code: PT, IT, FR, US.'),
            Textarea::make('bio.en')->label('Bio (EN)')->required()->rows(6)->maxLength(3000),
            Textarea::make('bio.it')->label('Bio (IT)')->rows(6)->maxLength(3000),
            FileUpload::make('photo_path')->label('Photo')->disk('public')->directory(SpeakerPhoto::UPLOADS)
                ->image()->acceptedFileTypes(['image/jpeg', 'image/png'])->maxSize(5120)
                ->helperText('Square JPG or PNG, at least 800 × 800, at most 5 MB. Cropped and stripped of hidden data (camera, GPS) on save.'),
            TextInput::make('website')->url()->maxLength(500),
            TagsInput::make('links')->label('Other links')->nestedRecursiveRules(['url', 'max:500']),
            TextInput::make('email')->email()->maxLength(255)->helperText('Private: never shown on the site.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('last_name')
            ->columns([
                ImageColumn::make('photo_path')->label('Photo')->disk('public')->square()->imageSize(40),
                TextColumn::make('last_name')->label('Name')->searchable(['first_name', 'last_name'])
                    ->formatStateUsing(fn (Speaker $record) => $record->name()),
                TextColumn::make('code')->searchable(),
                TextColumn::make('affiliation')->limit(40),
                TextColumn::make('country'),
                TextColumn::make('program_sessions_count')->counts('programSessions')->label('Sessions'),
            ])
            ->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageSpeakers::route('/')];
    }
}
