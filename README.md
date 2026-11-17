# TataInap

Aplikasi Laravel 12 + driver MySQL untuk operasional beberapa hotel. Tampilan berbahasa Indonesia memakai Blade, CSS, dan JavaScript lokal tanpa CDN atau build frontend wajib. Identitas visual tercatat di `DESIGN.md`.

## Jalankan lokal

Prasyarat: PHP 8.2+, Composer, MySQL 8+ atau MariaDB yang kompatibel, ekstensi PDO MySQL, mbstring, openssl, DOM, fileinfo, dan intl. Lingkungan yang telah diuji di komputer ini: PHP 8.2.29 dan MariaDB 10.4.22 dari XAMPP, menggunakan koneksi Laravel `mysql`.

```powershell
composer install
Copy-Item .env.example .env
php artisan key:generate
```

Buat database `hotel_manager_local` dengan charset utf8mb4, lalu sesuaikan kredensial MySQL di `.env`. Jangan menyalin `.env` apabila file lokal Anda sudah dikonfigurasi.

```powershell
php artisan migrate --seed
php artisan serve --host=127.0.0.1 --port=8000
```

Buka http://127.0.0.1:8000. Tidak perlu menjalankan Vite untuk halaman aplikasi ini. `composer dev` juga menjalankan server lokal.

## Akun contoh

Hanya untuk lokal. Seeder menolak berjalan di luar environment local/testing dan tidak menambah ulang apabila sudah ada hotel. Atur `TATAINAP_DEMO_LOGIN=true` pada instalasi demo bila ingin menampilkan akun contoh di halaman masuk.

| Peran | Email | Kata sandi |
| --- | --- | --- |
| Owner dua hotel contoh | owner@example.test | DemoHotel2026! |
| Housekeeping hotel utama | housekeeping@example.test | DemoHotel2026! |

Data awal memuat kamar berbagai status, reservasi manual, menu, karyawan, jadwal shift, dan persediaan. Data tersebut sengaja diberi nama Contoh. Reservasi bersumber Agoda pada seed bukan hasil integrasi aktif.

## Fitur yang berjalan

Pembaruan restoran: tema gratis Tabler dan ikon lokal, POS outlet restoran/spa/fasilitas dengan keranjang multi-menu, makan di tempat/takeaway/layanan kamar, pembayaran kasir dengan kembalian, struk, diskon berizin, pajak/service per hotel, serta breakfast dengan jatah per reservasi per hari dan pencatatan penggunaan. Buka `/#pos`, `/#food`, dan `/#breakfast`.

Hak breakfast ditentukan saat reservasi atau melalui Atur jatah. Reservasi lama dimigrasikan dengan jatah 0 agar tidak otomatis menjanjikan paket breakfast. Hak dimulai pagi setelah check-in sampai tanggal check-out; pengecualian di luar jam layanan memerlukan izin reservasi dan alasan. Breakfast tambahan berbayar dapat dicatat sebagai menu penjualan POS.

Tema dan ikon disimpan pada `public/vendor`, sehingga tidak memerlukan CDN pada runtime. Versi dependency dikunci di package-lock.json; lisensi MIT disertakan. `npm ci --ignore-scripts` memasang sumber paket apabila perlu memperbarui aset vendored. Dokumentasi tema: https://github.com/tabler/tabler.

