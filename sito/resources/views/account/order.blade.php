@extends('layouts.app')

@section('title', __('Your order'))

@php $status = $order->status->value; @endphp

@section('content')
    <section class="sezione">
        <div class="wrap stack misura" style="--stack: var(--s-5)">
            <p><a href="{{ route('account.index') }}">← {{ __('Your account') }}</a></p>

            @if ($status === 'pending')
                <h1 class="t-1">{{ __('Check the names and pay') }}</h1>
                <div class="avviso avviso--warn" role="note">
                    <b>{{ __('Names cannot be changed later.') }}</b>
                    <p>{{ __('Each ticket carries the name and email below: they are printed on the ticket and checked at the entrance.') }}</p>
                </div>
            @elseif ($status === 'paid')
                <h1 class="t-1">{{ __('Payment received') }}</h1>
                <div class="avviso avviso--ok" role="status">
                    <b>{{ __('Your tickets are ready.') }}</b>
                    <p>{{ __('We also sent them by email, to you and to each attendee.') }}</p>
                </div>
            @elseif ($status === 'processing')
                <h1 class="t-1">{{ __('Payment in progress') }}</h1>
                <div class="avviso avviso--info" role="status">
                    <p>{{ __('Your bank is confirming the payment. Your seats are kept: we send the tickets by email as soon as it is confirmed.') }}</p>
                </div>
            @elseif ($status === 'needs_refund')
                <h1 class="t-1">{{ __('We are refunding you') }}</h1>
                <div class="avviso avviso--warn" role="status">
                    <p>{{ __('The payment arrived after the seats were sold out. We refund the full amount automatically: you do not need to do anything.') }}</p>
                </div>
            @elseif ($status === 'needs_review')
                <h1 class="t-1">{{ __('We are checking your payment') }}</h1>
                <div class="avviso avviso--info" role="status">
                    <p>{{ __('We received your payment and are checking it. We send the tickets by email or refund you: you do not need to do anything.') }}</p>
                </div>
            @elseif (in_array($status, ['refunded', 'partially_refunded']))
                <h1 class="t-1">{{ __('Order refunded') }}</h1>
                <div class="avviso avviso--info" role="status"><p>{{ __('The refund goes back to the card you paid with.') }}</p></div>
            @else
                <h1 class="t-1">{{ __('This order was not paid') }}</h1>
                <div class="avviso avviso--info" role="status">
                    <p>{{ __('No payment was taken and the seats were released.') }} <a href="{{ route('shop.index') }}">{{ __('Start a new order') }}</a></p>
                </div>
            @endif

            <p class="t-small t-muted">{{ __('Order status') }}: <b>{{ __('status.'.$status) }}</b> · {{ $order->created_at->timezone('Europe/Lisbon')->translatedFormat('j M Y, H:i') }}</p>

            <div class="scorri-x">
                <table class="tabella">
                    <caption>{{ __('Names on the tickets') }}</caption>
                    <thead><tr><th scope="col">{{ __('Ticket') }}</th><th scope="col">{{ __('Attendees') }}</th><th scope="col" class="num">{{ __('Price') }}</th></tr></thead>
                    <tbody>
                        @foreach ($order->items as $item)
                            <tr>
                                <td>{{ $item->stripe_line_name }} × {{ $item->quantity }}</td>
                                <td>@foreach ($item->attendees as $a)<b>{{ $a['name'] }}</b><br><span class="t-muted">{{ $a['email'] }}</span><br>@endforeach</td>
                                <td class="num">{{ euro($item->total_cents) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        @if ($order->discount_cents)
                            <tr><th scope="row" colspan="2">{{ __('Discount') }}</th><td class="num">−{{ euro($order->discount_cents) }}</td></tr>
                        @endif
                        <tr><th scope="row" colspan="2">{{ __('Total') }}</th><td class="num">{{ euro($order->total_cents) }}</td></tr>
                    </tfoot>
                </table>
            </div>

            @if ($status === 'pending')
                <form method="post" action="{{ route('shop.checkout', $order->id) }}" class="stack" style="--stack: var(--s-4)">
                    @csrf
                    <label class="scelta scelta--forte">
                        <input type="checkbox" name="attendees_confirmed" value="1" required aria-describedby="confirm-help">
                        <span><b>{{ __('I have checked the names.') }}</b> {{ __('I understand that they cannot be changed after payment.') }}</span>
                    </label>
                    <p class="campo__aiuto" id="confirm-help">{{ __('Required. Tickets are non-refundable; we refund automatically only if the event is cancelled.') }}</p>
                    <button class="btn btn--primary btn--block">{{ $order->total_cents ? __('Pay :amount by card on Stripe', ['amount' => euro($order->total_cents)]) : __('Confirm the order') }}</button>
                    <p class="t-small t-muted">{{ __('Seats are held until :time (Lisbon time). Card only, Apple Pay and Google Pay included: your card details never reach us.', ['time' => $order->checkout_expires_at->timezone('Europe/Lisbon')->format('H:i')]) }}</p>
                </form>
            @endif

            @if ($order->tickets->isNotEmpty())
                <h2 class="t-2">{{ __('Tickets') }}</h2>
                <div class="grid-2">
                    @foreach ($order->tickets as $ticket)
                        @include('account.ticket', ['ticket' => $ticket])
                    @endforeach
                </div>
            @endif
        </div>
    </section>
@endsection
