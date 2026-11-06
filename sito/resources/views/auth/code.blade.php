@extends('layouts.app')

@section('content')
    <h1>Enter the code</h1>
    <p>We sent a 6-digit code to {{ session('login_email') }}. It expires in 10 minutes.</p>
    <form method="post" action="{{ route('login.verify') }}">
        @csrf
        <label for="code">Code</label>
        <input id="code" name="code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" required autocomplete="one-time-code">
        <button>Log in</button>
    </form>
@endsection
