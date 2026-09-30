{{--
    Kerangka halaman galat.

    BERDIRI SENDIRI, DAN ITU DISENGAJA

    Halaman galat ditampilkan justru ketika ada yang rusak. Ia karena itu
    tidak boleh bergantung pada apa pun yang mungkin ikut rusak:

      * TIDAK memanggil basis data. Halaman 503 sebelumnya membaca
        `$appSettings`, yang berarti satu kueri — dan bila yang mati justru
        basis datanya, halaman galatnya sendiri ikut gagal dan pengguna
        mendapat jejak tumpukan Laravel.

      * TIDAK memuat bundel CSS Metronic (1,2 MB) maupun gambar latar.
        Seluruh gayanya inline. Halaman ini juga muncul saat jaringan
        sedang buruk, dan menuntut megabyte aset untuk memberi tahu bahwa
        ada yang salah adalah kebalikan dari membantu.

      * TIDAK memakai layout dashboard, yang memuat sidebar, menu, dan
        pemeriksaan izin — semuanya jalur yang bisa jadi penyebab galatnya.

    Variabel: $kode, $judul, $pesan, $saran (opsional, array of string)
--}}
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $kode }} — {{ $judul }} | Jargon GO</title>
    <style>
        :root {
            --navy:   #131A2E;
            --surface:#1B2540;
            --biru:   #4C7DF0;
            --teal:   #2DD4BF;
            --terang: #E8ECF8;
            --redup:  #8A93AE;
            --garis:  #2B3348;
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
            background: var(--navy);
            color: var(--terang);
            font-family: 'Plus Jakarta Sans', ui-sans-serif, system-ui,
                         -apple-system, 'Segoe UI', Roboto, sans-serif;
            line-height: 1.55;
            -webkit-font-smoothing: antialiased;
        }

        .kotak {
            width: 100%;
            max-width: 520px;
            background: var(--surface);
            border: 1px solid var(--garis);
            border-radius: 18px;
            padding: 40px 36px;
            box-shadow: 0 24px 60px -30px rgba(0, 0, 0, .8);
        }

        /* Lambang digambar dengan CSS, bukan berkas gambar: berkas berarti
           satu permintaan lagi yang bisa ikut gagal. */
        .mark {
            width: 52px; height: 52px;
            border-radius: 14px;
            background: var(--navy);
            border: 2px solid var(--biru);
            position: relative;
            margin-bottom: 26px;
        }
        .mark::after {
            content: '';
            position: absolute;
            left: 15px; top: 13px;
            width: 16px; height: 25px;
            border: solid var(--teal);
            border-width: 0 3px 3px 0;
            transform: rotate(45deg);
        }

        .kode {
            font-size: 12px;
            font-weight: 700;
            letter-spacing: .16em;
            text-transform: uppercase;
            color: var(--biru);
            margin: 0 0 10px;
        }

        h1 {
            font-size: 26px;
            font-weight: 800;
            letter-spacing: -.02em;
            margin: 0 0 14px;
            line-height: 1.2;
        }

        p { margin: 0 0 18px; color: #B9C1D8; font-size: 15px; }

        ul {
            margin: 0 0 26px;
            padding-left: 20px;
            color: var(--redup);
            font-size: 14px;
        }
        li { margin-bottom: 7px; }

        .aksi { display: flex; flex-wrap: wrap; gap: 10px; }

        a.tombol {
            display: inline-block;
            padding: 12px 22px;
            border-radius: 999px;
            font-size: 14px;
            font-weight: 700;
            text-decoration: none;
            transition: opacity .15s;
        }
        a.tombol:hover { opacity: .88; }
        .utama { background: var(--biru); color: #fff; }
        .biasa { background: transparent; color: var(--terang); border: 1px solid var(--garis); }

        .kaki {
            margin-top: 28px;
            padding-top: 18px;
            border-top: 1px solid var(--garis);
            font-size: 12px;
            color: var(--redup);
        }

        @media (max-width: 480px) {
            .kotak { padding: 30px 24px; }
            h1 { font-size: 22px; }
        }
    </style>
</head>
<body>
    <div class="kotak">
        <div class="mark" aria-hidden="true"></div>

        <p class="kode">Galat {{ $kode }}</p>
        <h1>{{ $judul }}</h1>
        <p>{{ $pesan }}</p>

        @if (! empty($saran))
            <ul>
                @foreach ($saran as $s)
                    <li>{{ $s }}</li>
                @endforeach
            </ul>
        @endif

        <div class="aksi">
            <a href="{{ url('/admin/dashboard') }}" class="tombol utama">Kembali ke Dashboard</a>
            <a href="javascript:history.back()" class="tombol biasa">Halaman sebelumnya</a>
        </div>

        <div class="kaki">
            Jargon GO &middot; Dinas Pendidikan Provinsi Sumatera Utara
        </div>
    </div>
</body>
</html>
