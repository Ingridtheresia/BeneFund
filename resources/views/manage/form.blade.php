@extends('layouts.app')
@section('title', 'Form Campaign')
@section('content')
<div class="wrap section">
    <div class="panel" style="max-width:760px;margin:auto">
        <h1>{{ $campaign->exists ? 'Edit' : 'Buat' }} Campaign</h1>
        <p class="muted">Campaign dan setiap perubahan akan diperiksa admin sebelum ditampilkan kepada publik.</p>
        <form method="POST" action="{{ $campaign->exists ? route('manage.update', $campaign) : route('manage.store') }}">
            @csrf
            @if($campaign->exists)@method('PUT')@endif
            <div class="field"><label for="campaign-title">Judul Campaign</label><input id="campaign-title" name="title" value="{{ old('title', $campaign->title) }}" maxlength="150" required></div>
            <div class="field">
                <label for="campaign-category">Kategori</label>
                <select id="campaign-category" name="category">
                    @foreach(App\Models\Campaign::CATEGORIES as $category)
                        <option value="{{ $category }}" @selected(old('category', $campaign->category) === $category)>{{ $category }}</option>
                    @endforeach
                </select>
            </div>
            <div class="field"><label for="campaign-location">Lokasi</label><input id="campaign-location" name="location" value="{{ old('location', $campaign->location) }}" maxlength="150"></div>
            <div class="field"><label for="campaign-description">Deskripsi</label><textarea id="campaign-description" name="description" minlength="30" maxlength="20000" required>{{ old('description', $campaign->description) }}</textarea></div>
            <div class="field"><label for="campaign-target">Target Donasi (Rp)</label><input id="campaign-target" type="number" min="100000" max="999999999999" name="target" value="{{ old('target', $campaign->target) }}" required></div>
            <div class="field"><label for="campaign-deadline">Deadline</label><input id="campaign-deadline" type="date" name="deadline" min="{{ today()->addDay()->format('Y-m-d') }}" value="{{ old('deadline', $campaign->deadline?->format('Y-m-d')) }}" required></div>
            <div class="field"><label for="campaign-image">URL Foto (http/https)</label><input id="campaign-image" type="url" name="image_url" maxlength="255" value="{{ old('image_url', $campaign->image_url) }}"></div>
            <button class="btn">Simpan dan Ajukan Verifikasi</button>
        </form>
    </div>
</div>
@endsection
