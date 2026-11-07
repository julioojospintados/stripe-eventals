<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\ProgramSession;
use App\Models\Speaker;

/** Speaker pubblici: solo chi ha almeno una sessione pubblicata. L'email non arriva mai alle viste pubbliche. */
class SpeakerController
{
    public function index()
    {
        return view('speakers.index', [
            'speakers' => Speaker::visible()->orderBy('last_name')->orderBy('first_name')->get(),
        ]);
    }

    public function show(string $slug)
    {
        $speaker = Speaker::visible()->where('slug', $slug)->firstOrFail();
        $event = Event::published()->latest('starts_on')->firstOrFail();
        $sessions = ProgramSession::published()
            ->where(fn ($q) => $q->whereHas('speakers', fn ($s) => $s->whereKey($speaker->id))
                ->orWhereHas('parts.speakers', fn ($s) => $s->whereKey($speaker->id)))
            ->with('room')->orderBy('starts_at')->get();

        return view('speakers.show', ['speaker' => $speaker, 'sessions' => $sessions, 'tz' => $event->timezone]);
    }
}
