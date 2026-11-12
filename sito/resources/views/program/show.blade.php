@extends('layouts.app')

@section('title', localized($session->title))

@php
    $time = fn ($s) => $s->starts_at && $s->ends_at
        ? $s->starts_at->timezone($tz)->format('H:i').'–'.$s->ends_at->timezone($tz)->format('H:i') : null;
@endphp

@section('content')
    <section class="sezione">
        <div class="wrap stack" style="--stack: var(--s-5)">
            <p><a href="{{ route('program.index', ['day' => $session->eventDay?->day->toDateString()]) }}">← {{ __('Programme') }}</a></p>
            <div class="stack" style="--stack: var(--s-3)">
                <p class="t-small">
                    <span class="badge">{{ $session->format->label() }}</span>
                    @if ($session->track) <span class="badge">{{ localized($session->track->name) }}</span> @endif
                </p>
                <h1 class="t-1">{{ localized($session->title) }}</h1>
            </div>

            <dl class="meta misura">
                @if ($session->starts_at)
                    <div><dt>{{ __('When') }}</dt><dd>{{ $session->starts_at->timezone($tz)->translatedFormat('l j F') }}, <span class="num">{{ $time($session) }}</span> ({{ __('local time') }})</dd></div>
                @endif
                @if ($session->room)
                    <div><dt>{{ __('Room') }}</dt><dd>{{ localized($session->room->name) }}</dd></div>
                @endif
                <div><dt>{{ __('Access') }}</dt><dd>
                    @if ($session->access->value === 'paid')
                        {{ __('Workshop with limited seats, sold separately.') }} <a href="{{ route('shop.index') }}">{{ __('See tickets and prices') }}</a>
                    @elseif ($session->access->value === 'included_booking')
                        {{ __('Included in the full pass; booking needed.') }}
                    @else
                        {{ __('Included in the full pass.') }}
                    @endif
                </dd></div>
            </dl>

            @if ($session->abstract)
                <div class="misura stack" style="--stack: var(--s-3)">
                    @foreach (preg_split('/\R+/', localized($session->abstract)) as $paragraph)
                        <p>{{ $paragraph }}</p>
                    @endforeach
                </div>
            @endif

            @if ($session->speakers->isNotEmpty())
                <section class="stack" style="--stack: var(--s-3)" aria-labelledby="speakers">
                    <h2 class="t-2" id="speakers">{{ __('Speakers') }}</h2>
                    <ul class="griglia-speaker">
                        @foreach ($session->speakers as $speaker)
                            <li>@include('speakers._card', ['speaker' => $speaker, 'role' => $speaker->pivot->role === 'moderator' ? __('Moderator') : null])</li>
                        @endforeach
                    </ul>
                </section>
            @endif

            @if ($session->parts->isNotEmpty())
                <section class="stack" style="--stack: var(--s-3)" aria-labelledby="parts">
                    <h2 class="t-2" id="parts">{{ __('Talks') }}</h2>
                    <ol class="sessioni">
                        @foreach ($session->parts as $part)
                            <li class="sessione">
                                @if ($time($part)) <p class="t-small t-muted num">{{ $time($part) }}</p> @endif
                                <h3 class="t-3">{{ localized($part->title) }}</h3>
                                @include('program._speakers', ['speakers' => $part->speakers])
                                @if ($part->abstract) <p class="misura">{{ localized($part->abstract) }}</p> @endif
                            </li>
                        @endforeach
                    </ol>
                </section>
            @endif
        </div>
    </section>
@endsection
