<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ ($platform ?? false) ? 'Masuk ke administrasi SaaS' : 'Masuk ke hotel' }} | TataInap</title>
    <link rel="stylesheet" href="/vendor/tabler-icons/tabler-icons.min.css">
    <link rel="stylesheet" href="/app.css">
    @include('partials.pastel')
    <link rel="stylesheet" href="/hotel-ui.css">
    <script src="/login.js" defer></script>
</head>
<body class="sign-in-page">
<main class="sign-in-layout">
    <section class="sign-in-branding" aria-label="TataInap">
        <div class="hotel-brand">
            @include('partials.tatainap-logo')
        </div>
        <div class="sign-in-intro">
            <p>{{ ($platform ?? false) ? 'Administrasi layanan' : 'Ruang kerja hotel' }}</p>
            <span>{{ ($platform ?? false) ? 'Kelola akses, paket, dan langganan hotel.' : 'Kamar, reservasi, dan operasional staf dalam satu ruang kerja.' }}</span>
        </div>
    </section>
    <section class="sign-in-content" aria-labelledby="login-title">
        <div class="sign-in-main">
            <h1 id="login-title">{{ ($platform ?? false) ? 'Masuk ke panel SaaS' : 'Masuk ke hotel' }}</h1>
            <p class="sign-in-description">{{ ($platform ?? false) ? 'Gunakan akun administrator layanan.' : 'Gunakan akun dari pengelola hotel Anda.' }}</p>
            @if(session('status'))<div class="info" role="status">{{ session('status') }}</div>@endif
            @if($errors->any())<div class="error" role="alert" id="login-error">{{ $errors->first() }}</div>@endif
            <form method="post" action="/login" class="sign-in-form">
                @csrf
                <div>
                    <label for="email">Email</label>
                    <input id="email" name="email" type="email" value="{{ old('email') }}" autocomplete="username" required
                        @error('email') aria-invalid="true" aria-describedby="login-error" @enderror>
                </div>
                <div>
                    <label for="password">Kata sandi</label>
                    <div class="password-field">
                        <input id="password" name="password" type="password" autocomplete="current-password" required
                            @error('password') aria-invalid="true" aria-describedby="login-error" @enderror>
                        <button type="button" class="password-toggle" aria-controls="password" aria-label="Tampilkan kata sandi" aria-pressed="false" hidden><i class="ti ti-eye" aria-hidden="true"></i></button>
                    </div>
                </div>
                <a class="sign-in-recovery" href="/forgot-password">Lupa kata sandi?</a>
                <button class="primary sign-in-submit" type="submit">Masuk <i class="ti ti-arrow-right" aria-hidden="true"></i></button>
            </form>
            <nav class="sign-in-links" aria-label="Akses lain">
                @if($platform ?? false)
                    <a href="/login">Kembali ke login hotel</a>
                @else
                    <a href="/register">Daftar hotel</a>
                    <a href="/saas/login">Administrasi SaaS</a>
                @endif
            </nav>
            @if(app()->environment('local') && config('app.show_demo_accounts'))
                <details class="sign-in-demo">
                    <summary>Akun demo lokal</summary>
                    @if($platform ?? false)
                        <p>Super Admin <code>saas-admin@example.test</code><br>Sub-admin <code>saas-ops@example.test</code><br>Kata sandi <code>DemoSaas2026!</code></p>
                    @else
                        <p>Owner <code>owner@example.test</code><br>Housekeeping <code>housekeeping@example.test</code><br>Kata sandi <code>DemoHotel2026!</code></p>
                    @endif
                </details>
            @endif
        </div>
    </section>
</main>
</body>
</html>
