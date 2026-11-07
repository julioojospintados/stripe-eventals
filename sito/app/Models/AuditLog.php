<?php

namespace App\Models;

use App\Enums\StaffRole;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Registro audit, solo in aggiunta. */
#[Fillable(['occurred_at', 'actor_id', 'actor_type', 'action', 'entity_type', 'entity_id', 'ip', 'user_agent', 'data'])]
class AuditLog extends Model
{
    protected $table = 'audit_log';

    public $timestamps = false;

    protected function casts(): array
    {
        return ['occurred_at' => 'datetime', 'data' => 'array'];
    }

    /** Scrive una riga; senza utente è un'azione di sistema (comando, job). */
    public static function record(string $action, ?Model $entity = null, ?User $actor = null, array $data = []): self
    {
        return self::create([
            'occurred_at' => now(),
            'actor_id' => $actor?->id,
            'actor_type' => match (true) {
                $actor === null => 'system',
                $actor->hasAnyRole(StaffRole::names()) => 'staff',
                default => 'user',
            },
            'action' => $action,
            'entity_type' => $entity?->getMorphClass(),
            'entity_id' => $entity?->getKey(),
            'ip' => app()->runningInConsole() ? null : request()->ip(),
            'user_agent' => app()->runningInConsole() ? null : mb_substr((string) request()->userAgent(), 0, 500),
            'data' => $data ?: null,
        ]);
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
