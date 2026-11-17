'use strict';

const $ = (selector, root = document) => root.querySelector(selector);
const h = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const money = value => new Intl.NumberFormat('id-ID', {style:'currency',currency:'IDR',maximumFractionDigits:0}).format(Number(value || 0));
const dateLabel = value => value ? new Date(value.length === 10 ? `${value}T12:00:00` : value.replace(' ', 'T')).toLocaleDateString('id-ID', {day:'numeric',month:'short',year:'numeric'}) : 'Belum diisi';
const timeLabel = value => value ? `${dateLabel(value)} · ${value.slice(11,16)}` : 'Belum absen pulang';
const labels = {available:'Siap dijual',reserved:'Dipesan',occupied:'Menginap',dirty:'Menunggu dibersihkan',cleaning:'Dibersihkan',inspection:'Perlu diperiksa',maintenance:'Dalam perbaikan',clean:'Bersih',confirmed:'Dipesan',checked_in:'Menginap',checked_out:'Selesai',cancelled:'Dibatalkan',new:'Baru',preparing:'Diproses',delivering:'Diantar',served:'Selesai',pending:'Menunggu persetujuan',approved:'Disetujui',rejected:'Ditolak',open:'Terbuka',resolved:'Selesai',not_connected:'Belum terhubung',pending_verification:'Menunggu verifikasi'};
const permissionLabels = {
    'rooms.view':'Melihat papan dan data kamar','rooms.manage':'Menambah dan mengubah kamar','housekeeping.manage':'Mengatur kebersihan kamar',
    'reservations.view':'Melihat reservasi dan data tamu','reservations.manage':'Membuat reservasi dan memproses check-in/out','reservations.cancel':'Membatalkan reservasi',
    'finance.view':'Melihat nominal, laporan kas, dan tagihan','finance.manage':'Mengelola akun dan jurnal penyesuaian','payments.manage':'Mencatat pembayaran','food.view':'Melihat menu dan pesanan','food.manage':'Mengelola menu dan pesanan',
    'employees.view':'Melihat karyawan, jadwal, dan absensi','employees.manage':'Mengelola data karyawan','shifts.manage':'Menyusun jadwal shift','attendance.manage':'Mencatat absensi masuk/pulang','attendance.self':'Absen sendiri melalui akun karyawan','attendance.import':'Mengimpor rekap absensi mesin',
    'expenses.view':'Melihat pengeluaran','expenses.manage':'Mencatat pengeluaran','expenses.approve':'Menyetujui atau menolak pengeluaran',
    'inventory.view':'Melihat persediaan','inventory.manage':'Mengelola dan menyesuaikan stok','maintenance.view':'Melihat laporan pemeliharaan','maintenance.manage':'Mengelola pekerjaan pemeliharaan',
    'channels.view':'Melihat koneksi OTA','channels.manage':'Mengatur konfigurasi OTA','roles.manage':'Membuat peran dan mengubah izin','users.manage':'Membuat pengguna dan mengganti peran',
    'audit.view':'Melihat riwayat aktivitas','hotel.manage':'Mengubah identitas dan zona waktu hotel',
    'guests.identity':'Melihat nomor dan dokumen identitas tamu',
};
const badge = status => `<span class="status ${h(status)}"><span class="dot" aria-hidden="true"></span>${h(labels[status] || status)}</span>`;
const btn = (label, action, id = '', value = '', cls = 'secondary') => {
    const symbol={create:'plus',refresh:'refresh',room:'bed',booking:'book-2',pay:'credit-card',clean:'spray',print:'printer',role:'lock',member:'user-edit',hotel:'settings',channel:'plug',stock:'package','edit-menu':'edit','edit-employee':'user-edit',close:'x'}[action];
    return `<button type="button" class="${cls}" data-action="${action}" data-id="${h(id)}" data-value="${h(value)}">${symbol?`<i class="ti ti-${symbol}" aria-hidden="true"></i> `:''}${h(label.replace(/^\+ /,''))}</button>`;
};
const can = permission => state?.permissions.includes(permission);
const roomName = id => state.rooms?.find(r => r.id === Number(id))?.number || `#${id}`;
const employeeName = id => state.employees?.find(r => r.id === Number(id))?.name || `#${id}`;
const bookingName = id => (state.reservations || state.food_reservations)?.find(r => r.id === Number(id))?.code || `#${id}`;
let state = null;
let page = 'rooms';
let refreshing = false;
let toastTimer;
let calendarOffset = 0;
let filter = {search:'',floor:'',status:''};
const navItems = [
    ['Operasional','rooms','Papan kamar','rooms.view'],
    ['Operasional','reservations','Reservasi','reservations.view'],
    ['Operasional','calendar','Kalender kamar','reservations.view'],
    ['Operasional','housekeeping','Housekeeping','housekeeping.manage'],
    ['Restoran','food','Restoran & pesanan','food.view'],
    ['Restoran','pos','POS restoran','food.view'],
    ['Restoran','breakfast','Breakfast','food.view'],
    ['Tim hotel','employees','Karyawan','employees.view'],
    ['Tim hotel','shifts','Jadwal shift','employees.view'],
    ['Tim hotel','attendance','Absensi','employees.view'],
    ['Tim hotel','my-attendance','Absensi saya','attendance.self'],
    ['Tim hotel','attendance-import','Impor absensi mesin','attendance.import'],
    ['Pengelolaan','expenses','Pengeluaran','expenses.view'],
    ['Pengelolaan','inventory','Persediaan','inventory.view'],
    ['Pengelolaan','maintenance','Pemeliharaan','maintenance.view'],
    ['Pengelolaan','finance','Laporan & kas','finance.view'],
    ['Pengelolaan','channels','Distribusi & OTA','channels.view'],
    ['Pengaturan','access','Pengguna & hak akses','users.manage'],
    ['Pengaturan','roles','Peran & izin','roles.manage'],
    ['Pengaturan','settings','Pengaturan hotel','hotel.manage'],
    ['Pengaturan','booking-engine','Booking langsung','hotel.manage'],
    ['Pengaturan','audit','Riwayat aktivitas','audit.view'],
];

async function request(url, method = 'GET', body) {
    const response = await fetch(url, {method, credentials:'same-origin', headers:{Accept:'application/json','Content-Type':'application/json','X-CSRF-TOKEN':$('meta[name=csrf-token]').content,...(state&&method!=='GET'?{'X-Hotel-ID':String(state.hotel.id)}:{})}, ...(body === undefined ? {} : {body:JSON.stringify(body)})});
    if (response.status === 401 || response.status === 419) { window.location.href = '/login'; throw new Error('Sesi berakhir. Silakan masuk kembali.'); }
    const data = await response.json().catch(() => ({}));
    if(data.subscription_unavailable){window.location.href='/subscription';throw new Error(data.message);}
    if (!response.ok) throw new Error(data.errors ? Object.values(data.errors).flat().join('\n') : data.message || 'Permintaan gagal. Coba kembali.');
    if(data.csrf_token){$('meta[name=csrf-token]').content=data.csrf_token;document.querySelectorAll('input[name="_token"]').forEach(input=>input.value=data.csrf_token);}
    return data;
}

function toast(message, error = false) {
    const el = $('#toast'); el.textContent = message; el.classList.toggle('error-toast', error); el.hidden = false;
    clearTimeout(toastTimer); toastTimer = setTimeout(() => { el.hidden = true; }, 6500);
}

function deferAutoRefresh() {
    const focused = document.activeElement;
    return document.hidden || $('#modal').open || window.SearchSelect.isOpen()
        || $('#sidebar').classList.contains('is-open') || $('#account-menu').open
        || Boolean(focused?.closest('#main, #sidebar, .account-menu')
            && focused.matches('a[href], input, select, textarea, [contenteditable], summary, [tabindex="0"]'));
}

