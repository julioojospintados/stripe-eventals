<?php

namespace App\Services;

use App\Models\EventDay;
use App\Models\ProgramSession;
use App\Models\Room;
use App\Models\Track;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Salvataggio delle sessioni del programma, dal pannello e dall'import. Il trigger program_sessions_no_room_overlap
 * resta l'ultima difesa; qui c'è il lock sulla sala (due salvataggi in parallelo si aspettano) e un messaggio leggibile.
 */
class ProgramService
{
    public function save(ProgramSession $session): ProgramSession
    {
        return DB::transaction(function () use ($session) {
            $this->prepare($session);
            if ($session->room_id) {
                Room::whereKey($session->room_id)->lockForUpdate()->first();
            }
            if ($other = $this->overlap($session)) {
                throw new ProgramException($this->overlapMessage($other));
            }
            $session->save();

            return $session;
        }, attempts: 3);
    }

    /** La sessione della stessa sala che si sovrappone; due sessioni che si toccano (10:30 fine, 10:30 inizio) vanno bene. */
    public function overlap(ProgramSession $session): ?ProgramSession
    {
        if (! $session->room_id || ! $session->starts_at || ! $session->ends_at) {
            return null;
        }

        return ProgramSession::where('room_id', $session->room_id)
            ->when($session->exists, fn ($q) => $q->whereKeyNot($session->getKey()))
            ->where('starts_at', '<', $session->ends_at)
            ->where('ends_at', '>', $session->starts_at)
            ->first();
    }

    public function overlapMessage(ProgramSession $other): string
    {
        $tz = $other->event->timezone;

        return __('The room is already taken by ":code · :title" (:day, :from–:to). Change the room or the times.', [
            'code' => $other->code,
            'title' => $other->title['en'] ?? '',
            'day' => $other->starts_at?->timezone($tz)->format('d/m/Y'),
            'from' => $other->starts_at?->timezone($tz)->format('H:i'),
            'to' => $other->ends_at?->timezone($tz)->format('H:i'),
        ]);
    }

    /** Giorno dell'evento dall'ora di inizio (a Lisbona), sala e traccia dello stesso evento, slug alla creazione. */
    private function prepare(ProgramSession $session): void
    {
        $event = $session->event;

        if ($session->starts_at) {
            $day = $session->starts_at->copy()->timezone($event->timezone)->toDateString();
            $session->event_day_id = EventDay::where('event_id', $event->id)->where('day', $day)->value('id')
                ?? throw new ProgramException(__('The day :day is outside the event dates.', ['day' => $day]));
        }
        if ($session->room_id && Room::whereKey($session->room_id)->value('event_id') !== $event->id) {
            throw new ProgramException(__('The room belongs to another event.'));
        }
        if ($session->track_id && Track::whereKey($session->track_id)->value('event_id') !== $event->id) {
            throw new ProgramException(__('The track belongs to another event.'));
        }

        $session->slug ??= ltrim(Str::limit(Str::slug($session->title['en'] ?? ''), 110, '').'-'.Str::lower($session->code), '-');
    }
}
