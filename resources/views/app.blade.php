<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Papan kamar | TataInap</title>
    <link rel="stylesheet" href="/vendor/tabler/tabler.min.css">
    <link rel="stylesheet" href="/vendor/tabler-icons/tabler-icons.min.css">
    <link rel="stylesheet" href="/app.css">
    <link rel="stylesheet" href="/theme.css">
    @include('partials.pastel')
    <link rel="stylesheet" href="/workspace.css">
    <link rel="stylesheet" href="/hotel-ui.css">
    <script src="/search-select.js" defer></script>
    <script src="/restaurant.js" defer></script>
    <script src="/workspace.js" defer></script>
    <script src="/attendance.js" defer></script>
    <script src="/app.js" defer></script>
</head>
<body class="hotel-app">
<a class="skip-link" href="#main">Langsung ke isi</a>
<div class="app-shell">
    <aside class="sidebar" id="sidebar" aria-label="Navigasi hotel">
        <div class="sidebar-head">
            <a class="hotel-brand" href="#rooms">
                @include('partials.tatainap-logo')
            </a>
            <button type="button" class="secondary mobile-menu sidebar-close" id="sidebar-close" aria-label="Tutup menu"><i class="ti ti-x" aria-hidden="true"></i></button>
        </div>
        <div class="property-picker" hidden>
            <label for="hotel-switch">Ganti hotel</label>
            <select id="hotel-switch"><option value="{{ $hotel->id }}">{{ $hotel->name }}</option></select>
        </div>
        <nav id="navigation" aria-label="Menu utama"></nav>
        <div class="sidebar-bottom">
            <a href="/subscription"><i class="ti ti-credit-card" aria-hidden="true"></i> Langganan hotel</a>
            <a href="#guide"><i class="ti ti-help" aria-hidden="true"></i> Panduan penggunaan</a>
        </div>
    </aside>
    <div class="menu-scrim" id="menu-scrim" aria-hidden="true" hidden></div>
    <div class="workspace">
        <header class="topbar">
            <div class="topbar-context">
                <button type="button" id="menu-toggle" class="secondary mobile-menu" aria-label="Buka menu" aria-controls="sidebar" aria-expanded="false"><i class="ti ti-menu-2" aria-hidden="true"></i></button>
                <div class="property-context">
                    <strong>{{ $hotel->name }}</strong>
                    <span id="breadcrumb">Operasional</span>
                </div>
            </div>
            <div class="topbar-right">
                @if($hotel->is_demo)<span class="demo-status">Data contoh</span>@endif
                <time id="today-label"></time>
                <details class="account-menu" id="account-menu">
                    <summary aria-label="Menu akun">
                        <span class="user-initial" aria-hidden="true">{{ mb_substr($user->name, 0, 1) }}</span>
                        <span class="account-name">{{ $user->name }}</span>
                        <i class="ti ti-chevron-down" aria-hidden="true"></i>
                    </summary>
                    <div class="account-dropdown">
                        <strong><span class="account-full-name">{{ $user->name }}</span><small id="user-role"></small></strong>
                        <a href="#account"><i class="ti ti-user-cog" aria-hidden="true"></i> Pengaturan akun</a>
                        <a href="#account-password"><i class="ti ti-key" aria-hidden="true"></i> Ganti kata sandi</a>
                        <form action="/logout" method="post">@csrf<button type="submit"><i class="ti ti-logout" aria-hidden="true"></i> Keluar dari akun</button></form>
                    </div>
                </details>
            </div>
        </header>
        <main id="main" tabindex="-1"><div class="loading-panel" role="status">Menyiapkan ruang kerja hotel…</div></main>
        <footer class="app-footer"><span id="sync-label">Memuat data</span><span>Zona waktu <span id="timezone-label">{{ $hotel->timezone }}</span></span></footer>
    </div>
</div>
<dialog id="modal" aria-labelledby="modal-title">
    <div class="dialog-heading"><h2 id="modal-title"></h2><button id="close-modal" class="secondary" type="button" aria-label="Tutup dialog">Tutup</button></div>
    <div id="modal-body"></div>
</dialog>
<div id="toast" class="toast" role="status" aria-live="polite" hidden></div>
</body>
</html>
