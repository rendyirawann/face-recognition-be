<?php

/*
|--------------------------------------------------------------------------
| Pesan validasi bahasa Indonesia
|--------------------------------------------------------------------------
|
| Sebelumnya tidak ada berkas terjemahan sama sekali dan `APP_LOCALE`
| bernilai `en`, sehingga SELURUH pesan validasi di dashboard muncul dalam
| bahasa Inggris — "The radius field must be between 20 and 2000." di tengah
| antarmuka yang seluruhnya berbahasa Indonesia.
|
| Bagi operator sekolah yang bukan pengguna teknis, pesan berbahasa asing
| bukan sekadar kurang rapi: ia tidak memberi tahu apa yang harus diperbaiki,
| sehingga formulirnya dicoba berulang kali atau ditinggalkan.
|
| Kalimatnya sengaja menyebut APA YANG HARUS DILAKUKAN, bukan hanya
| menyatakan aturan. "Wajib diisi" lebih berguna daripada "bidang ini
| diperlukan", dan "Pilih salah satu dari daftar" lebih berguna daripada
| "nilai yang dipilih tidak valid".
|
*/

return [

    'accepted' => 'Kolom :attribute harus disetujui.',
    'active_url' => 'Kolom :attribute bukan alamat web yang sah.',
    // `:date` dapat berisi TANGGAL maupun NAMA KOLOM lain — aturan
    // `after_or_equal:starts_on` mengisinya dengan "tanggal mulai". Kalimatnya
    // karena itu tidak boleh memuat kata "tanggal" sendiri, atau hasilnya
    // berbunyi "harus tanggal tanggal mulai atau sesudahnya".
    'after' => 'Kolom :attribute harus setelah :date.',
    'after_or_equal' => 'Kolom :attribute tidak boleh lebih awal dari :date.',
    'alpha' => 'Kolom :attribute hanya boleh berisi huruf.',
    'alpha_dash' => 'Kolom :attribute hanya boleh berisi huruf, angka, tanda hubung, dan garis bawah.',
    'alpha_num' => 'Kolom :attribute hanya boleh berisi huruf dan angka.',
    'array' => 'Kolom :attribute harus berupa daftar.',
    'before' => 'Kolom :attribute harus sebelum :date.',
    'before_or_equal' => 'Kolom :attribute tidak boleh lebih akhir dari :date.',

    'between' => [
        'array' => 'Kolom :attribute harus berisi antara :min sampai :max item.',
        'file' => 'Ukuran berkas :attribute harus antara :min sampai :max kilobyte.',
        'numeric' => 'Kolom :attribute harus bernilai antara :min sampai :max.',
        'string' => 'Kolom :attribute harus antara :min sampai :max karakter.',
    ],

    'boolean' => 'Kolom :attribute hanya boleh bernilai ya atau tidak.',
    'confirmed' => 'Konfirmasi :attribute tidak cocok.',
    'current_password' => 'Kata sandi yang Anda masukkan salah.',
    'date' => 'Kolom :attribute bukan tanggal yang sah.',
    'date_equals' => 'Kolom :attribute harus sama dengan :date.',
    'date_format' => 'Format :attribute tidak sesuai. Contoh yang benar: :format.',
    'different' => 'Kolom :attribute dan :other harus berbeda.',
    'digits' => 'Kolom :attribute harus terdiri dari :digits angka.',
    'digits_between' => 'Kolom :attribute harus terdiri dari :min sampai :max angka.',
    'dimensions' => 'Ukuran gambar :attribute tidak sesuai.',
    'distinct' => 'Kolom :attribute memuat nilai yang sama dua kali.',
    'email' => 'Kolom :attribute harus berupa alamat email yang sah.',
    'ends_with' => 'Kolom :attribute harus diakhiri salah satu dari: :values.',
    'exists' => 'Pilihan :attribute tidak ada dalam data.',
    'file' => 'Kolom :attribute harus berupa berkas.',
    'filled' => 'Kolom :attribute wajib diisi.',

    'gt' => [
        'array' => 'Kolom :attribute harus berisi lebih dari :value item.',
        'file' => 'Ukuran berkas :attribute harus lebih dari :value kilobyte.',
        'numeric' => 'Kolom :attribute harus lebih besar dari :value.',
        'string' => 'Kolom :attribute harus lebih dari :value karakter.',
    ],
    'gte' => [
        'array' => 'Kolom :attribute harus berisi :value item atau lebih.',
        'file' => 'Ukuran berkas :attribute minimal :value kilobyte.',
        'numeric' => 'Kolom :attribute minimal :value.',
        'string' => 'Kolom :attribute minimal :value karakter.',
    ],

    'image' => 'Kolom :attribute harus berupa gambar.',
    'in' => 'Pilihan :attribute tidak sah. Pilih salah satu dari daftar.',
    'in_array' => 'Kolom :attribute tidak ada pada :other.',
    'integer' => 'Kolom :attribute harus berupa bilangan bulat.',
    'ip' => 'Kolom :attribute harus berupa alamat IP yang sah.',
    'ipv4' => 'Kolom :attribute harus berupa alamat IPv4 yang sah.',
    'ipv6' => 'Kolom :attribute harus berupa alamat IPv6 yang sah.',
    'json' => 'Kolom :attribute harus berupa JSON yang sah.',

    'lt' => [
        'array' => 'Kolom :attribute harus berisi kurang dari :value item.',
        'file' => 'Ukuran berkas :attribute harus kurang dari :value kilobyte.',
        'numeric' => 'Kolom :attribute harus lebih kecil dari :value.',
        'string' => 'Kolom :attribute harus kurang dari :value karakter.',
    ],
    'lte' => [
        'array' => 'Kolom :attribute tidak boleh lebih dari :value item.',
        'file' => 'Ukuran berkas :attribute maksimal :value kilobyte.',
        'numeric' => 'Kolom :attribute maksimal :value.',
        'string' => 'Kolom :attribute maksimal :value karakter.',
    ],

    'max' => [
        'array' => 'Kolom :attribute maksimal :max item.',
        'file' => 'Ukuran berkas :attribute maksimal :max kilobyte.',
        'numeric' => 'Kolom :attribute maksimal :max.',
        'string' => 'Kolom :attribute maksimal :max karakter.',
    ],

    'mimes' => 'Jenis berkas :attribute harus salah satu dari: :values.',
    'mimetypes' => 'Jenis berkas :attribute harus salah satu dari: :values.',

    'min' => [
        'array' => 'Kolom :attribute minimal :min item.',
        'file' => 'Ukuran berkas :attribute minimal :min kilobyte.',
        'numeric' => 'Kolom :attribute minimal :min.',
        'string' => 'Kolom :attribute minimal :min karakter.',
    ],

    'not_in' => 'Pilihan :attribute tidak diperbolehkan.',
    'not_regex' => 'Format :attribute tidak sesuai.',
    'numeric' => 'Kolom :attribute harus berupa angka.',
    'password' => 'Kata sandi salah.',
    'present' => 'Kolom :attribute harus ada.',
    'prohibited' => 'Kolom :attribute tidak boleh diisi.',
    'regex' => 'Format :attribute tidak sesuai.',
    'required' => 'Kolom :attribute wajib diisi.',
    'required_if' => 'Kolom :attribute wajib diisi bila :other bernilai :value.',
    'required_unless' => 'Kolom :attribute wajib diisi kecuali :other bernilai :values.',
    'required_with' => 'Kolom :attribute wajib diisi bila ada :values.',
    'required_with_all' => 'Kolom :attribute wajib diisi bila ada :values.',
    'required_without' => 'Kolom :attribute wajib diisi bila tidak ada :values.',
    'required_without_all' => 'Kolom :attribute wajib diisi bila tidak ada satu pun dari :values.',
    'same' => 'Kolom :attribute dan :other harus sama.',

    'size' => [
        'array' => 'Kolom :attribute harus berisi :size item.',
        'file' => 'Ukuran berkas :attribute harus :size kilobyte.',
        'numeric' => 'Kolom :attribute harus bernilai :size.',
        'string' => 'Kolom :attribute harus :size karakter.',
    ],

    'starts_with' => 'Kolom :attribute harus diawali salah satu dari: :values.',
    'string' => 'Kolom :attribute harus berupa teks.',
    'timezone' => 'Kolom :attribute harus berupa zona waktu yang sah.',
    'unique' => ':attribute ini sudah dipakai.',
    'uploaded' => 'Berkas :attribute gagal diunggah.',
    'url' => 'Kolom :attribute harus berupa alamat web yang sah.',
    'uuid' => 'Kolom :attribute bukan pengenal yang sah.',

    /*
    |--------------------------------------------------------------------------
    | Nama kolom
    |--------------------------------------------------------------------------
    |
    | Tanpa ini, pesan memakai nama KOLOM DATABASE — "Kolom check_in_after
    | wajib diisi" tidak berarti apa pun bagi operator sekolah.
    |
    | Controller tetap dapat menimpanya lewat argumen ketiga `validate()`
    | untuk hal yang hanya berlaku di satu formulir.
    |
    */
    'attributes' => [
        'name' => 'nama',
        'email' => 'email',
        'password' => 'kata sandi',
        'password_confirmation' => 'konfirmasi kata sandi',
        'new_password' => 'kata sandi baru',
        'current_password' => 'kata sandi saat ini',
        'phone' => 'nomor HP',
        'address' => 'alamat',
        'school_id' => 'sekolah',
        'student_id' => 'siswa',
        'student_ids' => 'tautan siswa',
        'classroom_id' => 'kelas',
        'place_id' => 'tempat PKL',
        'region_id' => 'wilayah',
        'academic_year_id' => 'tahun ajaran',
        'identity_number' => 'NIK/NISN',
        'nisn' => 'NISN',
        'nis' => 'NIS',
        'npsn' => 'NPSN',
        'full_name' => 'nama lengkap',
        'grade_level' => 'tingkat',
        'latitude' => 'lintang',
        'longitude' => 'bujur',
        'radius_meters' => 'radius',
        'starts_on' => 'tanggal mulai',
        'ends_on' => 'tanggal selesai',
        'check_in_after' => 'jam masuk',
        'check_out_before' => 'jam pulang',
        'supervisor_name' => 'nama pembimbing',
        'supervisor_phone' => 'telepon pembimbing',
        'contact_name' => 'nama kontak',
        'contact_phone' => 'telepon kontak',
        'notes' => 'catatan',
        'note' => 'catatan',
        'status' => 'status',
        'role' => 'peran',
        'employee_no' => 'NIP/NUPTK',
        'position' => 'jabatan',
        'guardian_relation' => 'hubungan wali',
    ],

];
