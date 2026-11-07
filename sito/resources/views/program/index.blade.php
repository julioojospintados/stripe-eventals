@extends('layouts.app')

@section('title', __('Programme'))

@php
    $query = fn (array $change) => route('program.index', array_filter(array_merge([
        'day' => $day?->day->toDateString(), 'room' => $room?->code, 'track' => $track?->slug,
        'view' => $listOnly ? 'list' : null,
    ], $change)));
@endphp

@section('content')
    <section class="sezione">
        <div class="wrap stack" style="--stack: var(--s-5)">
            <h1 class="t-1">{{ __('Programme') }}</h1>
            <p class="t-lead misura">{{ __('Times are Lisbon time. Sessions are included in the full pass unless marked as a workshop.') }}</p>

            @if ($days->isNotEmpty())
                <nav aria-label="{{ __('Days') }}">
                    <ul class="giorni">
                        @foreach ($days as $d)
                            <li><a href="{{ $query(['day' => $d->day->toDateString()]) }}" @if ($d->is($day)) aria-current="page" @endif>
                                <b>{{ localized($d->label) ?: $d->day->translatedFormat('l') }}</b>
                                <span>{{ $d->day->translatedFormat('j F') }}</span>
                            </a></li>
                        @endforeach
                    </ul>
                </nav>
            @endif

            <form method="get" action="{{ route('program.index') }}" class="filtri-programma" aria-label="{{ __('Filter the programme') }}">
                <input type="hidden" name="day" value="{{ $day?->day->toDateString() }}">
                @if ($listOnly) <input type="hidden" name="view" value="list"> @endif
                <div class="campo">
                    <label for="room">{{ __('Room') }}</label>
                    <select class="input" id="room" name="room">
                        <option value="">{{ __('All :count rooms', ['count' => $rooms->count()]) }}</option>
                        @foreach ($rooms as $r)
                            <option value="{{ $r->code }}" @selected($r->is($room))>{{ localized($r->name) }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="campo">
                    <label for="track">{{ __('Track') }}</label>
                    <select class="input" id="track" name="track">
                        <option value="">{{ __('All tracks') }}</option>
                        @foreach ($tracks as $t)
                            <option value="{{ $t->slug }}" @selected($t->is($track))>{{ localized($t->name) }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="filtri-programma__azioni">
                    <button class="btn btn--primary">{{ __('Show') }}</button>
                    @if ($room || $track)
                        <a href="{{ $query(['room' => null, 'track' => null]) }}">{{ __('Clear filters') }}</a>
                    @endif
                </div>
            </form>

            <p class="t-small t-muted" role="status">{{ trans_choice(':count session|:count sessions', $sessions->count()) }}</p>

            @if ($sessions->isEmpty())
                <div class="avviso avviso--info" role="note">
                    <b>{{ __('No sessions to show.') }}</b>
                    <p>{{ $room || $track ? __('Try another room or track.') : __('The programme for this day will be published soon.') }}</p>
                </div>
            @else
                <div class="agenda {{ $listOnly ? 'agenda--lista' : '' }}">
                    @if ($grid['rows'] > 0)
                        <div class="griglia-box">
                            <div class="griglia-comandi">
                                <p class="t-small t-muted">{{ __('The grid scrolls sideways inside its frame: use the scroll bar, the arrow keys or swipe.') }}</p>
                                <a href="{{ $query(['view' => 'list']) }}">{{ __('Show as a list') }}</a>
                            </div>
                            <div class="griglia-scorri" role="region" tabindex="0" aria-label="{{ __('Programme grid by room') }}">
                                <div class="griglia" style="--cols: {{ $gridRooms->count() }}; --righe: {{ $grid['rows'] }}">
                                    <div class="griglia__angolo" style="grid-column: 1">{{ __('Time') }}</div>
                                    @foreach ($gridRooms as $r)
                                        <div class="griglia__sala" style="grid-column: {{ $loop->index + 2 }}">
                                            {{ localized($r->name) }}
                                            <small>{{ trans_choice(':count seat|:count seats', $r->capacity) }}</small>
                                        </div>
                                    @endforeach
                                    @for ($i = 0; $i < $grid['rows']; $i += 2)
                                        <div class="griglia__ora" style="grid-row: {{ $i + 2 }} / span 2">{{ $grid['start']->copy()->addMinutes($i * 15)->format('H:i') }}</div>
                                        <div class="griglia__riga" style="grid-row: {{ $i + 2 }} / span 2"></div>
                                    @endfor
                                    @foreach ($grid['cells'] as $cell)
                                        @php($s = $cell['session'])
                                        <a class="cella" href="{{ route('program.show', $s->slug) }}"
                                           style="grid-column: {{ $cell['col'] }}; grid-row: {{ $cell['row'] }} / span {{ $cell['span'] }}; @if ($s->track?->color) --tk: {{ $s->track->color }}; @endif">
                                            <small>{{ $s->starts_at->timezone($tz)->format('H:i') }}–{{ $s->ends_at->timezone($tz)->format('H:i') }}<span class="sr-only">, {{ localized($s->room->name) }}</span></small>
                                            <b>{{ localized($s->title) }}</b>
                                            @if ($s->access->value === 'paid') <span class="badge badge--warn">{{ __('Workshop') }}</span> @endif
                                        </a>
                                    @endforeach
                                </div>
                            </div>
                        </div>
                    @endif

                    <div class="lista stack" style="--stack: var(--s-6)">
                        @if ($listOnly && $grid['rows'] > 0)
                            <p class="solo-desktop"><a href="{{ $query(['view' => null]) }}">{{ __('Show as a grid') }}</a></p>
                        @endif
                        @foreach ($byTime as $time => $group)
                            <section aria-labelledby="ora-{{ str_replace(':', '', $time) }}">
                                <h2 class="t-3 fascia" id="ora-{{ str_replace(':', '', $time) }}">{{ $time }} <span class="t-small t-muted">· {{ trans_choice(':count session|:count sessions', $group->count()) }}</span></h2>
                                <ul class="sessioni">
                                    @foreach ($group as $s)
                                        <li class="sessione" @if ($s->track?->color) style="--tk: {{ $s->track->color }}" @endif>
                                            <p class="t-small t-muted">
                                                <span class="num">{{ $s->starts_at->timezone($tz)->format('H:i') }}–{{ $s->ends_at->timezone($tz)->format('H:i') }}</span>
                                                @if ($s->room) · {{ localized($s->room->name) }} @endif
                                                @if ($s->track) · {{ localized($s->track->name) }} @endif
                                            </p>
                                            <h3 class="t-3"><a href="{{ route('program.show', $s->slug) }}">{{ localized($s->title) }}</a></h3>
                                            <p class="t-small">
                                                <span class="badge">{{ $s->format->label() }}</span>
                                                @if ($s->access->value === 'paid') <span class="badge badge--warn">{{ __('Workshop · sold separately') }}</span> @endif
                                            </p>
                                            @include('program._speakers', ['speakers' => $s->speakers])
                                        </li>
                                    @endforeach
                                </ul>
                            </section>
                        @endforeach
                    </div>
                </div>
            @endif
        </div>
    </section>
@endsection
