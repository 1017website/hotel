# Pembaruan front desk dan operasional

Pembaruan 15–16 November 2026 meliputi:

- Papan kamar per lantai, pencarian lintas lantai, 24 kartu per halaman, kalender rentang reservasi dengan header tetap, dan contoh gedung 20 lantai/184 kamar.
- Identitas tamu KTP/Paspor/SIM saat check-in, rincian terenkripsi, empat karakter terakhir pada daftar dan unggahan dokumen privat opsional (JPG/PNG/PDF maksimal 3 MB).
- Deposit terpisah dari pendapatan: terima, pakai untuk tagihan, kembali. Checkout/pembatalan menolak saldo jaminan yang belum diselesaikan. Refund pembayaran kamar, pindah dan perpanjang kamar tersedia.
- Absensi roster, status kehadiran, koreksi beralasan, jam kerja, shift lintas malam dan pengajuan/persetujuan izin/cuti.
- Siklus linen bersih/kotor/laundry, mutasi amenities per kamar, POS outlet spa/fasilitas, booking publik tanpa gateway, serta jurnal operasional basis kas.
- Profil akun, ganti password dan logout di menu kanan navbar. Panduan aplikasi diperbarui.

## Instalasi lama

Cadangkan database, `storage/app/private` dan `APP_KEY` sebelum migrasi. Kunci yang sama diperlukan untuk membaca kembali rincian/dokumen terenkripsi.

```powershell
php artisan migrate
php artisan ledger:sync
```

Jangan memakai `migrate:fresh` pada database operasional. Sinkronisasi jurnal historis aman diulang. Migrasi 000003–000005 sudah diterapkan pada database lokal tanpa reset.

Izin baru:

- `guests.identity`: membaca nomor/dokumen lengkap, dengan dependensi akses reservasi/kamar. Tindakan membaca dicatat pada audit.
- `finance.manage`: mengelola akun, jurnal penyesuaian dan sinkronisasi historis, dengan dependensi `finance.view`.

Owner otomatis menerima semua izin. Peran khusus perlu diberi izin sesuai kebutuhan. `hotel.manage` tidak lagi mengizinkan penyesuaian jurnal.

Untuk transaksi dari integrasi internal, kirim `request_key` UUID pada pembayaran/mutasi stok serta `X-Hotel-ID` sesuai layar aktif. Formulir aplikasi sudah mengirim keduanya. Header konteks tidak menggantikan pemeriksaan hak akses server.

## Mencoba gedung besar

```powershell
php scripts/demo-building.php
```

Script khusus lokal menambahkan **Hotel Contoh 20 Lantai** secara idempotent: lantai pertama 32 kamar, 19 lantai lain masing-masing delapan. Pilih hotel tersebut, cari lantai 20 atau cari nomor kamar lintas lantai. Halaman booking contoh telah diaktifkan hanya pada hotel demo tersebut di localhost untuk pengujian; hotel utama tetap mengikuti pengaturannya sendiri.

Uji browser sebelumnya: deposit contoh Rp300.000 diterima, Rp200.000 dipakai, Rp100.000 kembali; identitas paspor fiktif dicatat dan tamu check-in. Booking website contoh menghasilkan reservasi sumber Website pada PMS. Tidak ada pembayaran/gateway atau identitas nyata yang dikirim.

Status terkini, hasil tes, cakupan dan pekerjaan terbuka tersedia pada [audit modul](AUDIT-2026-11-16.md) dan [enam komponen utama](COMPONENT-STATUS.md).
