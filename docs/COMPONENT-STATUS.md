# Status enam komponen hotel

Status implementasi lokal setelah pembaruan 15–16 November 2026. Kolom batas menunjukkan pekerjaan yang masih diperlukan sebelum produk produksi lengkap.

| Komponen | Yang berjalan | Batas saat ini |
| --- | --- | --- |
| PMS | Reservasi, kalender per lantai, check-in dengan identitas, check-out, pembayaran/refund kamar, deposit, pindah/perpanjang kamar dengan pemeriksaan konflik. | Grup booking, rate plan/musiman, pajak akomodasi dan arsip transaksi skala besar. |
| Channel Manager | Konfigurasi per hotel, mapping dan penyimpanan kredensial terenkripsi. | **Belum terhubung.** Pemilik belum memiliki akses mitra/API. Konektor resmi, webhook, retry/reconciliation dan uji penyedia belum tersedia. Polling internal 30 detik bukan sinkronisasi OTA. |
| Online Booking Engine | Halaman publik per hotel, tanggal/kapasitas, ketersediaan, harga dihitung server, konfirmasi langsung ke PMS, tautan untuk website hotel. Tipe/harga/fasilitas sama dikelompokkan agar tidak menampilkan ratusan kartu. | Pembayaran online, rate plan, pembatalan mandiri tamu dan widget iframe khusus. |
| Housekeeping | Status kebersihan, penugasan, kamar siap huni, maintenance, stok minimum, mutasi amenities per kamar dan siklus linen bersih/kotor/laundry. | Mutasi dilakukan petugas; konsumsi otomatis berdasarkan resep/kebijakan kamar dan procurement belum ada. |
| POS | Restoran multi-menu, spa/fasilitas sebagai outlet layanan, bayar langsung atau tagihan kamar, antrean, pajak/service per hotel, struk, diskon berizin dan breakfast harian. | Split bill, refund restoran, thermal langsung, jadwal janji spa; persentase pajak/service masih satu konfigurasi bersama per hotel. |
| Akuntansi & keuangan | Invoice, pembayaran/refund kamar, deposit sebagai kewajiban, persetujuan biaya, daftar akun, jurnal otomatis debit/kredit, buku besar, kas harian, neraca saldo dan ringkasan posisi basis kas; jurnal penyesuaian. | Akrual/piutang, rekonsiliasi bank/OTA, pelaporan pajak, penguncian periode dan tutup kas per shift belum otomatis. |

Tambahan lintas modul: profil/password/logout pada navbar, peran per hotel, izin identitas terpisah, absensi mandiri per akun, impor rekap mesin lewat CSV dengan pratinjau dan deteksi duplikasi, roster absensi/koreksi/izin/cuti, panduan aplikasi, tema Tabler pastel dan font Plus Jakarta Sans lokal.

Lihat [catatan pembaruan](FRONTDESK-UPGRADE.md) untuk bukti uji dan batas keamanan/operasional. Tidak ada klaim koneksi OTA aktif, audit keamanan lengkap, atau kesiapan SaaS produksi.

Pembaruan absensi terbaru dan template: [EMPLOYEE-ATTENDANCE.md](EMPLOYEE-ATTENDANCE.md). Mesin fisik belum dipilih atau dihubungkan secara langsung.