function comparableData(data) {
    const {updated_at, csrf_token, ...content} = data;
    return JSON.stringify(content);
}

async function load(silent = false, {automatic = false} = {}) {
    if (refreshing) {
        if (automatic) return;
        await refreshing;
        return load(silent);
    }
    let finish;
    refreshing = new Promise(resolve => { finish = resolve; });
    try {
        const next = await request('/api/data');
        if (state && state.hotel.id !== next.hotel.id) { window.location.reload(); return; }
        if (automatic && deferAutoRefresh()) return;
        $('#sync-label').textContent = `Data diperiksa pukul ${next.updated_at}`;
        if (automatic && comparableData(state) === comparableData(next)) return;

        const scrollPositions = automatic ? [...document.querySelectorAll('#main .table-wrap')].map(el => [el.scrollLeft, el.scrollTop]) : [];
        const focus = document.activeElement;
        const actionFocus = automatic && focus?.closest('#main button[data-action]') ? focus.dataset : null;
        const refreshButton = focus?.matches('[data-action="refresh"]');
        state = next;
        $('.property-picker').hidden = state.hotels.length < 2;
        $('#hotel-switch').innerHTML = state.hotels.map(hotel => `<option value="${hotel.id}" ${hotel.id === state.hotel.id ? 'selected' : ''}>${h(hotel.name)}</option>`).join('');
        $('#today-label').dateTime = state.today;
        $('#today-label').textContent = dateLabel(state.today);
        $('#timezone-label').textContent = state.hotel.timezone;
        $('#user-role').textContent = state.role;
        $('.account-name').textContent = state.user.name;
        $('.account-full-name').textContent = state.user.name;
        $('.user-initial').textContent = Array.from(state.user.name)[0] || '';
        $('.property-context strong').textContent = state.hotel.name;
        if (!silent || !$('#modal').open) {
            render();
            if (refreshButton) $('#main [data-action="refresh"]')?.focus({preventScroll: true});
            else if (actionFocus) {
                const target = [...document.querySelectorAll('#main button[data-action]:not(:disabled)')].find(button =>
                    ['action', 'id', 'value'].every(key => button.dataset[key] === actionFocus[key]));
                (target || $('#main')).focus({preventScroll: true});
            }
            document.querySelectorAll('#main .table-wrap').forEach((el, i) => {
                if (scrollPositions[i]) el.scrollTo(...scrollPositions[i]);
            });
        }
    } catch (error) {
        $('#sync-label').textContent = 'Pembaruan gagal. Data mungkin belum terbaru.';
        if (!state) $('#main').innerHTML = `<div class="empty-state"><h1>Data belum dapat dimuat</h1><p>${h(error.message)}</p>${btn('Coba lagi','refresh')}</div>`;
        else if (!silent) toast(error.message, true);
    } finally {
        refreshing = false;
        finish();
    }
}

function heading(title, description, actions = '') {
    return `<div class="page-heading"><div><h1>${h(title)}</h1>${description ? `<p>${h(description)}</p>` : ''}</div><div class="actions">${actions}</div></div>`;
}
function empty(title = 'Belum ada data', text = 'Tambahkan data pertama melalui tombol di atas.') { return `<div class="empty-state"><h2>${h(title)}</h2><p>${h(text)}</p></div>`; }
function table(headers, rows, module = '', {showCount = true, variant = ''} = {}) {
    if (!rows.length) return empty();
    const count = state.counts?.[module] || rows.length;
    const responsive = variant === 'reservation-ledger';
    return `<div class="table-wrap ${variant}"><table ${responsive ? 'role="table" aria-label="Daftar reservasi"' : ''}>
        <thead ${responsive ? 'role="rowgroup"' : ''}><tr ${responsive ? 'role="row"' : ''}>${headers.map(label => `<th scope="col" ${responsive ? 'role="columnheader"' : ''}>${h(label)}</th>`).join('')}</tr></thead>
        <tbody ${responsive ? 'role="rowgroup"' : ''}>${rows.map(row => `<tr ${responsive ? 'role="row"' : ''}>${row.map((cell, index) => `<td ${responsive ? `role="cell" data-label="${h(headers[index])}"` : ''}>${cell}</td>`).join('')}</tr>`).join('')}</tbody></table></div>`
        + (showCount ? `<p class="table-note">Menampilkan ${rows.length} dari ${count} catatan${count > rows.length ? ' · 250 catatan terbaru. Arsip lengkap akan tersedia pada pengembangan berikutnya.' : '.'}</p>` : '');
}
function summary(cells) { return `<div class="summary-strip">${cells.map(([label,value,detail]) => `<div class="summary-cell"><small>${h(label)}</small><strong>${h(value)}</strong>${detail ? `<div class="summary-detail">${h(detail)}</div>` : ''}</div>`).join('')}</div>`; }

function render() {
    page = location.hash.slice(1) || (state.role === 'Housekeeping' && can('housekeeping.manage') ? 'housekeeping' : 'rooms');
    const allowed = navItems.filter(x => can(x[3]));
    if (!['guide','account','account-password'].includes(page) && !allowed.some(x => x[1] === page)) page = allowed[0]?.[1] || 'guide';
    let group = '';
    window.SearchSelect.close();
    $('#navigation').innerHTML = allowed.map(item => {
        const section = group !== item[0] ? `<p class="nav-group">${h(item[0])}</p>` : ''; group = item[0];
        const icons={rooms:'bed',reservations:'book-2',calendar:'calendar',housekeeping:'spray',food:'tools-kitchen-2',pos:'device-desktop',breakfast:'sunrise',employees:'users',shifts:'calendar-time',attendance:'fingerprint','my-attendance':'user-check','attendance-import':'file-upload',expenses:'receipt',inventory:'packages',maintenance:'tool',finance:'chart-bar',channels:'world',access:'user-shield',roles:'lock',settings:'settings','booking-engine':'browser-check',audit:'history'};
        return `${section}<a href="#${item[1]}" class="nav-link ${page === item[1] ? 'active' : ''}" ${page === item[1] ? 'aria-current="page"' : ''}><i class="ti ti-${icons[item[1]]}" aria-hidden="true"></i>${h(item[2])}</a>`;
    }).join('');
    const current = allowed.find(x => x[1] === page);
    $('#breadcrumb').textContent = current?.[0] || (page === 'guide' ? 'Bantuan' : 'Akun');
    document.title = `${current?.[2] || (page==='account'?'Pengaturan akun':page==='account-password'?'Ganti kata sandi':'Panduan')} | TataInap`;
    const views = {rooms:Workspace.board,housekeeping:Workspace.housekeeping,reservations:Workspace.reservations,calendar:()=>Workspace.calendar(),food:()=>Restaurant.orders(),pos:()=>Restaurant.pos(),breakfast:()=>Restaurant.breakfast(),employees:employees,shifts:shifts,attendance:()=>Workspace.attendance(),'my-attendance':()=>StaffAttendance.self(),'attendance-import':()=>StaffAttendance.importPage(),expenses:expenses,inventory:()=>Workspace.inventory(),maintenance:maintenance,finance:()=>Workspace.finance(),channels:channels,access:access,roles:roles,settings:settings,audit:audit,guide:guide,account:()=>Workspace.account(), 'account-password':()=>Workspace.account(true),'booking-engine':()=>Workspace.bookingEngine()};
    $('#main').innerHTML = (views[page] || guide)();
    if (page === 'rooms') Workspace.drawRooms();
    if (page === 'reservations') Workspace.drawReservations();
    window.SearchSelect.enhance();
}

