> Catatan historis versi awal. Audit dan bukti terbaru ada di [AUDIT-2026-11-16.md](AUDIT-2026-11-16.md).

# Pemeriksaan versi awal

Tanggal: 15 November 2026. Cakupan: aplikasi lokal dan draf visual, bukan persetujuan rilis SaaS produksi.

## Delivery Gate

- Hard Gate PASS: aplikasi dijalankan pada Laravel dan database lokal; penanda status memakai teks; tidak ada aset visual/angka pemasaran buatan; data seed diberi label contoh; navigasi menuju modul nyata; formulir memakai label; source tidak memuat tautan kosong atau em dash. Alur browser dan batas pemeriksaan dicatat di bawah.
- Purpose Gate PASS: keputusan warna, font lokal, kartu kamar, tabel, batas, dan dialog dijelaskan pada DESIGN.md. Warna di luar aksen utama dipakai untuk membedakan kondisi kamar, bukan dekorasi.
- Liveliness PASS: ENERGY 1 / RHYTHM 1 / MOTION 1 dideklarasikan sebagai draf tanpa panduan merek; papan kamar merupakan fokus operasional; aksen hijau menunjukkan tindakan utama; nomor kamar besar dan pita status mengulang pola identifikasi kamar.
- Craftsmanship PASS: layout diperiksa pada lebar default 988 px, tablet 768 px, dan ponsel 390 px. Lebar dokumen tidak melebihi viewport pada tampilan yang diperiksa. Menu ponsel dapat dibuka/ditutup dan sidebar tertutup tidak menerima fokus keyboard. Batas integrasi dan versi awal terlihat di produk dan README.

## Bukti pengujian aplikasi

- PHPUnit default: 14 tes, SQLite in-memory. Pengujian terpisah pada MariaDB lokal menggunakan koneksi mysql dan database test khusus.
- Skenario mencakup autentikasi, keanggotaan hotel, pembatasan server, data tamu/nominal yang tidak dikirim ke housekeeping, overlap dan batas tanggal reservasi, syarat kebersihan check-in, pembayaran sebagian/penuh, pencegahan pembayaran berlebih, check-out, biaya room service, urutan dapur, tiket pemeliharaan, shift malam, absensi lintas hari, persetujuan biaya, proteksi Owner, enkripsi kredensial, isolasi tagihan, dan provisi hotel tanpa seed.
- Syntax JavaScript diperiksa dengan `node --check public/app.js` dan `node --check public/invoice.js`. Blade dikompilasi dengan `php artisan view:cache`. Manifest Composer divalidasi.

## Bukti interaksi browser

- Login Owner: berhasil membuka papan kamar berisi data lokal. Logout dan login Housekeeping: hanya menu kamar, housekeeping, dan pemeliharaan; kartu tidak menampilkan nama tamu atau tarif.
- Reservasi baru: Tamu Uji Browser (contoh), kamar 204, tersimpan dan muncul di daftar.
- Check-in: dialog konfirmasi bekerja dan status berubah menjadi menginap.
- Bayar: nominal Rp950.000 tercatat pada reservasi uji. Check-out berhasil setelah pelunasan dan kamar menjadi menunggu dibersihkan.
- Kebersihan: kamar 204 diubah menjadi dibersihkan dan ditugaskan kepada Petugas Uji Browser (contoh).
- Pencarian nomor 204, filter lantai 3, dan filter dibersihkan: mengubah daftar sesuai pilihan.
- Pergantian hotel: cabang menampilkan 4 kamar tersendiri; kembali ke hotel utama menampilkan 24 kamar.
- Kalender: navigasi pekan berikut/sebelumnya dan Hari ini berfungsi; tanggal reservasi terlihat per kamar.
- Formulir karyawan, jadwal shift, absensi, pengeluaran, persediaan, pemeliharaan, pesanan, pengguna, dan peran: dialog dan field yang sesuai terbuka, Batal menutup dialog. Mutasi dan validasi utama diperiksa oleh pengujian server.
- Pengaturan izin: label dalam bahasa Indonesia dan checkbox per tindakan terlihat. Pengaturan hotel: nama, alamat, dan zona waktu tersedia pada dialog. Audit menampilkan perubahan alur uji.
- OTA: halaman serta formulir konfigurasi terbuka dan menjelaskan bahwa belum ada koneksi produksi.
- Panduan: langkah operasional per alur terlihat. Tagihan: nama tamu, rincian biaya, penerimaan, dan sisa Rp0 sesuai data uji.
- Tombol cetak memanggil dialog cetak bawaan. Dialog tersebut menghalangi kendali tab pada browser dalam aplikasi; tab pratinjau dibuka ulang. Penyimpanan file PDF dan printer fisik belum diverifikasi.
- Console halaman terakhir: tidak ada error/warning yang tercatat. Tidak dilakukan pengukuran Lighthouse atau Core Web Vitals.

## Kontras yang dihitung

Semua rasio di bawah memenuhi 4,5:1 untuk teks normal:

| Pasangan | Rasio |
| --- | --- |
| Teks utama / latar | 11,90 |
| Teks sekunder / putih | 5,74 |
| Putih / tombol hijau | 7,20 |
| Status tersedia | 6,28 |
| Status booking | 5,90 |
| Status menginap | 6,76 |
| Status kotor | 5,11 |
| Status dibersihkan | 5,33 |
| Status pemeriksaan | 5,76 |
| Status perbaikan | 6,09 |

Screenshot: `docs/screenshots/papan-kamar.jpg`. Matriks ini merupakan pemeriksaan fungsional dan visual awal; bukan audit keamanan menyeluruh, uji konkurensi multi-proses, atau uji beban produksi.
