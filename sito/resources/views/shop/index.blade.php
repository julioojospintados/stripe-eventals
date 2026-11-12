@extends('layouts.app')

@section('title', __('Tickets'))

@php
    $user = auth()->user();
    $tz = $event->timezone;
@endphp

@section('content')
    <section class="sezione">
        <div class="wrap stack" style="--stack: var(--s-5)">
            <h1 class="t-1">{{ __('Tickets') }}</h1>
            <p class="t-lead misura">{{ __('Two prices: public and member. Pay by card on Stripe (Apple Pay and Google Pay included). Every ticket is personal: the name cannot be changed after purchase.') }}</p>

            @guest
                <div class="avviso avviso--info" role="note">
                    <b>{{ __('You are browsing as a visitor.') }}</b>
                    <p>{{ __('You see both prices. Log in to buy: if you are a member, your price is applied automatically.') }}</p>
                </div>
            @elseif ($role->value === 'member')
                <div class="avviso avviso--ok" role="note">
                    <b>{{ __('You are a member.') }}</b>
                    <p>{{ __('The member price applies to your own ticket; tickets for other people cost the public price.') }}</p>
                </div>
            @else
                <div class="avviso avviso--info" role="note">
                    <b>{{ __('You pay the public price.') }}</b>
                    <p><a href="{{ route('membership.show') }}">{{ __('Become a member') }}</a> {{ __('to get the member price and the members-only early bird.') }}</p>
                </div>
            @endguest

            @if ($hasPass)
                <div class="avviso avviso--ok" role="note">
                    <b>{{ __('You already have a full pass.') }}</b>
                    <p>{{ __('Leave the pass fields empty to add only workshops.') }}</p>
                </div>
            @endif

            @if ($tiers->isNotEmpty())
                <div class="scorri-x">
                    <table class="tabella">
                        <caption>{{ __('Full pass, three days: price by tier') }}</caption>
                        <thead>
                            <tr>
                                <th scope="col">{{ __('Tier') }}</th>
                                <th scope="col">{{ __('Until') }}</th>
                                <th scope="col" class="num">{{ __('Public') }}</th>
                                <th scope="col" class="num">{{ __('Member') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($tiers as $row)
                                @php $current = $tier && $tier->is($row['tier']); @endphp
                                <tr @class(['is-attiva' => $current])>
                                    <th scope="row">{{ localized($row['tier']->name) }}
                                        @if ($current) <span class="badge badge--info">{{ __('Now') }}</span> @endif</th>
                                    <td class="num">{{ $row['tier']->ends_at->timezone($tz)->subSecond()->translatedFormat('j M Y') }}</td>
                                    <td class="num">{{ $row['public'] ? money($row['public']->amount_cents, $event->currency) : '—' }}</td>
                                    <td class="num">{{ $row['member'] && $row['member']->price_role->value === 'member' ? money($row['member']->amount_cents, $event->currency) : '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <p class="t-small t-muted">{{ __('Dates are local time. A dash means the public price applies.') }}</p>
            @endif
        </div>
    </section>

    <section class="sezione bande" aria-labelledby="order">
        <div class="wrap">
            <form method="post" action="{{ route('shop.reserve') }}" class="stack" style="--stack: var(--s-6)" novalidate>
                @csrf
                <h2 class="t-1" id="order">{{ __('Build your order') }}</h2>

                @foreach ($passes as ['type' => $type, 'price' => $price, 'seats' => $seats])
                    @php $max = $type->max_per_order ?? 6; @endphp
                    <fieldset class="scheda">
                        <legend class="t-2">{{ localized($type->name) }} · <span class="num">{{ money($price->amount_cents, $event->currency) }}</span></legend>
                        <p class="t-muted">{{ $price->price_role->value === 'member' ? __('Member price for your own ticket; other people pay the public price.') : __('Public price.') }}
                            {{ __('Write each name exactly as on an ID document: it is printed on the ticket and checked at the entrance.') }}</p>
                        @if ($seats === 0)
                            <p class="badge badge--err">{{ __('Sold out') }}</p>
                        @else
                            @include('shop.person', ['typeId' => $type->id, 'i' => 0, 'label' => __('Attendee 1'), 'prefill' => $hasPass ? null : $user])
                            @if ($max > 1)
                                <details @if (collect(old("lines.{$type->id}.attendees", []))->slice(1)->flatten()->filter()->isNotEmpty()) open @endif>
                                    <summary>{{ __('Add more people (up to :max)', ['max' => $max]) }}</summary>
                                    @for ($i = 1; $i < $max; $i++)
                                        @include('shop.person', ['typeId' => $type->id, 'i' => $i, 'label' => __('Attendee :n', ['n' => $i + 1])])
                                    @endfor
                                </details>
                            @endif
                        @endif
                    </fieldset>
                @endforeach

                @if ($workshops->isNotEmpty())
                    <fieldset class="stack" style="--stack: var(--s-3)">
                        <legend class="t-2">{{ __('Workshops') }}</legend>
                        <p class="t-muted misura">{{ __('Optional. Only together with a full pass, in this order or one you already bought. At most 2 workshops per order, never two at the same time.') }}</p>
                        @foreach ($workshops as ['type' => $type, 'price' => $price, 'seats' => $seats])
                            <div class="scheda workshop">
                                <div class="workshop__testa">
                                    <h3 class="t-3">{{ localized($type->name) }}</h3>
                                    <span class="prezzo">{{ money($price->amount_cents, $event->currency) }}</span>
                                </div>
                                <p class="t-small">
                                    @if ($type->programSession?->starts_at)
                                        <time datetime="{{ $type->programSession->starts_at->toIso8601String() }}">{{ $type->programSession->starts_at->timezone($tz)->translatedFormat('D j M, H:i') }}</time> ·
                                    @endif
                                    @if ($seats === 0)
                                        <span class="badge badge--err">{{ __('Sold out') }}</span>
                                    @elseif ($seats !== null && $seats <= 5)
                                        <span class="badge badge--warn">{{ trans_choice(':count seat left|:count seats left', $seats) }}</span>
                                    @elseif ($seats !== null)
                                        <span class="badge badge--ok">{{ trans_choice(':count seat left|:count seats left', $seats) }}</span>
                                    @endif
                                </p>
                                @if ($seats !== 0)
                                    <details @if (collect(old("lines.{$type->id}.attendees", []))->flatten()->filter()->isNotEmpty()) open @endif>
                                        <summary>{{ __('Book this workshop') }} <span class="sr-only">: {{ localized($type->name) }}</span></summary>
                                        @for ($i = 0; $i < ($type->max_per_order ?? 1); $i++)
                                            @include('shop.person', ['typeId' => $type->id, 'i' => $i, 'label' => __('Attendee :n', ['n' => $i + 1])])
                                        @endfor
                                    </details>
                                @endif
                            </div>
                        @endforeach
                    </fieldset>
                @endif

                <div class="campo misura">
                    <label for="discount_code">{{ __('Discount code') }} <span class="t-muted">({{ __('optional') }})</span></label>
                    <input class="input" id="discount_code" name="discount_code" value="{{ old('discount_code') }}" autocomplete="off" autocapitalize="characters" spellcheck="false">
                </div>

                <div class="stack misura" style="--stack: var(--s-3)">
                    @auth
                        <button class="btn btn--primary btn--block">{{ __('Continue: check the names') }}</button>
                        <p class="t-small t-muted">{{ __('Seats are held for about 30 minutes. On the next page you check the names and pay by card on Stripe.') }}</p>
                    @else
                        <a class="btn btn--primary btn--block" href="{{ route('login') }}">{{ __('Log in to buy') }}</a>
                        <p class="t-small t-muted">{{ __('We send you a 6-digit code by email: no password. You find your tickets again in your account.') }}</p>
                    @endauth
                </div>
            </form>
        </div>
    </section>
@endsection
