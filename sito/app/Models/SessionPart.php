<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/** Intervento dentro una sessione (es. un simposio con tre relatori), in ordine di position. */
#[Fillable(['program_session_id', 'position', 'title', 'abstract', 'starts_at', 'ends_at'])]
class SessionPart extends Model
{
    use HasFactory, HasUuids;

    protected function casts(): array
    {
        return [
            'title' => 'array',
            'abstract' => 'array',
            'position' => 'integer',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<ProgramSession, $this> */
    public function programSession(): BelongsTo
    {
        return $this->belongsTo(ProgramSession::class);
    }

    /** @return BelongsToMany<Speaker, $this> */
    public function speakers(): BelongsToMany
    {
        return $this->belongsToMany(Speaker::class, 'session_part_speaker')->withPivot('position')->orderByPivot('position');
    }
}
