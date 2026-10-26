@extends('layouts.app')
@section('title', 'Notifikasi')
@section('content')
<section class="wrap section notification-page" data-notification-inbox data-initial-latest="{{ auth()->user()->notifications()->orderByDesc('id')->value('id') }}">
    <div class="section-title">
        <div>
            <p class="eyebrow">AKTIVITAS AKUN</p>
            <h1>Notifikasi</h1>
            <p class="muted">Pantau pembayaran, bukti transfer, penyaluran dana, dan perkembangan campaign Anda.</p>
            <p><strong data-inbox-unread-count>{{ $unreadCount }}</strong> notifikasi belum dibaca.</p>
        </div>
        @if($unreadCount > 0)
            <form method="POST" action="{{ route('notifications.read-all') }}">
                @csrf
                <button class="btn outline small" type="submit">Tandai semua dibaca</button>
            </form>
        @endif
    </div>
    <div class="notification-refresh alert" role="status" data-notification-refresh hidden>
        Ada pembaruan notifikasi. <a class="text-link" href="{{ route('notifications.index', ['filter' => $filter]) }}">Muat ulang daftar</a>
    </div>
    <nav class="notification-filters" aria-label="Filter notifikasi">
        <a href="{{ route('notifications.index') }}" @if($filter === 'all') aria-current="page" @endif>Semua</a>
        <a href="{{ route('notifications.index', ['filter' => 'unread']) }}" @if($filter === 'unread') aria-current="page" @endif>Belum dibaca</a>
    </nav>
    <div class="notification-list">
        @forelse($notifications as $notification)
            <article class="panel notification-item {{ $notification->read_at ? '' : 'is-unread' }}">
                <div class="notification-item-top">
                    <h2>{{ $notification->data['title'] ?? 'Aktivitas BeneFund' }}</h2>
                    @unless($notification->read_at)<span class="tag">Belum dibaca</span>@endunless
                </div>
                <p>{{ $notification->data['message'] ?? '' }}</p>
                <div class="notification-item-bottom">
                    <time class="muted" datetime="{{ $notification->created_at->toIso8601String() }}">{{ $notification->created_at->format('d/m/Y H:i') }} WIB</time>
                    <div class="actions">
                        @if(!empty($notification->data['url']))
                            <a class="text-link" href="{{ $notification->data['url'] }}">Lihat detail <span aria-hidden="true">→</span></a>
                        @endif
                        @unless($notification->read_at)
                            <form method="POST" action="{{ route('notifications.read', $notification->id) }}">
                                @csrf
                                <button class="btn small outline" type="submit">Tandai dibaca</button>
                            </form>
                        @endunless
                    </div>
                </div>
            </article>
        @empty
            <div class="panel notification-empty">
                <h2>{{ $filter === 'unread' ? 'Semua sudah terbaca' : 'Belum ada notifikasi' }}</h2>
                <p class="muted">{{ $filter === 'unread' ? 'Tidak ada notifikasi yang belum dibaca.' : 'Aktivitas baru yang berkaitan dengan akun Anda akan muncul di sini.' }}</p>
                <a class="btn outline" href="{{ route('dashboard') }}">Kembali ke Dashboard</a>
            </div>
        @endforelse
    </div>
    <div class="notification-pagination">{{ $notifications->links() }}</div>
    <p class="muted notification-help">Notifikasi tersimpan di akun Anda. Jumlah yang belum dibaca diperbarui otomatis selama halaman aktif.</p>
</section>
@endsection
