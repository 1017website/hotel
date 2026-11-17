'use strict';

const StaffAttendance = (() => {
    let mine = null, mineKey = '', mineLoading = '', history = [], historyKey = '', historyLoading = '';
    let file = null, preview = null, previewPage = 0, busy = false, message = '', error = '';
    const sources = {manual:'Petugas',self:'Mandiri',fingerprint:'Mesin',correction:'Koreksi'};
    const button = (text, action, disabled = false, primary = false) => `<button type="button" class="${primary?'primary':'secondary'}" data-action="staff-${action}" ${disabled?'disabled':''}>${h(text)}</button>`;

    function self() {
        const key = `${state.hotel.id}/${state.updated_at}`;
        if (!mineKey.startsWith(`${state.hotel.id}/`)) mine = null;
        if (mineKey !== key && mineLoading !== key) {
            mineLoading = key;
            const hotelId = state.hotel.id;
            request('/api/my-attendance').then(data => {
                if (state.hotel.id !== hotelId) return;
                mine = data; mineKey = key;
                if (page === 'my-attendance') render();
            }).catch(e => toast(e.message,true)).finally(() => { if (mineLoading === key) mineLoading = ''; });
        }
        const title = heading('Absensi saya','Catat kehadiran Anda menggunakan waktu server dan zona waktu hotel.');
        if (!mine) return title + '<div class="loading-panel">Memuat kehadiran Anda…</div>';
        if (!mine.employee) return title + empty('Akun belum terhubung ke karyawan','Minta pengelola menghubungkan akun Anda melalui Karyawan → Akun / ID mesin. Anda tidak dapat memilih nama karyawan lain.');
        const employee = mine.employee, today = mine.records.find(row => row.date === mine.today), open = mine.open;
        return title + `<section class="panel self-attendance"><div><h2>${h(employee.name)}</h2><p>${h(employee.department)} · ${h(employee.position)}</p><p>${dateLabel(mine.today)} · ${h(state.hotel.timezone)}</p></div><div class="self-attendance-status"><strong>${open?'Sedang bekerja':today?.clock_out?'Kehadiran hari ini selesai':today?'Ada catatan kehadiran hari ini':'Belum absen masuk'}</strong><p>${open?`Masuk ${timeLabel(open.clock_in)}`:today?.clock_out?`Pulang ${timeLabel(today.clock_out)}`:'Waktu dicatat otomatis saat tombol ditekan.'}</p><div class="actions">${open?button('Absen pulang','clockout',busy,true):button('Absen masuk','clockin',busy||!!today||!employee.active,true)}</div></div></section>`
            + (!employee.active?'<div class="info">Data karyawan Anda sudah nonaktif. Absensi masuk baru dinonaktifkan; absensi terbuka masih dapat diselesaikan.</div>':'')
            + '<p class="table-note">Absensi mandiri belum membatasi lokasi atau Wi-Fi. Koreksi waktu dan izin/cuti ditangani petugas. Satu catatan kehadiran per tanggal kerja; shift malam tetap ditutup pada catatan masuk sebelumnya.</p>'
            + '<h2>Jadwal tujuh hari ke depan</h2>' + (mine.shifts.length?table(['Tanggal','Jam kerja','Catatan'],mine.shifts.map(row=>[dateLabel(row.starts_at.slice(0,10)),`${timeLabel(row.starts_at)} – ${timeLabel(row.ends_at)}`,h(row.notes||'Tidak ada catatan')])):empty('Belum ada jadwal','Jadwal ditambahkan oleh pengelola hotel.'))
            + '<h2 class="section-gap">Riwayat saya · 31 catatan terakhir</h2>' + (mine.records.length?table(['Tanggal kerja','Masuk','Pulang','Kehadiran','Sumber'],mine.records.map(row=>[dateLabel(row.date),row.clock_in?timeLabel(row.clock_in):'Tidak ada',row.clock_out?timeLabel(row.clock_out):row.clock_in?'Belum pulang':'Tidak ada',h({present:'Hadir',sick:'Sakit',leave:'Izin / cuti',absent:'Alpa'}[row.status]||row.status),h(sources[row.source]||'Petugas')])):empty('Belum ada kehadiran','Gunakan Absen masuk untuk mulai mencatat kehadiran Anda.'));
    }

    function importPage() {
        const key = `${state.hotel.id}/${state.updated_at}`;
        if (historyKey !== key && historyLoading !== key) {
            historyLoading = key;
            const hotelId = state.hotel.id;
            request('/api/attendance-imports').then(data => {
                if (state.hotel.id !== hotelId) return;
                history = data.batches; historyKey = key;
                if (page === 'attendance-import' && $('#staff-import-history')) $('#staff-import-history').innerHTML=historyContent();
            }).catch(e=>toast(e.message,true)).finally(()=>{if(historyLoading===key)historyLoading='';});
        }
        return heading('Impor absensi mesin','Masukkan rekap hasil mesin fingerprint melalui CSV yang sudah diperiksa.', '<a class="button-link secondary" href="/attendance/template.csv">Unduh template CSV</a>')
            + `<div class="attendance-import-layout"><section class="panel"><h2>Unggah & periksa</h2><p>CSV UTF-8, maksimal 2 MB / 1.000 baris. Ekspor Excel atau TXT dari mesin perlu disesuaikan ke empat kolom template terlebih dahulu.</p><form id="staff-import-form"><div class="field"><label for="staff-import-file">${file?'Ganti file CSV':'File CSV'}</label><input type="file" id="staff-import-file" ${file?'hidden':''} accept=".csv,text/csv" ${file?'':'required'} ${busy?'disabled':''}>${file?button('Pilih CSV lain','pick-file',busy):''}</div><p id="staff-file-name" class="table-note">${file?'Terpilih: '+h(file.name):'Belum ada file dipilih.'}</p><button class="primary" type="submit" ${busy?'disabled':''}>${busy?'Memproses…':'Periksa file / pratinjau'}</button></form><p class="table-note">Pratinjau belum menyimpan absensi. Penyimpanan dilakukan setelah Anda memilih Simpan impor.</p></section><section class="panel"><h2>Isi template</h2><ol><li>Atur <strong>ID mesin</strong> setiap karyawan pada menu Karyawan → Akun / ID mesin.</li><li>Gunakan satu baris per karyawan per tanggal kerja.</li><li>Isi waktu lengkap, misalnya <strong>2026-11-15 22:00:00</strong> dan <strong>2026-11-16 06:00:00</strong> untuk shift malam.</li><li>Jam pulang boleh kosong jika belum pulang. Impor berikutnya dapat melengkapinya bila jam masuk sama.</li></ol><p>ID seperti <strong>0012</strong> harus disimpan sebagai teks agar nol di depan tetap utuh. Pemisah koma dan titik koma diterima. Tanggal dan jam mengikuti zona waktu hotel.</p><p>File berisi rekap waktu, tanpa citra atau template biometrik sidik jari.</p></section></div>`
            + `<div id="staff-import-error" class="error section-gap" role="alert" ${error?'':'hidden'}>${h(error)}</div>${message?`<div class="info section-gap" role="status">${h(message)}</div>`:''}<section id="staff-preview">${previewContent()}</section>`
            + `<section id="staff-import-history">${historyContent()}</section>`;
    }

    function historyContent() {
        return '<h2 class="section-gap">Riwayat impor · 30 berkas terakhir</h2>' + (history.length?table(['Berkas','Waktu (UTC)','Baru','Pulang dilengkapi','Dilewati'],history.map(batch=>[h(batch.filename),timeLabel(batch.created_at),batch.inserted,batch.completed,batch.skipped])):empty('Belum ada impor','Periksa file CSV di atas untuk menambahkan absensi mesin.'));
    }

    function previewContent() {
        if (!preview) return '';
        const counts=preview.counts, pages=Math.ceil(preview.rows.length/50);
        return `<h2 class="section-gap">Pratinjau ${h(preview.filename)}</h2>` + summary([['Catatan baru',counts.new,'Akan ditambahkan'],['Lengkapi pulang',counts.complete,'Impor mesin sebelumnya'],['Dilewati',counts.skip,'Sudah ada / identik'],['Perlu diperbaiki',counts.error,'Impor ditahan jika ada masalah']])
            + (preview.already_imported?'<div class="info">File yang sama sudah pernah diimpor. Pengiriman ulang tidak menggandakan data.</div>':'')
            + table(['Baris','ID mesin / karyawan','Tanggal kerja','Masuk / pulang','Hasil'],preview.rows.slice(previewPage*50,previewPage*50+50).map(row=>[row.line,`<strong>${h(row.code)}</strong><small>${h(row.employee||'Tidak ditemukan')}</small>`,h(row.date),`${h(row.clock_in)}<small>${h(row.clock_out||'Belum pulang')}</small>`,`<strong class="${row.status==='error'?'import-row-error':''}">${h({new:'Baru',complete:'Lengkapi pulang',skip:'Dilewati',error:'Perbaiki'}[row.status])}</strong><small>${h(row.reason)}</small>`]))
            + `<div class="pagination-controls">${button('Sebelumnya','preview-prev',previewPage===0||busy)}<span>Halaman ${previewPage+1} / ${pages} · ${preview.rows.length} baris</span>${button('Berikutnya','preview-next',previewPage>=pages-1||busy)}</div><div class="panel section-gap"><p>${preview.valid?'Seluruh baris valid. Data akan diperiksa kembali saat disimpan. Pratinjau berlaku 15 menit.':'Perbaiki baris bermasalah, lalu unggah ulang. Tidak ada baris yang disimpan selama masih ada kesalahan.'}</p>${button(busy?'Menyimpan…':'Simpan impor','import-commit',!preview.valid||busy,true)}</div>`;
    }

    async function upload(url, token) {
        if (!file) throw new Error('Pilih file CSV terlebih dahulu.');
        if (file.size>2*1024*1024) throw new Error('File melebihi 2 MB. Pecah menjadi beberapa file.');
        const body=new FormData();body.append('file',file);if(token)body.append('preview_token',token);
        const response=await fetch(url,{method:'POST',credentials:'same-origin',headers:{Accept:'application/json','X-CSRF-TOKEN':$('meta[name=csrf-token]').content,'X-Hotel-ID':String(state.hotel.id)},body});
        if([401,419].includes(response.status)){location.href='/login';throw new Error('Sesi berakhir. Silakan masuk kembali.');}
        const data=await response.json().catch(()=>({}));
        if(data.subscription_unavailable){location.href='/subscription';throw new Error(data.message);}
        if(!response.ok)throw new Error(data.errors?Object.values(data.errors).flat().join('\n'):data.message||'Berkas belum dapat diproses. Periksa ukuran dan format CSV.');
        return data;
    }

    document.addEventListener('change',event=>{
        if(event.target.id!=='staff-import-file')return;
        file=event.target.files[0]||null;preview=null;previewPage=0;error='';message='';
        $('#staff-file-name').textContent=file?`Terpilih: ${file.name}`:'Belum ada file dipilih.';
        $('#staff-preview').innerHTML='';$('#staff-import-error').hidden=true;
    });
    document.addEventListener('submit',async event=>{
        if(event.target.id!=='staff-import-form')return;
        event.preventDefault();if(busy)return;
        busy=true;error='';message='';render();
        try{preview=await upload('/api/attendance-import/preview');previewPage=0;}catch(e){preview=null;error=e.message;}finally{busy=false;if(page==='attendance-import')render();}
    });
    document.addEventListener('click',async event=>{
        const element=event.target.closest('[data-action^="staff-"]');if(!element)return;
        const action=element.dataset.action;if(busy)return;
        if(action==='staff-pick-file'){$('#staff-import-file').click();return;}
        try{
            if(action==='staff-preview-prev'||action==='staff-preview-next'){previewPage+=action==='staff-preview-next'?1:-1;render();return;}
            if(action==='staff-map'){
                const employee=state.employees.find(row=>row.id===Number(element.dataset.id));if(!employee)return;
                formModal(`Akun / ID mesin · ${employee.name}`,field('attendance_code','ID karyawan pada mesin','text',employee.attendance_code||'',{required:false,wide:true,hint:'Harus sama dengan kode pada CSV. Huruf, angka, titik, garis bawah atau tanda minus; maksimal 40 karakter.'})+(can('users.manage')?field('user_id','Akun login untuk absensi mandiri','select',employee.user_id||'',{required:false,wide:true,choices:(state.members||[]).map(member=>({value:member.id,label:`${member.name} · ${member.email}`})),hint:'Kosongkan untuk melepas akun. Satu akun hanya untuk satu karyawan per hotel.'}):'<p class="field wide">Menghubungkan akun login memerlukan izin Mengelola pengguna. ID mesin dapat diatur tanpa mengubah hubungan akun.</p>'),values=>request(`/api/employee-attendance/${employee.id}`,'PUT',values),{info:'Absensi mandiri juga membutuhkan izin Absen sendiri pada peran akun tersebut. ID mesin hanya pengenal, bukan data sidik jari.'});return;
            }
            if(action==='staff-clockin'||action==='staff-clockout'){
                busy=true;render();const result=await request('/api/my-attendance','POST',{action:action==='staff-clockin'?'clockin':'clockout'});mineKey='';await load(true);toast(result.message);return;
            }
            if(action==='staff-import-commit'&&preview?.valid){
                busy=true;error='';render();const result=await upload('/api/attendance-import/commit',preview.token);const batch=result.batch;
                message=result.already_imported?`${result.message} Mengacu pada impor #${batch.id}; tidak ada perubahan baru.`:`${result.message} Baru: ${batch.inserted}, pulang dilengkapi: ${batch.completed}, dilewati: ${batch.skipped}.`;
                preview=null;file=null;historyKey='';await load(true);
            }
        }catch(e){if(action==='staff-import-commit')error=e.message;else toast(e.message,true);}
        finally{busy=false;if(state)render();}
    });
    return {self,importPage};
})();
