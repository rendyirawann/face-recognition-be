{{--
    Tambah tempat PKL.

    Koordinat diisi dengan menempelkan tautan Google Maps ATAU mengetik
    angkanya. Menempelkan tautan adalah cara yang benar-benar dipakai
    orang di lapangan — operator membuka Maps di ponsel, menekan bagikan,
    lalu menempelkannya. Menuntut mereka mengurai lintang dan bujur
    sendiri dari tautan itu adalah pekerjaan yang bisa dikerjakan mesin.
--}}
<div class="modal fade" id="modal-tempat" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <form method="POST" action="{{ route('pkl.places.store') }}">
                @csrf
                <input type="hidden" name="school_id" value="{{ $schoolId }}">

                <div class="modal-header">
                    <h3 class="modal-title fw-bold">Tambah Tempat PKL</h3>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body">
                    <div class="row g-4">
                        <div class="col-12">
                            <label class="form-label required">Nama tempat</label>
                            <input type="text" name="name" required minlength="3" maxlength="150"
                                   class="form-control @error('name') is-invalid @enderror"
                                   value="{{ old('name') }}" placeholder="PT Maju Jaya Sejahtera">
                            @error('name') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>

                        <div class="col-12">
                            <label class="form-label required">Alamat</label>
                            <textarea name="address" required minlength="10" rows="2"
                                      class="form-control @error('address') is-invalid @enderror"
                                      placeholder="Jl. Gatot Subroto No. 12, Medan">{{ old('address') }}</textarea>
                            @error('address') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>

                        <div class="col-12">
                            <label class="form-label">Tempel tautan Google Maps</label>
                            <div class="input-group">
                                <input type="url" id="tempel-maps" class="form-control"
                                       placeholder="https://maps.google.com/...  atau  https://maps.app.goo.gl/...">
                                <button type="button" class="btn btn-light-primary" id="btn-urai-maps">Ambil titik</button>
                            </div>
                            <div class="form-text" id="pesan-maps">
                                Buka lokasinya di Google Maps, tekan Bagikan, lalu tempel di sini.
                                Lintang dan bujur akan terisi sendiri.
                            </div>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label required">Lintang</label>
                            <input type="text" name="latitude" id="f-lat" required inputmode="decimal"
                                   class="form-control font-monospace @error('latitude') is-invalid @enderror"
                                   value="{{ old('latitude') }}" placeholder="3.5952">
                            @error('latitude') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>

                        <div class="col-md-4">
                            <label class="form-label required">Bujur</label>
                            <input type="text" name="longitude" id="f-lon" required inputmode="decimal"
                                   class="form-control font-monospace @error('longitude') is-invalid @enderror"
                                   value="{{ old('longitude') }}" placeholder="98.6722">
                            @error('longitude') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>

                        <div class="col-md-4">
                            <label class="form-label required">Radius (meter)</label>
                            <input type="number" name="radius_meters" required min="20" max="2000" step="10"
                                   class="form-control @error('radius_meters') is-invalid @enderror"
                                   value="{{ old('radius_meters', 100) }}">
                            @error('radius_meters') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            <div class="form-text">
                                Minimal 20 m. GPS ponsel di dalam gedung meleset 10&ndash;30 m secara wajar,
                                dan radius yang lebih ketat daripada ketelitian alatnya akan menolak
                                siswa yang benar-benar berada di sana.
                            </div>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Nama kontak</label>
                            <input type="text" name="contact_name" maxlength="120"
                                   class="form-control" value="{{ old('contact_name') }}">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Telepon kontak</label>
                            <input type="text" name="contact_phone" maxlength="20"
                                   class="form-control" value="{{ old('contact_phone') }}">
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
    // Mengurai koordinat dari tautan Google Maps.
    //
    // Tiga bentuk yang benar-benar muncul:
    //   .../@3.5952,98.6722,17z        dari bilah alamat peramban
    //   ...?q=3.5952,98.6722           dari tombol Bagikan
    //   ...!3d3.5952!4d98.6722         dari tautan panjang tempat
    //
    // Tautan pendek maps.app.goo.gl TIDAK dapat diurai di sini: isinya baru
    // diketahui setelah diikuti, dan mengikutinya dari peramban terhalang
    // CORS. Itu dikatakan apa adanya kepada operator alih-alih gagal diam.
    document.getElementById('btn-urai-maps')?.addEventListener('click', function () {
        var url = document.getElementById('tempel-maps').value.trim();
        var pesan = document.getElementById('pesan-maps');

        if (!url) { return; }

        if (/goo\.gl|app\.goo\.gl/.test(url)) {
            pesan.className = 'form-text text-danger';
            pesan.textContent = 'Tautan pendek tidak dapat dibaca langsung. Buka tautannya di '
                + 'peramban lebih dulu, lalu salin alamat lengkapnya dari bilah alamat.';
            return;
        }

        var m = url.match(/@(-?\d+\.\d+),(-?\d+\.\d+)/)
             || url.match(/[?&]q=(-?\d+\.\d+),\s*(-?\d+\.\d+)/)
             || url.match(/!3d(-?\d+\.\d+)!4d(-?\d+\.\d+)/);

        if (!m) {
            pesan.className = 'form-text text-danger';
            pesan.textContent = 'Koordinat tidak ditemukan di tautan itu. Isi lintang dan bujur manual.';
            return;
        }

        document.getElementById('f-lat').value = m[1];
        document.getElementById('f-lon').value = m[2];
        pesan.className = 'form-text text-success';
        pesan.textContent = 'Titik terisi: ' + m[1] + ', ' + m[2];
    });
</script>
