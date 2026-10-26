@extends('backend.layout.app')
@section('title', 'Detail Penempatan PKL')

@section('content')
    @include('backend.partials._flash')

    @php
        $sisa = $placement->sisa_hari;
        $berlaku = $placement->status === 'aktif'
            && ! $placement->starts_on->isFuture() && $sisa >= 0;
    @endphp

    <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mt-5 mb-6">
        <div>
            <h2 class="fw-bold text-gray-900 mb-1">{{ $placement->student->full_name ?? '—' }}</h2>
            <span class="text-muted fs-7">
                {{ $placement->place->name ?? '—' }} &middot;
                {{ $placement->starts_on->format('d/m/Y') }} &ndash; {{ $placement->ends_on->format('d/m/Y') }}
            </span>
        </div>
        <a href="{{ route('pkl.index', ['school_id' => $placement->school_id]) }}"
           class="btn btn-sm btn-light">Kembali</a>
    </div>

    <div class="row g-5">
        <div class="col-xl-4">
            <div class="card card-flush border border-gray-200">
                <div class="card-body p-5 fs-8">
                    <div class="mb-4">
                        <span class="text-muted d-block">Status</span>
                        @if ($placement->status !== 'aktif')
                            <span class="badge badge-light-secondary">{{ $placement->status }}</span>
                        @elseif ($berlaku)
                            <span class="badge badge-light-success">berlaku &middot; sisa {{ $sisa }} hari</span>
                        @elseif ($placement->starts_on->isFuture())
                            <span class="badge badge-light-info">akan datang</span>
                        @else
                            <span class="badge badge-light-warning">masa habis</span>
                        @endif
                    </div>

                    <div class="mb-4">
                        <span class="text-muted d-block">Alamat</span>
                        <span class="fw-semibold">{{ $placement->place->address ?? '—' }}</span>
                    </div>

                    <div class="mb-4">
                        <span class="text-muted d-block">Titik absen</span>
                        <span class="fw-semibold font-monospace">
                            {{ $placement->place->latitude ?? '' }}, {{ $placement->place->longitude ?? '' }}
                        </span>
                        <div class="text-muted">radius {{ $placement->place->radius_meters ?? '—' }} m</div>
                        @if ($placement->place)
                            <a class="fs-9" target="_blank" rel="noopener"
                               href="https://www.google.com/maps?q={{ $placement->place->latitude }},{{ $placement->place->longitude }}">
                                Buka di peta
                            </a>
                        @endif
                    </div>

                    <div class="mb-4">
                        <span class="text-muted d-block">Jam kerja</span>
                        <span class="fw-semibold">
                            {{ $placement->check_in_after ?? '—' }} s.d. {{ $placement->check_out_before ?? '—' }}
                        </span>
                    </div>

                    <div>
                        <span class="text-muted d-block">Pembimbing</span>
                        <span class="fw-semibold">{{ $placement->supervisor_name ?? '—' }}</span>
                        <div class="text-muted">{{ $placement->supervisor_phone }}</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-8">
            <div class="card card-flush border border-gray-200">
                <div class="card-header pt-5">
                    <h3 class="card-title fw-bold fs-5">Bukti Absensi</h3>
                    <div class="card-toolbar">
                        <span class="text-muted fs-8">{{ $proofs->total() }} bukti</span>
                    </div>
                </div>

                <div class="card-body pt-3">
                    @forelse ($proofs as $b)
                        @php $selisih = $b->selisih_jam; @endphp
                        <div class="d-flex gap-4 py-4 {{ ! $loop->last ? 'border-bottom border-gray-200' : '' }}">
                            {{-- Foto bukti.
                                 onerror WAJIB: berkas dapat hilang (volume
                                 dibuat ulang, dibersihkan berkala), dan ikon
                                 gambar rusak bawaan peramban terbaca sebagai
                                 sistem yang error, bukan sebagai berkas yang
                                 memang tidak ada lagi. --}}
                            <a href="{{ url('/admin/files/' . $b->photo_key) }}" target="_blank" rel="noopener">
                                <img src="{{ url('/admin/files/' . $b->photo_key) }}"
                                     alt="Bukti absen {{ $b->kind }}"
                                     width="96" height="96" loading="lazy"
                                     class="rounded object-fit-cover bg-light"
                                     onerror="this.onerror=null;this.replaceWith(Object.assign(document.createElement('div'),{className:'rounded bg-light-warning d-flex align-items-center justify-content-center text-warning fs-9 text-center p-2',style:'width:96px;height:96px',textContent:'Foto tidak tersedia'}))">
                            </a>

                            <div class="flex-grow-1">
                                <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                                    <span class="badge badge-light-{{ $b->kind === 'masuk' ? 'primary' : 'success' }}">
                                        {{ $b->kind }}
                                    </span>
                                    <span class="fw-bold text-gray-900">
                                        {{ $b->proof_date->format('d/m/Y') }}
                                    </span>
                                    <span class="text-muted fs-8">
                                        {{ $b->recorded_at->timezone(config('app.timezone'))->format('H:i') }}
                                    </span>
                                </div>

                                <div class="d-flex flex-wrap gap-2">
                                    <span class="badge badge-light fs-9">
                                        {{ number_format((float) $b->distance_meters, 0) }} m dari titik
                                    </span>
                                    @if ($b->accuracy_meters)
                                        <span class="badge badge-light fs-9">
                                            ketelitian GPS &plusmn;{{ number_format((float) $b->accuracy_meters, 0) }} m
                                        </span>
                                    @endif
                                    @if ($b->override_by)
                                        <span class="badge badge-light-warning fs-9">diterima manual</span>
                                    @endif
                                </div>

                                {{-- Selisih jam ponsel terhadap server.
                                     Bukan bukti kecurangan — zona waktu salah
                                     setel juga menghasilkannya — tetapi cukup
                                     menjadi alasan melihat baris ini lebih
                                     teliti. Ambang 5 menit supaya selisih
                                     jaringan biasa tidak ikut ditandai. --}}
                                @if ($selisih !== null && abs($selisih) > 300)
                                    <div class="text-warning fs-9 mt-2">
                                        Jam ponsel meleset {{ round(abs($selisih) / 60) }} menit dari jam server.
                                    </div>
                                @endif

                                <div class="text-muted fs-9 mt-2 font-monospace">
                                    {{ $b->latitude }}, {{ $b->longitude }}
                                    <a class="ms-2" target="_blank" rel="noopener"
                                       href="https://www.google.com/maps?q={{ $b->latitude }},{{ $b->longitude }}">peta</a>
                                </div>
                            </div>
                        </div>
                    @empty
                        <p class="text-muted fs-7 py-8 mb-0 text-center">
                            Belum ada bukti absensi. Bukti muncul di sini setelah siswa
                            menekan tombol absen dari aplikasi di tempat PKL.
                        </p>
                    @endforelse

                    {{ $proofs->links() }}
                </div>
            </div>
        </div>
    </div>
@endsection
