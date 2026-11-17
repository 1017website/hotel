<?php

namespace App\Http\Controllers;

use App\Services\AttendanceImportService;
use App\Support\HotelContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AttendanceImportController extends Controller
{
    public function template(Request $request)
    {
        (new HotelContext($request))->authorize('attendance.import');
        return response(implode(',', AttendanceImportService::HEADER)."\r\n")
            ->header('Content-Type', 'text/csv; charset=UTF-8')->header('Content-Disposition', 'attachment; filename="template-absensi-mesin.csv"')->header('Cache-Control', 'no-store');
    }

    public function history(Request $request)
    {
        $context = new HotelContext($request);
        $context->authorize('attendance.import');
        return response()->json(['batches' => $context->table('attendance_imports')->orderByDesc('id')->limit(30)->get()])->header('Cache-Control', 'no-store');
    }

    public function preview(Request $request, AttendanceImportService $service)
    {
        $context = new HotelContext($request);
        $context->authorize('attendance.import');
        $request->validate(['file' => 'required|file|max:2048']);
        $input = $service->read($request->file('file'));
        $plan = $service->plan($context, $input);
        $token = $plan['valid'] ? encrypt(['hotel' => $context->hotel->id, 'user' => $request->user()->id, 'hash' => $input['hash'], 'targets' => $service->targetsHash($plan), 'expires' => now()->addMinutes(15)->timestamp]) : null;
        return response()->json([...$plan, 'filename' => $input['filename'], 'token' => $token, 'already_imported' => $context->table('attendance_imports')->where('file_hash', $input['hash'])->exists()])->header('Cache-Control', 'no-store');
    }

    public function commit(Request $request, AttendanceImportService $service)
    {
        $context = new HotelContext($request);
        $context->authorize('attendance.import');
        $request->validate(['file' => 'required|file|max:2048', 'preview_token' => 'required|string']);
        $input = $service->read($request->file('file'));
        try { $token = decrypt($request->input('preview_token')); } catch (\Throwable) { $this->invalid('Pratinjau tidak valid. Unggah dan periksa file kembali.'); }
        if (!is_array($token) || ($token['hotel'] ?? null) !== $context->hotel->id || ($token['user'] ?? null) !== $request->user()->id || ($token['hash'] ?? null) !== $input['hash'] || ($token['expires'] ?? 0) < now()->timestamp) $this->invalid('Pratinjau berubah atau kedaluwarsa. Periksa file kembali sebelum impor.');
        return DB::transaction(function () use ($context, $input, $token, $service) {
            DB::table('hotels')->where('id', $context->hotel->id)->lockForUpdate()->first();
            if ($batch = $context->table('attendance_imports')->where('file_hash', $input['hash'])->first()) return response()->json(['message' => 'File yang sama sudah diimpor; tidak ada data yang digandakan.', 'batch' => $batch, 'already_imported' => true]);
            $codes = array_unique(array_map(fn ($r) => strtoupper($r['cells'][0] ?? ''), $input['rows']));
            $context->table('employees')->whereIn('attendance_code', $codes)->orderBy('id')->lockForUpdate()->get();
            $plan = $service->plan($context, $input);
            if (!$plan['valid']) $this->invalid('Data berubah atau masih bermasalah. Tidak ada baris yang disimpan. Buat pratinjau ulang.');
            if ($service->targetsHash($plan) !== $token['targets']) $this->invalid('Pemetaan karyawan berubah setelah pratinjau. Periksa file kembali.');
            $id = DB::table('attendance_imports')->insertGetId(['hotel_id' => $context->hotel->id, 'user_id' => auth()->id(), 'filename' => $input['filename'], 'file_hash' => $input['hash'], 'inserted' => $plan['counts']['new'], 'completed' => $plan['counts']['complete'], 'skipped' => $plan['counts']['skip'], 'created_at' => now(), 'updated_at' => now()]);
            foreach ($plan['rows'] as $row) {
                if ($row['status'] === 'new') DB::table('attendances')->insert([
                    'hotel_id' => $context->hotel->id, 'employee_id' => $row['employee_id'], 'date' => $row['date'],
                    'clock_in' => $row['clock_in'], 'clock_out' => $row['clock_out'], 'status' => 'present', 'source' => 'fingerprint', 'attendance_import_id' => $id, 'created_at' => now(), 'updated_at' => now(),
                ]);
                if ($row['status'] === 'complete') $context->table('attendances')->where('id', $row['record_id'])->update(['clock_out' => $row['clock_out'], 'attendance_import_id' => $id, 'updated_at' => now()]);
            }
            $context->audit('Absensi mesin diimpor', 'attendance_imports', $id, $plan['counts']);
            return response()->json(['message' => 'Impor selesai. Seluruh baris valid disimpan dalam satu transaksi.', 'batch' => $context->table('attendance_imports')->find($id)]);
        }, 3);
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['file' => $message]);
    }
}
