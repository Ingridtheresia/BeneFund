@extends('layouts.app')
@section('title', 'Daftar')
@section('content')
<div class="wrap section">
    <div class="panel" style="max-width:500px;margin:auto">
        <h1>Mulai Perjalanan Kebaikan</h1>
        <form method="POST" action="{{ route('register.submit') }}">
            @csrf
            <div class="field">
                <label for="name">Nama Lengkap</label>
                <input id="name" name="name" value="{{ old('name') }}" maxlength="100" autocomplete="name" required autofocus>
            </div>
            <div class="field">
                <label for="email">Email</label>
                <input id="email" type="email" name="email" value="{{ old('email') }}" maxlength="254" autocomplete="email" required>
            </div>
            <div class="field">
                <label for="role">Jenis Akun</label>
                <select id="role" name="role" required>
                    <option value="donor" @selected(old('role', 'donor') === 'donor')>Donatur</option>
                    <option value="fundraiser" @selected(old('role') === 'fundraiser')>Penggalang Dana</option>
                </select>
            </div>
            <div class="field">
                <label for="password">Password (8–72 karakter)</label>
                <input id="password" type="password" name="password" minlength="8" maxlength="72" autocomplete="new-password" required>
            </div>
            <div class="field">
                <label for="password_confirmation">Konfirmasi Password</label>
                <input id="password_confirmation" type="password" name="password_confirmation" minlength="8" maxlength="72" autocomplete="new-password" required>
            </div>
            <button class="btn" type="submit">Buat Akun</button>
        </form>
        <p>Sudah punya akun? <a href="{{ route('login') }}">Masuk</a></p>
    </div>
</div>
@endsection
