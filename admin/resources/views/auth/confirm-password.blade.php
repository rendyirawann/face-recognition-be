{{--
    Halaman konfirmasi ulang kata sandi.

    Rutenya sudah terdaftar sejak awal (routes/auth.php -> password.confirm),
    tetapi viewnya tidak pernah dibuat, sehingga /admin/confirm-password
    membalas 500. Belum ada rute dashboard yang memakai middleware
    `password.confirm`, jadi halaman ini baru muncul bila alamatnya diketik
    langsung — tetap saja lebih baik daripada layar galat.
--}}
@extends('auth.app')
@section('title', 'Konfirmasi Kata Sandi')
@section('content')
    <div class="d-flex flex-column-fluid flex-lg-row-auto justify-content-center justify-content-lg-start p-12">
        <div class="bg-body d-flex flex-column flex-center rounded-4 w-md-600px p-10 shadow-lg">
            <div class="d-flex flex-center flex-column align-items-stretch h-lg-100 w-md-400px">

                <div class="d-flex flex-center flex-column flex-column-fluid mb-2">
                    <img alt="Logo" class="theme-light-show h-40px h-lg-150px"
                        src="{{ asset('assets/media/logos/base-logo.png') }}" />
                    <img alt="Logo" class="theme-dark-show h-40px h-lg-150px"
                        src="{{ asset('assets/media/logos/base-logo.png') }}" />
                </div>

                <div class="d-flex flex-center flex-column flex-column-fluid pb-15 pb-lg-20 my-6">
                    <div class="text-center mb-10">
                        <h1 class="text-gray-900 fw-bolder mb-3 fs-2">Konfirmasi Kata Sandi</h1>
                        <div class="text-gray-500 fw-semibold fs-6">
                            Anda akan membuka bagian yang sensitif. Masukkan kata sandi
                            Anda sekali lagi untuk melanjutkan.
                        </div>
                    </div>

                    <form method="POST" action="{{ route('password.confirm') }}" class="w-100">
                        @csrf
                        <div class="fv-row mb-8">
                            <label class="form-label fw-semibold text-gray-700" for="password">Kata Sandi</label>
                            <input type="password" name="password" id="password" required
                                   autocomplete="current-password" autofocus
                                   class="form-control bg-transparent @error('password') is-invalid @enderror">
                            @error('password')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="d-flex flex-column flex-sm-row gap-3">
                            <button type="submit" class="btn btn-primary flex-grow-1">Konfirmasi</button>
                            <a href="{{ route('dashboard') }}" class="btn btn-light flex-grow-1">Batal</a>
                        </div>
                    </form>

                </div>
            </div>
        </div>
    </div>
@endsection
