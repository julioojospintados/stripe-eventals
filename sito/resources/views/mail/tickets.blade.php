{{-- Email dei biglietti: aspetto definitivo al passo 3. --}}
<p>{{ $lang === 'it' ? 'Ecco i tuoi biglietti. Mostra il QR all\'ingresso.' : 'Here are your tickets. Show the QR code at the entrance.' }}</p>

@foreach ($tickets as $ticket)
    <h2>{{ $ticket->ticketType->name[$lang] ?? $ticket->ticketType->name['en'] }}</h2>
    <p>{{ $ticket->attendee_name }} · {{ $ticket->short_code }}</p>
    <img src="{{ $message->embedData($qr[$ticket->id], $ticket->short_code.'.png', 'image/png') }}" width="240" height="240" alt="QR {{ $ticket->short_code }}">
@endforeach

<p><a href="{{ route('account.index') }}">{{ $lang === 'it' ? 'I tuoi biglietti sul sito' : 'Your tickets on the website' }}</a></p>
