{{-- Nomi degli speaker di una sessione o di un intervento, con il link alla scheda. --}}
@if ($speakers->isNotEmpty())
    <p class="t-small">
        @foreach ($speakers as $speaker)
            @if (! $loop->first) · @endif
            <a href="{{ route('speakers.show', $speaker->slug) }}">{{ $speaker->name() }}</a>@if (($speaker->pivot->role ?? null) === 'moderator') <span class="t-muted">({{ __('Moderator') }})</span>@endif
        @endforeach
    </p>
@endif
