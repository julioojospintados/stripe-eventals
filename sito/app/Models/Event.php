<?php

namespace App\Models;

use App\Enums\EventStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['slug', 'title', 'summary', 'description', 'status', 'timezone', 'starts_on', 'ends_on', 'venue_name',
    'venue_address', 'currency', 'sales_open_at', 'sales_close_at'])]
class Event extends Model
{
    use HasFactory, HasUuids;

    protected function casts(): array
    {
        return [
            'title' => 'array',
            'summary' => 'array',
            'description' => 'array',
            'status' => EventStatus::class,
            'starts_on' => 'date',
            'ends_on' => 'date',
            'sales_open_at' => 'datetime',
            'sales_close_at' => 'datetime',
        ];
    }

    public function scopePublished(Builder $query): void
    {
        $query->where('status', EventStatus::Published);
    }

    public function days(): HasMany
    {
        return $this->hasMany(EventDay::class)->orderBy('day');
    }

    public function programSessions(): HasMany
    {
        return $this->hasMany(ProgramSession::class);
    }

    public function ticketTypes(): HasMany
    {
        return $this->hasMany(TicketType::class)->orderBy('position');
    }

    public function priceTiers(): HasMany
    {
        return $this->hasMany(PriceTier::class)->orderBy('starts_at');
    }

    public function capacityPools(): HasMany
    {
        return $this->hasMany(CapacityPool::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function discountCodes(): HasMany
    {
        return $this->hasMany(DiscountCode::class);
    }
}
