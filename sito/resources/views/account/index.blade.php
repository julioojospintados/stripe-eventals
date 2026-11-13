@extends('layouts.app')

@section('title', __('Your account'))

@section('content')
    <section class="sezione">
        <div class="wrap stack" style="--stack: var(--s-6)">
            <div class="stack" style="--stack: var(--s-2)">
                <h1 class="t-1">{{ __('Your account') }}</h1>
                <p class="t-muted">{{ auth()->user()->email }}</p>
            </div>

            <section aria-labelledby="tickets" class="stack">
                <h2 class="t-2" id="tickets">{{ __('Tickets') }}</h2>
                @forelse ($tickets as $ticket)
                    @if ($loop->first)<div class="grid-3">@endif
                    @include('account.ticket', ['ticket' => $ticket])
                    @if ($loop->last)</div>@endif
                @empty
                    <p>{{ __('No tickets yet.') }} <a href="{{ route('shop.index') }}">{{ __('Buy tickets') }}</a></p>
                @endforelse
            </section>

            <section aria-labelledby="membership" class="stack">
                <h2 class="t-2" id="membership">{{ __('Membership') }}</h2>
                @if ($isMember)
                    <p><span class="badge badge--ok">{{ __('Active') }}</span> {{ __('You get the member price on your own full pass.') }}</p>
                @else
                    <p>{{ __('You are not a member.') }} <a href="{{ route('membership.show') }}">{{ __('About membership') }}</a></p>
                @endif
                @if ($membershipInvoiceUrl)
                    <p><a href="{{ $membershipInvoiceUrl }}" rel="noopener">{{ __('Latest invoice') }}</a></p>
                @endif
                @if (auth()->user()->stripe_customer_id)
                    <form method="post" action="{{ route('membership.portal') }}">
                        @csrf
                        <button class="btn">{{ __('Manage membership and invoices') }}</button>
                    </form>
                @endif
            </section>

            <section aria-labelledby="orders" class="stack">
                <h2 class="t-2" id="orders">{{ __('Orders') }}</h2>
                @if ($orders->isEmpty())
                    <p>{{ __('No orders yet.') }}</p>
                @else
                    <div class="scorri-x">
                        <table class="tabella">
                            <caption class="sr-only">{{ __('Orders') }}</caption>
                            <thead><tr><th scope="col">{{ __('Date') }}</th><th scope="col">{{ __('Status') }}</th><th scope="col" class="num">{{ __('Total') }}</th></tr></thead>
                            <tbody>
                                @foreach ($orders as $order)
                                    <tr>
                                        <td><a href="{{ route('account.orders.show', $order->id) }}">{{ $order->created_at->timezone($order->event->timezone)->translatedFormat('j M Y, H:i') }}</a></td>
                                        <td>{{ __('status.'.$order->status->value) }}</td>
                                        <td class="num">{{ money($order->total_cents, $order->currency) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </section>
        </div>
    </section>
@endsection
