<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'BeneFund') — BeneFund</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Playfair+Display:wght@600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('css/app.css') }}?v={{ filemtime(public_path('css/app.css')) }}">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body>
    <a class="skip-link" href="#main-content">Lewati ke konten</a>
    <header class="nav">
        <div class="wrap nav-inner">
            <a class="brand" href="{{ route('home') }}"><span aria-hidden="true">♥</span> BeneFund</a>
            <button class="menu-toggle" type="button" aria-expanded="false" aria-controls="site-navigation">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="M4 6h16M4 12h16M4 18h16"/></svg>
                Menu
            </button>
            <nav class="links" id="site-navigation" aria-label="Navigasi utama">
                <a href="{{ route('home') }}" @if(request()->routeIs('home')) aria-current="page" @endif>Beranda</a>
                <a href="{{ route('campaigns') }}" @if(request()->routeIs('campaigns', 'campaign.show')) aria-current="page" @endif>Campaign</a>
                <a href="{{ route('about') }}" @if(request()->routeIs('about')) aria-current="page" @endif>Tentang Kami</a>
                <a href="{{ route('transparency') }}" @if(request()->routeIs('transparency')) aria-current="page" @endif>Transparansi</a>
                <a href="{{ route('news') }}" @if(request()->routeIs('news')) aria-current="page" @endif>Berita</a>
                <a href="{{ route('top') }}" @if(request()->routeIs('top')) aria-current="page" @endif>Top Donatur</a>
                <div class="nav-account">
                    @auth
                        <a href="{{ route('dashboard') }}" @if(request()->routeIs('dashboard')) aria-current="page" @endif>Dashboard</a>
                        <form action="{{ route('logout') }}" method="POST">
                            @csrf
                            <button class="btn small outline" type="submit">Keluar</button>
                        </form>
                    @else
                        <a href="{{ route('login') }}">Masuk</a>
                        <a class="btn small" href="{{ route('register') }}">Daftar</a>
                    @endauth
                </div>
            </nav>
        </div>
    </header>
    <main id="main-content">
        @if(session('ok'))
            <div class="wrap"><div class="alert" role="status">{{ session('ok') }}</div></div>
        @endif
        @if($errors->any())
            <div class="wrap"><div class="alert" role="alert">
                @foreach($errors->all() as $error)
                    <div>{{ $error }}</div>
                @endforeach
            </div></div>
        @endif
        @yield('content')
    </main>
    <footer class="footer">
        <div class="wrap">
            <b class="footer-brand">♥ BeneFund</b>
            <p>Every Gift, A Real Impact. <span>Pembayaran uji coba melalui Midtrans Sandbox.</span></p>
        </div>
    </footer>
    <script src="{{ asset('js/navigation.js') }}?v={{ filemtime(public_path('js/navigation.js')) }}" defer></script>
    <noscript><style>@media(max-width:1050px){.links{display:flex}.menu-toggle{display:none}}</style></noscript>
</body>
</html>
