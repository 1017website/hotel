# Restoran, POS, breakfast, dan tema Tabler

Tema menggunakan Tabler 1.7.5 dan Tabler Icons berlisensi MIT, disimpan lokal di public/vendor. Package-lock.json mengunci dependency dan lisensi upstream disertakan. Ikon bed, kalender, dapur, kasir, sunrise, karyawan, dan laporan dipilih berdasarkan fungsi operasional. Sidebar slate gelap memisahkan navigasi dari ruang kerja terang; primary mengikuti Tabler. Tombol ringkas dan select pencarian dipertahankan.

## Alur operasional

1. POS: klik beberapa menu, atur kuantitas, cari menu atau pilih kategori. Proses pesanan untuk makan di tempat (nomor meja wajib), takeaway, atau layanan kamar (reservasi sedang menginap wajib).
2. Pesanan dikirim sebagai antrean dapur. Urutan dapur: Baru, Diproses, Diantar/disajikan, Selesai. Status dapur dan pembayaran terpisah.
3. Makan di tempat/takeaway dibayar dari tombol Bayar. Pembayaran tunai mencatat uang diterima dan kembalian; kartu/transfer hanya pencatatan setelah kasir memverifikasi penerimaan, bukan transaksi gateway.
4. Layanan kamar langsung menambah tagihan reservasi dan tidak bisa ditagih lagi melalui pembayaran POS. Pendapatan kamar dihitung saat pembayaran reservasi; POS langsung dihitung saat kasir melunasi pesanan.
5. Diskon membutuhkan izin payments.manage. Harga diambil dari database; service dihitung setelah diskon, pajak dari nilai neto ditambah service. Tarif restoran dan jam breakfast dapat diatur per hotel oleh pengguna dengan hotel.manage.
6. Jatah breakfast dipilih saat reservasi atau diperbarui melalui Atur jatah. Default reservasi lama 0. Jumlah jatah tidak boleh melebihi jumlah tamu atau lebih kecil daripada penggunaan yang sudah tercatat.
7. Hak breakfast dimulai pagi setelah check-in sampai hari check-out. Petugas mencatat jumlah porsi; server mengunci reservasi sebelum menghitung sisa jatah. Kunjungan di luar jam layanan memerlukan izin reservasi dan alasan.
8. Breakfast berbayar di luar paket dapat dijual sebagai menu POS. Pembelian tersebut tidak otomatis membuat entitlement paket kamar.

Idempotency key pada POS dan kunjungan breakfast mencegah pengulangan efek saat permintaan dikirim ulang. Pembayaran POS mengunci pesanan; pesanan yang sudah lunas tidak dibayar dua kali. Semua relasi menu, reservasi, pesanan, dan struk dibatasi hotel aktif. Kredensial/peran lama tetap dipakai, tanpa memberi izin baru kepada pengguna yang tidak berhak.

## Bukti pemeriksaan

- 19 tes / 152 assertion lulus pada SQLite dan database MariaDB test melalui driver MySQL: multi-menu, kuantitas, harga server, diskon/pajak/service, pembayaran kurang, kembalian, idempotensi, tagihan kamar sekali, isolasi tenant, hak akses, batas breakfast, hari berikutnya, dan pengecualian di luar jam.
- Browser: POS menyimpan Nasi goreng 1 dan Kopi hitam 2, total Rp85.000, meja QA-Contoh-08; kasir menerima Rp100.000 dan struk menampilkan kembalian Rp15.000 serta status Lunas.
- Browser: reservasi contoh CONTOH-1 diatur dua porsi breakfast, kemudian dicatat satu porsi dengan alasan uji di luar jam layanan. Sisa hari ini satu porsi terlihat di tabel dan riwayat.
- Browser: pencarian menu “nasi” memfilter katalog. Keranjang mendukung beberapa menu dan kontrol jumlah. Ikon lokal terlihat pada navigasi dan katalog.
- Lebar ponsel 390 px: lebar dokumen 375 px; katalog dua kolom dan keranjang berada di bawah. Tabel breakfast memiliki wadah gulir sendiri. Tablet 768 px memakai katalog dua kolom dan keranjang di bawah; desktop menempatkan keranjang di sebelah katalog.
- Struk diakses melalui route berotorisasi; menyimpan PDF/printer fisik tidak diuji otomatis. Tidak ada integrasi payment gateway, printer thermal langsung, inventory resep, split bill, atau refund restoran pada versi ini.

## Delivery Gate

- Hard Gate PASS: tema dan ikon resmi memiliki lisensi; data pengujian ditandai contoh; kontrol POS diuji sampai struk, breakfast sampai saldo porsi, select tetap searchable, tabel berada di wadah responsif. Tidak ada klaim pembayaran gateway atau integrasi printer.
- Purpose Gate PASS: Tabler diterapkan sesuai permintaan pemilik. Ikon memberi petunjuk fungsi; warna status menunjukkan keadaan pembayaran/dapur/kamar. Tidak memakai gambar produk atau statistik buatan.
- Liveliness PASS: ENERGY 1 / RHYTHM 1 / MOTION 1; fokus POS berada pada katalog dan keranjang, pola card/badge/typography berasal dari tema pilihan. Arah perubahan dicatat di DESIGN.md.
- Craftsmanship PASS: pengujian backend, syntax JavaScript, kompilasi Blade, dan pemeriksaan browser dilakukan; alur pembayaran dan hak breakfast punya validasi server serta transaksi.

Sumber: https://github.com/tabler/tabler dan https://github.com/tabler/tabler-icons.
