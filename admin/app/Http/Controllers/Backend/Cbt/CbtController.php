<?php

namespace App\Http\Controllers\Backend\Cbt;

use App\Http\Controllers\Controller;

/**
 * CBT/TKA — Computer Based Test & Tes Kemampuan Akademik.
 *
 * Modulnya belum dibangun. Halaman ini sengaja ada lebih dulu supaya
 * menunya dapat dipasang dan hak aksesnya diuji sekarang, bukan setelah
 * seluruh modul selesai.
 */
class CbtController extends Controller
{
    public function index()
    {
        return view('backend.cbt.index');
    }
}
