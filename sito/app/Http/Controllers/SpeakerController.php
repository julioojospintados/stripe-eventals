<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\Speaker;

/** Speaker pubblici: solo chi ha almeno una sessione pubblicata. L'email non arriva mai alle viste pubbliche. */
class SpeakerController
{
    public function index()
    {
        $event = Event::current() ?? abort(404);

        return view('speakers.index', [
            'speakers' => Speaker::visible($event->id)->orderBy('last_name')->orderBy('first_name')->get(),
        ]);
    }

    public function show(string $slug)
    {
        $event = Event::current() ?? abort(404);
        $speaker = Speaker::visible($event->id)->where('slug', $slug)->firstOrFail();
        $sessions = $event->programSessions()->published()
            ->where(fn ($q) => $q->whereHas('speakers', fn ($s) => $s->whereKey($speaker->id))
                ->orWhereHas('parts.speakers', fn ($s) => $s->whereKey($speaker->id)))
            ->with('room')->orderBy('starts_at')->get();

        return view('speakers.show', ['speaker' => $speaker, 'sessions' => $sessions, 'tz' => $event->timezone]);
    }
}
