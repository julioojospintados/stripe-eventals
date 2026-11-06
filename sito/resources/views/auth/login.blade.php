@extends('layouts.app')

@section('content')
    <h1>Login</h1>
    <form method="post" action="{{ route('login.send') }}">
        @csrf
        <label for="email">Email</label>
        <input id="email" name="email" type="email" value="{{ old('email') }}" required autocomplete="email">
        <button>Send me a code</button>
    </form>
@endsection
