<?php

namespace App\Models;

use App\Services\SpeakerPhoto;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Speaker o moderatore. Pubblico solo se ha almeno una sessione pubblicata (scope visible). L'email è privata: serve
 * al biglietto omaggio e ai contatti, mai nelle pagine.
 */
#[Fillable(['code', 'honorific', 'first_name', 'last_name', 'affiliation', 'country', 'bio', 'photo_path', 'website',
    'links', 'email'])]
#[Hidden(['email'])]
class Speaker extends Model
{
    use HasFactory, HasUuids;

    protected function casts(): array
    {
        return ['bio' => 'array', 'links' => 'array'];
    }

    protected static function booted(): void
    {
        static::creating(function (Speaker $speaker) {
            $slug = Str::limit(Str::slug($speaker->first_name.' '.$speaker->last_name), 120, '');
            $speaker->slug = self::where('slug', $slug)->exists() ? $slug.'-'.Str::lower($speaker->code) : $slug;
        });

        static::saving(function (Speaker $speaker) {
            $speaker->country = $speaker->country ? strtoupper($speaker->country) : null;
            $speaker->email = $speaker->email ? Str::lower($speaker->email) : null;

            // Foto appena caricata dal pannello: ricodificata (senza EXIF) e originale cancellata.
            $disk = Storage::disk('public');
            if ($speaker->isDirty('photo_path') && str_starts_with((string) $speaker->photo_path, SpeakerPhoto::UPLOADS.'/')) {
                $upload = (string) $speaker->photo_path;
                $speaker->photo_path = app(SpeakerPhoto::class)->store((string) $disk->get($upload));
                $disk->delete($upload);
            }
            if ($speaker->isDirty('photo_path') && $speaker->getOriginal('photo_path')) {
                $disk->delete($speaker->getOriginal('photo_path'));
            }
        });
    }

    public function scopeVisible(Builder $query): void
    {
        $query->where(fn (Builder $q) => $q
            ->whereHas('programSessions', fn (Builder $s) => $s->where('published', true))
            ->orWhereHas('sessionParts.programSession', fn (Builder $s) => $s->where('published', true)));
    }

    public function name(): string
    {
        return trim($this->honorific.' '.$this->first_name.' '.$this->last_name);
    }

    public function photoUrl(): ?string
    {
        return $this->photo_path ? Storage::disk('public')->url($this->photo_path) : null;
    }

    /** @return BelongsToMany<ProgramSession, $this> */
    public function programSessions(): BelongsToMany
    {
        return $this->belongsToMany(ProgramSession::class, 'session_speaker')->withPivot('role', 'position');
    }

    /** @return BelongsToMany<SessionPart, $this> */
    public function sessionParts(): BelongsToMany
    {
        return $this->belongsToMany(SessionPart::class, 'session_part_speaker');
    }

    /** @return HasMany<SessionSpeaker, $this> */
    public function sessionSpeakers(): HasMany
    {
        return $this->hasMany(SessionSpeaker::class);
    }
}