function board() {
    const rooms = state.rooms || [];
    const housekeeping = page === 'housekeeping';
    return heading(housekeeping ? 'Housekeeping' : 'Papan kamar', housekeeping ? 'Atur kebersihan dan petugas. Kamar kembali dijual setelah dinyatakan bersih.' : `Kondisi kamar hari ini, ${dateLabel(state.today)}. Klik kamar untuk melihat detail.`, `${btn('Perbarui','refresh')}${can('rooms.manage') && !housekeeping ? btn('+ Kamar','create','rooms') : ''}${can('reservations.manage') ? btn('+ Reservasi','create','reservations','','primary') : ''}`)
        + summary([
            ['Siap dijual',rooms.filter(r => r.board_status === 'available').length,'Kosong dan bersih'],
            ['Menginap',rooms.filter(r => r.occupancy === 'occupied').length,`dari ${rooms.length} kamar`],
            ['Terbooking hari ini',rooms.filter(r => r.occupancy === 'reserved').length,'Menunggu kedatangan'],
            ['Perlu penanganan',rooms.filter(r => ['dirty','cleaning','inspection','maintenance'].includes(r.board_status)).length,'Kebersihan dan perbaikan'],
        ])
        + `<div class="toolbar"><div class="search"><label for="room-search">Cari kamar</label><input id="room-search" type="search" placeholder="Nomor, tipe kamar${can('reservations.view') ? ', atau tamu' : ''}" value="${h(filter.search)}"></div><div class="filter"><label for="floor-filter">Lantai</label><select id="floor-filter"><option value="">Semua lantai</option>${[...new Set(rooms.map(r => r.floor))].map(f => `<option value="${f}" ${String(f) === filter.floor ? 'selected' : ''}>Lantai ${f}</option>`).join('')}</select></div><div class="filter"><label for="status-filter">Status</label><select id="status-filter"><option value="">Semua status</option>${['available','reserved','occupied','dirty','cleaning','inspection','maintenance'].map(s => `<option value="${s}" ${s === filter.status ? 'selected' : ''}>${h(labels[s])}</option>`).join('')}</select></div></div>`
        + `<div class="legend">${['available','reserved','occupied','dirty','cleaning','inspection','maintenance'].map(s => `<span class="${s}"><i class="dot" aria-hidden="true"></i>${h(labels[s])}</span>`).join('')}</div><div id="room-results"></div>`;
}

function renderRooms() {
    let rooms = (state.rooms || []).filter(r => (!filter.floor || String(r.floor) === filter.floor) && (!filter.status || r.board_status === filter.status) && `${r.number} ${r.type} ${r.reservation?.guest || ''}`.toLowerCase().includes(filter.search.toLowerCase()));
    if (page === 'housekeeping' && !filter.status) rooms = rooms.filter(r => r.housekeeping !== 'clean');
    $('#room-results').innerHTML = rooms.length ? [...new Set(rooms.map(r => r.floor))].map(floor => {
        const floorRooms = rooms.filter(r => r.floor === floor);
        return `<section aria-labelledby="floor-${floor}"><div class="floor-title"><h2 id="floor-${floor}">Lantai ${floor}</h2><small>${floorRooms.length} kamar</small></div><div class="room-grid">${floorRooms.map(room => {
            const note = room.reservation ? `<strong>${h(room.reservation.guest)}</strong><small>${room.occupancy === 'occupied' ? 'Check-out' : 'Check-in'} ${dateLabel(room.occupancy === 'occupied' ? room.reservation.departure : room.reservation.arrival)} · ${h(room.reservation.source)}</small>` : room.assigned_to && room.housekeeping !== 'clean' ? `<strong>${h(room.assigned_to)}</strong><small>Petugas housekeeping</small>` : `<strong>${room.board_status === 'available' ? 'Siap menyambut tamu' : h(labels[room.board_status])}</strong><small>${room.rate !== undefined ? `${money(room.rate)} / malam` : `${room.capacity} tamu`}</small>`;
            return `<article class="room-card ${room.board_status}"><button type="button" class="room-open" data-action="room" data-id="${room.id}" aria-label="Lihat kamar ${h(room.number)}, ${h(labels[room.board_status])}"><div class="room-head"><span class="room-number">${h(room.number)}</span><span class="room-type">${h(room.type)}</span></div><div class="room-meta">${room.capacity} tamu · ${h(room.facilities.slice(0,2).join(' · '))}</div>${badge(room.board_status)}${room.occupancy === 'reserved' && room.board_status !== 'reserved' ? ` <span class="status reserved">Juga terbooking</span>` : ''}<div class="room-detail">${note}</div></button></article>`;
        }).join('')}</div></section>`;
    }).join('') : empty('Tidak ada kamar yang sesuai', page === 'housekeeping' ? 'Tidak ada kamar dalam antrean kebersihan untuk filter ini.' : 'Ubah kata pencarian atau filter kamar.');
}

