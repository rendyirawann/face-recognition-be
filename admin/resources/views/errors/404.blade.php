@include('errors._kerangka', [
    'kode' => 404,
    'judul' => 'Halaman tidak ditemukan',
    'pesan' => 'Alamat yang Anda buka tidak ada. Mungkin menunya sudah '
        .'dipindahkan, atau tautan yang Anda simpan sudah tidak berlaku.',
    'saran' => [
        'Periksa kembali alamatnya bila Anda mengetiknya sendiri.',
        'Data yang dicari mungkin sudah dihapus oleh operator lain.',
        'Gunakan menu di dashboard untuk menuju halaman yang benar.',
    ],
])
