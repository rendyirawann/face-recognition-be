{{--
    Tempatkan seorang siswa di satu tempat PKL.

    Pencarian siswa memakai endpoint yang SUDAH ada milik halaman Akun
    Aplikasi (`app-accounts.students`). Endpoint itu sudah dibatasi cakupan
    tenant, dan membuat endpoint kedua yang menanyakan hal sama berarti dua
    tempat yang harus dijaga sama-sama rapat.
--}}
<div class="modal fade" id="modal-penempatan" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <form method="POST" action="{{ route('pkl.placements.store') }}">
                @csrf

                <div class="modal-header">
                    <h3 class="modal-title fw-bold">Tempatkan Siswa PKL</h3>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body">
                    <div class="row g-4">
                        <div class="col-12">
                            <label class="form-label required">Siswa</label>
                            <input type="text" id="cari-siswa" class="form-control"
                                   value="{{ $siswaAwal->full_name ?? '' }}"
                                   placeholder="Ketik nama atau NISN, minimal 3 huruf" autocomplete="off">
                            <input type="hidden" name="student_id" id="f-student-id" required
                                   value="{{ $siswaAwal->id ?? '' }}">
                            <div id="hasil-siswa" class="list-group mt-2" style="max-height:200px;overflow-y:auto"></div>
                            @error('student_id') <div class="text-danger fs-8 mt-1">{{ $message }}</div> @enderror
                        </div>

                        <div class="col-12">
                            <label class="form-label required">Tempat PKL</label>
                            <select name="place_id" required class="form-select @error('place_id') is-invalid @enderror">
                                <option value="">&mdash; pilih tempat &mdash;</option>
                                @foreach ($places as $p)
                                    <option value="{{ $p->id }}" @selected(old('place_id') === $p->id)>
                                        {{ $p->name }} (radius {{ $p->radius_meters }} m)
                                    </option>
                                @endforeach
                            </select>
                            @error('place_id') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>

                        <div class="col-md-6">
                            <label class="form-label required">Tanggal mulai</label>
                            <input type="date" name="starts_on" required
                                   class="form-control @error('starts_on') is-invalid @enderror"
                                   value="{{ old('starts_on', now()->toDateString()) }}">
                            @error('starts_on') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>

                        <div class="col-md-6">
                            <label class="form-label required">Tanggal selesai</label>
                            <input type="date" name="ends_on" required
                                   class="form-control @error('ends_on') is-invalid @enderror"
                                   value="{{ old('ends_on', now()->addMonths(3)->toDateString()) }}">
                            @error('ends_on') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            <div class="form-text">
                                Setelah tanggal ini siswa tidak dapat absen dari ponsel lagi.
                            </div>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Jam masuk</label>
                            <input type="time" name="check_in_after" class="form-control"
                                   value="{{ old('check_in_after') }}">
                            <div class="form-text">
                                Dikosongkan berarti tidak ada status terlambat &mdash; menebak jam kerja
                                tempat orang lain bukan tugas sistem ini.
                            </div>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Jam pulang</label>
                            <input type="time" name="check_out_before" class="form-control"
                                   value="{{ old('check_out_before') }}">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Pembimbing lapangan</label>
                            <input type="text" name="supervisor_name" maxlength="120"
                                   class="form-control" value="{{ old('supervisor_name') }}">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Telepon pembimbing</label>
                            <input type="text" name="supervisor_phone" maxlength="20"
                                   class="form-control" value="{{ old('supervisor_phone') }}">
                        </div>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary">Simpan</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    @if (! empty($siswaAwal))
        // Datang dari halaman detail siswa: modalnya dibuka sendiri, dengan
        // siswanya sudah terisi. Membiarkan operator menekan "Tempatkan
        // siswa" sekali lagi setelah ia baru saja menekan "Tempatkan PKL"
        // di halaman sebelumnya adalah satu ketukan tanpa guna.
        document.addEventListener('DOMContentLoaded', function () {
            var m = document.getElementById('modal-penempatan');
            if (m && window.bootstrap) { new bootstrap.Modal(m).show(); }
        });
    @endif

    (function () {
        var kotak = document.getElementById('cari-siswa');
        var hasil = document.getElementById('hasil-siswa');
        var terpilih = document.getElementById('f-student-id');
        if (!kotak) { return; }

        var tunda;

        kotak.addEventListener('input', function () {
            terpilih.value = '';
            var q = kotak.value.trim();

            // Ketikan digabung menjadi satu permintaan per jeda, bukan satu
            // per huruf: mengetik nama lengkap akan memicu belasan kueri
            // ILIKE atas tabel siswa yang besar.
            clearTimeout(tunda);
            if (q.length < 3) { hasil.innerHTML = ''; return; }

            tunda = setTimeout(function () {
                fetch('{{ route('app-accounts.students') }}?q=' + encodeURIComponent(q),
                      { headers: { 'Accept': 'application/json' } })
                    .then(function (r) { return r.ok ? r.json() : []; })
                    .then(function (data) {
                        var baris = (data.data || data || []);
                        if (!baris.length) {
                            hasil.innerHTML = '<div class="list-group-item text-muted fs-8">'
                                + 'Tidak ada siswa yang cocok.</div>';
                            return;
                        }
                        hasil.innerHTML = baris.map(function (s) {
                            var nama = s.name || '';
                            var ket = [s.nisn, s.classroom].filter(Boolean).join(' · ');
                            return '<button type="button" class="list-group-item list-group-item-action fs-8" '
                                 + 'data-id="' + s.id + '" data-nama="' + nama + '">'
                                 + '<b>' + nama + '</b> '
                                 + '<span class="text-muted">' + ket + '</span></button>';
                        }).join('');
                    })
                    .catch(function () {
                        hasil.innerHTML = '<div class="list-group-item text-danger fs-8">'
                            + 'Gagal memuat daftar siswa.</div>';
                    });
            }, 350);
        });

        hasil.addEventListener('click', function (e) {
            var b = e.target.closest('[data-id]');
            if (!b) { return; }
            terpilih.value = b.dataset.id;
            kotak.value = b.dataset.nama;
            hasil.innerHTML = '';
        });
    })();
</script>