function reservationActions(r) {
    let actions = btn('Detail','booking',r.id);
    if (can('finance.view')) actions += `<a href="/reservations/${r.id}/invoice">Tagihan</a>`;
    if (can('payments.manage') && ['confirmed','checked_in'].includes(r.status) && Number(r.total) > Number(r.paid || 0)) actions += btn('Bayar','pay',r.id);
    if (can('reservations.manage') && r.status === 'confirmed') actions += btn('Check-in','transition',r.id,'checkin');
    if (can('reservations.manage') && r.status === 'checked_in') actions += btn('Check-out','transition',r.id,'checkout');
    return `<div class="actions">${actions}</div>`;
}
function reservations() {
    return heading('Reservasi', 'Kelola kedatangan, masa menginap, dan penyelesaian tagihan.', can('reservations.manage') ? btn('+ Reservasi','create','reservations','','primary') : '')
        + table(['Reservasi / tamu','Kamar','Tanggal menginap','Sumber','Status',...(can('finance.view') || can('payments.manage') ? ['Tagihan / sisa'] : []),'Tindakan'], (state.reservations || []).map(r => [
            `<strong>${h(r.code)}</strong><small>${h(r.guest)}</small>`,h(roomName(r.room_id)),`${dateLabel(r.arrival)}<small>s.d. ${dateLabel(r.departure)}</small>`,h(r.source),badge(r.status),
            ...(r.total !== undefined ? [`${money(r.total)}<small>Sisa ${money(r.total - r.paid)}</small>`] : []),reservationActions(r),
        ]), 'reservations');
}
function addDays(date, days) { const d = new Date(`${date}T12:00:00Z`); d.setUTCDate(d.getUTCDate() + days); return d.toISOString().slice(0,10); }
function calendar() {
    const dates = Array.from({length:7}, (_,i) => addDays(state.today,calendarOffset+i));
    return heading('Kalender kamar', 'Lihat reservasi per tanggal. Tanggal check-out dapat dipakai untuk reservasi berikutnya.', `${btn('7 hari sebelumnya','calendar','',-7)}${btn('Hari ini','calendar','',0)}${btn('7 hari berikutnya','calendar','',7)}`)
        + `<div class="table-wrap"><table class="calendar-table"><thead><tr><th scope="col">Kamar</th>${dates.map(d => `<th scope="col" class="${d === state.today ? 'calendar-today' : ''}">${dateLabel(d)}</th>`).join('')}</tr></thead><tbody>${(state.rooms || []).map(room => `<tr><th scope="row" class="calendar-room">${h(room.number)}<small>${h(room.type)}</small></th>${dates.map(d => {
            const r = (state.calendar_reservations || state.reservations || []).find(r => r.room_id === room.id && ['confirmed','checked_in'].includes(r.status) && r.arrival <= d && r.departure > d);
            return `<td>${r ? `<div class="calendar-booking ${r.status}"><strong>${h(r.guest)}</strong>${h(labels[r.status])}</div>` : room.out_of_service ? '<span class="muted">Perbaikan</span>' : '<span class="muted">Kosong</span>'}</td>`;
        }).join('')}</tr>`).join('')}</tbody></table></div><p class="table-note">Kalender menunjukkan pemesanan. Kondisi kebersihan terkini tersedia di Papan kamar.</p>`;
}
function food() {
    const next = {new:['Mulai proses','preparing'],preparing:['Mulai antar','delivering'],delivering:['Selesaikan','served']};
    return heading('Restoran & room service','Pesanan kamar otomatis ditambahkan ke tagihan tamu.',can('food.manage') ? `${btn('+ Menu','create','food_items')}${btn('+ Pesanan','create','food_orders','','primary')}` : '')
        + `<h2>Pesanan kamar</h2>` + table(['Pesanan','Reservasi','Menu','Total','Status','Tindakan'],(state.food_orders || []).map(o => [
            `#${o.id}<small>${dateLabel(o.created_at)}</small>`,h(bookingName(o.reservation_id)),o.items.map(i => `${h(i.quantity)} × ${h(i.name)}`).join('<br>'),money(o.total),badge(o.status),can('food.manage') && next[o.status] ? btn(next[o.status][0],'update',o.id,`food_orders:${next[o.status][1]}`) : 'Selesai',
        ]),'food_orders')
        + `<h2>Daftar menu</h2>` + table(['Menu','Kategori','Harga','Ketersediaan','Tindakan'],(state.food_items || []).map(i => [h(i.name),h(i.category),money(i.price),i.available ? 'Tersedia' : 'Habis',can('food.manage') ? btn('Ubah menu','edit-menu',i.id) : 'Lihat saja']),'food_items');
}
function employees() { return heading('Karyawan','Hubungkan akun untuk absen sendiri dan ID mesin untuk impor fingerprint.',can('employees.manage') ? btn('+ Karyawan','create','employees','','primary') : '') + table(['Nama','Departemen','Jabatan','Telepon','Akun / ID mesin','Status','Tindakan'],(state.employees || []).map(e => [h(e.name),h(e.department),h(e.position),h(e.phone || 'Belum diisi'),`${e.user_id?h(state.members?.find(m=>m.id===e.user_id)?.name||'Akun terhubung'):'Belum ada akun'}<small>ID mesin: ${h(e.attendance_code||'Belum diatur')}</small>`,e.active ? 'Aktif' : 'Nonaktif',can('employees.manage') ? `<div class="actions">${btn('Ubah','edit-employee',e.id)}<button type="button" class="secondary" data-action="staff-map" data-id="${e.id}">Akun / ID mesin</button></div>` : 'Lihat saja']),'employees'); }
function shifts() { return heading('Jadwal shift','Atur tanggal dan jam kerja, termasuk shift yang melewati tengah malam.',can('shifts.manage') ? btn('+ Jadwal shift','create','shifts','','primary') : '') + table(['Karyawan','Mulai','Selesai','Catatan'],(state.shifts || []).map(s => [h(employeeName(s.employee_id)),timeLabel(s.starts_at),timeLabel(s.ends_at),h(s.notes || 'Tidak ada')]),'shifts'); }
function attendance() { return heading('Absensi','Pencatatan masuk dan pulang oleh petugas yang memiliki izin.',can('attendance.manage') ? `${btn('Absen pulang','clock','', 'clockout')}${btn('Absen masuk','clock','','clockin','primary')}` : '') + `<div class="info">Waktu dicatat oleh server sesuai zona waktu hotel. Absensi pulang menyelesaikan catatan masuk yang masih terbuka, termasuk shift malam.</div>` + table(['Karyawan','Tanggal kerja','Masuk','Pulang'],(state.attendances || []).map(a => [h(employeeName(a.employee_id)),dateLabel(a.date),timeLabel(a.clock_in),timeLabel(a.clock_out)]),'attendances'); }
function expenses() { return heading('Pengeluaran','Catat biaya operasional dan proses persetujuannya.',can('expenses.manage') ? btn('+ Pengeluaran','create','expenses','','primary') : '') + table(['Keperluan','Kategori','Tanggal','Nominal','Status','Tindakan'],(state.expenses || []).map(e => [h(e.description),h(e.category),dateLabel(e.date),money(e.amount),badge(e.status),e.status === 'pending' && can('expenses.approve') ? `<div class="actions">${btn('Setujui','update',e.id,'expenses:approved')}${btn('Tolak','update',e.id,'expenses:rejected')}</div>` : 'Sudah diproses']),'expenses'); }
function inventory() { return heading('Persediaan','Pantau perlengkapan hotel dan jumlah minimum yang perlu disediakan.',can('inventory.manage') ? btn('+ Barang','create','inventory','','primary') : '') + table(['Barang','Jumlah','Minimum','Kondisi','Tindakan'],(state.inventory || []).map(i => [h(i.name),`${i.quantity} ${h(i.unit)}`,`${i.minimum} ${h(i.unit)}`,i.quantity < i.minimum ? badge('dirty').replace('Menunggu dibersihkan','Perlu restok') : badge('clean').replace('Bersih','Cukup'),can('inventory.manage') ? btn('Sesuaikan stok','stock',i.id) : 'Lihat saja']),'inventory'); }
function maintenance() { return heading('Pemeliharaan','Laporan terbuka memblokir kamar dari penjualan sampai perbaikan selesai.',can('maintenance.manage') ? btn('+ Laporan','create','maintenance','','primary') : '') + table(['Kamar','Pekerjaan','Petugas','Status','Tindakan'],(state.maintenance || []).map(m => [h(roomName(m.room_id)),h(m.description),h(m.assigned_to || 'Belum ditugaskan'),badge(m.status),m.status === 'open' && can('maintenance.manage') ? btn('Selesaikan','update',m.id,'maintenance:resolved') : 'Selesai']),'maintenance'); }
function finance() {
    const f = state.finance || {};
    return heading('Laporan & kas','Ringkasan penerimaan dan pengeluaran yang telah disetujui, bulan berjalan.',btn('Cetak laporan','print'))
        + summary([['Penerimaan',money(f.income),'Pembayaran yang sudah tercatat'],['Pengeluaran',money(f.expenses),'Hanya biaya disetujui'],['Selisih kas',money(f.income-f.expenses),'Penerimaan dikurangi pengeluaran'],['Pembayaran terbaru',f.payments?.length || 0,'Maksimum 250 transaksi terbaru']])
        + `<p class="financial-note">Selisih kas bukan laba akuntansi. Laporan ini belum mencakup pajak, rekonsiliasi settlement OTA, atau tutup kas per shift.</p><h2>Riwayat penerimaan</h2>`
        + `<div class="info">Penerimaan sudah mencakup pembayaran POS langsung sebesar ${money(f.restaurant_income)}. Pesanan yang dibebankan ke kamar dihitung saat pembayaran reservasi, sehingga tidak dihitung dua kali.</div>`
        + table(['Tanggal','Reservasi','Metode','Nominal'],(f.payments || []).map(p => [dateLabel(p.created_at),h(bookingName(p.reservation_id)),h(p.method),money(p.amount)]));
}
function channels() { return heading('Distribusi & OTA','Siapkan koneksi distribusi untuk setiap hotel.') + `<div class="info"><strong>Belum ada sinkronisasi OTA aktif.</strong> Konfigurasi di sini disimpan sebagai persiapan. Integrasi produksi memerlukan akses mitra, pengembangan konektor, dan pengujian penyedia. Booking berlabel OTA pada data contoh dimasukkan manual.</div><div class="channel-grid">${(state.channels || []).map(c => `<section class="panel channel-card"><div class="channel-heading"><h2>${h(c.provider)}</h2>${badge(c.status)}</div><p>Property ID: <strong>${h(c.property_id || 'Belum diisi')}</strong><br>Jalur: ${c.connection_mode === 'direct' ? 'Langsung ke OTA' : 'Channel manager'}<br>Mitra: ${h(c.partner_name || 'Belum dipilih')}<br>Sinkronisasi terakhir: ${c.last_synced_at ? dateLabel(c.last_synced_at) : 'Belum pernah'}</p>${can('channels.manage') ? btn('Atur koneksi','channel',c.id) : ''}</section>`).join('')}</div>`; }
function access() { return heading('Pengguna & hak akses','Akun pengguna hanya dapat membuka hotel dan tindakan yang diberikan.',btn('+ Pengguna','create','members','','primary')) + table(['Nama','Email','Peran','Tindakan'],(state.members || []).map(m => [h(m.name),h(m.email),h(state.roles?.find(r => r.id === m.role_id)?.name || 'Peran terpasang'),state.roles?.find(r => r.id === m.role_id)?.is_owner ? 'Owner dilindungi' : btn('Ubah peran','member',m.id)])); }
function roles() { return heading('Peran & izin','Sesuaikan izin tindakan untuk kebutuhan hotel aktif.',btn('+ Peran','create','roles','','primary')) + table(['Peran','Izin','Tindakan'],(state.roles || []).map(r => [h(r.name),r.is_owner ? 'Seluruh izin · akses Owner dilindungi' : `${r.permissions.length} izin`,r.is_owner ? 'Tidak dapat diubah' : btn('Atur izin','role',r.id)]),'roles'); }
function settings() { return heading('Pengaturan hotel','Identitas dan zona waktu berlaku untuk hotel aktif.') + `<section class="panel"><h2>${h(state.hotel.name)}</h2><p>${h(state.hotel.address || 'Alamat belum diisi')}<br>Zona waktu: ${h(state.hotel.timezone)}</p>${btn('Ubah pengaturan','hotel')}</section>`; }
function audit() { return heading('Riwayat aktivitas','Jejak perubahan operasional dan pengaturan pada hotel aktif.') + table(['Waktu (UTC)','Tindakan','Entitas','ID','Pengguna'],(state.audit_logs || []).map(a => [h(a.created_at),h(a.action),h(a.entity),h(a.entity_id || 'Tidak ada'),`#${h(a.user_id || 'Sistem')}`]),'audit_logs'); }
function guide() {
    const sections = [
        ['Mulai menggunakan hotel',['Owner mengisi identitas hotel, menambahkan kamar beserta fasilitas dan tarif per malam.','Buat peran pada Peran & izin, kemudian tambahkan akun melalui Pengguna & hak akses.','Data karyawan dihubungkan ke akun pengguna melalui Karyawan → Akun / ID mesin. Berikan izin Absen sendiri pada peran akun agar karyawan dapat memakai Absensi saya.']],
        ['Menerima dan melepas tamu',['Buka Reservasi, pilih kamar, tanggal kedatangan dan keberangkatan. Tarif dihitung dari jumlah malam dikali tarif kamar.','Saat tamu tiba, pastikan kamar bersih lalu pilih Check-in. Lengkapi data tamu dan nomor KTP/Paspor; foto dokumen bersifat opsional. Reservasi harus mencakup hari ini.','Catat pembayaran melalui Bayar. Pesanan room service menambah tagihan secara otomatis.','Deposit dapat diterima, dipakai untuk tagihan, atau dikembalikan melalui Detail reservasi. Setelah tagihan lunas dan deposit terselesaikan, pilih Check-out. Kamar otomatis masuk antrean kebersihan. Pindah/perpanjang tersedia pada Detail.']],
        ['Membaca papan kamar',['Hijau berarti kosong dan siap dijual; biru berarti terbooking hari ini; ungu berarti sedang ditempati.','Kuning, oranye, dan abu menunjukkan tahap kebersihan. Merah berarti sedang diperbaiki.','Status reservasi dan kebersihan terpisah. Kamar dapat sekaligus terbooking dan masih dibersihkan.','Pilih lantai pada panel lantai agar gedung besar mudah dipindai. Maksimal 24 kartu ditampilkan per halaman; pencarian kamar menjangkau seluruh lantai. Kalender memiliki filter lantai dan rentang tujuh hari.']],
        ['Housekeeping & pemeliharaan',['Buka Housekeeping dan pilih kamar untuk menugaskan petugas atau mengubah status kebersihan.','Urutan yang disarankan: menunggu dibersihkan, dibersihkan, perlu diperiksa, lalu bersih.','Laporan pemeliharaan memblokir penjualan kamar. Setelah semua pekerjaan selesai, kamar harus dibersihkan kembali.']],
        ['Absensi mandiri & mesin',['Karyawan masuk dengan akun pribadi, lalu membuka Absensi saya. Jam masuk/pulang memakai waktu server; karyawan tidak memilih nama orang lain. Fitur ini belum membatasi lokasi atau jaringan Wi-Fi.','Pengelola menghubungkan akun dan ID mesin melalui Karyawan → Akun / ID mesin. Satu akun dan ID mesin hanya untuk satu karyawan pada hotel yang sama.','Untuk mesin fingerprint, unduh template pada Impor absensi mesin. Isi kode_mesin, tanggal_kerja, jam_masuk, jam_pulang. Ekspor Excel/TXT perlu disesuaikan menjadi CSV UTF-8.','Gunakan tanggal lengkap pada shift malam; jam pulang boleh kosong. Periksa pratinjau, perbaiki masalah, lalu simpan. Impor ulang data identik dilewati dan catatan manual/izin tidak ditimpa. Data biometrik tidak diunggah.']],
        ['Tim, biaya & persediaan',['Jadwalkan shift memakai tanggal dan jam mulai serta selesai. Shift malam memakai tanggal selesai pada hari berikutnya.','Petugas berizin mencatat absensi masuk dan pulang. Absensi masuk yang terbuka tetap bisa ditutup setelah tengah malam.','Pengeluaran baru menunggu persetujuan. Laporan kas menghitung biaya yang telah disetujui.','Gunakan Koreksi untuk hadir, sakit, izin, atau alpa; alasan wajib diisi. Izin/cuti memiliki proses persetujuan. Pada Persediaan, ubah Jenis barang menjadi Linen untuk melacak bersih, kotor, dikirim laundry, dan kembali bersih melalui Mutasi.']],
        ['POS restoran & breakfast',['Buka POS restoran, pilih beberapa menu dan atur jumlah pada keranjang. Proses pesanan untuk makan di tempat, takeaway, atau layanan kamar.','Layanan kamar wajib memilih reservasi yang sedang menginap. Pesanan langsung menambah tagihan kamar; pesanan meja/takeaway dibayar dari daftar restoran.','Pajak dan service diatur per hotel melalui Pengaturan restoran. Diskon memerlukan izin pembayaran. Harga dan total dihitung server.','Jatah breakfast ditentukan saat reservasi atau melalui Atur jatah di halaman Breakfast. Hak berlaku mulai pagi setelah check-in sampai hari check-out.','Catat porsi yang benar-benar digunakan. Sistem menolak porsi melebihi jatah harian dan mencegah pencatatan ulang akibat pengiriman ulang. Di luar jam layanan, petugas reservasi wajib mengisi alasan.']],
        ['Booking langsung, jurnal & akun',['Booking langsung dapat diaktifkan dari pengaturan setelah tarif dan kapasitas diperiksa. Tamu memilih tanggal dan kamar melalui tautan publik; pembayaran online belum tersedia.','Pilih outlet Restoran, Spa, atau Fasilitas pada POS. Menu layanan harus dibuat pada outlet yang sama; pembayaran dapat langsung atau ditagihkan ke kamar.','Laporan & kas menampilkan jurnal otomatis, akun, kas harian, dan neraca saldo basis kas. Selaraskan transaksi lama sekali setelah migrasi; proses aman diulang. Akrual dan rekonsiliasi bank/OTA belum otomatis.','Menu akun ada di kanan navbar: Profil & akun, Ganti password, dan Keluar. Password baru minimal 12 karakter; sesi perangkat lain dikeluarkan. Izin Melihat identitas lengkap tamu mengontrol akses nomor/dokumen identitas.']],
        ['Integrasi & batas versi awal',['Agoda, Traveloka, Booking.com, dan Expedia masih berupa konfigurasi persiapan. Belum ada panggilan API atau sinkronisasi produksi.','Kredensial yang diisi disimpan terenkripsi dan tidak dikirim kembali ke browser. Jangan mengisi rahasia produksi sebelum konektor siap.','Daftar transaksi memuat maksimum 250 catatan terbaru. Refund pembayaran kamar tersedia pada Detail reservasi. Payroll, refund restoran, pajak akomodasi, tutup kas per shift, dan langganan SaaS belum tersedia.','Jalankan backup database dan simpan APP_KEY secara terpisah sebelum memakai data nyata. Jangan memakai akun demo untuk produksi.']],
    ];
    return heading('Panduan penggunaan','Petunjuk singkat sesuai alur kerja hotel.') + `<div class="guide-grid">${sections.map(([title,steps]) => `<section class="panel"><h2>${h(title)}</h2><ol>${steps.map(s => `<li>${h(s)}</li>`).join('')}</ol></section>`).join('')}</div>`;
}

