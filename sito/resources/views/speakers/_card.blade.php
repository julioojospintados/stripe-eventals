{{-- Scheda breve di uno speaker: foto (o iniziali), nome, ruolo o affiliazione. Il nome è nel testo: la foto è decorativa. --}}
<a class="speaker-card" href="{{ route('speakers.show', $speaker->slug) }}">
    @if ($speaker->photoUrl())
        <img class="ritratto" src="{{ $speaker->photoUrl() }}" alt="" width="72" height="72" loading="lazy">
    @else
        <span class="ritratto" aria-hidden="true">{{ mb_substr($speaker->first_name, 0, 1).mb_substr($speaker->last_name, 0, 1) }}</span>
    @endif
    <span>
        <span class="speaker-card__nome">{{ $speaker->name() }}</span>
        <span class="speaker-card__ruolo">{{ collect([$role ?? null, $speaker->affiliation, $speaker->country])->filter()->implode(' · ') }}</span>
    </span>
</a>
