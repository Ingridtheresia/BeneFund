@extends('layouts.app')
@section('title', 'Profil')
@section('content')
<div class="wrap section">
    <div class="panel" style="max-width:550px;margin:auto">
        <h1>Profil Saya</h1>
        <form method="POST" action="{{ route('profile.update') }}">
            @csrf
            <div class="field">
                <label for="name">Nama</label>
                <input id="name" name="name" value="{{ old('name', auth()->user()->name) }}" maxlength="100" autocomplete="name" required>
            </div>
            <div class="field">
                <label for="email">Email</label>
                <input id="email" name="email" type="email" value="{{ old('email', auth()->user()->email) }}" maxlength="254" autocomplete="email" required>
            </div>
            <p class="muted">Jenis akun: {{ ['donor' => 'Donatur', 'fundraiser' => 'Penggalang Dana', 'admin' => 'Admin'][auth()->user()->role] ?? auth()->user()->role }}</p>
            <h2>Ganti Password</h2>
            <p class="muted">Isi bagian ini jika ingin mengganti password.</p>
            <div class="field">
                <label for="current_password">Password Saat Ini</label>
                <input id="current_password" name="current_password" type="password" maxlength="72" autocomplete="current-password">
            </div>
            <div class="field">
                <label for="password">Password Baru (8–72 karakter)</label>
                <input id="password" name="password" type="password" minlength="8" maxlength="72" autocomplete="new-password">
            </div>
            <div class="field">
                <label for="password_confirmation">Konfirmasi Password Baru</label>
                <input id="password_confirmation" name="password_confirmation" type="password" minlength="8" maxlength="72" autocomplete="new-password">
            </div>
            <button class="btn" type="submit">Simpan Profil</button>
        </form>
    </div>
</div>
@endsection