- Login, logout, pembatasan percobaan login, sesi server, CSRF, dan pemilihan hotel berdasarkan keanggotaan.
- Papan kamar hari ini: tersedia, terbooking, menginap, kotor, dibersihkan, perlu diperiksa, dan perbaikan. Filter lantai/status/pencarian, fasilitas kamar, tarif, dan penugasan kebersihan.
- Reservasi manual dengan total dihitung server; pemeriksaan kapasitas dan overlap, penguncian kamar dalam transaksi, check-in dengan identitas tamu, check-out, pemindahan/perpanjangan kamar, dan pembatalan setelah seluruh pembayaran/deposit dikembalikan.
- Pembayaran sebagian/penuh dengan pengecekan sisa tagihan dan tagihan yang bisa dicetak melalui browser.
- Kalender reservasi mingguan; reservasi aktif kalender tidak dibatasi daftar 250 transaksi terbaru.
- Menu dan room service, penambahan otomatis biaya pesanan ke tagihan kamar, urutan status dapur sampai tersaji.
- Data karyawan, aktivasi/nonaktivasi, jadwal shift termasuk lintas hari, pencegahan shift bertabrakan, pencatatan absensi masuk/pulang oleh petugas.
- Pengeluaran dengan persetujuan/penolakan, persediaan minimum dan penyesuaian stok, tiket pemeliharaan yang memblokir penjualan kamar.
- Jurnal debit/kredit otomatis, daftar akun, buku besar, kas harian, neraca saldo dan ringkasan posisi keuangan basis kas; refund pembayaran kamar. Deposit ditahan dicatat sebagai kewajiban.
- Peran khusus per hotel dengan izin tindakan, pembuatan pengguna, perubahan peran, proteksi peran Owner, dan jejak audit.
- Konfigurasi OTA per hotel dengan penyimpanan kredensial terenkripsi. Statusnya tetap belum terhubung/menunggu verifikasi.
- Booking langsung publik per hotel, pencarian ketersediaan/tarif dan konfirmasi ke PMS; pembayaran ditindaklanjuti hotel tanpa payment gateway.
- Deposit terima/pakai/kembali; data KTP/Paspor terenkripsi dan dokumen privat opsional, dengan izin membaca identitas terpisah.
- Absensi mandiri melalui akun yang terhubung, roster harian, koreksi dengan alasan, keterlambatan/durasi dan pengajuan izin/cuti. Impor rekap CSV mesin fingerprint dengan template, pratinjau, pemeriksaan konflik dan duplikasi. Siklus stok linen bersih/kotor/laundry serta mutasi amenities per kamar.
- Profil, ganti password dan logout melalui navbar; panduan penggunaan serta layout ponsel/tablet/desktop.
- Contoh gedung 20 lantai/184 kamar: panel lantai dapat dicari, pencarian kamar lintas lantai, 24 kartu per halaman, kalender per lantai dengan header tetap.

Hak akses diperiksa kembali oleh server, termasuk pada ID relasi yang dikirim pengguna. Setiap kueri operasional dibatasi hotel aktif. Pengguna tanpa izin reservasi tidak menerima identitas tamu di papan kamar. Housekeeping tidak menerima nominal kamar, data kas, atau akun pengguna.

## Deploy TataInap

Siapkan database MySQL/MariaDB kosong dan arahkan document root web server ke folder `public/`. Atur `.env` dari `.env.example` dengan `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL` HTTPS yang benar, `SESSION_SECURE_COOKIE=true`, serta kredensial database khusus aplikasi.

```bash
composer install --no-dev --optimize-autoloader
php artisan key:generate
php artisan migrate --force
php artisan saas:setup admin@domain-anda.example
php artisan optimize
```

Jalankan `key:generate` sekali pada instalasi baru. Untuk pembaruan, gunakan kunci yang sudah ada. Ganti email contoh dengan alamat email Super Admin yang Anda kelola. Perintah akan meminta nama dan kata sandi secara tersembunyi. Jalankan `php artisan saas:setup` tanpa email untuk menyiapkan katalog saja. Perintah aman dijalankan ulang karena paket yang sudah ada dipertahankan.

| Paket | Bulanan | Tahunan | Kamar | Akun termasuk pemilik |
| --- | ---: | ---: | ---: | ---: |
| Starter | Rp299.000 | Rp2.990.000 | 25 | 8 |
| Bisnis | Rp599.000 | Rp5.990.000 | 75 | 25 |
| Pro | Rp999.000 | Rp9.990.000 | 150 | 60 |

Paket berlaku per hotel. Harga tahunan setara 10 bulan, dan perbedaan paket ada pada batas kamar serta akun. Pengelola bisa mengubah harga dan batas di panel SaaS. Tagihan yang sudah terbit menyimpan harga dan batas saat diterbitkan.

Masuk ke `/saas/login`. Di **Pengaturan layanan**, isi instruksi pembayaran dan kontak dukungan, lalu aktifkan pendaftaran mandiri. Tarif bisa dilihat di `/register` saat pendaftaran ditutup. Pengelola memverifikasi pembayaran secara manual dari panel, lalu akses operasional hotel aktif. Isi SMTP dan uji pengiriman tautan pemulihan kata sandi sebelum menerima pelanggan.

Gunakan database baru dan jangan jalankan seeder hotel contoh di server. Untuk meningkatkan instalasi yang sudah ada, cadangkan database dan ikuti bagian **Pembaruan database lama**.

