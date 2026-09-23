@extends('backend.layout.app')
@section('title', 'CBT/TKA')

@section('content')
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mt-5 mb-6">
        <div>
            <h2 class="fw-bold text-gray-900 mb-1">CBT/TKA</h2>
            <span class="text-muted fs-7">
                Computer Based Test dan Tes Kemampuan Akademik.
            </span>
        </div>
    </div>

    <div class="card card-flush border border-gray-200">
        <div class="card-body text-center py-20 px-5">

            <div class="d-flex align-items-center justify-content-center mb-8">
                <span class="symbol symbol-70px">
                    <span class="symbol-label bg-light-warning">
                        <i class="ki-duotone ki-setting-3 fs-2x text-warning">
                            <span class="path1"></span><span class="path2"></span>
                            <span class="path3"></span><span class="path4"></span>
                            <span class="path5"></span>
                        </i>
                    </span>
                </span>
            </div>

            <h3 class="fw-bold text-gray-900 fs-2 mb-3">Sedang Dikembangkan</h3>

            <p class="text-muted fs-6 mb-0 mx-auto" style="max-width: 460px">
                Modul CBT/TKA belum tersedia. Menu ini sudah dipasang lebih dulu
                agar hak aksesnya dapat diatur dari
                <span class="fw-semibold text-gray-700">Peran &amp; Izin</span>
                sebelum modulnya dibuka.
            </p>

        </div>
    </div>
@endsection
