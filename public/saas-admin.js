'use strict';
(() => {
    const $=(s,root=document)=>root.querySelector(s), h=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    const cash=v=>new Intl.NumberFormat('id-ID',{style:'currency',currency:'IDR',maximumFractionDigits:0}).format(Number(v||0));
    const stamp=v=>v?new Date(v.replace(' ','T')+'Z').toLocaleString('id-ID',{timeZone:'Asia/Jakarta',dateStyle:'medium',timeStyle:'short'})+' WIB':'Belum ditetapkan';
    const localValue=v=>v?new Intl.DateTimeFormat('sv-SE',{timeZone:'Asia/Jakarta',year:'numeric',month:'2-digit',day:'2-digit',hour:'2-digit',minute:'2-digit',hour12:false}).format(new Date(v.replace(' ','T')+'Z')).replace(' ','T'):'';
    const names={'tenants.view':'Melihat hotel & audit','tenants.manage':'Mendaftarkan hotel','subscriptions.manage':'Aktivasi, masa aktif & suspend','invoices.manage':'Tagihan & verifikasi pembayaran','plans.manage':'Mengatur paket dan harga','maintenance.manage':'Maintenance per hotel & global','settings.manage':'Pendaftaran, kontak & instruksi pembayaran'};
    const labels={active:'Aktif',pending:'Menunggu aktivasi',expired:'Kedaluwarsa',suspended:'Ditangguhkan',maintenance:'Pemeliharaan',unpaid:'Belum dibayar',paid:'Lunas',void:'Dibatalkan'};
    let data=null,view='tenants',query='',currentPage=1,selectedHotel=null,timer;
    let accessFilter = '';
    const tenantStatuses = ['active', 'pending', 'expired', 'suspended', 'maintenance'];
    const can=p=>data?.is_super||data?.permissions.includes(p);
    const badge=(s,text)=>`<span class="saas-badge ${h(s)}">${h(text||labels[s]||s)}</span>`;
    const button=(label,action,id='',value='',primary=false)=>`<button type="button" class="${primary?'primary':'secondary'}" data-saas="${action}" data-id="${h(id)}" data-value="${h(value)}">${h(label)}</button>`;
    const table=(headers,rows)=>rows.length?`<div class="table-wrap"><table><thead><tr>${headers.map(x=>`<th>${h(x)}</th>`).join('')}</tr></thead><tbody>${rows.map(row=>`<tr>${row.map(cell=>`<td>${cell}</td>`).join('')}</tr>`).join('')}</tbody></table></div>`:'<div class="empty-state"><h3>Belum ada data</h3><p>Data akan tampil setelah ditambahkan.</p></div>';
    function field(name,label,type='text',value='',choices=[],optional=false){const id=`saas-field-${name}`,attrs=optional?'':'required';let control=type==='select'?`<select id="${id}" name="${name}" ${attrs}>${choices.map(c=>`<option value="${h(c.value)}" ${String(value)===String(c.value)?'selected':''}>${h(c.label)}</option>`).join('')}</select>`:type==='textarea'?`<textarea id="${id}" name="${name}" ${attrs}>${h(value)}</textarea>`:`<input id="${id}" name="${name}" type="${type}" value="${h(value)}" ${attrs} ${type==='number'?'min="0"':''} ${type==='password'?'autocomplete="new-password" minlength="12"':''}>`;return `<div class="field ${type==='textarea'?'wide':''}"><label for="${id}">${h(label)}${optional?' (opsional)':''}</label>${control}</div>`;}
    const yesno=[{value:1,label:'Ya'},{value:0,label:'Tidak'}];
    const planChoices=()=>data.plans.filter(p=>p.published).map(p=>({value:p.id,label:p.name}));
    const periodChoices=[{value:1,label:'Bulanan'},{value:12,label:'Tahunan'}];
    function toast(text,error=false){const el=$('#saas-toast');el.textContent=text;el.classList.toggle('error-toast',error);el.hidden=false;clearTimeout(timer);timer=setTimeout(()=>el.hidden=true,6500);}
    async function request(url,method='GET',body){const response=await fetch(url,{method,credentials:'same-origin',headers:{Accept:'application/json','Content-Type':'application/json','X-CSRF-TOKEN':$('meta[name=csrf-token]').content},...(body===undefined?{}:{body:JSON.stringify(body)})});if([401,419].includes(response.status)){location.href='/saas/login';throw new Error('Sesi berakhir.');}const result=await response.json().catch(()=>({}));if(!response.ok)throw new Error(result.errors?Object.values(result.errors).flat().join('\n'):result.message||'Permintaan gagal.');return result;}
    async function load(){data=await request(`/saas/api/data?${new URLSearchParams({q: query, page: currentPage, access: accessFilter})}`);$('#saas-actor').textContent=`${data.user.name} · ${data.is_super?'Super Admin':'Sub-admin'}`;render();}
    function close(){window.SearchSelect.close();$('#saas-modal').close();}
    function modal(title,body){window.SearchSelect.close();$('#saas-modal-title').textContent=title;$('#saas-modal-body').innerHTML=body;window.SearchSelect.enhance($('#saas-modal-body'));if(!$('#saas-modal').open)$('#saas-modal').showModal();}
    function form(title,fields,save,info=''){modal(title,`${info?`<div class="info">${h(info)}</div>`:''}<form id="saas-form"><div id="saas-error" class="error" role="alert" hidden></div><div class="form-grid">${fields}</div><div class="form-actions">${button('Batal','close')}<button type="submit" class="primary">Simpan</button></div></form>`);$('#saas-form').onsubmit=async e=>{e.preventDefault();const form=e.currentTarget,submit=form.querySelector('button[type=submit]');submit.disabled=true;submit.textContent='Menyimpan…';const values=Object.fromEntries(new FormData(form));if(form.querySelector('[name=permissions]'))values.permissions=new FormData(form).getAll('permissions');try{const result=await save(values);await load();close();toast(result.message||'Tersimpan.');}catch(error){$('#saas-error').textContent=error.message;$('#saas-error').hidden=false;}finally{submit.disabled=false;submit.textContent='Simpan';}};}
    function render(){
        const tabs=[['tenants','Hotel & langganan',can('tenants.view')],['plans','Paket',can('plans.manage')],['admins','Sub-admin',data.is_super],['settings','Pengaturan',can('maintenance.manage')||can('settings.manage')],['audit','Riwayat',can('tenants.view')]].filter(x=>x[2]);
        if(!tabs.some(t=>t[0]===view))view=tabs[0]?.[0]||'none';
        $('#saas-nav').innerHTML=tabs.map(t=>`<button type="button" class="secondary" data-saas="view" data-id="${t[0]}" aria-pressed="${view===t[0]}">${t[1]}</button>`).join('');
        $('#saas-content').innerHTML=({tenants,plans,admins,settings,audit}[view]||(()=>'<section class="panel">Belum ada izin tindakan. Hubungi Super Admin SaaS.</section>'))();window.SearchSelect.enhance();
        document.querySelectorAll('[data-saas=page]').forEach(button=>button.disabled=Number(button.dataset.value)<0?currentPage<=1:currentPage>=data.tenants.last_page);
        $('#saas-search')?.addEventListener('submit', async event => {
            event.preventDefault();
            const submit = event.submitter || $('#saas-search button[type="submit"]');
            if (submit.disabled) return;
            submit.disabled = true;
            const previous = {query, accessFilter, currentPage};
            query = $('#saas-query').value.trim();
            accessFilter = $('#saas-access').value;
            currentPage = 1;
            try {
                await load();
                $('#saas-tenant-count')?.focus({preventScroll: true});
            } catch (error) {
                ({query, accessFilter, currentPage} = previous);
                toast(error.message, true);
            } finally {
                submit.disabled = false;
            }
        });
    }
    function tenants() {
        const list = data.tenants;
        const s = data.summary;
        const summary = [['Hotel terdaftar', s.total], ['Langganan aktif', s.active], ['Kedaluwarsa', s.expired], ['Ditangguhkan', s.suspended]];
        return `<div class="summary-strip">${summary.map(([label, value]) => `<div class="summary-cell"><small>${label}</small><strong>${value}</strong></div>`).join('')}</div>
            <div class="saas-row"><h2>Hotel &amp; langganan</h2><div class="actions">
                ${button('Perbarui', 'refresh')}${can('tenants.manage') ? button('Daftarkan hotel', 'tenant-new', '', '', true) : ''}
            </div></div>
            <form class="work-toolbar tenant-toolbar" id="saas-search">
                <div class="work-search"><label for="saas-query">Cari hotel atau pemilik</label>
                    <input id="saas-query" type="search" placeholder="Nama hotel, nama pemilik, atau email" value="${h(query)}" maxlength="100">
                </div>
                <div class="tenant-status-filter"><label for="saas-access">Status akses</label><select id="saas-access">
                    <option value="">Semua status</option>
                    ${tenantStatuses.map(status => `<option value="${status}" ${status === accessFilter ? 'selected' : ''}>${labels[status]}</option>`).join('')}
                </select></div>
                <button type="submit" class="secondary">Terapkan</button>
            </form>
            <p class="table-note result-count" id="saas-tenant-count" role="status" tabindex="-1">${list.total} hotel</p>`
            + (list.data.length ? tenantResults(list.data) : `<div class="empty-state"><h3>${query || accessFilter ? 'Hotel tidak ditemukan' : 'Belum ada hotel'}</h3>
                <p>${query || accessFilter ? 'Ubah kata pencarian atau status akses.' : 'Hotel terdaftar akan muncul di sini.'}</p></div>`)
            + (list.last_page > 1 ? `<div class="saas-pagination">${button('Sebelumnya', 'page', '', -1)}
                <span>Halaman ${list.current_page} / ${list.last_page}</span>${button('Berikutnya', 'page', '', 1)}</div>` : '');
    }
    function tenantResults(hotels) {
        const detail = hotel => button('Kelola', 'tenant-detail', hotel.hotel_id);
        const desktop = table(['Hotel / pemilik', 'Paket / batas', 'Status akses', 'Masa aktif sampai', 'Tindakan'], hotels.map(hotel => [
            `<strong>${h(hotel.hotel_name)}</strong>${hotel.is_demo ? '<small>Data contoh</small>' : ''}<small>${h(hotel.owner_name)} · ${h(hotel.owner_email)}</small>`,
            `${h(hotel.plan_name)}<small>${hotel.room_limit} kamar · ${hotel.user_limit} akun</small>`,
            badge(hotel.access_state), stamp(hotel.ends_at), detail(hotel),
        ]));
        const mobile = hotels.map(hotel => `<article class="panel tenant-card">
            <div><h3>${h(hotel.hotel_name)}</h3><p>${h(hotel.owner_name)}${hotel.is_demo ? ' · Data contoh' : ''}</p></div>
            <div class="tenant-card-meta">${badge(hotel.access_state)}<span>Masa aktif · ${stamp(hotel.ends_at)}</span></div>
            ${detail(hotel)}
        </article>`).join('');
        return `<div class="tenant-table">${desktop}</div><div class="tenant-cards">${mobile}</div>`;
    }
    function plans(){return `<div class="saas-row"><div><h2>Paket langganan</h2><p>Harga dan batas disalin ke tagihan agar transaksi lama tetap konsisten.</p></div>${button('Paket baru','plan-new','','',true)}</div>`+table(['Paket','Bulanan / tahunan','Batas kamar / akun','Status','Tindakan'],data.plans.map(p=>[`<strong>${h(p.name)}</strong><small>${h(p.description)}</small>`,`${p.monthly_price?cash(p.monthly_price):'Tidak ditawarkan'}<small>${p.yearly_price?cash(p.yearly_price):'Tidak ditawarkan'}</small>`,`${p.room_limit} kamar · ${p.user_limit} akun`,p.published?'Terbit':'Draf',button('Ubah paket','plan',p.id)]));}
    function admins(){return `<div class="saas-row"><div><h2>Sub-admin SaaS</h2><p>Akun platform terpisah dari pemilik dan staf hotel.</p></div>${button('Tambah sub-admin','admin-new','','',true)}</div>`+table(['Nama / email','Peran','Izin','Status','Tindakan'],data.admins.map(a=>[`<strong>${h(a.name)}</strong><small>${h(a.email)}</small>`,a.is_super?'Super Admin':'Sub-admin',a.is_super?'Seluruh izin':JSON.parse(a.permissions).map(p=>h(names[p])).join('<br>'),a.active?'Aktif':'Nonaktif',a.is_super?'Dikelola melalui administrator server':button('Atur akses','admin',a.id)]));}
    function settings(){const s=data.settings,effective=s.maintenance&&(!s.maintenance_until||new Date(s.maintenance_until.replace(' ','T')+'Z')>new Date());return `<h2>Pengaturan layanan</h2><div class="saas-plan-grid"><section class="panel"><h3>Maintenance global</h3>${badge(effective?'maintenance':'active',effective?'Sedang maintenance':'Operasional tersedia')}<p>${h(s.maintenance_message||'Belum ada pesan maintenance.')}</p><p>Sampai: ${s.maintenance_until?stamp(s.maintenance_until):'Dinonaktifkan manual'}</p>${can('maintenance.manage')?button('Atur maintenance global','global-maintenance'):''}<p class="table-note">Membatasi operasional dan booking publik seluruh hotel. Panel SaaS dan halaman langganan tetap dapat dibuka.</p></section><section class="panel"><h3>Pendaftaran & pembayaran</h3><p>Pendaftaran: ${s.signup_enabled?'Dibuka':'Ditutup'}</p><p>Kontak: ${h(s.support_contact||'Belum diisi')}</p><p class="saas-preline">${h(s.payment_instructions||'Instruksi pembayaran belum diisi.')}</p>${can('settings.manage')?button('Ubah pengaturan','settings'):''}<p><a href="/register" target="_blank" rel="noopener">Lihat halaman pendaftaran</a></p></section></div>`;}
    function audit(){return '<h2>Riwayat administrasi · 25 catatan terakhir</h2>'+table(['Waktu','Petugas','Tindakan','Hotel','Keterangan'],data.audit.map(a=>{const d=JSON.parse(a.details||'{}');return[stamp(a.created_at),h(a.actor||'Sistem'),h(a.action),a.hotel_id?`#${a.hotel_id}`:'Seluruh layanan',h(d.reason|| (d.invoice_id?`Tagihan #${d.invoice_id}`:d.plan_id?`Paket #${d.plan_id}`:'Perubahan tercatat'))];}));}
    async function tenantDetail(id){const item=data.tenants.data.find(a=>a.hotel_id===Number(id));if(!item)return;selectedHotel=item;const detail=await request(`/saas/api/tenants/${id}`);modal(item.hotel_name,`<dl class="detail-grid"><div><dt>Pemilik</dt><dd>${h(item.owner_name)}<small>${h(item.owner_email)}</small></dd></div><div><dt>Status</dt><dd>${badge(item.access_state)}</dd></div><div><dt>Masa aktif</dt><dd>${stamp(item.ends_at)}</dd></div><div><dt>Pemakaian</dt><dd>${detail.usage.rooms}/${item.room_limit} kamar · ${detail.usage.users}/${item.user_limit} akun</dd></div></dl><div class="saas-detail-actions">${can('subscriptions.manage')?button('Tambah masa aktif','access',id,'extend')+button(item.status==='suspended'?'Buka suspend':'Suspend','access',id,item.status==='suspended'?'unsuspend':'suspend'):''}${can('maintenance.manage')?button('Maintenance hotel','hotel-maintenance',id):''}${can('invoices.manage')?button('Buat tagihan','issue',id):''}</div>${can('invoices.manage')?'<h3>Tagihan langganan</h3>'+table(['Kode / paket','Nominal','Status / referensi','Tindakan'],detail.invoices.map(i=>[`<strong>${h(i.code)}</strong><small>${h(i.plan_name)} · ${i.months} bulan</small>`,cash(i.amount),`${badge(i.status,i.reported_at&&i.status==='unpaid'?'Menunggu verifikasi':null)}<small>${h(i.payment_reference||'Belum ada referensi')}</small>`,`<div class="actions"><a class="button-link secondary" href="/saas/invoices/${i.id}" target="_blank" rel="noopener">Tagihan</a>${i.status==='unpaid'?button('Verifikasi lunas','invoice-paid',i.id,i.payment_reference||'')+button('Batalkan','invoice-void',i.id):''}</div>`])):''}`);}
    document.addEventListener('click',async e=>{
        const el=e.target.closest('[data-saas]');if(!el)return;const{saas:action,id,value}=el.dataset;
        try{
            if(action==='close'){close();return;}
            if(action==='view'){view=id;render();return;}
            if(action==='refresh'){await load();return;}
            if(action==='page'){const next=currentPage+Number(value);if(next<1||next>data.tenants.last_page)return;currentPage=next;await load();return;}
            if(action==='tenant-detail'){el.disabled=true;await tenantDetail(id);return;}
            if(action==='tenant-new'){form('Daftarkan hotel',field('hotel_name','Nama hotel')+field('name','Nama pemilik')+field('email','Email pemilik','email')+field('password','Password awal','password')+field('password_confirmation','Ulangi password','password')+field('plan_id','Paket','select','',planChoices())+field('months','Periode','select',1,periodChoices),v=>request('/saas/api/tenants','POST',v),'Satu akun pemilik untuk satu hotel. Buat kredensial awal sesuai prosedur pengelola; tidak ada email yang dikirim otomatis. Hotel menunggu aktivasi setelah dibuat.');return;}
            if(action==='plan'||action==='plan-new'){const p=data.plans.find(p=>p.id===Number(id))||{};form(p.id?'Ubah paket':'Paket baru',field('name','Nama paket','text',p.name||'')+field('slug','Kode paket','text',p.slug||'')+field('monthly_price','Harga bulanan (Rp)','number',p.monthly_price||0)+field('yearly_price','Harga tahunan (Rp)','number',p.yearly_price||0)+field('room_limit','Maksimal kamar','number',p.room_limit||50)+field('user_limit','Maksimal akun termasuk Owner','number',p.user_limit||20)+field('published','Terbitkan paket','select',Number(p.published||0),yesno)+field('description','Deskripsi','textarea',p.description||'',[],true),v=>request(p.id?`/saas/api/plans/${p.id}`:'/saas/api/plans',p.id?'PUT':'POST',v),'Harga 0 menonaktifkan periode tersebut. Paket terbit wajib memiliki setidaknya satu periode berharga. Tagihan dan masa aktif yang sudah ada tidak dihitung ulang.');return;}
            if(action==='access'){const requestKey=crypto.randomUUID();const title={extend:'Tambah masa aktif',suspend:'Suspend hotel',unsuspend:'Buka suspend'}[value];form(title,(value==='extend'?field('days','Tambahan hari','number',30):'')+field('reason','Alasan / pesan','textarea'),v=>request(`/saas/api/tenants/${id}/access`,'POST',{...v,action:value,request_key:requestKey}),value==='extend'?'Perpanjangan administratif tanpa pencatatan uang. Masa aktif tersisa dipertahankan. Suspend tetap berlaku sampai dibuka secara terpisah.':'Perubahan berlaku pada akses operasional dan booking publik. Data hotel tetap tersimpan; halaman langganan tetap dapat dibuka.');return;}
            if(action==='hotel-maintenance'||action==='global-maintenance'){const global=action==='global-maintenance',a=global?data.settings:selectedHotel;form(global?'Maintenance seluruh hotel':'Maintenance hotel',field('enabled','Aktifkan maintenance','select',Number(a.maintenance),yesno)+field('until','Sampai (WIB)','datetime-local',localValue(a.maintenance_until),[],true)+field('reason','Pesan / alasan','textarea',a.maintenance_message||''),v=>global?request('/saas/api/maintenance','PUT',v):request(`/saas/api/tenants/${id}/access`,'POST',{...v,action:Number(v.enabled)?'maintenance_on':'maintenance_off'}),global?'Seluruh hotel dan booking publik akan dibatasi. Kosongkan waktu untuk maintenance sampai dinonaktifkan manual. Panel SaaS dan langganan tidak ditutup.':'Maintenance hotel ini berakhir otomatis pada waktu yang ditetapkan; kosong berarti sampai dinonaktifkan manual.');return;}
            if(action==='issue'){form('Buat tagihan langganan',field('plan_id','Paket','select',selectedHotel.plan_id,planChoices())+field('months','Periode','select',1,periodChoices),v=>request(`/saas/api/tenants/${id}/invoices`,'POST',v),'Tagihan yang belum dibayar tidak mengaktifkan hotel. Perubahan paket tidak memakai prorata; harga dan batas paket disalin ke tagihan.');return;}
            if(action==='invoice-paid'){form('Verifikasi pembayaran langganan',field('reference','Referensi pembayaran','text',value)+field('method','Metode','select','Transfer',[{value:'Transfer',label:'Transfer'},{value:'Tunai',label:'Tunai'},{value:'Lainnya',label:'Lainnya'}]),v=>request(`/saas/api/invoices/${id}`,'POST',{...v,action:'paid'}),'Verifikasi setelah penerimaan uang diperiksa di luar aplikasi. Tindakan ini menandai tagihan lunas dan menambah masa aktif sekali; tidak memindahkan uang.');return;}
            if(action==='invoice-void'){form('Batalkan tagihan belum dibayar',field('reference','Alasan pembatalan','textarea'),v=>request(`/saas/api/invoices/${id}`,'POST',{...v,action:'void'}),'Tagihan yang sudah lunas tidak dapat dibatalkan dari tindakan ini.');return;}
            if(action==='settings'){const s=data.settings;form('Pengaturan layanan',field('signup_enabled','Buka pendaftaran mandiri','select',Number(s.signup_enabled),yesno)+field('support_contact','Kontak layanan','text',s.support_contact||'',[],true)+field('payment_instructions','Instruksi pembayaran','textarea',s.payment_instructions||'',[],true),v=>request('/saas/api/settings','PUT',v),'Instruksi pembayaran dan kontak ditampilkan kepada pemilik hotel. Isi sesuai saluran pembayaran yang benar-benar dikelola.');return;}
            if(action==='admin'||action==='admin-new'){const a=data.admins.find(a=>a.id===Number(id)),selected=a?JSON.parse(a.permissions):[];form(a?'Atur akses sub-admin':'Sub-admin baru',(a?'':field('name','Nama')+field('email','Email','email')+field('password','Password awal','password')+field('password_confirmation','Ulangi password','password'))+field('active','Akun aktif','select',Number(a?.active??1),yesno)+`<fieldset class="field wide"><legend>Izin admin SaaS</legend><div class="check-grid">${data.permission_options.map(p=>`<label><input name="permissions" type="checkbox" value="${p}" ${selected.includes(p)?'checked':''}>${h(names[p])}</label>`).join('')}</div></fieldset>`,v=>request(a?`/saas/api/admins/${a.id}`:'/saas/api/admins',a?'PUT':'POST',v),'Sub-admin tidak dapat membuat Super Admin atau membuka data operasional/tamu. Menonaktifkan akun mengakhiri sesi login yang tersimpan.');return;}
        }catch(error){toast(error.message,true);}finally{if(el.isConnected)el.disabled=false;}
    });
    $('#saas-modal-close').addEventListener('click',close);$('#saas-modal').addEventListener('close',()=>window.SearchSelect.close());
    load().catch(error=>{$('#saas-content').innerHTML=`<div class="error" role="alert">${h(error.message)}</div>`;});
})();