function openModal(title, content) { window.SearchSelect.close(); $('#modal-title').textContent = title; $('#modal-body').innerHTML = content; window.SearchSelect.enhance($('#modal-body')); if (!$('#modal').open) $('#modal').showModal(); }
function closeModal() { window.SearchSelect.close(); $('#modal').close(); }
function field(name,label,type='text',value='',options={}) {
    const id = `field-${name}`;
    const attrs = `${options.required === false ? '' : 'required'} ${options.min !== undefined ? `min="${h(options.min)}"` : ''} ${options.max !== undefined ? `max="${h(options.max)}"` : ''} ${options.minlength ? `minlength="${options.minlength}"` : ''}`;
    let control;
    if (type === 'select') control = `<select id="${id}" name="${name}" ${attrs}><option value="">Pilih ${h(label.toLowerCase())}</option>${(options.choices || []).map(c => `<option value="${h(c.value)}" ${String(c.value) === String(value) ? 'selected' : ''}>${h(c.label)}</option>`).join('')}</select>`;
    else if (type === 'textarea') control = `<textarea id="${id}" name="${name}" ${attrs}>${h(value)}</textarea>`;
    else control = `<input id="${id}" name="${name}" type="${type}" value="${h(value)}" ${attrs} ${type === 'password' ? `autocomplete="${name === 'current_password' ? 'current-password' : 'new-password'}"` : ''}>`;
    return `<div class="field ${options.wide ? 'wide' : ''}"><label for="${id}">${h(label)}${options.required === false ? ' (opsional)' : ''}</label>${control}${options.hint ? `<small>${h(options.hint)}</small>` : ''}</div>`;
}
const choice = list => list.map(x => ({value:x,label:x}));
const roomChoices = () => (state.rooms || []).map(r => ({value:r.id,label:`${r.number} · ${r.type}${r.out_of_service ? ' · dalam perbaikan' : ''}`}));
const employeeChoices = () => (state.employees || []).map(e => ({value:e.id,label:e.name}));
const roleChoices = () => (state.roles || []).map(r => ({value:r.id,label:r.name}));
function permissionFields(selected = []) {
    return `<fieldset class="field wide"><legend>Izin tindakan</legend><div class="check-grid">${state.permission_options.map(p => `<label><input type="checkbox" name="permissions" value="${p}" ${selected.includes(p) ? 'checked' : ''}>${h(permissionLabels[p] || p)}</label>`).join('')}</div></fieldset>`;
}
function formModal(title, fields, submit, options = {}) {
    openModal(title, `${options.info ? `<div class="info">${h(options.info)}</div>` : ''}<form id="operation-form"><div id="form-error" class="error" role="alert" hidden></div><div class="form-grid">${fields}</div><div class="form-actions">${btn('Batal','close')}<button type="submit" class="primary">${h(options.submitLabel || 'Simpan')}</button></div></form>`);
    $('#operation-form').onsubmit = async event => {
        event.preventDefault(); const form = event.currentTarget; const submitButton = $('button[type=submit]', form); submitButton.disabled = true; submitButton.textContent = 'Menyimpan…'; $('#form-error').hidden = true;
        const values = Object.fromEntries(new FormData(form));
        if (form.querySelector('[name=permissions]')) values.permissions = new FormData(form).getAll('permissions');
        try { const result = await submit(values); await load(true); closeModal(); render(); toast(result.message || 'Data tersimpan.'); }
        catch (error) { $('#form-error').textContent = error.message; $('#form-error').hidden = false; $('#form-error').scrollIntoView({block:'nearest'}); }
        finally { submitButton.disabled = false; submitButton.textContent = options.submitLabel || 'Simpan'; }
    };
}
function createForm(module, preset = {}) {
    if (module === 'food_orders') { closeModal(); location.hash='pos'; return; }
    let fields = ''; let title = '';
    switch (module) {
        case 'reservations':
            title = 'Reservasi baru';
            fields = field('guest','Nama tamu','text','',{wide:true}) + field('phone','Nomor telepon','tel','',{required:false}) + field('room_id','Kamar','select',preset.room_id || '',{choices:roomChoices()})
                + field('arrival','Tanggal check-in','date',state.today,{min:state.today}) + field('departure','Tanggal check-out','date',addDays(state.today,1),{min:addDays(state.today,1)})
                + field('adults','Jumlah tamu','number',1,{min:1,max:20}) + field('source','Sumber booking','select','Langsung',{choices:choice(['Langsung','Telepon','Website','Agoda','Traveloka','Booking.com','Lainnya'])})
                + field('breakfast_guests','Jatah breakfast per hari','number',0,{min:0,max:20,hint:'0 untuk paket tanpa breakfast; maksimum sesuai jumlah tamu.'})
                + field('notes','Catatan','textarea','',{required:false,wide:true}); break;
        case 'rooms': title = preset.id ? `Ubah kamar ${preset.number}` : 'Tambah kamar'; fields = field('number','Nomor kamar','text',preset.number || '') + field('type','Tipe kamar','text',preset.type || '') + field('floor','Lantai','number',preset.floor ?? 1,{min:0,max:200}) + field('capacity','Kapasitas tamu','number',preset.capacity || 2,{min:1,max:20}) + field('rate','Tarif per malam (Rp)','number',preset.rate ?? '',{min:0,max:100000000,wide:true}) + field('facilities','Fasilitas','text',preset.facilities?.join(', ') || '',{required:false,wide:true,hint:'Pisahkan dengan koma, misalnya: AC, Wi-Fi, shower'}); break;
        case 'food_items': title = 'Tambah menu / layanan'; fields = field('name','Nama menu / layanan','text','',{wide:true}) + field('category','Kategori') + field('price','Harga (Rp)','number','',{min:0})+field('outlet','Outlet','select',Restaurant.outlet(),{wide:true,choices:[{value:'restaurant',label:'Restoran / kafe'},{value:'spa',label:'Spa'},{value:'facility',label:'Fasilitas / layanan lain'}]}); break;
        case 'food_orders': title = 'Pesanan room service'; fields = field('reservation_id','Tamu yang menginap','select','',{choices:(state.reservations || state.food_reservations || []).filter(r => r.status === 'checked_in').map(r => ({value:r.id,label:`${roomName(r.room_id)} · ${r.guest || r.code}`})),wide:true}) + field('food_item_id','Menu','select','',{choices:(state.food_items || []).filter(i => i.available).map(i => ({value:i.id,label:`${i.name} · ${money(i.price)}`}))}) + field('quantity','Jumlah','number',1,{min:1,max:100}) + field('notes','Catatan dapur','textarea','',{required:false,wide:true}); break;
        case 'employees': title = 'Tambah karyawan'; fields = field('name','Nama karyawan','text','',{wide:true}) + field('department','Departemen') + field('position','Jabatan') + field('phone','Telepon','tel','',{required:false,wide:true}); break;
        case 'shifts': title = 'Jadwal shift baru'; fields = field('employee_id','Karyawan','select','',{choices:employeeChoices(),wide:true}) + field('starts_at','Mulai','datetime-local',`${state.today}T07:00`) + field('ends_at','Selesai','datetime-local',`${state.today}T15:00`) + field('notes','Catatan','textarea','',{required:false,wide:true}); break;
        case 'expenses': title = 'Catat pengeluaran'; fields = field('description','Keperluan','text','',{wide:true}) + field('category','Kategori') + field('amount','Nominal (Rp)','number','',{min:1}) + field('date','Tanggal','date',state.today); break;
        case 'inventory': title = 'Tambah persediaan'; fields = field('name','Nama barang') + field('unit','Satuan') + field('quantity','Jumlah saat ini','number',0,{min:0}) + field('minimum','Minimum persediaan','number',0,{min:0}); break;
        case 'maintenance': title = 'Laporan pemeliharaan'; fields = field('room_id','Kamar','select','',{choices:roomChoices()}) + field('assigned_to','Petugas','text','',{required:false}) + field('description','Kerusakan / pekerjaan','textarea','',{wide:true}); break;
        case 'roles': title = 'Buat peran'; fields = field('name','Nama peran','text','',{wide:true}) + permissionFields(); break;
        case 'members': title = 'Tambah pengguna'; fields = field('name','Nama pengguna') + field('email','Email','email') + field('password','Kata sandi awal','password','',{minlength:12,hint:'Minimal 12 karakter.'}) + field('role_id','Peran','select','',{choices:roleChoices()}); break;
        default: return;
    }
    formModal(title,fields,values => request(preset.id ? `/api/room_details/${preset.id}` : `/api/${module}`,preset.id ? 'PUT' : 'POST',values),{info:module === 'reservations' ? 'Total dihitung di server dari tarif kamar × jumlah malam. Memilih sumber OTA di sini hanya mencatat booking manual.' : module === 'food_orders' ? 'Biaya pesanan langsung masuk ke tagihan kamar.' : undefined});
}

