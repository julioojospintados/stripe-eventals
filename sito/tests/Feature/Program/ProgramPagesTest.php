<?php

// Pagine pubbliche del programma e degli speaker: rispondono, mostrano solo le sessioni pubblicate, filtrano per sala
// e traccia, cambiano lingua, non mostrano mai l'email degli speaker.

use App\Models\Speaker;
use App\Models\Track;

beforeEach(function () {
    $this->main = room(['code' => 'room-01', 'name' => ['en' => 'Main Hall', 'it' => 'Sala plenaria'], 'position' => 1]);
    $this->small = room(['code' => 'room-02', 'name' => ['en' => 'Room 2'], 'position' => 2]);
    $this->track = Track::create(['event_id' => $this->main->event_id, 'name' => ['en' => 'Clinical'], 'color' => '#3d8fc9']);
    $this->ana = Speaker::factory()->create(['first_name' => 'Ana', 'last_name' => 'Exemplo', 'email' => 'ana@private.test']);
    $this->hidden = Speaker::factory()->create(['first_name' => 'Hidden', 'last_name' => 'Person']);

    $this->opening = scheduledSession($this->main, '09:00', '10:30', [
        'title' => ['en' => 'Opening lecture', 'it' => 'Lezione di apertura'], 'abstract' => ['en' => 'About aligners.'],
        'track_id' => $this->track->id,
    ]);
    $this->opening->sessionSpeakers()->create(['speaker_id' => $this->ana->id, 'role' => 'speaker', 'position' => 1]);
    $this->opening->parts()->create(['position' => 1, 'title' => ['en' => 'First talk']])->speakers()->attach($this->ana->id);
    scheduledSession($this->small, '09:00', '10:00', ['title' => ['en' => 'Small room talk']]);
    $draft = scheduledSession($this->small, '11:00', '12:00', ['title' => ['en' => 'Secret draft'], 'published' => false, 'slug' => 'secret-draft']);
    $draft->sessionSpeakers()->create(['speaker_id' => $this->hidden->id, 'role' => 'speaker', 'position' => 1]);
});

it('risponde 200 su programma, sessione, speaker e scheda', function () {
    $this->get('/programme')->assertOk()->assertSee('Opening lecture')->assertSee('Small room talk')
        ->assertSee('Main Hall')->assertSee('Ana Exemplo')->assertSee('2 sessions');
    $this->get('/programme/'.$this->opening->slug)->assertOk()->assertSee('About aligners.')->assertSee('First talk')
        ->assertSee('Ana Exemplo')->assertSee('09:00–10:30');
    $this->get('/speakers')->assertOk()->assertSee('Ana Exemplo');
    $this->get('/speakers/'.$this->ana->slug)->assertOk()->assertSee('Opening lecture')->assertDontSee('ana@private.test');
});

it('nasconde sessioni non pubblicate e chi parla solo lì', function () {
    $this->get('/programme')->assertDontSee('Secret draft');
    $this->get('/programme/secret-draft')->assertNotFound();
    $this->get('/speakers')->assertDontSee('Hidden Person');
    $this->get('/speakers/'.$this->hidden->slug)->assertNotFound();
});

it('filtra per sala, traccia e giorno', function () {
    $this->get('/programme?room=room-02')->assertOk()->assertSee('Small room talk')->assertDontSee('Opening lecture');
    $this->get('/programme?track=clinical')->assertOk()->assertSee('Opening lecture')->assertDontSee('Small room talk');
    $this->get('/programme?day=2027-04-09')->assertOk()->assertSee('No sessions to show.')->assertDontSee('Opening lecture');
});

it('mostra i testi in italiano, con l\'inglese dove manca', function () {
    $this->withSession(['locale' => 'it'])->get('/programme')->assertOk()
        ->assertSee('Lezione di apertura')->assertSee('Sala plenaria')->assertSee('Small room talk')->assertSee('Programma');
});

it('mette sotto il 9 aprile una sessione che inizia alle 00:30 a Lisbona (23:30 UTC dell\'8)', function () {
    $late = scheduledSession($this->main, '00:30', '01:30', ['title' => ['en' => 'Night owl session']], day: '2027-04-09');

    expect($late->eventDay->day->toDateString())->toBe('2027-04-09');
    $this->get('/programme?day=2027-04-09')->assertOk()->assertSee('Night owl session')->assertSee('00:30');
    $this->get('/programme?day=2027-04-08')->assertOk()->assertDontSee('Night owl session');
});
