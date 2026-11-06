@extends('layouts.app')

@section('title', __('Enter the code'))

@section('content')
    <section class="sezione">
        <div class="wrap stack misura" style="--stack: var(--s-5); max-width: 32rem">
            <h1 class="t-1">{{ __('Enter the code') }}</h1>
            <p>{{ __('We sent a 6-digit code to :email. It expires in 10 minutes.', ['email' => session('login_email')]) }}</p>
            <form method="post" action="{{ route('login.verify') }}" class="stack">
                @csrf
                <div class="campo">
                    <label for="code">{{ __('Code') }}</label>
                    <input class="input" id="code" name="code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" required autocomplete="one-time-code" @error('code') aria-invalid="true" @enderror>
                </div>
                <button class="btn btn--primary btn--block">{{ __('Log in') }}</button>
            </form>
            <p><a href="{{ route('login') }}">{{ __('Use another email or get a new code') }}</a></p>
        </div>
    </section>
@endsection
