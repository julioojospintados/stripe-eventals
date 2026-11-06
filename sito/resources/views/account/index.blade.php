@extends('layouts.app')

@section('content')
    <h1>Your account</h1>

    <h2>Membership</h2>
    @if ($isMember)
        <p>You are a member.</p>
    @else
        <form method="post" action="{{ route('membership.start') }}">@csrf <button>Become a member</button></form>
    @endif
    @if (auth()->user()->stripe_customer_id)
        <form method="post" action="{{ route('membership.portal') }}">@csrf <button>Manage membership and invoices</button></form>
    @endif

    <h2>Tickets</h2>
    @forelse ($tickets as $ticket)
        <p>{{ $ticket->ticketType->name['en'] }} · {{ $ticket->attendee_name }} · {{ $ticket->short_code }}<br>
            <img src="{{ route('account.tickets.qr', $ticket->id) }}" width="200" height="200" alt="QR {{ $ticket->short_code }}"></p>
    @empty
        <p>No tickets yet.</p>
    @endforelse

    <h2>Orders</h2>
    <ul>
        @foreach ($orders as $order)
            <li><a href="{{ route('account.orders.show', $order->id) }}">{{ $order->created_at->format('d/m/Y H:i') }}</a>
                · {{ $order->status->value }} · {{ number_format($order->total_cents / 100, 2) }} €</li>
        @endforeach
    </ul>
@endsection
