@extends('layouts.app')

@section('title', $speaker->name())

@php
    $links = collect([$speaker->website, ...($speaker->links ?? [])])
        ->filter(fn ($url) => is_string($url) && preg_match('#^https?://#i', $url));
@endphp

@section('content')
    <section class="sezione">
        <div class="wrap stack" style="--stack: var(--s-5)">
            <p><a href="{{ route('speakers.index') }}">← {{ __('Speakers') }}</a></p>
            <div class="speaker-testa">
                @if ($speaker->photoUrl())
                    <img class="ritratto ritratto--grande" src="{{ $speaker->photoUrl() }}" alt="{{ __('Photo of :name', ['name' => $speaker->name()]) }}" width="256" height="256">
                @else
                    <span class="ritratto ritratto--grande" aria-hidden="true">{{ mb_substr($speaker->first_name, 0, 1).mb_substr($speaker->last_name, 0, 1) }}</span>
                @endif
                <div class="stack" style="--stack: var(--s-2)">
                    <h1 class="t-1">{{ $speaker->name() }}</h1>
                    @if ($speaker->affiliation || $speaker->country)
                        <p class="t-lead">{{ collect([$speaker->affiliation, $speaker->country])->filter()->implode(' · ') }}</p>
                    @endif
                </div>
            </div>

            @if ($speaker->bio)
                <div class="misura stack" style="--stack: var(--s-3)">
                    @foreach (preg_split('/\R+/', localized($speaker->bio)) as $paragraph)
                        <p>{{ $paragraph }}</p>
                    @endforeach
                </div>
            @endif

            @if ($links->isNotEmpty())
                <ul class="link-lista">
                    @foreach ($links as $url)
                        <li><a href="{{ $url }}" rel="noopener">{{ preg_replace('#^https?://(www\.)?#i', '', rtrim($url, '/')) }}</a></li>
                    @endforeach
                </ul>
            @endif

            <section class="stack" style="--stack: var(--s-3)" aria-labelledby="sessions">
                <h2 class="t-2" id="sessions">{{ __('Sessions') }}</h2>
                <ul class="sessioni">
                    @foreach ($sessions as $s)
                        <li class="sessione">
                            @if ($s->starts_at)
                                <p class="t-small t-muted">{{ $s->starts_at->timezone($tz)->translatedFormat('l j F') }}, <span class="num">{{ $s->starts_at->timezone($tz)->format('H:i') }}–{{ $s->ends_at?->timezone($tz)->format('H:i') }}</span>@if ($s->room) · {{ localized($s->room->name) }}@endif</p>
                            @endif
                            <h3 class="t-3"><a href="{{ route('program.show', $s->slug) }}">{{ localized($s->title) }}</a></h3>
                        </li>
                    @endforeach
                </ul>
            </section>
        </div>
    </section>
@endsection
