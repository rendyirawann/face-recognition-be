<?php

namespace App\Http\Controllers\Backend\Attendance;

use App\Http\Controllers\Controller;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\View\View;

/**
 * Absen dengan kartu siswa (barcode batang / QR) di tablet kios.
 *
 * Halaman ini hanya pembaca: kode dikirim langsung dari peramban ke
 * POST /v1/kiosk/card dengan device token yang sama dengan Absensi Wajah
 * (pairing disimpan di localStorage dengan kunci yang sama), jadi satu
 * tablet cukup dipasangkan sekali untuk kedua cara absen.
 */
class CardScanController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            'auth',
            // Izin yang sama dengan Absensi Wajah: keduanya MENCATAT kehadiran.
            new Middleware('can:operate_face_kiosk'),
        ];
    }

    public function scan(): View
    {
        return view('backend.attendance.card-scan', [
            'apiBase' => rtrim((string) config('services.absensi_api.public_url'), '/'),
        ]);
    }
}
