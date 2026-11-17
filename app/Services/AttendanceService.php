<?php

namespace App\Services;

use App\Support\HotelContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AttendanceService
{
    public function clock(HotelContext $context, int $employeeId, string $action, string $source = 'manual'): int
    {
        return DB::transaction(function () use ($context, $employeeId, $action, $source) {
            $employee = $context->table('employees')->where('id', $employeeId)->lockForUpdate()->first();
            abort_unless($employee, 404);
            if ($source === 'self') abort_unless($employee->user_id === auth()->id(), 403, 'Hubungan akun karyawan berubah. Muat ulang halaman.');
            $open = $context->table('attendances')->where('employee_id', $employeeId)->whereNotNull('clock_in')->whereNull('clock_out')->first();
            $now = now($context->hotel->timezone);
            if ($action === 'clockout') {
                if (!$open) $this->invalid('Tidak ada absensi masuk yang masih terbuka.');
                if ($now->format('Y-m-d H:i:s') <= $open->clock_in) $this->invalid('Waktu pulang harus setelah waktu masuk.');
                $context->table('attendances')->where('id', $open->id)->update(['clock_out' => $now->format('Y-m-d H:i:s'), 'updated_at' => now()]);
                $id = $open->id;
            } else {
                if (!$employee->active) $this->invalid('Karyawan sudah nonaktif.');
                if ($open || $context->table('attendances')->where('employee_id', $employeeId)->where('date', $now->toDateString())->exists()) {
                    $this->invalid('Sudah ada catatan kehadiran hari ini atau absensi masuk yang belum ditutup. Hubungi petugas untuk koreksi.');
                }
                $id = DB::table('attendances')->insertGetId([
                    'hotel_id' => $context->hotel->id, 'employee_id' => $employeeId,
                    'date' => $now->toDateString(), 'clock_in' => $now->format('Y-m-d H:i:s'),
                    'status' => 'present', 'source' => $source, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            $context->audit($action === 'clockout' ? 'Absen pulang' : 'Absen masuk', 'attendances', $id, ['source' => $source]);
            return $id;
        }, 3);
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['attendance' => $message]);
    }
}
