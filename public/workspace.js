'use strict';
const Workspace=(()=>{
    let floor=null,roomQuery='',roomStatus='',roomPage=0,calendarFloor=null,calendarStart='',rosterDate='',department='',attendanceStatus='',roster=null,rosterKey='',rosterLoading=false;
    let reportFrom='',reportTo='',ledger=null,ledgerKey='',ledgerLoading=false;
    let housekeepingFloor = '', housekeepingStatus = '', housekeepingPage = 0;
    let reservationQuery = '', reservationFilter = 'all';
    const housekeepingSteps = {
        dirty: {label: 'Mulai bersihkan', next: 'cleaning'},
        cleaning: {label: 'Ajukan pemeriksaan', next: 'inspection'},
        inspection: {label: 'Tandai bersih', next: 'clean'},
    };
    const reservationFilters = {
        all: 'Semua',
        arrivals: 'Kedatangan hari ini',
        departures: 'Keberangkatan hari ini',
        staying: 'Menginap',
    };
    const op=(label,action,id='',value='',cls='secondary')=>`<button type="button" class="${cls}" data-action="desk-${action}" data-id="${h(id)}" data-value="${h(value)}">${label}</button>`;
    const icon=n=>`<i class="ti ti-${n}" aria-hidden="true"></i>`;
    const floors=()=>[...new Set((state.rooms||[]).map(r=>r.floor))].sort((a,b)=>a-b);
    const balance=id=>(state.deposits||[]).filter(d=>d.reservation_id===Number(id)).reduce((n,d)=>n+(d.type==='receive'?1:-1)*Number(d.amount),0);
    const roomStatuses = ['available', 'reserved', 'occupied', 'dirty', 'cleaning', 'inspection', 'maintenance'];
    const attentionStatuses = ['dirty', 'cleaning', 'inspection', 'maintenance'];
    const boardStatuses = ['available', 'occupied', 'reserved', 'attention'];
    const statusLabel = status => status === 'attention' ? 'Perlu penanganan' : labels[status];
    function matchesStatus(room, status) {
        if (status === 'attention') return attentionStatuses.includes(room.board_status);
        if (status === 'reserved') return room.occupancy === 'reserved';
        return !status || room.board_status === status;
    }

    function card(room) {
        const status = room.board_status;
        const booking = room.reservation;
        const note = booking
            ? `${h(booking.code)} · ${status === 'occupied' ? `Check-out ${dateLabel(booking.departure)}` : `Tiba ${dateLabel(booking.arrival)}`}`
            : room.assigned_to ? `${h(room.assigned_to)} · Petugas` : room.rate !== undefined ? `${money(room.rate)} / malam` : `${h(room.capacity)} tamu`;
        return `<article class="room-card ${h(status)}">
            <button type="button" class="room-open" data-action="room" data-id="${room.id}" aria-label="Lihat kamar ${h(room.number)}, ${h(labels[status])}">
                <span class="room-head"><span class="room-number">${h(room.number)}</span><span class="room-type">${h(room.type)}</span></span>
                <span class="room-statuses">${badge(status)}${room.occupancy === 'reserved' && status !== 'reserved' ? badge('reserved') : ''}</span>
                <span class="room-detail">${booking ? `<strong>${h(booking.guest)}</strong>` : ''}<small>${note}</small>${icon('chevron-right')}</span>
            </button>
        </article>`;
    }

    function board() {
        const fs = floors(), rooms = state.rooms || [];
        if (floor === null || (!fs.includes(Number(floor)) && floor !== 'all')) floor = String(fs[0] ?? 'all');
        const intro = heading('Papan kamar', `${dateLabel(state.today)} · ${rooms.length} kamar`, 
            `${can('reservations.manage') ? btn('Reservasi baru', 'create', 'reservations', '', 'primary') : ''}${can('rooms.manage') ? `<button type="button" class="secondary board-add-room" data-action="create" data-id="rooms" aria-label="Tambah kamar">${icon('plus')} <span>Tambah </span>Kamar</button>` : ''}<button type="button" class="secondary board-refresh" data-action="refresh" aria-label="Perbarui data kamar" title="Perbarui data kamar">${icon('refresh')} <span>Perbarui</span></button>`);
        if (!rooms.length) return intro + `<section class="panel"><h2>Belum ada kamar</h2><p>${can('rooms.manage')
            ? 'Tambahkan kamar beserta tarif, kapasitas, dan fasilitas untuk menerima reservasi.'
            : 'Hubungi pengelola hotel untuk menambahkan data kamar.'}</p><div class="actions">
            ${can('rooms.manage') ? btn('Tambahkan kamar pertama', 'create', 'rooms', '', 'primary') : ''}
            ${can('hotel.manage') ? '<a class="button-link secondary" href="#settings">Lengkapi identitas hotel</a>' : ''}
            ${can('users.manage') ? '<a class="button-link secondary" href="#access">Siapkan akun staf</a>' : ''}
            <a class="button-link secondary" href="#guide">Panduan penggunaan</a></div></section>`;

        return intro + `<div class="board-summary" role="group" aria-label="Ringkasan status seluruh hotel">
            ${boardStatuses.map(status => `<button class="board-stat ${status}" type="button"
                data-action="desk-quick-status" data-value="${status}" aria-pressed="${roomStatus === status && floor === 'all'}">
                <span class="dot" aria-hidden="true"></span><strong>${rooms.filter(room => matchesStatus(room, status)).length}</strong><span>${h(statusLabel(status))}</span></button>`).join('')}
        </div><section class="room-stage" aria-labelledby="room-results-title">
            <div class="board-tools" role="group" aria-label="Cari dan filter kamar">
                <div><label for="desk-room-search">Cari kamar atau tamu</label><div class="search-field">${icon('search')}
                    <input id="desk-room-search" type="search" value="${h(roomQuery)}" placeholder="Nomor, tipe, atau nama tamu"></div></div>
                <div><label for="desk-room-floor">Lantai</label><select id="desk-room-floor">
                    <option value="all" ${floor === 'all' ? 'selected' : ''}>Semua lantai</option>
                    ${fs.map(f => `<option value="${f}" ${String(f) === floor ? 'selected' : ''}>Lantai ${f}</option>`).join('')}</select></div>
                <div><label for="desk-room-status">Status kamar</label><select id="desk-room-status"><option value="">Semua status</option>
                    ${[...roomStatuses, 'attention'].map(s => `<option value="${s}" ${roomStatus === s ? 'selected' : ''}>${h(statusLabel(s))}</option>`).join('')}</select></div>
            </div>
            <div class="board-results"><div class="stage-heading"><h2 id="room-results-title" tabindex="-1"></h2>
                <span id="room-results-count" role="status" aria-atomic="true"></span></div><div id="desk-room-results"></div></div>
        </section>`;
    }

    function drawRooms() {
        const root = $('#desk-room-results');
        if (!root) return;
        const query = roomQuery.trim().toLowerCase();
        const rows = (state.rooms || []).filter(room =>
            (floor === 'all' || String(room.floor) === floor) && matchesStatus(room, roomStatus)
            && `${room.number} ${room.type} ${room.reservation?.guest || ''}`.toLowerCase().includes(query));
        const pageSize = 24;
        const pages = Math.max(1, Math.ceil(rows.length / pageSize));
        roomPage = Math.min(roomPage, pages - 1);
        const scope = floor === 'all' ? 'Semua lantai' : `Lantai ${floor}`;
        $('#room-results-title').textContent = scope;
        $('#room-results-count').textContent = `${rows.length} kamar · ${statusLabel(roomStatus) || 'Semua status'}`;
        document.querySelectorAll('[data-action="desk-quick-status"]').forEach(button =>
            button.setAttribute('aria-pressed', String(button.dataset.value === roomStatus && floor === 'all')));
        root.innerHTML = rows.length
            ? `<div class="room-grid board-grid">${rows.slice(roomPage * pageSize, (roomPage + 1) * pageSize).map(card).join('')}</div>`
            : `<div class="empty-state"><h3>Tidak ada kamar yang sesuai</h3><p>Ubah pencarian, lantai, atau status kamar.</p>${op('Hapus filter', 'reset-room-filters')}</div>`;
        if (pages > 1) {
            root.insertAdjacentHTML('beforeend', `<div class="pagination-controls">
                <button type="button" class="secondary" data-action="desk-room-page" data-value="-1" ${roomPage === 0 ? 'disabled' : ''}>Sebelumnya</button>
                <span>Halaman ${roomPage + 1} dari ${pages}</span>
                <button type="button" class="secondary" data-action="desk-room-page" data-value="1" ${roomPage === pages - 1 ? 'disabled' : ''}>Berikutnya</button>
            </div>`);
        }
    }

    function syncRoomControls() {
        $('#desk-room-floor').value = floor;
        $('#desk-room-status').value = roomStatus;
        $('#desk-room-search').value = roomQuery;
        window.SearchSelect.enhance($('.board-tools'));
        drawRooms();
    }
    function housekeeping() {
        const tasks = (state.rooms || []).filter(room => housekeepingSteps[room.housekeeping]
            && room.occupancy !== 'occupied' && !room.out_of_service
            && (!housekeepingFloor || String(room.floor) === housekeepingFloor)
            && (!housekeepingStatus || room.housekeeping === housekeepingStatus));
        const pageSize = 24;
        const pages = Math.max(1, Math.ceil(tasks.length / pageSize));
        housekeepingPage = Math.min(housekeepingPage, pages - 1);
        return heading('Housekeeping', 'Antrean kamar yang perlu dibersihkan atau diperiksa.', btn('Perbarui', 'refresh'))
            + `<section class="work-toolbar housekeeping-filters" aria-label="Filter antrean">
                <div><label for="desk-housekeeping-floor">Lantai</label><select id="desk-housekeeping-floor"><option value="">Semua lantai</option>
                    ${floors().map(f => `<option value="${f}" ${String(f) === housekeepingFloor ? 'selected' : ''}>Lantai ${f}</option>`).join('')}
                </select></div>
                <div><label for="desk-housekeeping-status">Status kebersihan</label><select id="desk-housekeeping-status"><option value="">Semua tugas</option>
                    ${Object.keys(housekeepingSteps).map(s => `<option value="${s}" ${s === housekeepingStatus ? 'selected' : ''}>${h(labels[s])}</option>`).join('')}
                </select></div>
            </section>
            <section aria-labelledby="housekeeping-queue-title">
                <div class="stage-heading"><h2 id="housekeeping-queue-title" tabindex="-1">Antrean kamar</h2><span>${tasks.length} kamar</span></div>
                ${tasks.length ? `<div class="housekeeping-grid">${tasks.slice(housekeepingPage * pageSize, (housekeepingPage + 1) * pageSize).map(housekeepingCard).join('')}</div>`
                    : empty('Antrean kosong', 'Ubah filter untuk melihat tugas di lantai atau status lain.')}
                ${pages > 1 ? `<div class="pagination-controls">
                    <button class="secondary" type="button" data-action="desk-housekeeping-page" data-value="-1" ${housekeepingPage === 0 ? 'disabled' : ''}>Sebelumnya</button>
                    <span>Halaman ${housekeepingPage + 1} / ${pages}</span>
                    <button class="secondary" type="button" data-action="desk-housekeeping-page" data-value="1" ${housekeepingPage === pages - 1 ? 'disabled' : ''}>Berikutnya</button>
                </div>` : ''}
            </section>`;
    }
    function housekeepingCard(room) {
        const step = housekeepingSteps[room.housekeeping];
        return `<article class="room-card housekeeping-card ${room.housekeeping}">
            <div class="room-head"><h3 class="room-number">${h(room.number)}</h3><span class="room-type">Lantai ${h(room.floor)}</span></div>
            <div class="task-status">${badge(room.housekeeping)}${room.occupancy === 'reserved' ? badge('reserved') : ''}</div>
            <p class="task-assignee">${room.assigned_to ? `Petugas · ${h(room.assigned_to)}` : 'Belum ditugaskan'}</p>
            <div class="task-actions">
                <button type="button" class="primary" data-action="desk-housekeeping-next" data-id="${room.id}" data-value="${step.next}"
                    aria-label="${step.label} kamar ${h(room.number)}">${step.label}</button>
                ${btn('Detail', 'room', room.id)}
            </div>
        </article>`;
    }
    function calendar() {
        const fs = floors();
        if (calendarFloor === null) calendarFloor = String(fs[0] ?? '');
        if (!calendarStart) calendarStart = state.today;
        const dates = Array.from({length: 7}, (_, i) => addDays(calendarStart, i));
        const rooms = (state.rooms || []).filter(room => !calendarFloor || String(room.floor) === calendarFloor);
        const bookingsByRoom = new Map();
        for (const booking of state.calendar_reservations || []) {
            const bookings = bookingsByRoom.get(booking.room_id) || [];
            bookings.push(booking);
            bookingsByRoom.set(booking.room_id, bookings);
        }
        return heading('Kalender kamar', 'Lihat ketersediaan dan masa inap per kamar.')
            + `<section class="calendar-toolbar" aria-label="Pengaturan kalender">
                <div class="calendar-range"><strong>${dateLabel(dates[0])} – ${dateLabel(dates[6])}</strong><span>${rooms.length} kamar</span></div>
                <div class="week-nav" role="group" aria-label="Ganti minggu">
                    ${op(icon('chevron-left') + ' Sebelumnya', 'week', '', -7)}${op('Hari ini', 'week', '', 0)}${op('Berikutnya ' + icon('chevron-right'), 'week', '', 7)}
                </div>
                <div><label for="desk-calendar-start">Mulai tanggal</label><input id="desk-calendar-start" type="date" value="${h(calendarStart)}" required></div>
                <div><label for="desk-calendar-floor">Lantai</label><select id="desk-calendar-floor"><option value="">Semua lantai</option>
                    ${fs.map(f => `<option value="${f}" ${String(f) === calendarFloor ? 'selected' : ''}>Lantai ${f}</option>`).join('')}</select></div>
            </section>`
            + (rooms.length ? `<div class="table-wrap schedule-wrap" tabindex="0" role="region" aria-label="Jadwal reservasi kamar">
                <table class="stay-calendar"><thead><tr><th scope="col" class="sticky-room">Kamar</th>
                    ${dates.map(date => `<th scope="col" ${date === state.today ? 'class="calendar-today" aria-current="date"' : ''}>
                        <time datetime="${date}">${dateLabel(date)}</time></th>`).join('')}</tr></thead>
                    <tbody>${rooms.map(room => calendarRow(room, dates, bookingsByRoom.get(room.id) || [])).join('')}</tbody></table></div>
                <p class="table-note">Geser tabel ke samping untuk melihat tanggal berikutnya.</p>`
                : empty('Belum ada kamar', 'Tambahkan kamar atau pilih lantai lain untuk melihat jadwal.'));
    }

    function calendarRow(room, dates, bookings) {
        const cells = [];
        for (let index = 0; index < dates.length;) {
            const date = dates[index];
            const booking = bookings.find(item => item.arrival <= date && item.departure > date);
            if (!booking) {
                cells.push(`<td class="empty-slot" aria-label="${h(room.number)} ${dateLabel(date)} ${room.out_of_service ? 'Perbaikan' : 'Tersedia'}">
                    ${room.out_of_service ? '<span class="muted">Perbaikan</span>' : '<span class="calendar-vacant" aria-hidden="true">·</span>'}</td>`);
                index++;
                continue;
            }
            let length = 1;
            while (index + length < dates.length && dates[index + length] < booking.departure) length++;
            cells.push(`<td colspan="${length}" class="booking-slot"><button type="button" class="stay-block ${h(booking.status)}" data-action="booking" data-id="${booking.id}">
                <strong>${h(booking.guest)}</strong><small>${dateLabel(booking.arrival)} – ${dateLabel(booking.departure)} · ${h(labels[booking.status])}</small></button></td>`);
            index += length;
        }
        return `<tr><th scope="row" class="sticky-room"><strong>${h(room.number)}</strong><small>${h(room.type)}</small></th>${cells.join('')}</tr>`;
    }

    function reservations() {
        return heading('Reservasi', 'Pantau kedatangan, keberangkatan, dan tamu yang menginap.',
            can('reservations.manage') ? btn('Reservasi baru', 'create', 'reservations', '', 'primary') : '')
            + `<section class="work-toolbar reservation-toolbar" aria-label="Cari dan filter reservasi">
                <div class="work-search"><label for="desk-reservation-search">Cari reservasi</label>
                    <input type="search" id="desk-reservation-search" value="${h(reservationQuery)}" placeholder="Nama tamu, kode, atau nomor kamar">
                </div>
                <div class="quick-filters" role="group" aria-label="Filter cepat reservasi">
                    ${Object.entries(reservationFilters).map(([value, label]) => `<button type="button" class="secondary"
                        data-action="desk-reservation-filter" data-value="${value}" aria-pressed="${reservationFilter === value}">${label}</button>`).join('')}
                </div>
            </section>
            <p class="table-note result-count" id="desk-reservation-count" role="status"></p>
            <div id="desk-reservation-results"></div>`;
    }
    function drawReservations() {
        const source = state.reservations || [];
        const query = reservationQuery.trim().toLowerCase();
        const rows = source.filter(r => {
            if (query && ![r.guest, r.code, roomName(r.room_id)].some(value => String(value ?? '').toLowerCase().includes(query))) return false;
            if (reservationFilter === 'arrivals') return r.arrival === state.today && ['confirmed', 'checked_in'].includes(r.status);
            if (reservationFilter === 'departures') return r.departure === state.today && ['checked_in', 'checked_out'].includes(r.status);
            return reservationFilter !== 'staying' || r.status === 'checked_in';
        });
        const limited = (state.counts?.reservations ?? 0) > source.length;
        $('#desk-reservation-count').textContent = `${rows.length} reservasi${limited ? ' · Pencarian mencakup 250 reservasi terbaru dan semua reservasi operasional hari ini.' : ''}`;
        $('#desk-reservation-results').innerHTML = rows.length
            ? table(['Reservasi / tamu', 'Kamar', 'Masa menginap', 'Status', 'Tagihan / deposit', 'Tindakan'], rows.map(r => [
                `<strong>${h(r.code)}</strong><small>${h(r.guest)}</small><small>${h(r.source)}${r.has_identity ? ' · Identitas tercatat' : ''}</small>`,
                h(roomName(r.room_id)),
                `${dateLabel(r.arrival)}<small>s.d. ${dateLabel(r.departure)}</small>`,
                badge(r.status),
                r.status === 'cancelled' ? 'Dibatalkan' : r.total !== undefined
                    ? `${money(r.total)}<small>Sisa ${money(r.total - (r.paid || 0))}</small><small>Deposit ditahan ${money(balance(r.id))}</small>`
                    : 'Akses nominal dibatasi',
                reservationActions(r),
            ]), 'reservations', {showCount: false, variant: 'reservation-ledger'})
            : empty('Reservasi tidak ditemukan', 'Ubah kata pencarian atau pilih filter lain.');
    }
    function reservationActions(r) {
        let primary = '';
        if (can('reservations.manage')) {
            if (r.status === 'confirmed' && r.arrival <= state.today && r.departure > state.today) primary = op('Check-in', 'guest', r.id, 'checkin', 'primary');
            if (r.status === 'checked_in') primary = btn('Check-out', 'transition', r.id, 'checkout', 'primary');
        }
        return `<div class="work-row-actions">${primary}${btn('Detail', 'booking', r.id)}</div>`;
    }
    async function booking(id){let r=state.reservations?.find(r=>r.id===Number(id));if(!r){try{const out=await request(`/api/reservation-detail/${id}`);r=out.reservation;state.reservations=[...(state.reservations||[]),r];}catch(error){toast(error.message,true);return;}}const held=balance(id),entries=(state.deposits||[]).filter(d=>d.reservation_id===r.id);
        openModal(`Reservasi ${r.code}`,`${badge(r.status)}<dl class="detail-grid">${[['Tamu',r.guest],['Kamar',roomName(r.room_id)],['Kedatangan',dateLabel(r.arrival)],['Keberangkatan',dateLabel(r.departure)],['Identitas',r.identity_masked||'Belum dilengkapi'],...(r.total!==undefined?[['Total tagihan',money(r.total)],['Sudah dibayar',money(r.paid)],['Sisa tagihan',money(r.total-(r.paid||0))],['Deposit ditahan',money(held)]]:[])].map(([k,v])=>`<div><dt>${h(k)}</dt><dd>${h(v)}</dd></div>`).join('')}</dl><div class="detail-actions">${can('reservations.manage')?op(r.has_identity?'Perbarui data tamu':'Lengkapi data tamu','guest',id):''}${can('guests.identity')&&r.has_identity?op('Lihat identitas lengkap','identity',id):''}${can('finance.view') && r.status !== 'cancelled' ? `<a class="button-link secondary" href="/reservations/${r.id}/invoice">${icon('receipt')} Tagihan</a>` : ''}${can('payments.manage')?op('Kelola deposit','deposit',id):''}${can('payments.manage')&&r.total>(r.paid||0)&&['confirmed','checked_in'].includes(r.status)?btn('Bayar tagihan','pay',id):''}${can('reservations.manage')&&['confirmed','checked_in'].includes(r.status)?op('Pindah / perpanjang','stay',id):''}${can('reservations.cancel')&&r.status==='confirmed'?btn('Batalkan reservasi','transition',id,'cancel','danger'):''}</div>${can('payments.manage')||can('finance.view')?`<h3 class="section-gap">Riwayat deposit</h3>${entries.length?table(['Waktu','Transaksi','Nominal'],entries.map(d=>[dateLabel(d.created_at),h({receive:'Diterima',refund:'Dikembalikan',apply:'Untuk tagihan'}[d.type]),money(d.amount)])):empty('Belum ada deposit','Uang jaminan dicatat terpisah dari pendapatan.')}`:''}`);
        if(can('payments.manage')&&r.paid>0)$('#modal-body .detail-actions').insertAdjacentHTML('beforeend',op('Refund pembayaran','refund',id));
    }
    async function guest(id,checkin=false){const r=state.reservations?.find(r=>r.id===Number(id));if(!r)return;let p={};if(r.has_identity&&can('guests.identity'))p=await request(`/api/guests/${id}`);
        formModal(`${checkin?'Check-in & identitas':'Data tamu'} · ${r.code}`,field('guest','Nama lengkap sesuai identitas','text',p.guest||r.guest,{wide:true})+field('phone','Telepon','tel',p.phone||r.phone||'',{required:false})+field('email','Email','email',p.email||'',{required:false})+field('identity_type','Jenis identitas','select',p.identity_type||'KTP',{choices:choice(['KTP','Paspor','SIM','Lainnya'])})+field('identity_number','Nomor identitas','text',p.identity_number||'',{hint:'KTP 16 angka; nomor disimpan terenkripsi.'})+field('birth_date','Tanggal lahir','date',p.birth_date||'',{required:false,max:state.today})+field('nationality','Kewarganegaraan','text',p.nationality||'Indonesia',{required:false})+field('address','Alamat','textarea',p.address||'',{required:false,wide:true})+`<div class="field wide"><label for="guest-document">Foto / pindai identitas (opsional)</label><input id="guest-document" type="file" accept="image/jpeg,image/png,application/pdf"><small>JPG, PNG, PDF maksimal 3 MB. Tersimpan privat dan terenkripsi.${p.has_document?' Dokumen sebelumnya tetap disimpan jika tidak diganti.':''}</small></div>`,async v=>{
            const body=new FormData();Object.entries(v).forEach(([k,value])=>body.append(k,value));body.append('checkin',checkin?'1':'0');const file=$('#guest-document').files[0];if(file)body.append('document',file);
            const res=await fetch(`/api/guests/${id}`,{method:'POST',headers:{Accept:'application/json','X-CSRF-TOKEN':$('meta[name=csrf-token]').content,'X-Hotel-ID':String(state.hotel.id)},body});if([401,419].includes(res.status)){location.href='/login';throw new Error('Sesi berakhir. Silakan masuk kembali.');}const data=await res.json();if(data.subscription_unavailable){location.href='/subscription';throw new Error(data.message);}if(!res.ok)throw new Error(data.errors?Object.values(data.errors).flat().join('\n'):data.message);return data;
        },{info:'Nomor identitas tidak ditampilkan pada daftar kamar atau pengguna housekeeping. Unggah dokumen hanya jika dibutuhkan untuk administrasi hotel.',submitLabel:checkin?'Simpan & check-in':'Simpan data tamu'});
    }
    function account(password=false){return heading(password?'Ganti kata sandi':'Pengaturan akun','Kelola akun pribadi Anda. Perubahan membutuhkan kata sandi saat ini.')+`<section class="panel account-panel"><h2>${icon(password?'key':'user-cog')} ${password?'Keamanan akun':'Profil akun'}</h2><p>${h(state.user.name)} · ${h(state.user.email)}</p>${password?op('Ubah kata sandi','password','','','primary'):op('Ubah profil','profile','','','primary')}<div class="account-links"><a href="#account">Profil</a><a href="#account-password">Ganti password</a></div></section>`;}
    function attendance(){
        if(!rosterDate)rosterDate=state.today;const key=`${state.hotel.id}/${rosterDate}/${state.updated_at}`;
        if(rosterKey.split('/').slice(0,2).join('/')!==key.split('/').slice(0,2).join('/'))roster=null;
        if(rosterKey!==key&&rosterLoading!==key){rosterLoading=key;request(`/api/workforce?date=${rosterDate}`).then(d=>{if(`${state.hotel.id}/${rosterDate}`!==key.split('/').slice(0,2).join('/'))return;roster=d;rosterKey=key;if(page==='attendance')render();}).catch(e=>toast(e.message,true)).finally(()=>{if(rosterLoading===key)rosterLoading=false;});}
        if(!roster||roster.date!==rosterDate)return heading('Absensi','Memuat daftar karyawan dan jadwal…')+'<div class="loading-panel">Menyiapkan rekap absensi…</div>';
        const rows=roster.employees.map(e=>{const rec=roster.records.find(r=>r.employee_id===e.id&&r.date===rosterDate)||roster.records.find(r=>r.employee_id===e.id&&!r.clock_out&&r.clock_in),shift=roster.shifts.filter(s=>s.employee_id===e.id).sort((a,b)=>{const target=new Date((rec?.clock_in||rosterDate+' 12:00:00').replace(' ','T'));return Math.abs(new Date(a.starts_at.replace(' ','T'))-target)-Math.abs(new Date(b.starts_at.replace(' ','T'))-target);})[0],leave=roster.leaves.find(l=>l.employee_id===e.id&&l.status==='approved');let status=rec?(rec.status!=='present'?rec.status:rec.clock_out?'closed':'working'):leave?leave.type:shift?'scheduled':'off';let late=rec?.clock_in&&shift?Math.max(0,Math.floor((new Date(rec.clock_in.replace(' ','T'))-new Date(shift.starts_at.replace(' ','T')))/60000)):0;if(late&&rec.status==='present')status=rec.clock_out?'closed':'late';if(!rec&&!leave&&shift&&shift.starts_at<roster.now)status='missing';return {e,rec,shift,status,late};});
        const names={working:'Sedang bekerja',late:'Terlambat',closed:'Selesai',scheduled:'Terjadwal',off:'Tanpa shift',missing:'Belum hadir',sick:'Sakit',sakit:'Sakit',leave:'Izin / cuti',cuti:'Cuti',izin:'Izin',absent:'Alpa'};
        const filtered=rows.filter(x=>(!department||x.e.department===department)&&(!attendanceStatus||x.status===attendanceStatus));
        return heading('Absensi & kehadiran','Pantau roster per hari, keterlambatan, jam kerja, dan pengajuan izin.',can('employees.manage')?op('Ajukan izin / cuti','leave'):'')+summary([['Karyawan pada roster',rows.length,'Termasuk absensi yang masih terbuka'],['Sedang bekerja',rows.filter(x=>['working','late'].includes(x.status)).length,'Belum absen pulang'],['Belum hadir',rows.filter(x=>x.status==='missing').length,'Sudah memasuki jam shift'],['Izin / sakit / cuti',rows.filter(x=>['sick','sakit','leave','cuti','izin'].includes(x.status)).length,'Catatan dan persetujuan']])
        +`<div class="roster-filters"><div><label for="desk-roster-date">Tanggal kerja</label><input id="desk-roster-date" type="date" value="${rosterDate}"></div><div><label for="desk-department">Departemen</label><select id="desk-department"><option value="">Semua departemen</option>${[...new Set(rows.map(x=>x.e.department))].map(d=>`<option ${d===department?'selected':''}>${h(d)}</option>`).join('')}</select></div><div><label for="desk-attendance-status">Kehadiran</label><select id="desk-attendance-status"><option value="">Semua status</option>${Object.entries(names).map(([k,v])=>`<option value="${k}" ${k===attendanceStatus?'selected':''}>${h(v)}</option>`).join('')}</select></div></div>`
        +table(['Karyawan','Shift','Kehadiran','Masuk / pulang','Durasi','Tindakan'],filtered.map(x=>{const minutes=x.rec?.clock_in&&x.rec?.clock_out?Math.max(0,Math.floor((new Date(x.rec.clock_out.replace(' ','T'))-new Date(x.rec.clock_in.replace(' ','T')))/60000)):null;return [`<strong>${h(x.e.name)}</strong><small>${h(x.e.department)} · ${h(x.e.position)}</small>`,x.shift?`${x.shift.starts_at.slice(11,16)} – ${x.shift.ends_at.slice(11,16)}<small>${dateLabel(x.shift.starts_at.slice(0,10))}</small>`:'Belum dijadwalkan',`<span class="roster-status status-${x.status}">${h(names[x.status])}</span>${x.late?`<small>Terlambat ${x.late} menit</small>`:''}`,`${x.rec?.clock_in?timeLabel(x.rec.clock_in):'Belum masuk'}${x.rec?`<small>${h({self:'Mandiri',fingerprint:'Mesin',correction:'Koreksi',manual:'Petugas'}[x.rec.source]||'Petugas')}</small>`:''}<small>${x.rec?.clock_out?timeLabel(x.rec.clock_out):x.rec?.clock_in?'Belum pulang':h(x.rec?.notes||'')}</small>`,minutes===null?'Belum selesai':`${Math.floor(minutes/60)}j ${minutes%60}m`,can('attendance.manage')?`<div class="actions">${rosterDate===state.today&&!x.rec?op('Masuk','clock',x.e.id,'clockin','primary'):rosterDate===state.today&&x.rec?.clock_in&&!x.rec.clock_out?op('Pulang','clock',x.e.id,'clockout','primary'):''}${rosterDate<=state.today?op('Koreksi','correction',x.e.id):''}</div>`:'Lihat saja'];}))
        +'<h2 class="section-gap">Pengajuan izin & cuti</h2>'+table(['Karyawan','Jenis','Tanggal','Alasan','Status','Tindakan'],(state.leaves||[]).map(l=>[h(employeeName(l.employee_id)),h(l.type),`${dateLabel(l.starts_on)} – ${dateLabel(l.ends_on)}`,h(l.reason),badge(l.status),l.status==='pending'&&can('employees.manage')?`<div class="actions">${op('Setujui','leave-approve',l.id,'approved')}${op('Tolak','leave-approve',l.id,'rejected')}</div>`:'Sudah diproses']));
    }
    function inventory(){return heading('Linen & amenities','Lacak stok bersih, linen kotor, laundry, dan pemakaian per kamar.',can('inventory.manage')?btn('Barang','create','inventory','','primary'):'')+table(['Barang','Jenis','Tersedia / bersih','Kotor','Di laundry','Minimum','Tindakan'],(state.inventory||[]).map(i=>[h(i.name),i.kind==='linen'?'Linen':'Amenities',`${i.quantity} ${h(i.unit)}`,i.dirty_quantity,i.laundry_quantity,i.minimum,can('inventory.manage')?`<div class="actions">${op('Mutasi','stock',i.id)}${op('Jenis barang','kind',i.id)}</div>`:'Lihat saja']))+'<h2 class="section-gap">Mutasi terbaru</h2>'+table(['Barang','Pergerakan','Jumlah','Kamar','Catatan'],(state.stock_movements||[]).map(m=>[h(state.inventory.find(i=>i.id===m.inventory_id)?.name),h({consume:'Dipakai',soil:'Linen kotor',send_laundry:'Dikirim laundry',receive_laundry:'Kembali bersih',restock:'Stok masuk'}[m.type]),m.quantity,m.room_id?h(roomName(m.room_id)):'Umum',h(m.notes)]));}
    function bookingEngine(){const url=`${location.origin}/booking/${state.hotel.public_slug||''}`;return heading('Booking langsung','Halaman pemesanan untuk tamu tanpa akun staf.')+`<section class="panel"><h2>${state.hotel.public_booking?'Aktif':'Belum diaktifkan'}</h2><p>Reservasi dari halaman ini langsung masuk ke PMS dengan sumber Website. Harga dan ketersediaan diperiksa kembali saat tamu memesan.</p>${op('Atur booking publik','booking-settings','','','primary')}${state.hotel.public_booking?`<div class="info"><a href="${h(url)}" target="_blank" rel="noopener">Buka halaman pemesanan hotel ${icon('external-link')}</a></div><label for="booking-widget">Tombol untuk website hotel</label><textarea id="booking-widget" readonly>${h(`<a href="${url}" target="_blank" rel="noopener">Pesan kamar</a>`)}</textarea>`:''}<p class="table-note">Pembayaran online belum diaktifkan. Hotel menindaklanjuti pembayaran secara langsung. Aktifkan hanya setelah tarif, kapasitas, fasilitas, dan kebijakan hotel selesai diatur.</p></section>`;}
    function finance(){if(!reportFrom)reportFrom=state.today;if(!reportTo)reportTo=state.today;const key=`${state.hotel.id}/${reportFrom}/${reportTo}/${state.updated_at}`;
        if(ledgerKey.split('/').slice(0,3).join('/')!==key.split('/').slice(0,3).join('/'))ledger=null;
        if(ledgerKey!==key&&ledgerLoading!==key){ledgerLoading=key;request(`/api/ledger?from=${reportFrom}&to=${reportTo}`).then(d=>{if(`${state.hotel.id}/${reportFrom}/${reportTo}`!==key.split('/').slice(0,3).join('/'))return;ledger=d;ledgerKey=key;if(page==='finance')render();}).catch(e=>toast(e.message,true)).finally(()=>{if(ledgerLoading===key)ledgerLoading=false;});}
        if(!ledger)return heading('Laporan & kas','Menyiapkan jurnal dan laporan…')+'<div class="loading-panel">Memuat buku besar…</div>';
        const accounts=ledger.accounts,byCode=code=>accounts.find(a=>a.code===code),income=accounts.filter(a=>a.type==='income').reduce((n,a)=>n+a.credit-a.debit,0),expenses=accounts.filter(a=>a.type==='expense').reduce((n,a)=>n+a.debit-a.credit,0);
        const assets=accounts.filter(a=>a.type==='asset').reduce((n,a)=>n+a.balance,0),liabilities=-accounts.filter(a=>a.type==='liability').reduce((n,a)=>n+a.balance,0),equity=-accounts.filter(a=>a.type==='equity').reduce((n,a)=>n+a.balance,0),retained=-accounts.filter(a=>['income','expense'].includes(a.type)).reduce((n,a)=>n+a.balance,0);
        const daily=[...new Set(ledger.entries.map(e=>e.date))].sort().map(date=>{const ids=ledger.entries.filter(e=>e.date===date).map(e=>e.id),cash=ledger.lines.filter(l=>ids.includes(l.journal_entry_id)&&l.account_id===byCode('1000')?.id),debit=cash.reduce((n,l)=>n+Number(l.debit),0),credit=cash.reduce((n,l)=>n+Number(l.credit),0);return [dateLabel(date),money(debit),money(credit),money(debit-credit)];});
        return heading('Laporan, jurnal & kas','Jurnal operasional basis kas dengan pemisahan deposit yang ditahan.',`${can('finance.manage')?op('Selaraskan transaksi lama','ledger-sync'):''}${can('finance.manage')?op('Jurnal penyesuaian','journal','','','primary'):''}`)+`<div class="roster-filters"><div><label for="desk-report-from">Dari tanggal</label><input id="desk-report-from" type="date" value="${reportFrom}"></div><div><label for="desk-report-to">Sampai tanggal</label><input id="desk-report-to" type="date" value="${reportTo}"></div><div>${op('Cetak laporan','print-ledger')}</div></div>`
        +summary([['Penerimaan periode',money(income),'Tidak termasuk deposit ditahan'],['Biaya periode',money(expenses),'Pengeluaran disetujui'],['Selisih pendapatan/biaya',money(income-expenses),'Basis kas'],['Deposit ditahan',money(-(byCode('2000')?.balance||0)),'Saldo s.d. akhir periode']])
        +`<div class="info">Transaksi baru dicatat otomatis ke jurnal. Gunakan Selaraskan transaksi lama untuk memasukkan transaksi sebelum pembaruan ini, tanpa duplikasi. Laporan ini basis kas; piutang, akrual, rekonsiliasi bank/OTA, dan pelaporan pajak tidak dihitung otomatis.</div><h2>Kas harian</h2>`+table(['Tanggal','Kas masuk','Kas keluar','Perubahan kas'],daily)
        +'<h2 class="section-gap">Jurnal umum</h2>'+table(['Tanggal','Referensi','Keterangan','Debit','Kredit','Tindakan'],ledger.entries.map(e=>{const ls=ledger.lines.filter(l=>l.journal_entry_id===e.id);return[dateLabel(e.date),h(e.reference),h(e.description),money(ls.reduce((n,l)=>n+Number(l.debit),0)),money(ls.reduce((n,l)=>n+Number(l.credit),0)),op('Rincian','journal-detail',e.id)];}))
        +`<div class="stage-heading"><h2>Neraca saldo s.d. ${dateLabel(reportTo)}</h2>${can('finance.manage')?op('Tambah akun','ledger-account'):''}</div>`+table(['Kode','Akun','Jenis','Saldo debit','Saldo kredit'],accounts.map(a=>[h(a.code),h(a.name),h({asset:'Aset',liability:'Liabilitas',equity:'Ekuitas',income:'Pendapatan',expense:'Biaya'}[a.type]),money(Math.max(0,a.balance)),money(Math.max(0,-a.balance))]))
        +`<section class="panel section-gap"><h2>Ringkasan posisi keuangan</h2><dl class="detail-grid"><div><dt>Aset</dt><dd>${money(assets)}</dd></div><div><dt>Liabilitas</dt><dd>${money(liabilities)}</dd></div><div><dt>Ekuitas awal / penyesuaian</dt><dd>${money(equity)}</dd></div><div><dt>Hasil berjalan kumulatif</dt><dd>${money(retained)}</dd></div></dl><p class="table-note">Kontrol keseimbangan: aset ${money(assets)} = liabilitas + ekuitas + hasil berjalan ${money(liabilities+equity+retained)}.</p></section>`;
    }
    document.addEventListener('change',e=>{if(e.target.id==='desk-report-from'){reportFrom=e.target.value;render();}if(e.target.id==='desk-report-to'){reportTo=e.target.value;render();}});
    document.addEventListener('input', event => {
        if (event.target.id !== 'desk-reservation-search') return;
        reservationQuery = event.target.value;
        drawReservations();
    });
    document.addEventListener('change', event => {
        const {id, value} = event.target;
        if (id === 'desk-housekeeping-floor') housekeepingFloor = value;
        else if (id === 'desk-housekeeping-status') housekeepingStatus = value;
        else return;
        housekeepingPage = 0;
        render();
        document.getElementById(id)?.parentElement.querySelector('[role="combobox"]')?.focus();
        window.SearchSelect.close();
    });
    document.addEventListener('input', event => {
        if (event.target.id !== 'desk-room-search') return;
        const queryWasEmpty = !roomQuery.trim();
        roomQuery = event.target.value;
        roomPage = 0;
        if (queryWasEmpty && roomQuery.trim() && floor !== 'all') {
            floor = 'all';
            syncRoomControls();
        } else drawRooms();
    });
    document.addEventListener('change', event => {
        const {id, value} = event.target;
        if (id === 'desk-room-status' || id === 'desk-room-floor') {
            if (id === 'desk-room-status') roomStatus = value;
            else floor = value;
            roomPage = 0;
            drawRooms();
            return;
        }
        switch (id) {
            case 'desk-calendar-start': calendarStart = value || state.today; break;
            case 'desk-calendar-floor': calendarFloor = value; break;
            case 'desk-roster-date': rosterDate = value; break;
            case 'desk-department': department = value; break;
            case 'desk-attendance-status': attendanceStatus = value; break;
            default: return;
        }
        render();
        const input = document.getElementById(id);
        (input?.hidden ? input.parentElement.querySelector('[role="combobox"]') : input)?.focus({preventScroll: true});
        window.SearchSelect.close();
    });
    document.addEventListener('click',async e=>{
        const el=e.target.closest('[data-action^="desk-"]');if(!el)return;const{action:a,id,value}=el.dataset;
        try{
            if (a === 'desk-quick-status') {
                roomStatus = roomStatus === value && floor === 'all' ? '' : value;
                floor = 'all';
                roomPage = 0;
                syncRoomControls();
            } else if (a === 'desk-reset-room-filters') {
                roomQuery = roomStatus = '';
                floor = 'all';
                roomPage = 0;
                syncRoomControls();
                $('#desk-room-search').focus();
            } else if (a === 'desk-room-page') {
                roomPage += Number(value);
                drawRooms();
                $('#room-results-title').focus();
            }
            else if (a === 'desk-reservation-filter') {
                reservationFilter = value;
                document.querySelectorAll('[data-action="desk-reservation-filter"]').forEach(button =>
                    button.setAttribute('aria-pressed', String(button.dataset.value === value)));
                drawReservations();
            }
            else if (a === 'desk-housekeeping-page') {
                housekeepingPage += Number(value);
                render();
                $('#housekeeping-queue-title')?.focus();
            }
            else if (a === 'desk-housekeeping-next') {
                if (el.disabled) return;
                el.disabled = true;
                try {
                    await request(`/api/rooms/${id}`, 'PUT', {housekeeping: value, advance_housekeeping: true});
                    toast(`Status kamar diperbarui menjadi ${labels[value].toLowerCase()}.`);
                } finally {
                    await load(true);
                    if (page === 'housekeeping' && !$('#modal').open) {
                        ($(`[data-action="desk-housekeeping-next"][data-id="${CSS.escape(id)}"]`) || $('#housekeeping-queue-title'))?.focus({preventScroll: true});
                    }
                }
            }
            else if (a === 'desk-week') {
                calendarStart = Number(value) === 0 ? state.today : addDays(calendarStart, Number(value));
                render();
                $(`[data-action="desk-week"][data-value="${value}"]`)?.focus({preventScroll: true});
            }
            else if(a==='desk-guest')await guest(id,value==='checkin');
            else if(a==='desk-identity'){const p=await request(`/api/guests/${id}`);openModal('Identitas tamu',`<dl class="detail-grid">${[['Nama',p.guest],['Jenis identitas',p.identity_type],['Nomor identitas',p.identity_number],['Alamat',p.address||'Belum diisi'],['Tanggal lahir',p.birth_date||'Belum diisi'],['Kewarganegaraan',p.nationality||'Belum diisi']].map(([k,v])=>`<div><dt>${h(k)}</dt><dd>${h(v)}</dd></div>`).join('')}</dl>${p.has_document?`<a class="button-link secondary" href="/guests/${id}/document">Unduh dokumen privat</a>`:''}`);}
            else if(a==='desk-deposit'){const key=crypto.randomUUID();formModal('Deposit / uang jaminan',field('type','Transaksi','select','receive',{choices:[{value:'receive',label:'Terima deposit'},{value:'apply',label:'Gunakan untuk tagihan'},{value:'refund',label:'Kembalikan deposit'}]})+field('amount','Nominal (Rp)','number','',{min:1})+field('method','Metode','select','Tunai',{choices:choice(['Tunai','Transfer','Kartu'])})+field('notes','Catatan / referensi','textarea','',{wide:true}),v=>request(`/api/deposits/${id}`,'POST',{...v,request_key:key}),{info:`Saldo ditahan ${money(balance(id))}. Penerimaan deposit bukan pendapatan; penggunaan ke tagihan membuat pembayaran reservasi. Pengembalian adalah pencatatan setelah uang dikembalikan petugas.`});}
            else if(a==='desk-refund'){const key=crypto.randomUUID();formModal('Refund pembayaran',field('amount','Nominal (Rp)','number','',{min:1})+field('method','Metode pengembalian','select','Tunai',{choices:choice(['Tunai','Transfer','Kartu'])})+field('notes','Alasan / referensi','textarea','',{wide:true}),v=>request(`/api/refunds/${id}`,'POST',{...v,request_key:key}),{info:'Ini pencatatan refund setelah pengembalian uang dilakukan petugas. Saldo pembayaran dan jurnal akan berkurang; bukan transfer otomatis.'});}
            else if(a==='desk-stay'){const r=state.reservations.find(r=>r.id===Number(id));formModal('Pindah kamar / perpanjang',field('room_id','Kamar tujuan','select',r.room_id,{choices:roomChoices()})+field('departure','Keberangkatan baru','date',r.departure,{min:r.departure})+field('notes','Alasan perubahan','textarea','',{wide:true}),v=>request(`/api/stays/${id}`,'PUT',v),{info:`Tarif reservasi dipertahankan${r.nightly_rate!==undefined?' '+money(r.nightly_rate)+' per malam':''}. Tambahan malam menambah tagihan. Kamar lama menjadi kotor jika tamu yang menginap dipindahkan.`});}
            else if(a==='desk-profile')formModal('Ubah profil',field('name','Nama','text',state.user.name)+field('email','Email','email',state.user.email)+field('current_password','Kata sandi saat ini','password','',{wide:true}),v=>request('/api/account/profile','POST',v));
            else if(a==='desk-password')formModal('Ganti kata sandi',field('current_password','Kata sandi saat ini','password','',{wide:true})+field('password','Kata sandi baru','password','',{wide:true,minlength:12,hint:'Minimal 12 karakter; berbeda dari kata sandi saat ini.'})+field('password_confirmation','Ulangi kata sandi baru','password','',{wide:true,minlength:12}),v=>request('/api/account/password','POST',v),{info:'Sesi akun ini di perangkat lain akan dikeluarkan.'});
            else if(a==='desk-clock'){el.disabled=true;await request('/api/attendances','POST',{employee_id:id,action:value});rosterKey='';await load();toast(value==='clockin'?'Absen masuk dicatat.':'Absen pulang dicatat.');}
            else if(a==='desk-correction'){const rec=roster.records.find(r=>r.employee_id===Number(id)&&r.date===rosterDate);formModal('Koreksi kehadiran',field('status','Kehadiran','select',rec?.status||'present',{choices:[{value:'present',label:'Hadir'},{value:'sick',label:'Sakit'},{value:'leave',label:'Izin / cuti'},{value:'absent',label:'Alpa'}]})+field('clock_in','Waktu masuk','datetime-local',rec?.clock_in?.replace(' ','T')||'',{required:false})+field('clock_out','Waktu pulang','datetime-local',rec?.clock_out?.replace(' ','T')||'',{required:false})+field('notes','Alasan koreksi','textarea','',{wide:true}),async v=>{const out=await request('/api/attendance-correction','POST',{...v,date:rosterDate,employee_id:id});rosterKey='';return out;},{info:`Tanggal kerja ${dateLabel(rosterDate)}. Waktu masuk wajib untuk Hadir; untuk izin/sakit/alpa waktu tidak digunakan.`});}
            else if(a==='desk-leave')formModal('Pengajuan izin / cuti',field('employee_id','Karyawan','select','',{choices:employeeChoices(),wide:true})+field('type','Jenis','select','cuti',{choices:choice(['cuti','izin','sakit'])})+field('starts_on','Mulai','date',state.today)+field('ends_on','Selesai','date',state.today)+field('reason','Alasan','textarea','',{wide:true}),v=>request('/api/leaves','POST',v));
            else if(a==='desk-leave-approve')formModal('Persetujuan izin/cuti','<p class="field wide">Konfirmasi keputusan untuk pengajuan ini?</p>',async()=>{const out=await request(`/api/leaves/${id}`,'PUT',{status:value});rosterKey='';return out;});
            else if(a==='desk-stock'){const key=crypto.randomUUID();const i=state.inventory.find(i=>i.id===Number(id));formModal(`Mutasi ${i.name}`,field('type','Pergerakan','select','consume',{choices:[{value:'consume',label:'Pemakaian / keluar'},{value:'restock',label:'Stok masuk'},...(i.kind==='linen'?[{value:'soil',label:'Bersih menjadi kotor'},{value:'send_laundry',label:'Kotor dikirim laundry'},{value:'receive_laundry',label:'Laundry kembali bersih'}]:[])]})+field('quantity','Jumlah','number',1,{min:1})+field('room_id','Kamar','select','',{required:false,choices:roomChoices()})+field('notes','Keterangan','textarea','',{wide:true}),v=>request(`/api/stock/${id}`,'POST',{...v,request_key:key}));}
            else if(a==='desk-kind'){const i=state.inventory.find(i=>i.id===Number(id));formModal('Jenis perlengkapan',field('kind','Jenis barang','select',i.kind,{choices:[{value:'linen',label:'Linen (bersih / kotor / laundry)'},{value:'amenity',label:'Amenities / bahan habis pakai'}],wide:true}),v=>request(`/api/stock/${id}/kind`,'PUT',v));}
            else if(a==='desk-booking-settings')formModal('Booking langsung',field('enabled','Status halaman publik','select',Number(state.hotel.public_booking),{wide:true,choices:[{value:1,label:'Aktif: tamu dapat memesan kamar'},{value:0,label:'Nonaktif'}]}),v=>request('/api/booking-settings','POST',v),{info:'Halaman publik menampilkan identitas hotel, fasilitas, harga, dan ketersediaan. Detail tamu dan daftar reservasi tidak dipublikasikan.'});
            else if(a==='desk-ledger-sync'){el.disabled=true;await request('/api/ledger-rebuild','POST',{});ledgerKey='';await load();toast('Jurnal transaksi lama diselaraskan.');}
            else if(a==='desk-journal'){const choices=ledger.accounts.map(a=>({value:a.code,label:`${a.code} · ${a.name}`})),key=crypto.randomUUID();formModal('Jurnal penyesuaian',field('date','Tanggal','date',state.today)+field('amount','Nominal (Rp)','number','',{min:1})+field('debit','Akun debit','select','',{choices})+field('credit','Akun kredit','select','',{choices})+field('description','Keterangan','textarea','',{wide:true}),async v=>{const out=await request('/api/ledger-adjustment','POST',{...v,request_key:key});ledgerKey='';return out;});}
            else if(a==='desk-journal-detail'){const entry=ledger.entries.find(e=>e.id===Number(id));openModal(`Jurnal ${entry.reference}`,`<p>${h(entry.description)} · ${dateLabel(entry.date)}</p>`+table(['Akun','Debit','Kredit'],ledger.lines.filter(l=>l.journal_entry_id===entry.id).map(l=>[h(ledger.accounts.find(a=>a.id===l.account_id)?.name),money(l.debit),money(l.credit)])));}
            else if(a==='desk-ledger-account')formModal('Tambah akun buku besar',field('code','Kode akun','text','',{hint:'3–10 angka.'})+field('name','Nama akun')+field('type','Kelompok','select','asset',{wide:true,choices:[{value:'asset',label:'Aset'},{value:'liability',label:'Liabilitas'},{value:'equity',label:'Ekuitas'},{value:'income',label:'Pendapatan'},{value:'expense',label:'Biaya'}]}),async v=>{const out=await request('/api/ledger-accounts','POST',v);ledgerKey='';return out;});
            else if(a==='desk-print-ledger')window.print();
        }catch(error){toast(error.message,true);}finally{if(el.isConnected)el.disabled=false;}
    });
    return {board,drawRooms,housekeeping,calendar,reservations,drawReservations,booking,guest,attendance,inventory,account,bookingEngine,finance};
})();
