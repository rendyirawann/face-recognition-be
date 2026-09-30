@include('errors._kerangka', [
    'kode' => 403,
    'judul' => 'Anda tidak berhak membuka halaman ini',
    'pesan' => 'Akun Anda tidak memiliki izin untuk bagian ini. Ini bukan '
        .'kerusakan — pembatasannya memang disengaja.',
    'saran' => [
        'Data sekolah lain hanya dapat dibuka oleh akun yang mencakupinya.',
        'Bila Anda memang memerlukannya, mintakan izin kepada Superadmin '
            .'atau Admin Dinas melalui menu Peran & Izin.',
    ],
])
