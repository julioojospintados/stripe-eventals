<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\ProgramSession;
use App\Models\Room;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/** Programma pubblico: un giorno alla volta, filtro per sala e traccia, solo sessioni pubblicate. */
class ProgramController
{
    /** Righe della griglia da 15 minuti. */
    private const SLOT_MINUTES = 15;

    public function index(Request $request)
    {
        $event = Event::current() ?? abort(404);
        $tz = $event->timezone;
        $days = $event->days;
        $day = $days->first(fn ($d) => $d->day->toDateString() === $request->query('day')) ?? $days->first();
        $rooms = $event->rooms;
        $tracks = $event->tracks;
        $room = $rooms->firstWhere('code', $request->query('room'));
        $track = $tracks->firstWhere('slug', $request->query('track'));

        $sessions = $event->programSessions()->published()
            ->where('event_day_id', $day?->id)->whereNotNull('starts_at')->whereNotNull('ends_at')
            ->when($room, fn ($q) => $q->where('room_id', $room->id))
            ->when($track, fn ($q) => $q->where('track_id', $track->id))
            ->with(['room', 'track', 'speakers'])
            ->orderBy('starts_at')->get()
            ->sortBy(fn (ProgramSession $s) => [$s->starts_at->getTimestamp(), $s->room->position ?? PHP_INT_MAX])->values();

        $gridRooms = $room ? collect([$room]) : $rooms;

        return view('program.index', [
            'event' => $event, 'tz' => $tz, 'days' => $days, 'day' => $day, 'rooms' => $rooms, 'tracks' => $tracks,
            'room' => $room, 'track' => $track, 'sessions' => $sessions, 'gridRooms' => $gridRooms,
            'byTime' => $sessions->groupBy(fn (ProgramSession $s) => $s->starts_at->timezone($tz)->format('H:i')),
            'grid' => $this->grid($sessions, $gridRooms, $tz),
            'listOnly' => $request->query('view') === 'list',
        ]);
    }

    public function show(string $slug)
    {
        $event = Event::current() ?? abort(404);
        $session = $event->programSessions()->published()->where('slug', $slug)
            ->with(['room', 'track', 'eventDay', 'speakers', 'parts.speakers', 'ticketType'])->firstOrFail();

        return view('program.show', ['event' => $event, 'session' => $session, 'tz' => $event->timezone]);
    }

    /**
     * Posizione di ogni sessione nella griglia: colonna = sala, righe = fasce da 15 minuti dall'ora piena della prima.
     *
     * @param  Collection<int, ProgramSession>  $sessions
     * @param  Collection<int, Room>  $rooms
     * @return array{start: ?CarbonInterface, rows: int, cells: list<array{session: ProgramSession, col: int, row: int, span: int}>}
     */
    private function grid(Collection $sessions, Collection $rooms, string $tz): array
    {
        $columns = array_flip($rooms->pluck('id')->all());
        $placed = $sessions->filter(fn (ProgramSession $s) => isset($columns[$s->room_id]));
        if ($placed->isEmpty()) {
            return ['start' => null, 'rows' => 0, 'cells' => []];
        }

        $start = $placed->min('starts_at')->copy()->timezone($tz)->startOfHour();
        $minutes = fn ($from, $to) => (int) round($from->diffInMinutes($to));
        $cells = $placed->map(fn (ProgramSession $s) => [
            'session' => $s,
            'col' => $columns[$s->room_id] + 2,
            'row' => intdiv($minutes($start, $s->starts_at), self::SLOT_MINUTES) + 2,
            'span' => max(1, (int) ceil($minutes($s->starts_at, $s->ends_at) / self::SLOT_MINUTES)),
        ])->values()->all();

        return [
            'start' => $start,
            'rows' => (int) ceil($minutes($start, $placed->max('ends_at')) / self::SLOT_MINUTES),
            'cells' => $cells,
        ];
    }
}
