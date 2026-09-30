{{--
    Mode pemeliharaan.

    Memakai kerangka yang sama dengan halaman galat lain. Versi sebelumnya
    membaca `$appSettings` — satu kueri basis data — padahal halaman ini
    justru sering tampil ketika basis datanya sedang dimatikan untuk
    pemeliharaan, sehingga halaman pemberitahuannya sendiri ikut gagal.
--}}
@include('errors._kerangka', [
    'kode' => 503,
    'judul' => 'Sistem sedang dalam pemeliharaan',
    'pesan' => 'Jargon GO sedang diperbarui. Layanan akan kembali normal '
        .'dalam waktu dekat, dan data Anda tidak terpengaruh.',
    'saran' => [
        'Absensi yang sudah tercatat tetap tersimpan.',
        'Tablet kios menyimpan absensi secara lokal dan mengirimkannya '
            .'menyusul setelah layanan kembali.',
        'Coba muat ulang halaman ini beberapa menit lagi.',
    ],
])