function roomDetail(id) {
    const room = state.rooms.find(r => r.id === Number(id)); if (!room) return;
    const r = room.reservation;
    const details = [['Tipe',room.type],['Kapasitas',`${room.capacity} tamu`],['Kebersihan',labels[room.housekeeping]],['Fasilitas',room.facilities.join(', ')],['Petugas',room.assigned_to || 'Belum ditugaskan'],...(room.rate !== undefined ? [['Tarif per malam',money(room.rate)]] : [])];
    openModal(`Kamar ${room.number}`,`${badge(room.board_status)}${room.occupancy === 'reserved' && room.board_status !== 'reserved' ? ` ${badge('reserved')}` : ''}<dl class="detail-grid">${details.map(([label,value]) => `<div><dt>${h(label)}</dt><dd>${h(value)}</dd></div>`).join('')}</dl>${r ? `<div class="info"><strong>${h(r.guest)}</strong><br>${dateLabel(r.arrival)} s.d. ${dateLabel(r.departure)} · ${h(r.code)}</div>` : ''}<div class="actions">${can('housekeeping.manage') && room.occupancy !== 'occupied' ? btn('Atur kebersihan','clean',room.id) : ''}${can('rooms.manage') ? btn('Ubah kamar','edit-room',room.id) : ''}${can('reservations.manage') && !room.out_of_service ? btn('+ Reservasi','new-room-booking',room.id) : ''}${r ? btn('Detail reservasi','booking',r.id) : ''}</div>`);
}
function bookingDetail(id) {
    const r = state.reservations?.find(r => r.id === Number(id)) || state.rooms?.find(room => room.reservation?.id === Number(id))?.reservation; if (!r) return;
    openModal(`Reservasi ${r.code}`,`${badge(r.status)}<dl class="detail-grid">${[['Tamu',r.guest],['Kamar',roomName(r.room_id)],['Check-in',dateLabel(r.arrival)],['Check-out',dateLabel(r.departure)],['Sumber',r.source],['Telepon',r.phone || 'Belum diisi'],...(r.total !== undefined ? [['Total tagihan',money(r.total)],['Catatan',r.notes || 'Tidak ada']] : [['Catatan',r.notes || 'Tidak ada']])].map(([label,value]) => `<div><dt>${h(label)}</dt><dd>${h(value)}</dd></div>`).join('')}</dl><div class="actions">${can('reservations.cancel') && r.status === 'confirmed' ? btn('Batalkan reservasi','transition',r.id,'cancel','danger') : ''}${can('payments.manage') && r.total !== undefined && ['confirmed','checked_in'].includes(r.status) ? btn('Catat pembayaran','pay',r.id) : ''}</div>`);
}

