# Kontrak pengembangan distribusi hotel

Status versi ini: konfigurasi koneksi per hotel, penyimpanan kredensial terenkripsi, pemetaan JSON, dan status belum terhubung/menunggu verifikasi. Tidak ada HTTP request ke penyedia, callback, polling, atau sinkronisasi produksi yang dijalankan. Menyimpan konfigurasi tidak membuktikan koneksi aktif.

Sumber kontrak resmi:

- Agoda Supply: https://developer.agoda.com/supply
- Agoda property mapping: https://partnerhub.agoda.com/how-do-i-manage-my-channel-manager-connection/
- Traveloka Connectivity: https://developer.connect.traveloka.com/

Supply/connectivity API diperlukan untuk mengelola hotel; API affiliate pencarian hotel bukan penggantinya. Jalur dapat langsung ke OTA atau lewat channel manager sesuai perjanjian komersial dan izin API yang diperoleh. Tidak ada mitra yang dianggap sudah menyetujui aplikasi ini.

Sebelum konektor diaktifkan:

1. Tentukan mitra, cakupan API, sertifikasi, batas permintaan, dan lingkungan pengujian.
2. Petakan properti, tipe kamar, rate plan, tanggal inventori, dan kebijakan. Inventori OTA umumnya berbasis tipe kamar, sementara versi awal lokal mengalokasikan kamar tertentu; adaptor harus menghitung agregat yang benar.
3. Implementasikan pengiriman ARI (availability, rates, inventory) melalui queue dengan transactional outbox, retry bertahap, dan pemisahan antrean per hotel/provider.
4. Implementasikan autentikasi callback sesuai kontrak penyedia. Verifikasi tanda tangan atau token dan cegah replay. Jangan membuat endpoint callback publik tanpa autentikasi.
5. Gunakan kunci unik booking eksternal per hotel dan penyedia. Terapkan idempotensi pada seluruh penerimaan/perubahan/pembatalan.
6. Semua penulis reservasi harus memakai protokol penguncian kamar yang sama dengan ReservationService sebelum memeriksa overlap. Perubahan inventori dan outbox harus dalam transaksi yang sama.
7. Simpan acknowledgment penyedia, status error, waktu keberhasilan, dan log tanpa rahasia. Jalankan rekonsiliasi berkala terhadap data OTA untuk menemukan event yang terlewat.
8. Uji perubahan tanggal, pembatalan, overbooking, koneksi putus, pesan berulang, pesan tidak berurutan, perbedaan zona waktu, komisi, dan settlement.
9. Lulus pengujian mitra dan pilot hotel sebelum menandai connected.

Tidak menjanjikan sinkronisasi instan atau bebas overbooking. Sistem eksternal memiliki jeda dan gangguan. Antarmuka operasional harus menampilkan kegagalan dan umur data secara jujur.
