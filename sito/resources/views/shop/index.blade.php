@extends('layouts.app')

@section('content')
    <h1>{{ $event->title['en'] }}</h1>
    @guest
        <p><a href="{{ route('login') }}">Log in</a> to buy tickets.</p>
    @endguest

    <form method="post" action="{{ route('shop.reserve') }}">
        @csrf
        @foreach ($types as ['type' => $type, 'price' => $price])
            <fieldset>
                <legend>{{ $type->name['en'] }} · {{ number_format($price->amount_cents / 100, 2) }} €</legend>
                @for ($i = 0; $i < $type->max_per_order; $i++)
                    <div>
                        <label>Name <input name="lines[{{ $type->id }}][attendees][{{ $i }}][name]" value="{{ old("lines.{$type->id}.attendees.$i.name") }}"></label>
                        <label>Email <input type="email" name="lines[{{ $type->id }}][attendees][{{ $i }}][email]" value="{{ old("lines.{$type->id}.attendees.$i.email") }}"></label>
                    </div>
                @endfor
            </fieldset>
        @endforeach
        <label>Discount code <input name="discount_code" value="{{ old('discount_code') }}"></label>
        @auth
            <button>Reserve</button>
        @endauth
    </form>
@endsection