async function action(event) {
    const el = event.target.closest('[data-action]'); if (!el) return;
    const {action:a,id,value} = el.dataset;
    try {
        if (a === 'close') closeModal();
        else if (a === 'refresh') { el.disabled = true; await load(); }
        else if (a === 'create') createForm(id);
        else if (a === 'room') roomDetail(id);
        else if (a === 'edit-room') createForm('rooms',state.rooms.find(r => r.id === Number(id)));
        else if (a === 'edit-employee') {
            const e = state.employees.find(x => x.id === Number(id));
            formModal('Ubah karyawan',field('name','Nama','text',e.name) + field('department','Departemen','text',e.department) + field('position','Jabatan','text',e.position) + field('phone','Telepon','tel',e.phone || '',{required:false}) + field('active','Status','select',Number(e.active),{choices:[{value:1,label:'Aktif'},{value:0,label:'Nonaktif'}]}),v => request(`/api/employee_details/${id}`,'PUT',v));
        } else if (a === 'edit-menu') {
            const i = state.food_items.find(x => x.id === Number(id));
            formModal('Ubah menu',field('name','Nama menu','text',i.name) + field('category','Kategori','text',i.category) + field('price','Harga (Rp)','number',i.price,{min:0}) + field('available','Ketersediaan','select',Number(i.available),{choices:[{value:1,label:'Tersedia'},{value:0,label:'Habis'}]}),v => request(`/api/menu_details/${id}`,'PUT',v));
        }
        else if (a === 'new-room-booking') createForm('reservations',{room_id:id});
        else if (a === 'booking') Workspace.booking(id);
        else if (a === 'calendar') { calendarOffset = Number(value) === 0 ? 0 : calendarOffset + Number(value); render(); }
        else if (a === 'print') window.print();
        else if (a === 'transition') {
            if(value==='checkin'){await Workspace.guest(id,true);return;}
            const text = {checkin:'Konfirmasi check-in',checkout:'Konfirmasi check-out',cancel:'Batalkan reservasi'};
            formModal(text[value],`<p class="field wide">${h(value === 'checkout' ? 'Tagihan harus lunas. Setelah check-out, kamar masuk antrean kebersihan.' : value === 'cancel' ? 'Reservasi akan dibatalkan dan ketersediaan kamar dilepas. Reservasi dengan pembayaran memerlukan proses refund terpisah.' : 'Kamar harus bersih dan reservasi harus mencakup hari ini.')}</p>`,() => request(`/api/reservations/${id}`,'PUT',{action:value}),{submitLabel:text[value]});
        } else if (a === 'pay') {
            const r = state.reservations?.find(r => r.id === Number(id)); if (!r) { toast('Buka daftar Reservasi untuk mencatat pembayaran.',true); return; }
            const remaining = r.total - (r.paid || 0); const key=crypto.randomUUID();
            if(remaining<=0){toast('Tagihan sudah lunas.');return;}
            formModal(`Pembayaran ${r.code}`,field('amount','Nominal (Rp)','number',remaining,{min:1,max:remaining}) + field('method','Metode','select','Tunai',{choices:choice(['Tunai','Transfer','Kartu','OTA'])}),v => request(`/api/payments/${id}`,'PUT',{...v,request_key:key}),{info:`Sisa tagihan ${money(remaining)}. Catatan ini tidak menjalankan payment gateway.`});
        } else if (a === 'clean') {
            const r = state.rooms.find(r => r.id === Number(id));
            formModal(`Kebersihan kamar ${r.number}`,field('housekeeping','Status kebersihan','select',r.housekeeping,{choices:['dirty','cleaning','inspection','clean'].map(s => ({value:s,label:labels[s]}))}) + field('assigned_to','Petugas','text',r.assigned_to || '',{required:false}),v => request(`/api/rooms/${id}`,'PUT',v));
        } else if (a === 'clock') {
            formModal(value === 'clockout' ? 'Absen pulang' : 'Absen masuk',field('employee_id','Karyawan','select','',{choices:employeeChoices(),wide:true}),v => request('/api/attendances','POST',{...v,action:value}));
        } else if (a === 'stock') {
            const i = state.inventory.find(x => x.id === Number(id)); formModal(`Stok ${i.name}`,field('quantity','Jumlah terbaru','number',i.quantity,{min:0,wide:true}),v => request(`/api/inventory/${id}`,'PUT',v));
        } else if (a === 'role') {
            const r = state.roles.find(x => x.id === Number(id)); formModal(`Izin ${r.name}`,permissionFields(r.permissions),v => request(`/api/roles/${id}`,'PUT',v));
        } else if (a === 'member') {
            const m = state.members.find(x => x.id === Number(id)); formModal(`Peran ${m.name}`,field('role_id','Peran','select',m.role_id,{choices:roleChoices(),wide:true}),v => request(`/api/members/${id}`,'PUT',v));
        } else if (a === 'hotel') {
            formModal('Pengaturan hotel',field('name','Nama hotel','text',state.hotel.name,{wide:true}) + field('address','Alamat','textarea',state.hotel.address || '',{wide:true,required:false}) + field('timezone','Zona waktu','select',state.hotel.timezone,{choices:choice(['Asia/Jakarta','Asia/Makassar','Asia/Jayapura']),wide:true}),v => request(`/api/hotel/${state.hotel.id}`,'PUT',v));
        } else if (a === 'channel') {
            const c = state.channels.find(x => x.id === Number(id));
            formModal(`Konfigurasi ${c.provider}`,field('property_id','Property ID','text',c.property_id || '') + field('connection_mode','Jalur koneksi','select',c.connection_mode,{choices:[{value:'channel_manager',label:'Melalui channel manager'},{value:'direct',label:'Langsung ke OTA'}]}) + field('partner_name','Nama channel manager / mitra','text',c.partner_name || '',{wide:true,required:false}) + field('credentials','Kredensial API','password','',{wide:true,required:false,hint:'Kosongkan untuk mempertahankan kredensial tersimpan.'}) + field('mapping','Pemetaan kamar dan paket harga (JSON)','textarea',JSON.stringify(c.mapping || [],null,2),{wide:true,required:false}),v => request(`/api/channels/${id}`,'PUT',v),{info:'Persiapan konfigurasi saja. Menyimpan belum mengaktifkan sinkronisasi. Pemetaan harus sesuai kontrak API konektor yang akan dikembangkan.'});
        } else if (a === 'update') {
            const [module,status] = value.split(':');
            formModal('Konfirmasi perubahan',`<p class="field wide">Ubah status menjadi <strong>${h(labels[status])}</strong>?</p>`,() => request(`/api/${module}/${id}`,'PUT',{status}),{submitLabel:'Konfirmasi'});
        }
    } catch (error) { toast(error.message,true); }
    finally { if (el.isConnected) el.disabled = false; }
}

