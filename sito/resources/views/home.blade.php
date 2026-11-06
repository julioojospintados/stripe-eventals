@extends('layouts.app')

@php
    $dates = $event->starts_on->format('j').'–'.$event->ends_on->translatedFormat('j F Y');
    $pass = $passes->first();
    $workshop = $workshops->first();
@endphp

@section('content')
    <section class="dark hero" aria-labelledby="event-title">
        <div class="wrap hero__corpo">
            <div class="stack">
                <h1 class="t-display" id="event-title">EAS8 <span class="hero__citta">Lisbon</span></h1>
                <p class="hero__data">{{ $dates }}</p>
                <p class="t-muted">{{ $event->venue_name }}</p>
                @if ($event->summary)
                    <p class="hero__tagline">{{ localized($event->summary) }}</p>
                @endif
                <div class="btn-gruppo">
                    <a class="btn btn--primary" href="{{ route('shop.index') }}">{{ __('Buy tickets') }}</a>
                    <a class="btn btn--on-dark" href="{{ route('membership.show') }}">{{ __('Become a member') }}</a>
                </div>
            </div>

            @if ($pass)
                <div class="hero__prezzi stack" style="--stack: var(--s-3)">
                    <h2 class="t-3">{{ localized($pass['type']->name) }}</h2>
                    <dl>
                        @if ($pass['public'])
                        <div class="prezzo-riga">
                            <dt>{{ __('Public') }} @if ($role->value === 'public' && auth()->check()) <span class="badge badge--you">{{ __('You') }}</span> @endif</dt>
                            <dd class="prezzo">{{ euro($pass['public']->amount_cents) }}</dd>
                        </div>
                        @endif
                        @if ($pass['member'] && $pass['member']->price_role->value === 'member')
                            <div class="prezzo-riga">
                                <dt>{{ __('Member') }} @if ($role->value === 'member') <span class="badge badge--you">{{ __('You') }}</span> @endif</dt>
                                <dd class="prezzo">{{ euro($pass['member']->amount_cents) }}</dd>
                            </div>
                        @endif
                    </dl>
                    @if ($tier)
                        <p class="t-small t-muted">{{ __(':tier until :date (Lisbon time)', ['tier' => localized($tier->name), 'date' => $tier->ends_at->timezone($event->timezone)->translatedFormat('j F Y, H:i')]) }}</p>
                    @endif
                </div>
            @endif
        </div>
    </section>

    <section class="sezione" aria-labelledby="attend">
        <div class="wrap stack" style="--stack: var(--s-5)">
            <h2 class="t-1" id="attend">{{ __('How to attend') }}</h2>
            <div class="grid-3">
                <div class="colonna">
                    <h3 class="t-3">{{ __('Full pass') }}</h3>
                    <p class="t-muted">{{ __('All included sessions on the three days.') }}</p>
                    @if ($pass)
                        <p><b class="prezzo">{{ euro($pass['price']->amount_cents) }}</b> <span class="t-muted">{{ $pass['price']->price_role->value === 'member' ? __('member price') : __('public price') }}</span></p>
                    @endif
                </div>
                <div class="colonna">
                    <h3 class="t-3">{{ __('Workshops') }}</h3>
                    <p class="t-muted">{{ __(':count hands-on workshops with limited seats, booked together with or after a full pass. Same price for everyone.', ['count' => $workshops->count()]) }}</p>
                    @if ($workshop)
                        <p><b class="prezzo">{{ euro($workshop['price']->amount_cents) }}</b> <span class="t-muted">{{ __('per workshop') }}</span></p>
                    @endif
                </div>
                <div class="colonna">
                    <h3 class="t-3">{{ __('Membership') }}</h3>
                    <p class="t-muted">{{ __('The member price on the full pass and the members-only early bird. Active right after payment.') }}</p>
                    @if ($plan)
                        <p><b class="prezzo">{{ euro($plan->amount_cents) }}</b> <span class="t-muted">{{ __('per year') }}</span></p>
                    @endif
                </div>
            </div>
            <p><a class="btn btn--primary" href="{{ route('shop.index') }}">{{ __('See tickets and prices') }}</a></p>
        </div>
    </section>

    <section class="sezione bande" aria-labelledby="info">
        <div class="wrap stack" style="--stack: var(--s-5)">
            <h2 class="t-1" id="info">{{ __('Practical information') }}</h2>
            <dl class="meta misura">
                <div><dt>{{ __('Dates') }}</dt><dd>{{ $dates }}</dd></div>
                <div><dt>{{ __('Place') }}</dt><dd>{{ $event->venue_address ?: $event->venue_name.' · '.__('venue to be confirmed') }}</dd></div>
                <div><dt>{{ __('Language') }}</dt><dd>{{ __('English') }}</dd></div>
                <div><dt>{{ __('Tickets') }}</dt><dd>{{ __('Personal: the name on a ticket cannot be changed after payment. Card payment on Stripe, Apple Pay and Google Pay included.') }}</dd></div>
                <div><dt>{{ __('Contact') }}</dt><dd><a href="mailto:office@eas-aligners.com">office@eas-aligners.com</a></dd></div>
            </dl>
        </div>
    </section>
@endsection
