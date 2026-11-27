@extends('backend.layout.app')
@section('title', 'Cetak Kartu Siswa')

@section('content')
<div class="row g-5">
    <div class="col-lg-5">
        <div class="card card-flush">
            <div class="card-header pt-5">
                <h3 class="card-title fw-bold">Cetak kartu siswa</h3>
            </div>
            <div class="card-body pt-2">
                <p class="text-muted fs-7">
                    Kartu dipakai untuk absen dengan memindai kode di tablet kios (menu
                    <strong>Absensi Kartu</strong>). Ukurannya sama dengan KTP
                    (85,6 &times; 54 mm), 10 kartu per lembar A4, urut per kelas lalu nama.
                </p>

                <form method="GET" action="{{ route('student-cards.print') }}" target="_blank" id="formCetak">
                    @if ($schools->isNotEmpty())
                        <div class="mb-4">
                            <label class="form-label" for="school_id">Sekolah</label>
                            <select id="school_id" name="school_id" class="form-select"
                                    onchange="window.location='{{ route('student-cards.index') }}?school_id='+this.value">
                                <option value="">Pilih sekolah</option>
                                @foreach ($schools as $s)
                                    <option value="{{ $s->id }}" @selected($s->id === $schoolId)>{{ $s->name }}</option>
                                @endforeach
                            </select>
                        </div>
                    @elseif ($schoolId)
                        <input type="hidden" name="school_id" value="{{ $schoolId }}">
                    @endif

                    @if ($schoolId)
                        <div class="mb-4">
                            <label class="form-label" for="classroom_id">Kelas</label>
                            <select id="classroom_id" name="classroom_id" class="form-select">
                                <option value="">Semua kelas ({{ $classrooms->sum('aktif_count') }} siswa)</option>
                                @foreach ($classrooms as $c)
                                    <option value="{{ $c->id }}">{{ $c->name }} ({{ $c->aktif_count }} siswa)</option>
                                @endforeach
                            </select>
                        </div>

                        <div class="mb-5">
                            <label class="form-label d-block">Jenis kode</label>
                            @foreach ($formats as $key => $label)
                                <label class="form-check form-check-inline form-check-custom me-6">
                                    <input class="form-check-input" type="radio" name="format" id="format_{{ $key }}"
                                           value="{{ $key }}" @checked($loop->first)>
                                    <span class="form-check-label">{{ $label }}</span>
                                </label>
                            @endforeach
                            <div class="text-muted fs-8 mt-2">
                                QR terbaca dari kamera tablet dengan mudah, meski kartunya agak lecek.
                                Barcode batang cocok untuk alat pemindai (scanner) genggam.
                            </div>
                        </div>

                        <button class="btn btn-primary w-100">
                            <i class="ki-duotone ki-printer fs-3"><span class="path1"></span><span class="path2"></span><span class="path3"></span><span class="path4"></span><span class="path5"></span></i>
                            Buka halaman cetak
                        </button>
                    @else
                        <div class="text-muted">Pilih sekolah lebih dulu.</div>
                    @endif
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-7">
        <div class="card card-flush">
            <div class="card-header pt-5"><h3 class="card-title fw-bold">Yang perlu diketahui</h3></div>
            <div class="card-body pt-2 fs-7">
                <ul class="ps-5 mb-0">
                    <li class="mb-2">Isi kode adalah <strong>kode acak</strong>, bukan NISN, jadi tidak bisa dibuat sendiri oleh siswa.</li>
                    <li class="mb-2">Kartu hilang? Buka detail siswa &rarr; <strong>Terbitkan ulang kartu</strong>. Kartu lama langsung tidak berlaku.</li>
                    <li class="mb-2">Saat mencetak, pilih kertas <strong>A4</strong>, skala <strong>100%</strong> (bukan "sesuaikan halaman"), agar ukuran kartu tepat.</li>
                    <li>Kartu bisa dipinjamkan atau difotokopi. Absen kartu tidak membuktikan siapa yang berdiri di depan tablet; absen wajah membuktikannya.</li>
                </ul>
            </div>
        </div>
    </div>
</div>
@endsection
