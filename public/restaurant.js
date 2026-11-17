'use strict';
const Restaurant = (() => {
    const cart = new Map();
    let category = '', query = '', busy = false, requestKey = crypto.randomUUID();
    let outlet='restaurant';
    const icon = name => `<i class="ti ti-${name}" aria-hidden="true"></i>`;
    const outlets={restaurant:'Restoran / kafe',spa:'Spa',facility:'Fasilitas'};
    const types = {room_service:'Layanan kamar',dine_in:'Makan di tempat',takeaway:'Takeaway'};
    const payment = {room:'Tagihan kamar',unpaid:'Belum dibayar',paid:'Lunas'};
    const action = (text,name,id='',extra='') => `<button type="button" class="secondary" data-action="rest-${name}" data-id="${h(id)}" ${extra}>${text}</button>`;
    function totals(discount=0) {
        const subtotal=Array.from(cart,([id,quantity])=>(state.food_items.find(i=>i.id===id)?.price||0)*quantity).reduce((a,b)=>a+b,0);
        const net=Math.max(0,subtotal-discount), service=Math.round(net*state.hotel.restaurant_service/100), tax=Math.round((net+service)*state.hotel.restaurant_tax/100);
        return {subtotal,service,tax,total:net+service+tax};
    }
    function products() {
        const items=(state.food_items||[]).filter(i=>(i.outlet||'restaurant')===outlet && i.available && (!category||i.category===category) && i.name.toLowerCase().includes(query.toLowerCase()));
        return items.length?items.map(i=>`<button type="button" class="menu-product" data-action="rest-add" data-id="${i.id}" ${!can('food.manage')?'disabled':''}><span class="product-icon">${icon(i.category.toLowerCase().includes('minum')?'coffee':'bowl-spoon')}</span><small>${h(i.category)}</small><strong>${h(i.name)}</strong><span>${money(i.price)}</span><span class="product-add" aria-label="Tambahkan">+</span></button>`).join(''):empty('Menu tidak ditemukan','Ubah pencarian atau tambahkan menu restoran.');
    }
    function pos() {
        const t=totals(), count=Array.from(cart.values()).reduce((a,b)=>a+b,0);
        return heading(outlet==='restaurant'?'POS restoran':`POS ${outlets[outlet]}`,'Pilih menu atau layanan, susun pesanan, lalu proses.',`${action(`${icon('receipt')} Pesanan`,'orders')}${can('food.manage')?btn('+ Menu','create','food_items'):''}`)
            + `<div class="outlet-switch"><label for="pos-outlet">Outlet / fasilitas</label><select id="pos-outlet">${[['restaurant','Restoran / kafe'],['spa','Spa'],['facility','Fasilitas & layanan lain']].map(([v,l])=>`<option value="${v}" ${v===outlet?'selected':''}>${l}</option>`).join('')}</select><small>Layanan non-restoran dapat dibayar langsung atau ditambahkan ke tagihan kamar.</small></div><div class="pos-layout"><section class="menu-catalog"><label for="menu-search">Cari menu / layanan</label><input id="menu-search" type="search" placeholder="Cari menu atau layanan…" value="${h(query)}"><div class="category-tabs">${['',...new Set((state.food_items||[]).filter(i=>(i.outlet||'restaurant')===outlet).map(i=>i.category))].map(c=>`<button type="button" class="${category===c?'primary':'secondary'}" data-action="rest-category" data-id="${h(c)}">${h(c||'Semua menu')}</button>`).join('')}</div><div class="menu-grid" id="menu-products">${products()}</div></section>
            <aside class="cart-panel card"><div class="cart-heading"><h2>${icon('shopping-cart')} Pesanan baru</h2><span>${count} item</span></div><div class="cart-lines">${cart.size?Array.from(cart,([id,qty])=>{const i=state.food_items.find(i=>i.id===id);return `<div class="cart-line"><div><strong>${h(i.name)}</strong><small>${money(i.price)}</small></div><div class="qty-control">${action('−','minus',id,`aria-label="Kurangi ${h(i.name)}"`)}<span>${qty}</span>${action('+','add',id,`aria-label="Tambah ${h(i.name)}"`)}</div><strong>${money(i.price*qty)}</strong></div>`;}).join(''):'<div class="cart-empty">'+icon('shopping-bag')+'<p>Keranjang masih kosong</p><small>Klik menu untuk menambah pesanan.</small></div>'}</div><dl class="pos-totals"><div><dt>Subtotal</dt><dd>${money(t.subtotal)}</dd></div><div><dt>Service (${state.hotel.restaurant_service}%)</dt><dd>${money(t.service)}</dd></div><div><dt>Pajak (${state.hotel.restaurant_tax}%)</dt><dd>${money(t.tax)}</dd></div><div class="total"><dt>Total</dt><dd>${money(t.total)}</dd></div></dl><button type="button" class="primary full" data-action="rest-checkout" ${!cart.size||!can('food.manage')?'disabled':''}>${icon('check')} Proses pesanan</button><p class="cart-help">Harga, pajak, dan total diperiksa kembali oleh server.</p></aside></div>`;
    }
    function orders() {
        const orders=state.food_orders||[];
        const next={new:['Proses','preparing'],preparing:['Antar / sajikan','delivering'],delivering:['Selesai','served']};
        return heading('Restoran & pesanan','Antrean dapur dan pembayaran restoran dalam satu tampilan.',`${can('hotel.manage')?action(icon('settings')+' Pengaturan','settings'):''}<a class="btn btn-primary btn-sm" href="#pos">${icon('plus')} Buka POS</a>`)
            + summary([['Pesanan baru',orders.filter(o=>o.status==='new').length,'Menunggu dapur'],['Sedang diproses',orders.filter(o=>['preparing','delivering'].includes(o.status)).length,'Masak dan pengantaran'],['Belum dibayar',orders.filter(o=>o.payment_status==='unpaid').length,'Pembayaran langsung'],['Menu tersedia',(state.food_items||[]).filter(i=>i.available).length,'Makanan dan minuman']])
            + table(['Pesanan','Tujuan','Menu','Total','Pembayaran','Dapur','Tindakan'],orders.map(o=>[`<strong>#${o.id}</strong><small>${dateLabel(o.created_at)}</small>`,`${h(o.outlet!=='restaurant'&&o.order_type==='takeaway'?'Layanan langsung':types[o.order_type]||'Layanan kamar')}<small>${h(outlets[o.outlet||'restaurant'])}</small><small>${h(o.table_label||o.customer||(o.reservation_id?bookingName(o.reservation_id):''))}</small>`,o.items.map(i=>`${i.quantity} × ${h(i.name)}`).join('<br>'),money(o.total),`<span class="badge ${o.payment_status==='paid'?'bg-green-lt':o.payment_status==='room'?'bg-blue-lt':'bg-orange-lt'}">${h(payment[o.payment_status])}</span>`,badge(o.status),`<div class="actions">${can('food.manage')&&next[o.status]?btn(next[o.status][0],'update',o.id,`food_orders:${next[o.status][1]}`):''}${o.payment_status==='unpaid'&&can('payments.manage')?action('Bayar','pay',o.id):''}<a href="/restaurant/${o.id}/receipt">Struk</a></div>`]),'food_orders')
            + '<h2>Menu restoran</h2>'+table(['Menu','Kategori','Harga','Ketersediaan','Tindakan'],(state.food_items||[]).map(i=>[h(i.name),h(i.category),money(i.price),i.available?'Tersedia':'Habis',can('food.manage')?btn('Ubah menu','edit-menu',i.id):'Lihat saja']))
            +(can('food.manage')?btn('+ Tambah menu','create','food_items'):'');
    }
    function breakfast() {
        const visits=state.breakfast_visits||[], bookings=state.restaurant_bookings||[];
        const quota=bookings.reduce((n,b)=>n+b.breakfast_guests,0), used=visits.reduce((n,v)=>n+v.quantity,0);
        return heading('Breakfast','Jatah per tamu, pencatatan porsi, dan kehadiran hari ini.',can('hotel.manage')?action(icon('settings')+' Jam layanan','settings'):'')
            + summary([['Tanggal layanan',dateLabel(state.today),'Zona waktu hotel'],['Jatah hari ini',quota,'Porsi dari paket menginap'],['Sudah digunakan',used,'Porsi tercatat'],['Jam breakfast',`${state.hotel.breakfast_start.slice(0,5)}–${state.hotel.breakfast_end.slice(0,5)}`,'Setiap hari']])
            + `<div class="info">Hak breakfast berlaku mulai pagi setelah check-in sampai tanggal check-out. Atur jatah 0 untuk paket tanpa breakfast. Di luar jam layanan, petugas reservasi harus mencatat alasan pengecualian.</div>`
            + table(['Kamar','Reservasi','Jatah / hari','Digunakan','Sisa hari ini','Tindakan'],bookings.map(b=>{const consumed=visits.filter(v=>v.reservation_id===b.id).reduce((n,v)=>n+v.quantity,0);return [`<strong>${h(b.number)}</strong>`,h(b.code),b.breakfast_guests,consumed,Math.max(0,b.breakfast_guests-consumed),`<div class="actions">${can('reservations.manage')?action('Atur jatah','quota',b.id):''}${can('food.manage')&&b.breakfast_guests>consumed?action('Catat breakfast','redeem',b.id):''}</div>`];}))
            + '<h2>Riwayat penggunaan hari ini</h2>'+table(['Reservasi','Porsi','Waktu (UTC)','Catatan'],visits.map(v=>[h(bookings.find(b=>b.id===v.reservation_id)?.code||`#${v.reservation_id}`),v.quantity,h(v.created_at),h(v.notes||'Dalam jam layanan')]));
    }
    function pay(id) {
        const o=state.food_orders.find(o=>o.id===Number(id)); if(!o)return;
        formModal(`Pembayaran pesanan #${id}`,field('method','Metode pembayaran','select','Tunai',{choices:choice(['Tunai','Kartu','Transfer'])})+field('tendered','Uang diterima (Rp)','number',o.total,{min:o.total}),async v=>{const res=await request(`/api/restaurant-pay/${id}`,'PUT',v);return {...res,message:`${res.message} Kembalian ${money(res.change||0)}.`};},{info:`Total ${money(o.total)}. Pembayaran kartu/transfer dicatat setelah Anda memverifikasi penerimaan; tidak memproses payment gateway.`});
    }
    document.addEventListener('input',e=>{
        if(e.target.id==='menu-search'){query=e.target.value;$('#menu-products').innerHTML=products();}
        if(e.target.name==='discount' && $('#checkout-total')) $('#checkout-total').textContent=`Perkiraan total setelah diskon: ${money(totals(Number(e.target.value)||0).total)}`;
    });
    document.addEventListener('change',e=>{if(e.target.id!=='pos-outlet')return;const next=e.target.value;if(cart.size){e.target.value=outlet;window.SearchSelect.enhance();formModal('Pindah outlet','<p class="field wide">Keranjang belum diproses. Kosongkan keranjang untuk pindah outlet?</p>',async()=>{cart.clear();outlet=next;category='';query='';requestKey=crypto.randomUUID();return {message:'Outlet diubah dan keranjang dikosongkan.'};});}else{outlet=next;category='';query='';render();}});
    document.addEventListener('click',async e=>{
        const el=e.target.closest('[data-action^="rest-"]'); if(!el)return;
        const {action:a,id}=el.dataset;
        try {
            if(a==='rest-add'||a==='rest-minus') { const qty=(cart.get(Number(id))||0)+(a==='rest-add'?1:-1);if(qty>100)return;if(qty<=0)cart.delete(Number(id));else cart.set(Number(id),qty);requestKey=crypto.randomUUID();render(); }
            else if(a==='rest-category'){category=id;render();}
            else if(a==='rest-orders')location.hash='food';
            else if(a==='rest-pay')pay(id);
            else if(a==='rest-checkout') {
                const key=requestKey;
                formModal('Proses pesanan',field('order_type','Jenis pesanan','select',outlet==='restaurant'?'dine_in':'takeaway',{choices:outlet==='restaurant'?Object.entries(types).map(([value,label])=>({value,label})):[{value:'takeaway',label:'Layanan langsung / bayar terpisah'},{value:'room_service',label:'Tagihan kamar'}]})+(outlet==='restaurant'?field('table_label','Nomor meja','text','',{required:false,hint:'Wajib untuk makan di tempat.'}):'')+field('reservation_id','Tagihan kamar','select','',{required:false,choices:(state.pos_rooms||[]).map(b=>({value:b.id,label:`Kamar ${b.number} · ${b.code}`}))})+field('customer','Nama pelanggan','text','',{required:false})+(can('payments.manage')?field('discount','Diskon nominal (Rp)','number',0,{min:0,max:totals().subtotal}):'')+field('notes','Catatan dapur / layanan','textarea','',{required:false,wide:true}),async v=>{
                    if(busy)throw new Error('Pesanan sedang diproses.');busy=true;
                    try {const res=await request('/api/food_orders','POST',{...v,outlet,reservation_id:v.order_type==='room_service'?v.reservation_id:null,items:Array.from(cart,([food_item_id,quantity])=>({food_item_id,quantity})),request_key:key});cart.clear();requestKey=crypto.randomUUID();location.hash='food';return res;}finally{busy=false;}
                },{info:'Makan di tempat dan takeaway disimpan sebagai belum dibayar. Lanjutkan Bayar pada daftar pesanan. Layanan kamar langsung menambah tagihan tamu.',submitLabel:'Kirim ke dapur'});
                const preview=document.createElement('p');preview.id='checkout-total';preview.className='info';preview.textContent=`Perkiraan total setelah diskon: ${money(totals().total)}`;$('#operation-form .form-actions').before(preview);
            } else if(a==='rest-quota') {
                const b=state.restaurant_bookings.find(b=>b.id===Number(id));
                formModal(`Jatah breakfast · kamar ${b.number}`,field('guests','Porsi termasuk per hari','number',b.breakfast_guests,{min:0,max:b.adults,wide:true}),v=>request(`/api/breakfast/${id}`,'PUT',v));
            } else if(a==='rest-redeem') {
                const b=state.restaurant_bookings.find(b=>b.id===Number(id)), used=(state.breakfast_visits||[]).filter(v=>v.reservation_id===b.id).reduce((n,v)=>n+v.quantity,0),key=crypto.randomUUID();
                formModal(`Breakfast · kamar ${b.number}`,field('quantity','Porsi yang digunakan','number',1,{min:1,max:b.breakfast_guests-used})+field('notes','Catatan / alasan di luar jam','textarea','',{required:false,wide:true}),v=>request('/api/breakfast-visits','POST',{...v,reservation_id:b.id,request_key:key}),{info:`Sisa jatah hari ini ${b.breakfast_guests-used} porsi.`});
            } else if(a==='rest-settings') formModal('Pengaturan restoran & breakfast',field('restaurant_tax','Pajak restoran (%)','number',state.hotel.restaurant_tax,{min:0,max:30})+field('restaurant_service','Service (%)','number',state.hotel.restaurant_service,{min:0,max:30})+field('breakfast_start','Breakfast mulai','time',state.hotel.breakfast_start.slice(0,5))+field('breakfast_end','Breakfast selesai','time',state.hotel.breakfast_end.slice(0,5)),v=>request('/api/restaurant-settings','POST',v),{info:'Service dihitung setelah diskon. Pajak dihitung dari nilai setelah diskon ditambah service. Sesuaikan tarif dengan kebijakan hotel.'});
        } catch(error){toast(error.message,true);}
    });
    return {pos,orders,breakfast,outlet:()=>outlet};
})();
