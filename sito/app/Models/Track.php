<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/** Traccia tematica; lo slug viene dal nome inglese ed è la chiave della colonna track del foglio. */
#[Fillable(['event_id', 'slug', 'name', 'color', 'position'])]
class Track extends Model
{
    use HasFactory, HasUuids;

    protected function casts(): array
    {
        return ['name' => 'array', 'position' => 'integer'];
    }

    protected static function booted(): void
    {
        static::saving(fn (Track $track) => $track->slug = self::slugFor($track->name['en'] ?? ''));
    }

    public static function slugFor(string $name): string
    {
        return Str::limit(Str::slug($name), 100, '');
    }

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }
}
