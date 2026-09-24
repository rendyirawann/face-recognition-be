<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Menolak dashboard web untuk peran yang memang hanya dilayani aplikasi HP.
 *
 * KENAPA PERLU MIDDLEWARE, BUKAN SEKADAR IZIN
 *
 * Rute `/admin/dashboard` sengaja terbuka untuk SEMUA peran yang sudah login —
 * lihat komentarnya di routes/web.php. Akibatnya akun siswa dan orang tua bisa
 * masuk dashboard walau satu pun izin halaman web tidak mereka miliki: menu
 * lain memang tertutup 403, tetapi halaman dashboard-nya sendiri terbuka
 * penuh. Diuji pada 24 September 2026: `siswa.uji` login lalu menerima
 * HTTP 200 di /admin/dashboard.
 *
 * Menambahkan `can:` pada rute dashboard tidak menyelesaikannya — halaman itu
 * memang harus terbuka untuk kepala sekolah, guru, TU, sekolah, capdis, dinas,
 * dan superadmin. Yang perlu ditolak adalah PERAN-nya, dan itu urusan
 * middleware.
 *
 * Daftar perannya eksplisit, bukan diturunkan dari izin. Menyimpulkan "boleh
 * web" dari ada-tidaknya izin tertentu akan diam-diam membuka dashboard begitu
 * satu izin baru diberikan ke peran siswa di kemudian hari.
 */
class HanyaAplikasiSeluler
{
    /** Peran yang dilayani aplikasi HP saja. */
    public const PERAN_SELULER = ['siswa', 'orang_tua'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();

        if ($user && $user->hasAnyRole(self::PERAN_SELULER)) {
            $pesan = 'Akun siswa dan orang tua hanya dapat masuk lewat aplikasi Jargon GO di ponsel.';

            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            if ($request->expectsJson()) {
                return response()->json(['status' => 'error', 'message' => $pesan], 403);
            }

            return redirect()->route('login')->withErrors(['email' => $pesan]);
        }

        return $next($request);
    }
}
