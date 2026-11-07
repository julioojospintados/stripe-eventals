<?php

namespace App\Models;

use App\Enums\SpeakerRole;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Chi parla o modera in una sessione, in ordine (riga della tabella session_speaker). */
#[Fillable(['program_session_id', 'speaker_id', 'role', 'position'])]
class SessionSpeaker extends Model
{
    use HasUuids;

    protected $table = 'session_speaker';

    protected function casts(): array
    {
        return ['role' => SpeakerRole::class, 'position' => 'integer'];
    }

    /** @return BelongsTo<ProgramSession, $this> */
    public function programSession(): BelongsTo
    {
        return $this->belongsTo(ProgramSession::class);
    }

    /** @return BelongsTo<Speaker, $this> */
    public function speaker(): BelongsTo
    {
        return $this->belongsTo(Speaker::class);
    }
}
