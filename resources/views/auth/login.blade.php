@extends('layouts.app')
@section('title', 'Masuk')
@section('content')
<div class="wrap section">
    <div class="panel" style="max-width:470px;margin:auto">
        <h1>Selamat Datang Kembali</h1>
        <form method="POST" action="{{ route('login.submit') }}">
            @csrf
            <div class="field">
                <label for="email">Email</label>
                <input id="email" type="email" name="email" value="{{ old('email') }}" maxlength="254" autocomplete="username" required autofocus>
            </div>
            <div class="field">
                <label for="password">Password</label>
                <input id="password" type="password" name="password" maxlength="72" autocomplete="current-password" required>
            </div>
            <p><label><input type="checkbox" name="remember" value="1" @checked(old('remember'))> Ingat saya</label></p>
            <button class="btn" type="submit" style="width:100%">Masuk</button>
        </form>
        <p>Belum punya akun? <a href="{{ route('register') }}">Daftar</a></p>
    </div>
</div>
@endsection
