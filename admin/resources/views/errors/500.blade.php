@include('errors._kerangka', [
    'kode' => 500,
    'judul' => 'Terjadi kesalahan pada sistem',
    'pesan' => 'Ini kesalahan di sisi kami, bukan pada yang Anda lakukan. '
        .'Kejadiannya sudah tercatat di log server.',
    'saran' => [
        'Coba muat ulang halaman ini sekali lagi.',
        'Bila terus berulang, catat jam kejadian dan apa yang sedang Anda '
            .'kerjakan, lalu sampaikan kepada operator Dinas — keduanya '
            .'yang paling menentukan cepat-lambatnya ditemukan.',
    ],
])
