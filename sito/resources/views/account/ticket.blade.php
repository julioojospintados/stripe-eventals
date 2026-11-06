{{-- Un biglietto con il QR (solo se valido). --}}
<article class="scheda">
    <h3 class="t-3">{{ localized($ticket->ticketType->name) }}</h3>
    <p><b>{{ $ticket->attendee_name }}</b><br><span class="t-muted">{{ $ticket->attendee_email }}</span></p>
    <p>{{ __('Code') }} <span class="codice-breve">{{ $ticket->short_code }}</span></p>
    @if ($ticket->status->value === 'valid')
        <img class="qr" src="{{ route('account.tickets.qr', $ticket->id) }}" width="200" height="200" alt="{{ __('QR code of ticket :code', ['code' => $ticket->short_code]) }}" loading="lazy">
        <p class="t-small t-muted">{{ __('Show this QR code at the entrance, on your phone or printed.') }}</p>
    @else
        <p class="badge badge--err">{{ __('status.ticket.'.$ticket->status->value) }}</p>
    @endif
</article>
