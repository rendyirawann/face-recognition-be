<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<title>Kartu Siswa — {{ $school->name }}</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
    /* Ukuran kartu ID-1 (KTP/ATM): 85,6 x 54 mm. 2 kolom x 5 baris = 10 per A4. */
    @page { size: A4; margin: 10mm; }
    * { box-sizing: border-box; }
    body { margin: 0; font-family: "Segoe UI", Roboto, Arial, sans-serif; color: #10223f; background: #e9edf3; }
    .alat { position: sticky; top: 0; z-index: 5; display: flex; flex-wrap: wrap; gap: 12px; align-items: center;
            padding: 12px 16px; background: #10223f; color: #fff; font-size: 14px; }
    .alat button { padding: 8px 16px; border: 0; border-radius: 6px; background: #3b82f6; color: #fff; font-weight: 600; cursor: pointer; }
    .alat .peringatan { color: #fde68a; }
    .lembar { width: 190mm; margin: 12px auto; display: grid; grid-template-columns: repeat(2, 85.6mm);
              justify-content: space-between; row-gap: 4mm; }
    .kartu { width: 85.6mm; height: 54mm; border: 0.3mm solid #b8c2d3; border-radius: 3mm; background: #fff;
             overflow: hidden; display: flex; flex-direction: column; break-inside: avoid; page-break-inside: avoid; }
    .kepala { background: #1f3864; color: #fff; padding: 2mm 3mm; font-size: 2.6mm; line-height: 1.25; }
    .kepala b { display: block; font-size: 3.1mm; letter-spacing: .02em; }
    .isi { flex: 1; display: flex; gap: 3mm; padding: 2.5mm 3mm; align-items: center; min-height: 0; }
    .data { flex: 1; min-width: 0; font-size: 2.7mm; line-height: 1.45; }
    .nama { font-size: 3.6mm; font-weight: 700; line-height: 1.2; margin-bottom: 1mm;
            display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
    .label { color: #5b6b84; }
    .qr { width: 27mm; height: 27mm; flex: none; overflow: hidden; }
    .qr img { display: block; width: 27mm; height: 27mm; }
    .bar { padding: 0 3mm 1.5mm; text-align: center; }
    .bar svg { width: 100%; height: 13mm; }
    .kode { font-family: Consolas, monospace; font-size: 2.6mm; letter-spacing: .08em; color: #33445f; }
    .kartu.fmt-barcode .isi { padding-bottom: 0; }
    @media print {
        body { background: #fff; }
        .alat { display: none; }
        .lembar { margin: 0; }
    }
</style>
</head>
<body>
<div class="alat">
    <strong>{{ $students->count() }} kartu</strong>
    <span>{{ $school->name }} &middot; {{ $format === 'qr' ? 'QR Code' : 'Barcode batang' }}</span>
    @if ($terpotong)
        <span class="peringatan">Hanya {{ $max }} kartu pertama yang ditampilkan. Cetak per kelas untuk sisanya.</span>
    @endif
    <span style="flex:1"></span>
    <span>Kertas A4, skala 100%</span>
    <button type="button" onclick="window.print()">Cetak</button>
</div>

@if ($students->isEmpty())
    <p style="text-align:center;padding:40px">Tidak ada siswa aktif untuk dicetak.</p>
@endif

<div class="lembar">
@foreach ($students as $s)
    <div class="kartu fmt-{{ $format }}">
        <div class="kepala">
            <b>KARTU SISWA</b>
            {{ $school->name }}
        </div>
        <div class="isi">
            <div class="data">
                <div class="nama">{{ $s->full_name }}</div>
                <div><span class="label">Kelas</span> {{ $s->classroom->name ?? '-' }}</div>
                <div><span class="label">NIS</span> {{ $s->nis ?: '-' }}</div>
                <div><span class="label">NISN</span> {{ $s->nisn ?: '-' }}</div>
                @if ($format === 'qr')
                    <div class="kode">{{ $s->card_code }}</div>
                @endif
            </div>
            @if ($format === 'qr')
                <div class="qr" data-kode="{{ $s->card_code }}"></div>
            @endif
        </div>
        @if ($format === 'barcode')
            <div class="bar"><svg class="bc" data-kode="{{ $s->card_code }}"></svg></div>
        @endif
    </div>
@endforeach
</div>

@if ($format === 'qr')
    <script src="{{ asset('assets/vendor/kartu/qrcode.min.js') }}"></script>
    <script>
        // qrcodejs menaruh <canvas> DAN <img> sekaligus di elemen tujuan, dan
        // keduanya ikut mengatur ukuran kartu. Digambar di wadah sementara,
        // lalu hanya satu <img> berukuran tetap yang masuk ke kartu.
        document.querySelectorAll('.qr').forEach(function (el) {
            var tmp = document.createElement('div');
            // Koreksi galat level M: tetap terbaca bila ~15% kode rusak/kotor.
            new QRCode(tmp, { text: el.dataset.kode, width: 256, height: 256, correctLevel: QRCode.CorrectLevel.M });
            var c = tmp.querySelector('canvas');
            var img = document.createElement('img');
            img.alt = el.dataset.kode;
            img.src = c ? c.toDataURL('image/png') : tmp.querySelector('img').src;
            el.appendChild(img);
        });
    </script>
@else
    <script src="{{ asset('assets/vendor/kartu/JsBarcode.all.min.js') }}"></script>
    <script>
        document.querySelectorAll('svg.bc').forEach(function (el) {
            JsBarcode(el, el.dataset.kode, {
                format: 'CODE128', displayValue: true, fontSize: 14, height: 60,
                margin: 0, font: 'Consolas, monospace', textMargin: 2
            });
        });
    </script>
@endif
</body>
</html>
