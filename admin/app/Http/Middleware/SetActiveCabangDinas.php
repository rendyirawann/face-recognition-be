<?php

namespace App\Http\Middleware;

use App\Support\Tenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Menetapkan Cabang Dinas aktif dari parameter `capdis_id`.
 *
 * Dikerjakan sebagai middleware, bukan di tiap controller, karena
 * `Tenant::selectableSchools()` dan penyaring halaman membacanya SEBELUM
 * controller sempat berjalan. Menaruhnya di controller berarti satu
 * halaman menyaring dengan wilayah lama sementara pemilihnya sudah
 * menampilkan wilayah baru.
 *
 * Nilainya divalidasi di `Tenant::currentCabangDinasId()`: akun yang tidak
 * berhak memilih wilayah tetap terkunci ke wilayahnya sendiri, berapa pun
 * yang dikirim lewat URL.
 */
class SetActiveCabangDinas
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->has('capdis_id')) {
            Tenant::currentCabangDinasId((string) $request->query('capdis_id'));
        }

        return $next($request);
    }
}
