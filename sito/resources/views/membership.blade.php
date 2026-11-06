@extends('layouts.app')

@section('title', __('Membership'))

@section('content')
    <section class="dark">
        <div class="wrap stack" style="--stack: var(--s-4); padding-block: var(--s-7)">
            <h1 class="t-1">{{ __('EAS membership') }}</h1>
            @if ($plan)
                <p><span class="prezzo prezzo--grande">{{ euro($plan->amount_cents) }}</span> <span class="t-muted">{{ __('per year') }}</span></p>
            @endif
            <p class="t-lead misura">{{ __('Active right after payment: the member price is available immediately.') }}</p>
        </div>
    </section>

    <section class="sezione">
        <div class="wrap grid-2">
            <div class="colonna">
                <h2 class="t-2">{{ __('What you get') }}</h2>
                <ul class="stack" style="--stack: var(--s-2); list-style: disc; padding-left: var(--s-5)">
                    <li>{{ __('The member price on your own EAS8 full pass.') }}</li>
                    <li>{{ __('The early bird price, for members only.') }}</li>
                    <li>{{ __('Renews every year; you can cancel any time from your account.') }}</li>
                </ul>
                <p class="t-small t-muted">{{ __('The member price applies only to the member’s own ticket, once per event. Workshops cost the same for everyone.') }}</p>
            </div>

            <div class="scheda">
                @if ($isMember)
                    <p><span class="badge badge--ok">{{ __('Active') }}</span></p>
                    <p>{{ __('You are a member until :date.', ['date' => $membership->current_period_end->timezone('Europe/Lisbon')->translatedFormat('j F Y')]) }}</p>
                    <form method="post" action="{{ route('membership.portal') }}">
                        @csrf
                        <button class="btn btn--block">{{ __('Manage membership and invoices') }}</button>
                    </form>
                    <a class="btn btn--primary btn--block" href="{{ route('shop.index') }}">{{ __('Buy your pass at the member price') }}</a>
                @elseif (auth()->check())
                    <h2 class="t-3">{{ __('Become a member') }}</h2>
                    <form method="post" action="{{ route('membership.start') }}">
                        @csrf
                        <button class="btn btn--primary btn--block">{{ __('Pay by card on Stripe') }}</button>
                    </form>
                    <p class="t-small t-muted">{{ __('Yearly subscription, card only (Apple Pay and Google Pay included).') }}</p>
                @else
                    <h2 class="t-3">{{ __('Become a member') }}</h2>
                    <p>{{ __('Log in first, so the membership is linked to your account.') }}</p>
                    <a class="btn btn--primary btn--block" href="{{ route('login') }}">{{ __('Log in to continue') }}</a>
                @endif
            </div>
        </div>
    </section>
@endsection
