<?php

namespace App\Services;

use App\Support\HotelContext;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AttendanceImportService
{
    public const HEADER = ['kode_mesin', 'tanggal_kerja', 'jam_masuk', 'jam_pulang'];

    public function read(UploadedFile $file): array
    {
        if (strtolower($file->getClientOriginalExtension()) !== 'csv') $this->invalid('Gunakan file CSV UTF-8 sesuai template. File Excel perlu diekspor menjadi CSV terlebih dahulu.');
        $bytes = file_get_contents($file->getRealPath());
        if (!preg_match('//u', $bytes)) $this->invalid('Encoding file harus UTF-8. Simpan ulang sebagai CSV UTF-8.');
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $bytes);
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $content);
        rewind($stream);
        $line = fgets($stream);
        $delimiter = str_contains($line ?: '', ';') ? ';' : ',';
        rewind($stream);
        $header = fgetcsv($stream, 0, $delimiter, '"', '');
        if (!$header || array_map(fn ($x) => strtolower(trim($x)), $header) !== self::HEADER) {
            fclose($stream);
            $this->invalid('Header harus: '.implode(',', self::HEADER).'. Gunakan template yang disediakan.');
        }
        $rows = [];
        $number = 1;
        while (($cells = fgetcsv($stream, 0, $delimiter, '"', '')) !== false) {
            $number++;
            if (count(array_filter($cells, fn ($value) => trim($value ?? '') !== '')) === 0) continue;
            if (count($rows) >= 1000) { fclose($stream); $this->invalid('Maksimal 1.000 baris per impor. Pecah file menjadi beberapa bagian.'); }
            $rows[] = ['line' => $number, 'cells' => array_map(fn ($x) => trim($x ?? ''), $cells)];
        }
        fclose($stream);
        if (!$rows) $this->invalid('File belum berisi data absensi. Isi baris di bawah header terlebih dahulu.');
        return ['rows' => $rows, 'hash' => hash('sha256', $bytes), 'filename' => mb_substr(basename($file->getClientOriginalName()), 0, 200)];
    }

    public function plan(HotelContext $context, array $input): array
    {
        $codes = array_unique(array_map(fn ($r) => strtoupper($r['cells'][0] ?? ''), $input['rows']));
        $codes = array_values(array_filter($codes, fn ($code) => preg_match('/^[A-Z0-9._-]{1,40}$/', $code)));
        $employees = $context->table('employees')->whereIn('attendance_code', $codes)->get()->keyBy('attendance_code');
        $dates = array_values(array_filter(array_map(fn ($r) => $r['cells'][1] ?? '', $input['rows']), fn ($date) => preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) && checkdate((int)substr($date,5,2),(int)substr($date,8,2),(int)substr($date,0,4))));
        $minimum = $dates ? min($dates) : now($context->hotel->timezone)->toDateString();
        $maximum = $dates ? max($dates) : $minimum;
        $records = $context->table('attendances')->whereIn('employee_id', $employees->pluck('id'))->where(function ($query) use ($minimum, $maximum) {
            $query->whereBetween('date', [$minimum, $maximum])->orWhere(fn ($q) => $q->where('clock_in', '<=', $maximum.' 23:59:59')->where(fn ($q) => $q->whereNull('clock_out')->orWhere('clock_out', '>=', $minimum.' 00:00:00')));
            // Include later records that an imported open clock could overlap.
            $query->orWhere('date', '>', $maximum);
        })->get();
        $now = now($context->hotel->timezone)->format('Y-m-d H:i:s');
        $rows = [];
        $seen = [];
        foreach ($input['rows'] as $inputRow) {
            $cells = $inputRow['cells'];
            $row = ['line' => $inputRow['line'], 'code' => strtoupper($cells[0] ?? ''), 'employee' => '', 'employee_id' => null, 'date' => $cells[1] ?? '', 'clock_in' => $cells[2] ?? '', 'clock_out' => $cells[3] ?? '', 'status' => 'error', 'reason' => '', 'record_id' => null];
            try {
                if (count($cells) !== 4) throw new \InvalidArgumentException('Harus ada tepat empat kolom.');
                if (!preg_match('/^[A-Z0-9._-]{1,40}$/', $row['code'])) {
                    $row['code'] = mb_substr($row['code'], 0, 80);
                    throw new \InvalidArgumentException('ID mesin harus 1–40 karakter: huruf, angka, titik, garis bawah atau tanda minus.');
                }
                $employee = $employees[$row['code']] ?? null;
                if (!$employee) throw new \InvalidArgumentException('ID mesin belum dipetakan pada karyawan hotel ini.');
                $row['employee'] = $employee->name;
                $row['employee_id'] = $employee->id;
                if (!$employee->active) throw new \InvalidArgumentException('Karyawan nonaktif. Periksa dengan petugas sebelum mengimpor.');
                $row['clock_in'] = $this->timestamp($row['clock_in'], $context->hotel->timezone);
                $row['clock_out'] = $row['clock_out'] === '' ? null : $this->timestamp($row['clock_out'], $context->hotel->timezone);
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $row['date']) || substr($row['clock_in'], 0, 10) !== $row['date']) throw new \InvalidArgumentException('Tanggal kerja harus sama dengan tanggal jam masuk (YYYY-MM-DD).');
                if ($row['clock_in'] > $now || ($row['clock_out'] && $row['clock_out'] > $now)) throw new \InvalidArgumentException('Waktu absensi tidak boleh di masa depan.');
                if ($row['clock_out'] && $row['clock_out'] <= $row['clock_in']) throw new \InvalidArgumentException('Jam pulang harus setelah jam masuk; gunakan tanggal esok hari untuk shift malam.');
                $key = $employee->id.'/'.$row['date'];
                $values = [$row['clock_in'], $row['clock_out']];
                if (isset($seen[$key])) {
                    if ($seen[$key] !== $values) throw new \InvalidArgumentException('Ada dua catatan berbeda untuk karyawan dan tanggal kerja yang sama dalam file.');
                    $row['status'] = 'skip';
                    $row['reason'] = 'Baris identik di dalam file; dilewati.';
                } else {
                    $seen[$key] = $values;
                    $old = $records->first(fn ($record) => $record->employee_id === $employee->id && $record->date === $row['date']);
                    if (!$old) {
                        $row['status'] = 'new';
                        $row['reason'] = 'Catatan baru.';
                    } elseif ($old->status === 'present' && $old->clock_in === $row['clock_in'] && ($old->clock_out === $row['clock_out'] || $row['clock_out'] === null)) {
                        $row['status'] = 'skip';
                        $row['reason'] = 'Sudah tercatat; tidak ditimpa.';
                    } elseif ($old->source === 'fingerprint' && $old->status === 'present' && $old->clock_in === $row['clock_in'] && !$old->clock_out && $row['clock_out']) {
                        $row['status'] = 'complete';
                        $row['record_id'] = $old->id;
                        $row['reason'] = 'Melengkapi jam pulang dari impor mesin sebelumnya.';
                    } else {
                        throw new \InvalidArgumentException('Bertentangan dengan catatan yang sudah ada. Gunakan koreksi beralasan; impor tidak menimpa absensi manual/izin.');
                    }
                }
            } catch (\InvalidArgumentException $error) {
                $row['status'] = 'error';
                $row['reason'] = $error->getMessage();
            }
            $rows[] = $row;
        }
        // Check the resulting timeline, including completions in this file, before any write.
        foreach ($employees as $employee) {
            $changes = array_keys(array_filter($rows, fn ($row) => $row['employee_id'] === $employee->id && in_array($row['status'], ['new', 'complete'])));
            $replaced = array_column(array_intersect_key($rows, array_flip($changes)), 'record_id');
            $intervals = $records->filter(fn ($record) => $record->employee_id === $employee->id && $record->clock_in && !in_array($record->id, $replaced))->map(fn ($record) => ['in' => $record->clock_in, 'out' => $record->clock_out, 'row' => null])->values()->all();
            foreach ($changes as $index) $intervals[] = ['in' => $rows[$index]['clock_in'], 'out' => $rows[$index]['clock_out'], 'row' => $index];
            usort($intervals, fn ($a, $b) => strcmp($a['in'], $b['in']));
            for ($i = 0; $i < count($intervals); $i++) {
                for ($j = $i + 1; $j < count($intervals); $j++) {
                    if ($intervals[$i]['out'] && $intervals[$j]['in'] >= $intervals[$i]['out']) break;
                    foreach ([$intervals[$i]['row'], $intervals[$j]['row']] as $index) if ($index !== null) {
                        $rows[$index]['status'] = 'error';
                        $rows[$index]['reason'] = 'Waktu bertabrakan atau ada absensi masuk lain yang belum ditutup.';
                    }
                }
            }
        }
        $counts = array_fill_keys(['new', 'complete', 'skip', 'error'], 0);
        foreach ($rows as $row) $counts[$row['status']]++;
        return ['rows' => $rows, 'counts' => $counts, 'valid' => $counts['error'] === 0];
    }

    public function targetsHash(array $plan): string
    {
        return hash('sha256', json_encode(array_map(fn ($row) => [$row['employee_id'], $row['code'], $row['date'], $row['clock_in'], $row['clock_out']], $plan['rows'])));
    }

    private function timestamp(string $value, string $timezone): string
    {
        $format = strlen($value) === 16 ? 'Y-m-d H:i' : 'Y-m-d H:i:s';
        try {
            $parsed = Carbon::createFromFormat('!'.$format, $value, $timezone);
            if (!$parsed || $parsed->format($format) !== $value) throw new \RuntimeException;
            return $parsed->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            throw new \InvalidArgumentException('Format jam harus YYYY-MM-DD HH:MM:SS, misalnya 2026-11-15 07:00:00.');
        }
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['file' => $message]);
    }
}