document.addEventListener('click',action);
$('#close-modal').addEventListener('click',closeModal);
const mobileViewport = window.matchMedia('(max-width: 720px)');
function setSidebar(open, moveFocus = false) {
    const mobile = mobileViewport.matches;
    const expanded = open && mobile;
    if (expanded) $('#account-menu').open = false;
    $('#sidebar').classList.toggle('is-open', expanded);
    $('#sidebar').inert = mobile && !expanded;
    $('.workspace').inert = expanded;
    $('.skip-link').inert = expanded;
    $('#menu-scrim').hidden = !expanded;
    document.body.classList.toggle('menu-open', expanded);
    $('#menu-toggle').setAttribute('aria-expanded', String(expanded));
    if (moveFocus && mobile) (expanded ? $('#sidebar-close') : $('#menu-toggle')).focus();
}
$('#menu-toggle').addEventListener('click',() => setSidebar(!$('#sidebar').classList.contains('is-open'),true));
$('#sidebar-close').addEventListener('click',() => setSidebar(false,true));
$('#menu-scrim').addEventListener('click',() => setSidebar(false,true));
$('#sidebar').addEventListener('click',event => { if (event.target.closest('a') && mobileViewport.matches) setSidebar(false,true); });
document.addEventListener('keydown',event => { if (event.key === 'Escape' && $('#sidebar').classList.contains('is-open')) setSidebar(false,true); });
mobileViewport.addEventListener('change',() => setSidebar(false));
setSidebar(false);
$('.skip-link').addEventListener('click',event => { event.preventDefault(); $('#main').focus(); $('#main').scrollIntoView({block:'start'}); });
$('#hotel-switch').addEventListener('change',async event => {
    event.target.disabled = true;
    try { await request('/api/hotel/switch','POST',{hotel_id:event.target.value}); filter = {search:'',floor:'',status:''}; window.location.reload(); }
    catch (error) { toast(error.message,true); event.target.value = state.hotel.id; event.target.disabled = false; }
});
$('#main').addEventListener('input',event => { if (event.target.id === 'room-search') { filter.search = event.target.value; renderRooms(); } });
$('#main').addEventListener('change',event => {
    if (event.target.id === 'floor-filter') { filter.floor = event.target.value; renderRooms(); }
    if (event.target.id === 'status-filter') { filter.status = event.target.value; renderRooms(); }
});
window.addEventListener('hashchange', () => {
    filter = {search:'', floor:'', status:''};
    $('#account-menu').open = false;
    if (!state) return;
    render();
    const title = $('#main h1');
    if (title) {
        title.tabIndex = -1;
        title.focus({preventScroll: true});
    }
    window.scrollTo(0, 0);
});
document.addEventListener('click',event=>{const menu=$('#account-menu');if(menu&&!menu.contains(event.target))menu.open=false;});
document.addEventListener('keydown', event => {
    const menu = $('#account-menu');
    if (event.key === 'Escape' && menu.open) {
        const restoreFocus = menu.contains(document.activeElement);
        menu.open = false;
        if (restoreFocus) menu.querySelector('summary').focus();
    }
});
$('#modal').addEventListener('close',() => window.SearchSelect.close());
setInterval(() => {
    if (state && !deferAutoRefresh()) load(true, {automatic: true});
}, 30000);
load();
