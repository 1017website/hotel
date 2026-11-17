<?php

return [
    'required' => ':attribute wajib diisi.', 'present' => ':attribute harus dikirim.',
    'string' => ':attribute harus berupa teks.', 'integer' => ':attribute harus berupa bilangan bulat.',
    'email' => 'Format :attribute tidak valid.', 'date' => ':attribute harus berupa tanggal yang valid.',
    'date_format' => 'Format :attribute tidak sesuai.', 'after' => ':attribute harus setelah :date.',
    'after_or_equal' => ':attribute harus pada atau setelah :date.',
    'unique' => ':attribute sudah digunakan.', 'exists' => ':attribute tidak tersedia pada hotel ini.',
    'in' => 'Pilihan :attribute tidak valid.', 'array' => ':attribute harus berupa daftar.',
    'boolean' => 'Nilai :attribute tidak valid.', 'json' => ':attribute harus berupa JSON yang valid.',
    'current_password'=>'Password saat ini tidak sesuai.','confirmed'=>'Konfirmasi :attribute tidak sama.','different'=>':attribute harus berbeda dari :other.','regex'=>'Format :attribute tidak sesuai.','required_if'=>':attribute wajib diisi untuk pilihan ini.','lte'=>['numeric'=>':attribute tidak boleh melebihi :value.'],
    'min' => ['numeric' => ':attribute minimal :min.', 'string' => ':attribute minimal :min karakter.', 'array' => ':attribute minimal :min pilihan.'],
    'max' => ['numeric' => ':attribute maksimal :max.', 'string' => ':attribute maksimal :max karakter.', 'array' => ':attribute maksimal :max pilihan.'],
    'attributes' => [
        'guest' => 'Nama tamu', 'room_id' => 'Kamar', 'arrival' => 'Tanggal check-in', 'departure' => 'Tanggal check-out',
        'adults' => 'Jumlah tamu', 'name' => 'Nama', 'email' => 'Email', 'password' => 'Kata sandi',
        'rate' => 'Tarif', 'number' => 'Nomor kamar', 'capacity' => 'Kapasitas', 'amount' => 'Nominal',
        'employee_id' => 'Karyawan', 'role_id' => 'Peran', 'starts_at' => 'Waktu mulai', 'ends_at' => 'Waktu selesai',
        'quantity' => 'Jumlah', 'description' => 'Keterangan', 'permissions' => 'Daftar izin',
    ],
];
