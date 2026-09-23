{{--
    Halaman prompt verifikasi email.

    Rutenya sudah ada sejak awal (routes/auth.php), tetapi viewnya tidak pernah
    dibuat, sehingga /admin/verify-email membalas 500 untuk siapa pun yang
    emailnya belum terverifikasi — dan sesudah migrasi 0023 itu berarti 4.170
    akun sekolah. Tidak ada rute dashboard yang memakai middleware `verified`,
    jadi halaman ini hanya muncul bila alamatnya diketik langsung; tetap saja
    lebih baik memberi keterangan daripada layar galat.
--}}
@extends('auth.app')
@section('title', 'Verifikasi Email')
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
                        <h1 class="text-gray-900 fw-bolder mb-3 fs-2">Verifikasi Email</h1>
                        <div class="text-gray-500 fw-semibold fs-6">
                            Akun Anda belum memverifikasi alamat email.<br>
                            Tautan verifikasi dikirim ke
                            <span class="fw-semibold text-gray-700">{{ auth()->user()?->email }}</span>.
                        </div>
                    </div>

                    @if (session('status') === 'verification-link-sent')
                        <div class="alert alert-success text-center mb-8">
                            Tautan verifikasi baru sudah dikirim.
                        </div>
                    @endif

                    <div class="d-flex flex-column flex-sm-row gap-3 w-100">
                        <form method="POST" action="{{ route('verification.send') }}" class="flex-grow-1">
                            @csrf
                            <button type="submit" class="btn btn-primary w-100">Kirim Ulang Tautan</button>
                        </form>
                        <form method="POST" action="{{ route('logout') }}" class="flex-grow-1">
                            @csrf
                            <button type="submit" class="btn btn-light w-100">Keluar</button>
                        </form>
                    </div>

                    <div class="text-muted fs-8 text-center mt-6">
                        Akun sekolah yang dibuat massal memakai alamat email sementara, sehingga
                        tautannya mungkin tidak sampai. Hubungi admin dinas bila Anda perlu
                        alamat email akun diperbarui.
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
