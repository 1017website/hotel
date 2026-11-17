@extends('saas.layout')
@section('title','Panel SaaS')
@push('scripts')<script src="/saas-admin.js" defer></script>@endpush
@section('content')
<div class="page-heading"><div><p class="eyebrow">Administrasi layanan</p><h1>Panel SaaS</h1><p>Kelola langganan hotel, tagihan, dan ketersediaan layanan.</p></div><span id="saas-actor"></span></div>
<nav class="saas-tabs" id="saas-nav" aria-label="Menu admin SaaS"></nav>
<div id="saas-content"><div class="loading-panel" role="status">Memuat administrasi SaaS…</div></div>
<dialog id="saas-modal" aria-labelledby="saas-modal-title"><div class="dialog-heading"><h2 id="saas-modal-title"></h2><button id="saas-modal-close" type="button" class="secondary" aria-label="Tutup dialog">Tutup</button></div><div id="saas-modal-body"></div></dialog>
<div id="saas-toast" class="toast" role="status" aria-live="polite" hidden></div>
@endsection
