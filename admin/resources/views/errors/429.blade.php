@include('errors._kerangka', [
    'kode' => 429,
    'judul' => 'Terlalu banyak permintaan',
    'pesan' => 'Permintaan dari akun Anda terlalu sering dalam waktu '
        .'singkat, sehingga ditahan sementara.',
    'saran' => [
        'Tunggu sekitar satu menit, lalu coba lagi.',
        'Bila Anda tidak merasa melakukan apa pun yang tidak wajar, '
            .'kemungkinan ada halaman lain yang terbuka dan menyegar '
            .'sendiri — tutup tab yang tidak dipakai.',
    ],
])
