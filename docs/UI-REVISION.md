# Revisi tampilan: kontrol ringkas dan pencarian select

Permintaan pemilik: tombol lebih kecil, seluruh select bisa dicari, nomor menu dihapus, primary diganti dari hijau, dan layout ponsel/tablet diperiksa kembali.

## Perubahan

- Tombol aksi desktop minimum 34 px, aksi dalam tabel minimum 30 px. Teks, padding, dan jarak dipadatkan. Ponsel memakai minimum 38 px dan perangkat coarse pointer 40 px untuk menjaga kemudahan sentuh.
- Primary biru #3555ad; warna aktif menu, identitas, info, login, dan kontrol mengikuti palet yang sama. Hijau tetap memiliki arti operasional untuk kamar siap/bersih.
- Menu menampilkan nama tanpa indeks angka.
- Semua select diubah menjadi combobox pencarian lokal. Nilai tetap tersimpan pada select asli, sehingga FormData, validasi required, dan event perubahan yang sudah ada tetap bekerja.
- Dropdown mendukung pencarian tanpa jaringan, pilihan klik, ArrowUp/ArrowDown, Home/End, Enter, Escape, Tab, hasil kosong, nilai default, serta status disabled. Pilihan ditempatkan ke atas jika ruang bawah tidak cukup.
- Tombol pada judul papan kamar disatukan dalam satu kelompok; kartu, filter, formulir, dan ringkasan menyesuaikan ruang layar. Formulir ponsel satu kolom.

## Pemeriksaan browser

- Filter status: cari “dibersihkan”, pilih “Dibersihkan”, daftar menjadi tiga kamar. Pencarian yang tidak cocok menampilkan pesan kosong. Escape menutup tanpa mengganti nilai yang telah dipilih.
- Filter lantai: cari “3” dan Enter menampilkan delapan kamar lantai 3. Pilihan “Semua” mengembalikan daftar.
- Form reservasi: pencarian kamar “308” bekerja. Submit tanpa kamar membuka kontrol yang wajib diisi dan menandai invalid, tanpa error fokus kontrol tersembunyi. Escape dari dropdown tidak menutup dialog reservasi.
- Reservasi contoh dengan sumber “Telepon” disimpan melalui FormData dan terlihat dengan sumber yang benar di tabel, lalu dibatalkan setelah pengujian.
- Pencarian hotel “Cabang” membuka hotel contoh cabang dengan empat kamar. Pemilihan “Utama” mengembalikan data hotel utama.
- Papan kamar memiliki 3 select asli dan 3 kontrol pencarian, tanpa indeks menu. Kontrol dalam dialog juga ditingkatkan saat dialog dibuka.
- Lebar 320/360/390 px: dokumen 305/345/375 px, tanpa overflow halaman. Kartu memakai satu kolom pada layar terkecil dan dua kolom pada 390 px.
- Lebar tablet 768 px: dokumen 753 px dan kartu dua kolom. Halaman reservasi, kalender, restoran, laporan, dan peran diperiksa; tabel bergulir di dalam wadahnya.
- Lebar tablet 1024 px: dokumen 1009 px dan kartu tiga kolom. Lebar default browser 988 px juga diperiksa secara visual.
- Console akhir tidak memuat error/warning. Viewport sementara dikembalikan ke ukuran default.

## Delivery Gate

- Hard Gate PASS: kontrol pencarian diuji melalui browser, required tetap bekerja, sumber tidak memakai CDN, label/role combobox tersedia, hasil kosong ditangani, dan perubahan hanya mencakup UI yang diminta. Ukuran tombol mengikuti permintaan eksplisit pemilik; target sentuh tetap lebih besar daripada desktop.
- Purpose Gate PASS: palet biru memenuhi revisi pemilik; warna status tetap menunjukkan kondisi operasional; panah CSS pada select menunjukkan keberadaan pilihan, bukan dekorasi.
- Liveliness PASS: arah DESIGN.md diperbarui, ENERGY 1 / RHYTHM 1 / MOTION 1 dipertahankan, papan kamar tetap menjadi fokus dengan nomor kamar dan pita status.
- Craftsmanship PASS: pencarian/keyboard/validasi/submit diuji; lima ukuran ponsel/tablet diperiksa; 14 tes aplikasi dengan 121 assertion lulus; kedua file JavaScript lulus syntax check dan Blade berhasil dikompilasi.

Kontras terhitung: putih pada primary 6,88:1; teks menu aktif 8,15:1; teks utama 11,95:1; teks sekunder 5,54:1; batas input 3,07:1. Tidak dilakukan klaim skor Lighthouse atau pengujian pembaca layar penuh.

Screenshot tersimpan di `docs/screenshots/papan-kamar-compact.jpg`, `docs/screenshots/mobile-compact.jpg`, dan `docs/screenshots/tablet-compact.jpg`.
