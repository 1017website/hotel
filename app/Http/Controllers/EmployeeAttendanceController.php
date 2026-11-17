<?php

namespace App\Http\Controllers;

use App\Services\AttendanceService;
use App\Support\HotelContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class EmployeeAttendanceController extends Controller
{
    public function mine(Request $request)
    {
        $context = new HotelContext($request);
        $context->authorize('attendance.self');
        $employee = $context->table('employees')->where('user_id', $request->user()->id)->first();
        $now = now($context->hotel->timezone);
        return response()->json([
            'employee' => $employee ? collect($employee)->only(['id', 'name', 'department', 'position', 'active'])->all() : null,
            'today' => $now->toDateString(), 'now' => $now->format('Y-m-d H:i:s'),
            'records' => $employee ? $context->table('attendances')->where('employee_id', $employee->id)->orderByDesc('date')->limit(31)->get() : [],
            'open' => $employee ? $context->table('attendances')->where('employee_id', $employee->id)->whereNotNull('clock_in')->whereNull('clock_out')->first() : null,
            'shifts' => $employee ? $context->table('shifts')->where('employee_id', $employee->id)->where('ends_at', '>=', $now->copy()->startOfDay()->format('Y-m-d H:i:s'))->where('starts_at', '<', $now->copy()->addDays(7)->endOfDay()->format('Y-m-d H:i:s'))->orderBy('starts_at')->get() : [],
        ])->header('Cache-Control', 'no-store');
    }

    public function clock(Request $request)
    {
        $context = new HotelContext($request);
        $context->authorize('attendance.self');
        $data = $request->validate(['action' => ['required', Rule::in(['clockin', 'clockout'])], 'employee_id' => 'prohibited', 'clock_in' => 'prohibited', 'clock_out' => 'prohibited', 'date' => 'prohibited']);
        $employee = $context->table('employees')->where('user_id', $request->user()->id)->first();
        abort_unless($employee, 422, 'Akun belum dihubungkan dengan data karyawan pada hotel ini.');
        (new AttendanceService)->clock($context, $employee->id, $data['action'], 'self');
        return response()->json(['message' => $data['action'] === 'clockout' ? 'Absen pulang Anda tercatat.' : 'Absen masuk Anda tercatat.']);
    }

    public function mapping(Request $request, int $id)
    {
        $context = new HotelContext($request);
        $context->authorize('employees.manage');
        if(is_string($request->input('attendance_code')))$request->merge(['attendance_code'=>strtoupper(trim($request->input('attendance_code')))]);
        if ($request->has('user_id')) $context->authorize('users.manage');
        $data = $request->validate([
            'attendance_code' => ['present', 'nullable', 'string', 'regex:/^[A-Za-z0-9._-]{1,40}$/', Rule::unique('employees')->where('hotel_id', $context->hotel->id)->ignore($id)],
            'user_id' => ['sometimes', 'nullable', 'integer', Rule::exists('hotel_user', 'user_id')->where('hotel_id', $context->hotel->id), Rule::unique('employees')->where('hotel_id', $context->hotel->id)->ignore($id)],
        ]);
        DB::transaction(function () use ($context, $data, $id) {
            // Serialize mappings per hotel so two employees cannot claim one login or device ID.
            DB::table('hotels')->where('id', $context->hotel->id)->lockForUpdate()->first();
            $employee = $context->table('employees')->where('id', $id)->lockForUpdate()->first();
            abort_unless($employee, 404);
            foreach (['user_id', 'attendance_code'] as $field) {
                if (isset($data[$field]) && $data[$field] !== '' && $context->table('employees')->where('id', '!=', $id)->where($field, $data[$field])->exists()) {
                    throw \Illuminate\Validation\ValidationException::withMessages([$field => 'Sudah terhubung ke karyawan lain.']);
                }
            }
            $context->table('employees')->where('id', $id)->update([...$data, 'updated_at' => now()]);
            $context->audit('Akun / ID mesin karyawan diatur', 'employees', $id);
        }, 3);
        return response()->json(['message' => 'Hubungan akun dan ID mesin disimpan.']);
    }
}
