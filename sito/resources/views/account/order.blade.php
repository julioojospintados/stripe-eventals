@extends('layouts.app')

@section('content')
    <h1>Order</h1>
    <p>Status: <strong>{{ $order->status->value }}</strong></p>

    <table>
        <tr><th>Ticket</th><th>Attendees</th><th>Price</th></tr>
        @foreach ($order->items as $item)
            <tr>
                <td>{{ $item->stripe_line_name }} × {{ $item->quantity }}</td>
                <td>@foreach ($item->attendees as $a){{ $a['name'] }} ({{ $a['email'] }})<br>@endforeach</td>
                <td>{{ number_format($item->total_cents / 100, 2) }} €</td>
            </tr>
        @endforeach
    </table>
    <p>Total: {{ number_format($order->total_cents / 100, 2) }} €
        @if ($order->discount_cents) (discount {{ number_format($order->discount_cents / 100, 2) }} €) @endif</p>

    @if ($order->status->value === 'pending')
        <form method="post" action="{{ route('shop.checkout', $order->id) }}">
            @csrf
            <label><input type="checkbox" name="attendees_confirmed" value="1" required>
                I confirm the attendee names and emails: they cannot be changed after payment.</label>
            <button>Pay</button>
        </form>
        <p>Seats are held until {{ $order->checkout_expires_at->timezone('Europe/Lisbon')->format('H:i') }} (Lisbon time).</p>
    @endif

    @foreach ($order->tickets as $ticket)
        <p>{{ $ticket->ticketType->name['en'] }} · {{ $ticket->attendee_name }} · {{ $ticket->short_code }}<br>
            <img src="{{ route('account.tickets.qr', $ticket->id) }}" width="200" height="200" alt="QR {{ $ticket->short_code }}"></p>
    @endforeach
@endsection