## Menambahkan hotel nyata

Jalankan migrasi tanpa seeder dan buat Owner melalui administrator server:

```powershell
php artisan migrate --force
php artisan hotel:create "Nama Hotel" "owner@hotel.example"
```

Perintah meminta nama pemilik dan kata sandi minimal 12 karakter secara tersembunyi. Tidak membuat data contoh. Pemilik mengisi identitas, kamar, peran, dan pengguna setelah masuk. Email yang sudah ada tidak diambil alih otomatis.

## Pembaruan database lama

Jangan gunakan migrate:fresh pada database operasional. Untuk instalasi yang sudah ada:

```powershell
php artisan migrate
php artisan ledger:sync
```

Migrasi mempertahankan reservasi lama dan membentuk snapshot tarif. Sinkronisasi jurnal aman diulang. Cadangkan database, storage privat, dan APP_KEY; dokumen identitas terenkripsi memerlukan kunci yang sama untuk dibaca kembali. Owner otomatis mendapat izin `guests.identity` dan `finance.manage`; berikan kepada peran staf yang memang perlu membaca identitas lengkap atau mengelola jurnal. Untuk menambah hotel uji lokal 20 lantai, jalankan `php scripts/demo-building.php`; script menolak environment produksi.

Rincian pembaruan: [docs/FRONTDESK-UPGRADE.md](docs/FRONTDESK-UPGRADE.md). Audit terbaru per modul beserta perbaikan, hasil uji, dan pekerjaan terbuka: [docs/AUDIT-2026-11-16.md](docs/AUDIT-2026-11-16.md).

## Pengujian

```powershell
php artisan test
node --check public/app.js
php artisan view:cache
```

Pengujian default memakai SQLite in-memory dan tidak mengubah database lokal. Untuk menguji mesin MySQL/MariaDB, buat database khusus `hotel_manager_codex_test_20261115`, periksa konfigurasi `phpunit.mysql.xml`, kemudian:

```powershell
vendor\bin\phpunit.bat -c phpunit.mysql.xml
```

**Database pada konfigurasi MySQL test boleh dihapus seluruh tabelnya oleh RefreshDatabase. Jangan menunjuk database operasional.** Kredensial server mengikuti `.env`; nama database test ditimpa oleh konfigurasi PHPUnit. Pengujian mencakup otorisasi, isolasi hotel, overlap reservasi, penagihan, room service, pemeliharaan, shift malam, absensi, persetujuan biaya, dan penyimpanan rahasia OTA.

Panduan absensi mandiri, izin, dan format mesin: [docs/EMPLOYEE-ATTENDANCE.md](docs/EMPLOYEE-ATTENDANCE.md). Absensi mandiri memakai waktu server; pembatasan lokasi/Wi-Fi belum diterapkan. Impor memakai rekap CSV, belum koneksi langsung ke mesin.

## Batas versi awal

Versi awal mendukung billing langganan dengan verifikasi pembayaran manual dan pemulihan kata sandi melalui email. Fitur yang masih terbuka meliputi konektor OTA produksi, payment gateway, refund POS restoran, tarif musiman/rate plan, pajak/service charge akomodasi, payroll/lembur, procurement supplier, tutup kas per shift, MFA, dan backup terjadwal. Jurnal masih basis kas; akrual, rekonsiliasi bank/OTA, serta laporan pajak belum otomatis. Daftar transaksi memuat maksimum 250 catatan terbaru; pencarian arsip dan pagination server perlu ditambahkan sebelum volume tinggi. Papan kamar diperbarui dengan polling 30 detik, bukan WebSocket. Detail status enam komponen utama ada pada docs/COMPONENT-STATUS.md.

Alur konektor resmi dan prasyarat integrasi dijelaskan di [docs/OTA-INTEGRATION.md](docs/OTA-INTEGRATION.md). Tidak ada koneksi eksternal yang diaktifkan atau pendaftaran mitra yang dilakukan.

Sebelum produksi: tentukan kebijakan tarif/refund, lengkapi kebutuhan operasional di atas, uji beban dan konkurensi pada MySQL produksi, gunakan akun database khusus, HTTPS, `APP_DEBUG=false`, cookie secure, backup database beserta APP_KEY, serta uji pemulihan. Jangan gunakan akun contoh. Dokumen ini tidak mengklaim audit keamanan atau sertifikasi kepatuhan.
