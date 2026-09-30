@include('errors._kerangka', [
    'kode' => 419,
    'judul' => 'Halaman kedaluwarsa, isian belum tersimpan',
    'pesan' => 'Formulir dibiarkan terbuka terlalu lama sehingga sesi '
        .'keamanannya berakhir. Ini pengaman, bukan kerusakan — tetapi '
        .'isian Anda memang belum tersimpan.',
    'saran' => [
        'Tekan "Halaman sebelumnya", lalu muat ulang dan isi kembali.',
        'Bila isiannya panjang, salin dulu teksnya sebelum memuat ulang.',
        'Ini juga muncul bila Anda login di tab lain dengan akun berbeda.',
    ],
])
