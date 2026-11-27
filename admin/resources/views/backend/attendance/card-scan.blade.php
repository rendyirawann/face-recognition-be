@extends('backend.layout.app')
@section('title', 'Absensi Kartu')

@section('content')
    {{-- Mode kios, sama seperti Absensi Wajah: tanpa navbar/sidebar. --}}
    @push('stylesheets')
        <style>
            #kt_header, #kt_app_sidebar, #kt_sidebar_overlay, .footer, #kt_footer { display: none !important; }
            #kt_content_container { padding: 0 !important; }
            .content { padding: 10px !important; }
            .header-fixed[data-kt-sticky-header="on"] .wrapper { padding-top: 0 !important; }
            .jg-kios-keluar { position: fixed; right: 12px; bottom: 12px; z-index: 1050; opacity: .3; }
            .jg-kios-keluar:hover, .jg-kios-keluar:focus { opacity: 1; }

            #reader { width: 100%; max-width: 560px; margin: 0 auto; border-radius: 10px; overflow: hidden; background: #0e1526; min-height: 260px; }
            #reader video { border-radius: 10px; }
            .hasil { border-radius: 12px; padding: 22px; text-align: center; transition: background .2s; }
            .hasil.ok { background: #e8fff3; }
            .hasil.info { background: #fff8dd; }
            .hasil.gagal { background: #fff5f8; }
            .hasil .nama { font-size: 1.9rem; font-weight: 800; }
            #kodeManual { font-family: Consolas, monospace; letter-spacing: .1em; }
        </style>
    @endpush

    <a href="{{ route('dashboard') }}" class="btn btn-sm btn-light jg-kios-keluar">Keluar mode kios</a>

    <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mt-5 mb-5">
        <div>
            <h2 class="fw-bold text-gray-900 mb-1">Absensi Kartu</h2>
            <span class="text-muted fs-7">Tunjukkan kartu siswa ke kamera, atau pindai dengan alat scanner.</span>
        </div>
        <a href="{{ route('biometric.scan') }}" class="btn btn-sm btn-light">Absensi Wajah</a>
    </div>

    {{-- Belum dipasangkan: pairing dilakukan di halaman Absensi Wajah dan
         berlaku juga di sini (kunci localStorage yang sama). --}}
    <div id="belumPair" class="card card-flush d-none">
        <div class="card-body text-center py-10">
            <div class="fs-3 fw-bold mb-3">Tablet ini belum dipasangkan</div>
            <p class="text-muted">Pasangkan sekali di halaman Absensi Wajah dengan kode 8 digit dari menu Perangkat Tablet. Setelah itu halaman ini langsung bisa dipakai.</p>
            <a href="{{ route('biometric.scan') }}" class="btn btn-primary">Buka Absensi Wajah untuk pairing</a>
        </div>
    </div>

    <div id="panelScan" class="row g-5 d-none">
        <div class="col-lg-7">
            <div class="card card-flush">
                <div class="card-body">
                    <div id="reader"></div>
                    <div class="d-flex flex-wrap gap-2 mt-4 align-items-center">
                        <button id="btnKamera" class="btn btn-primary">Nyalakan kamera</button>
                        <button id="btnGanti" class="btn btn-light d-none">Ganti kamera</button>
                        <span id="status" class="text-muted fs-7 ms-2"></span>
                    </div>
                    <div class="separator my-5"></div>
                    <label class="form-label fs-7" for="kodeManual">Alat scanner / ketik kode kartu</label>
                    <form id="formManual" class="d-flex gap-2" autocomplete="off">
                        <input id="kodeManual" class="form-control" maxlength="64" placeholder="Arahkan scanner, lalu pindai">
                        <button class="btn btn-light-primary">Kirim</button>
                    </form>
                    <div class="text-muted fs-8 mt-2">Scanner USB/Bluetooth bekerja seperti keyboard: kolom ini selalu siap menerima hasil pindaian.</div>
                </div>
            </div>
        </div>
        <div class="col-lg-5">
            <div id="hasil" class="hasil info mb-5">
                <div class="nama" id="hasilNama">Siap</div>
                <div class="fs-5 mt-2" id="hasilPesan">Pindai kartu untuk absen.</div>
                <div class="text-muted mt-2" id="hasilMeta"></div>
            </div>
            <div class="card card-flush">
                <div class="card-header pt-4"><h3 class="card-title fs-6">Pindaian terakhir</h3></div>
                <div class="card-body pt-0">
                    <table class="table table-row-dashed fs-7 mb-0"><tbody id="riwayat"></tbody></table>
                </div>
            </div>
            <div class="text-muted fs-8 mt-4" id="infoPerangkat"></div>
        </div>
    </div>
@endsection

@push('scripts')
<script src="{{ asset('assets/vendor/kartu/html5-qrcode.min.js') }}"></script>
<script>
(function () {
    'use strict';
    const API = @json($apiBase);
    const STORE_KEY = 'jargon.kiosk.device';   // sama dengan Absensi Wajah
    const JEDA_KODE_SAMA_MS = 5000;            // kartu yang masih di depan kamera tidak dikirim berulang
    const el = (id) => document.getElementById(id);

    let device = null;
    try { device = JSON.parse(localStorage.getItem(STORE_KEY) || 'null'); } catch (e) { device = null; }

    if (!device || !device.device_token) {
        el('belumPair').classList.remove('d-none');
        return;
    }
    el('panelScan').classList.remove('d-none');
    el('infoPerangkat').textContent = device.device_name + ' (' + device.device_code + ') · ' + device.school_name;

    let sibuk = false, kodeTerakhir = '', waktuTerakhir = 0;

    function nonce() {
        const a = new Uint8Array(12);
        crypto.getRandomValues(a);
        return Array.from(a, b => b.toString(16).padStart(2, '0')).join('');
    }

    function tampil(kelas, nama, pesan, meta) {
        el('hasil').className = 'hasil ' + kelas + ' mb-5';
        el('hasilNama').textContent = nama;
        el('hasilPesan').textContent = pesan;
        el('hasilMeta').textContent = meta || '';
    }

    function catat(nama, pesan) {
        const tr = document.createElement('tr');
        const jam = new Date().toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
        tr.innerHTML = '<td class="text-muted">' + jam + '</td><td class="fw-bold"></td><td></td>';
        tr.children[1].textContent = nama;
        tr.children[2].textContent = pesan;
        el('riwayat').prepend(tr);
        while (el('riwayat').children.length > 12) el('riwayat').lastChild.remove();
    }

    async function kirim(kode) {
        kode = (kode || '').trim();
        if (!kode || sibuk) return;
        const sekarang = Date.now();
        if (kode === kodeTerakhir && sekarang - waktuTerakhir < JEDA_KODE_SAMA_MS) return;
        kodeTerakhir = kode; waktuTerakhir = sekarang;

        sibuk = true;
        tampil('info', 'Memeriksa…', kode);
        try {
            const res = await fetch(API + '/v1/kiosk/card', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json',
                           'Authorization': 'Device ' + device.device_token },
                body: JSON.stringify({ code: kode, client_time: new Date().toISOString(), nonce: nonce() })
            });
            let json = null;
            try { json = await res.json(); } catch (e) { /* bukan JSON */ }

            if (res.status === 401) {
                localStorage.removeItem(STORE_KEY);
                tampil('gagal', 'Perangkat tidak dikenal', 'Token tablet sudah dicabut. Pasangkan ulang di Absensi Wajah.');
                return;
            }
            if (!res.ok) {
                tampil('gagal', 'Gagal', (json && json.message) || ('Server menolak (kode ' + res.status + ')'));
                return;
            }
            const d = (json && json.data) || {};
            const s = d.student || null;
            const nama = s ? s.full_name : 'Tidak dikenal';
            const kelas = (d.action === 'checked_in' || d.action === 'checked_out') ? 'ok'
                        : (d.action === 'already_recorded' ? 'info' : 'gagal');
            tampil(kelas, nama, d.message || '', s ? (s.classroom_name || '') : '');
            catat(nama, d.message || '');
            if (kelas === 'ok' && navigator.vibrate) navigator.vibrate(120);
        } catch (e) {
            tampil('gagal', 'Tidak terhubung', 'Periksa koneksi internet tablet. ' + (e.message || ''));
        } finally {
            sibuk = false;
            el('kodeManual').value = '';
            el('kodeManual').focus();
        }
    }

    // --- Scanner keyboard / ketik manual ---
    el('formManual').addEventListener('submit', function (ev) { ev.preventDefault(); kirim(el('kodeManual').value); });
    el('kodeManual').focus();

    // --- Kamera ---
    let qr = null, kamera = [], idx = 0;
    const FORMAT = [Html5QrcodeSupportedFormats.QR_CODE, Html5QrcodeSupportedFormats.CODE_128];

    async function mulaiKamera() {
        el('status').textContent = 'Menyalakan kamera…';
        try {
            if (!kamera.length) {
                kamera = await Html5Qrcode.getCameras();
                // Kamera belakang lebih tajam untuk kode kecil; dipilih bila ada.
                const belakang = kamera.findIndex(c => /back|rear|belakang|environment/i.test(c.label));
                idx = belakang >= 0 ? belakang : 0;
            }
            if (!kamera.length) throw new Error('Tidak ada kamera.');
            if (!qr) qr = new Html5Qrcode('reader', { formatsToSupport: FORMAT, verbose: false });
            if (qr.isScanning) await qr.stop();
            await qr.start(kamera[idx].id, { fps: 10, qrbox: { width: 300, height: 180 } },
                           (teks) => kirim(teks), () => {});
            el('status').textContent = 'Kamera aktif: ' + (kamera[idx].label || 'kamera ' + (idx + 1));
            el('btnKamera').classList.add('d-none');
            el('btnGanti').classList.toggle('d-none', kamera.length < 2);
        } catch (e) {
            el('status').textContent = 'Kamera tidak dapat dipakai: ' + (e.message || e) + '. Alat scanner tetap bisa dipakai.';
        }
    }

    el('btnKamera').addEventListener('click', mulaiKamera);
    el('btnGanti').addEventListener('click', function () { idx = (idx + 1) % kamera.length; mulaiKamera(); });
    mulaiKamera();
})();
</script>
@endpush
