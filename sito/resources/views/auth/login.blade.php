@extends('layouts.app')

@section('title', __('Log in'))

@section('content')
    <section class="sezione">
        <div class="wrap stack misura" style="--stack: var(--s-5); max-width: 32rem">
            <h1 class="t-1">{{ __('Log in') }}</h1>
            <p>{{ __('Enter your email: we send you a 6-digit code. No password. If you are new, we create your account.') }}</p>
            <form method="post" action="{{ route('login.send') }}" class="stack">
                @csrf
                <div class="campo">
                    <label for="email">{{ __('Email') }}</label>
                    <input class="input" id="email" name="email" type="email" value="{{ old('email') }}" required autocomplete="email" inputmode="email" @error('email') aria-invalid="true" @enderror>
                </div>
                <button class="btn btn--primary btn--block">{{ __('Send me a code') }}</button>
            </form>
        </div>
    </section>
@endsection
