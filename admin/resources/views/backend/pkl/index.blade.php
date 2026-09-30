@extends('backend.layout.app')
@section('title', 'PKL')

@section('content')
    @include('backend.partials._flash')

    <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mt-5 mb-6">
        <div>
            <h2 class="fw-bold text-gray-900 mb-1">Praktik Kerja Lapangan</h2>
            <span class="text-muted fs-7">
                Siswa PKL mengabsenkan diri dari ponsel, di tempat PKL, selama masa penempatannya berlaku.
            </span>
        </div>
        @include('backend.partials._school_picker', [
            'schools' => $schools,
            'schoolId' => $schoolId,
            'allowAll' => false,
        ])
    </div>

    @if (! $schoolId)
        <div class="card card-flush border border-gray-200">
            <div class="card-body text-center py-20">
                <h3 class="fw-bold text-gray-900 fs-4 mb-3">Pilih sekolah lebih dulu</h3>
                <p class="text-muted fs-6 mb-0 mx-auto" style="max-width:420px">
                    Tempat dan penempatan PKL selalu milik satu sekolah, jadi halaman ini
                    tidak dapat menampilkan apa pun sebelum sekolahnya dipilih.
                </p>
            </div>
        </div>
    @else
        {{-- Ringkasan --}}
        <div class="row g-3 mb-6">
            @foreach ([
                ['Tempat PKL', $stats['tempat'], 'gray-900'],
                ['Sedang berlaku', $stats['berlaku'], 'success'],
                ['Akan datang', $stats['akan_datang'], 'info'],
                ['Selesai / batal', $stats['selesai'], 'gray-600'],
            ] as [$label, $nilai, $warna])
                <div class="col-6 col-xl-3">
                    <div class="card card-flush border border-gray-200 h-100">
                        <div class="card-body p-5">
                            <span class="text-muted fs-8 text-uppercase d-block mb-2">{{ $label }}</span>
                            <span class="fs-2hx fw-bold text-{{ $warna }}">{{ number_format($nilai) }}</span>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>

        <div class="row g-5">
            {{-- ============ TEMPAT PKL ============ --}}
            <div class="col-xl-5">
                <div class="card card-flush border border-gray-200">
                    <div class="card-header pt-5">
                        <h3 class="card-title fw-bold fs-5">Tempat PKL</h3>
                        @can('manage_pkl')
                            <div class="card-toolbar">
                                <button class="btn btn-sm btn-light-primary" data-bs-toggle="modal"
                                        data-bs-target="#modal-tempat">Tambah</button>
                            </div>
                        @endcan
                    </div>
                    <div class="card-body pt-3">
                        @forelse ($places as $p)
                            <div class="d-flex align-items-start gap-3 py-3 {{ ! $loop->last ? 'border-bottom border-gray-200' : '' }}">
                                <div class="flex-grow-1">
                                    <div class="fw-bold text-gray-900">{{ $p->name }}</div>
                                    <div class="text-muted fs-8">{{ $p->address }}</div>
                                    <div class="d-flex flex-wrap gap-2 mt-2">
                                        <span class="badge badge-light-primary fs-9">radius {{ $p->radius_meters }} m</span>
                                        <span class="badge badge-light fs-9 font-monospace">{{ $p->latitude }}, {{ $p->longitude }}</span>
                                        @if ($p->siswa_aktif > 0)
                                            <span class="badge badge-light-success fs-9">{{ $p->siswa_aktif }} siswa</span>
                                        @endif
                                    </div>
                                </div>
                                @can('manage_pkl')
                                    <form method="POST" action="{{ route('pkl.places.destroy', $p->id) }}"
                                          onsubmit="return confirm('Hapus tempat ini?')">
                                        @csrf
                                        @method('DELETE')
                                        <button class="btn btn-icon btn-sm btn-light-danger">
                                            <i class="ki-outline ki-trash fs-5"></i>
                                        </button>
                                    </form>
                                @endcan
                            </div>
                        @empty
                            <p class="text-muted fs-7 py-5 mb-0 text-center">
                                Belum ada tempat PKL. Tambahkan dulu sebelum menempatkan siswa.
                            </p>
                        @endforelse
                    </div>
                </div>
            </div>

            {{-- ============ PENEMPATAN ============ --}}
            <div class="col-xl-7">
                <div class="card card-flush border border-gray-200">
                    <div class="card-header pt-5">
                        <h3 class="card-title fw-bold fs-5">Penempatan Siswa</h3>
                        @can('manage_pkl')
                            <div class="card-toolbar">
                                <button class="btn btn-sm btn-light-primary" data-bs-toggle="modal"
                                        data-bs-target="#modal-penempatan"
                                        @disabled($places->isEmpty())>Tempatkan siswa</button>
                            </div>
                        @endcan
                    </div>

                    <div class="card-body pt-3">
                        <form method="GET" class="d-flex flex-wrap gap-2 mb-4">
                            <input type="hidden" name="school_id" value="{{ $schoolId }}">
                            <input type="text" name="q" value="{{ request('q') }}"
                                   class="form-control form-control-sm w-200px" placeholder="Cari nama atau NISN">
                            <select name="status" class="form-select form-select-sm w-150px">
                                <option value="">Semua status</option>
                                <option value="berlaku" @selected(request('status') === 'berlaku')>Sedang berlaku</option>
                                <option value="selesai" @selected(request('status') === 'selesai')>Selesai</option>
                            </select>
                            <button class="btn btn-sm btn-light-primary">Saring</button>
                        </form>

                        <div class="table-responsive">
                            <table class="table table-row-dashed align-middle gs-0 gy-3">
                                <thead>
                                    <tr class="fw-bold text-muted fs-8 text-uppercase">
                                        <th>Siswa</th>
                                        <th>Tempat</th>
                                        <th>Masa</th>
                                        <th class="text-end">Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse ($placements as $pl)
                                        @php
                                            $sisa = $pl->sisa_hari;
                                            $berlaku = $pl->status === 'aktif'
                                                && ! $pl->starts_on->isFuture() && $sisa >= 0;
                                        @endphp
                                        <tr>
                                            <td>
                                                <a href="{{ route('pkl.placements.show', $pl->id) }}"
                                                   class="fw-bold text-gray-900 text-hover-primary">
                                                    {{ $pl->student->full_name ?? '—' }}
                                                </a>
                                                <div class="text-muted fs-8">
                                                    {{ $pl->student->nisn ?? '' }}
                                                    @if ($pl->student?->classroom)
                                                        &middot; {{ $pl->student->classroom->name }}
                                                    @endif
                                                </div>
                                            </td>
                                            <td class="fs-7">{{ $pl->place->name ?? '—' }}</td>
                                            <td class="fs-8 text-muted">
                                                {{ $pl->starts_on->format('d/m/Y') }} &ndash;
                                                {{ $pl->ends_on->format('d/m/Y') }}
                                                @if ($berlaku)
                                                    <div class="text-success fw-semibold">sisa {{ $sisa }} hari</div>
                                                @endif
                                            </td>
                                            <td class="text-end">
                                                @if ($pl->status !== 'aktif')
                                                    <span class="badge badge-light-secondary">{{ $pl->status }}</span>
                                                @elseif ($berlaku)
                                                    <span class="badge badge-light-success">berlaku</span>
                                                @elseif ($pl->starts_on->isFuture())
                                                    <span class="badge badge-light-info">akan datang</span>
                                                @else
                                                    <span class="badge badge-light-warning">masa habis</span>
                                                @endif

                                                @can('manage_pkl')
                                                    @if ($pl->status === 'aktif')
                                                        <form method="POST" class="d-inline"
                                                              action="{{ route('pkl.placements.end', $pl->id) }}"
                                                              onsubmit="return confirm('Akhiri penempatan ini? Siswa tidak dapat absen dari ponsel lagi.')">
                                                            @csrf
                                                            <button class="btn btn-sm btn-light ms-2 fs-9">Akhiri</button>
                                                        </form>
                                                    @endif
                                                @endcan
                                            </td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="4" class="text-center text-muted fs-7 py-8">
                                                Belum ada penempatan PKL.
                                            </td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>

                        {{ $placements->links() }}
                    </div>
                </div>
            </div>
        </div>

        @can('manage_pkl')
            @include('backend.pkl._modal_tempat')
            @include('backend.pkl._modal_penempatan', ['siswaAwal' => $siswaAwal ?? null])
        @endcan
    @endif
@endsection
