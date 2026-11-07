<?php

namespace App\Models;

use App\Enums\StripeEventStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** Evento Stripe ricevuto dal webhook: la chiave primaria è l'id di Stripe (evt_...), quindi si salva una volta sola. */
#[Fillable(['id', 'type', 'livemode', 'api_version', 'stripe_created_at', 'status', 'attempts', 'last_error', 'ignored_reason',
    'processed_at', 'object_id', 'payload'])]
class StripeEvent extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return [
            'livemode' => 'boolean',
            'stripe_created_at' => 'datetime',
            'received_at' => 'datetime',
            'status' => StripeEventStatus::class,
            'attempts' => 'integer',
            'processed_at' => 'datetime',
            'payload' => 'array',
        ];
    }
}
