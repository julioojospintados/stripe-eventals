@extends('layouts.app')

@section('title', __('Speakers'))

@section('content')
    <section class="sezione">
        <div class="wrap stack" style="--stack: var(--s-5)">
            <h1 class="t-1">{{ __('Speakers') }}</h1>
            @if ($speakers->isEmpty())
                <div class="avviso avviso--info" role="note">
                    <b>{{ __('The speakers will be announced soon.') }}</b>
                </div>
            @else
                <p class="t-small t-muted">{{ trans_choice(':count speaker, in alphabetical order|:count speakers, in alphabetical order', $speakers->count()) }}</p>
                <ul class="griglia-speaker">
                    @foreach ($speakers as $speaker)
                        <li>@include('speakers._card', ['speaker' => $speaker])</li>
                    @endforeach
                </ul>
            @endif
        </div>
    </section>
@endsection
