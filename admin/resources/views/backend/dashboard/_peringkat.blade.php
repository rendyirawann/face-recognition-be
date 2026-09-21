{{--
    Peringkat kehadiran sekolah.

    Dipakai panel provinsi maupun wilayah. Daftarnya sudah dibatasi cakupan
    oleh controller, jadi partial ini tidak perlu menyaring apa pun.
--}}
<div class="card card-flush h-100 border border-gray-200">
    <div class="card-header pt-5">
        <h3 class="card-title fw-bold">{{ $judul }}</h3>
    </div>
    <div class="card-body pt-3">
        @forelse ($rows as $row)
            <div class="d-flex align-items-center justify-content-between mb-4">
                <div class="me-3 overflow-hidden">
                    <span class="d-block fw-semibold fs-7 text-truncate">{{ $row->name }}</span>
                    <span class="text-muted fs-8">
                        {{ $row->jenjang }} &middot; {{ number_format($row->total_students) }} siswa
                    </span>
                </div>
                <span class="badge badge-light-{{ $warna }} flex-shrink-0">{{ $row->rate }}%</span>
            </div>
        @empty
            <p class="text-muted fs-7 mb-0">
                Belum ada sekolah yang melaporkan absensi pada tanggal ini.
            </p>
        @endforelse
    </div>
</div>
