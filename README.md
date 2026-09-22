# Jargon GO — Backend

Sistem absensi wajah, pengaduan siswa, dan pemberkasan untuk Dinas Pendidikan
Provinsi Sumatera Utara.

Repositori ini berisi **dua aplikasi** yang berbagi satu basis data:

| | Untuk apa | Alamat |
|---|---|---|
| `api/` — Rust (Axum) | API yang dipakai aplikasi mobile & tablet kios | `/v1/*`, dokumentasi di `/docs` |
| `admin/` — Laravel | Dashboard web untuk petugas | `/admin` |

Aplikasi mobilenya ada di repositori terpisah: [`jargon-fe`](https://github.com/rendyirawann/jargon-fe).

> **Kenapa dua bahasa?** Jalur absensi menerima ribuan permintaan terkonsentrasi
> di 45 menit setiap pagi dan mencocokkan vektor wajah — di situ Rust memberi
> keunggulan nyata. Dashboard hanya dipakai puluhan petugas sepanjang hari, dan
> yang menentukan di sana adalah kecepatan membangun layar CRUD, yang sudah
> tersedia di Laravel. Penjelasan lengkapnya di
> [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md) bagian 2.

---

## 1. Menjalankan untuk pertama kali

### Yang perlu terpasang

Hanya **Docker Desktop**. Rust, PHP, dan PostgreSQL tidak perlu dipasang di
komputer Anda — semuanya berjalan di dalam container.

- Windows/macOS: [Docker Desktop](https://www.docker.com/products/docker-desktop/)
- Linux: `docker` + `docker compose`

Pastikan Docker sudah berjalan sebelum lanjut:

```bash
docker info
```

Kalau perintah itu gagal, Docker belum hidup. Buka Docker Desktop dulu.

### Langkah

```bash
# 1. Ambil kode
git clone https://github.com/rendyirawann/jargon-be.git
cd jargon-be

# 2. Siapkan konfigurasi
cp .env.example .env
```

Buka `.env` dan isi dua nilai rahasia:

```ini
JWT_SECRET=<hasil perintah di bawah>
SECRETS_KEY_HEX=<hasil perintah di bawah>
```

```bash
# Linux/macOS/Git Bash
openssl rand -base64 48      # untuk JWT_SECRET
openssl rand -hex 32         # untuk SECRETS_KEY_HEX
```

Tanpa keduanya API menolak menyala — itu disengaja, supaya tidak ada
pemasangan yang berjalan dengan kunci bawaan.

```bash
# 3. Nyalakan semuanya
docker compose up -d --build
```

Pertama kali akan lama (5–15 menit) karena Rust dikompilasi dan image Laravel
dibangun. Berikutnya jauh lebih cepat.

```bash
# 4. Buat tabel dan isi data awal
docker compose run --rm --entrypoint /usr/local/bin/jargon-api api migrate
```

Migrasi juga berjalan otomatis saat API start. Menjalankannya terpisah dipakai
supaya kegagalan migrasi terlihat sebagai kegagalan migrasi, bukan sebagai
"API tidak mau menyala".

### Memastikan berhasil

```bash
docker compose ps
```

Enam layanan harus `healthy`: `postgres`, `redis`, `api`, `admin`, `web`, `nginx`.

Lalu buka:

| Alamat | Isi |
|---|---|
| http://localhost/docs | **Swagger UI** — dokumentasi & uji coba API |
| http://localhost/admin/login | **Dashboard Laravel** |
| http://localhost | Aplikasi Jargon GO versi web |

> Kalau port 80 sudah dipakai (XAMPP, IIS, Skype), setel `HTTP_PORT=8090` di
> `.env` lalu `docker compose up -d`. Semua alamat di atas menjadi
> `http://localhost:8090/...`.

---

## 2. Akun untuk masuk

Migrasi membuat akun-akun ini secara otomatis.

### Superadmin

| | |
|---|---|
| Pengguna | `superadmin` |
| Sandi | `Superadmin#2026` |

Melihat dan mengelola seluruh provinsi.

### Dinas Pendidikan Provinsi

| | |
|---|---|
| Pengguna | `disdik` |
| Sandi | `Disdik#2026` |

Memantau seluruh wilayah, dan satu-satunya yang boleh menyatakan laporan siswa
**Selesai**.

### Cabang Dinas — 14 wilayah

| | |
|---|---|
| Pengguna | `capdis01` sampai `capdis14` |
| Sandi | `Capdis#2026` |

Setiap akun hanya melihat sekolah di wilayahnya. Coba masuk sebagai `capdis01`
lalu `capdis07` — daftar sekolahnya akan berbeda.

### Sekolah — 2.085 akun

| | |
|---|---|
| Pengguna | **NPSN sekolah** |
| Sandi | `Sekolah#2026` |

Satu akun untuk setiap sekolah di Sumatera Utara, dan nama penggunanya adalah
NPSN sekolah itu sendiri — tidak ada daftar terpisah yang perlu dibagikan.
Akun ini memantau muridnya: absensi, laporan, dan rekap kehadiran.

### Admin Sekolah — 2.085 akun

| | |
|---|---|
| Pengguna | `admin.` + NPSN, misalnya `admin.10259001` |
| Sandi | `Admin#2026` |

Mengelola data sekolahnya: siswa, kelas, guru, dan pendaftaran wajah.

Mencari NPSN sebuah sekolah:

```bash
docker compose exec -T postgres psql -U absensi -d absensi \
  -c "SELECT npsn, name FROM schools WHERE name ILIKE '%medan%' LIMIT 10"
```

Keduanya terikat pada `users.school_id`, sehingga seluruh halaman dashboard
tersaring otomatis ke sekolah itu — tidak ada cara untuk melihat sekolah lain,
bahkan dengan mengubah alamat di peramban.

> **Semuanya wajib ganti sandi saat login pertama.** Itu bukan gangguan,
> melainkan pengaman: sandi di atas tertulis di repositori ini dan harus
> berhenti berlaku begitu sistem dipakai sungguhan.

---

## 3. Mengisi data contoh

Setelah migrasi, basis data berisi **2.085 sekolah** se-Sumatera Utara,
tetapi belum ada siswa maupun absensi — jadi dashboard masih kosong.

Untuk mengisinya:

```bash
docker compose exec -T postgres psql -U absensi -d absensi \
  < api/seeds/dummy_dashboard.sql
```

Yang dibuat: 3 sekolah per wilayah, masing-masing 4 kelas × 28 siswa, absensi
dua minggu terakhir, akun siswa, dan laporan pengaduan di berbagai tahap.

| | |
|---|---|
| Akun siswa | `siswa` + NISN, misalnya `siswa9000000043` |
| Sandi | `Siswa#2026` |

Nomor lengkapnya bisa dilihat dengan:

```bash
docker compose exec -T postgres psql -U absensi -d absensi \
  -c "SELECT username, name FROM users WHERE username LIKE 'siswa9%' LIMIT 10"
```

**Data contoh ini bukan migrasi, dan itu disengaja** — siswa palsu tidak boleh
pernah masuk ke basis data produksi tanpa ada yang memutuskannya.

Menghapusnya kembali:

```bash
docker compose exec -T postgres psql -U absensi -d absensi -c "
  DELETE FROM users WHERE username LIKE 'siswa9%';
  DELETE FROM students WHERE metadata->>'dummy' = 'true';"
```

Sekolah dan wilayah tidak ikut terhapus — itu data master.

---

## 4. Mencoba API lewat Swagger

Buka http://localhost/docs

API ini memakai **JWT**, jadi sebagian besar endpoint menolak permintaan tanpa
token. Urutannya:

**1. Ambil token.** Cari `POST /v1/auth/login`, klik **Try it out**, isi:

```json
{
  "identifier": "superadmin",
  "password": "Superadmin#2026",
  "device_name": "Swagger"
}
```

Klik **Execute**, lalu salin nilai `access_token` dari respons.

**2. Pasang token.** Klik tombol **Authorize** di kanan atas, tempel tokennya,
klik **Authorize** lalu **Close**.

**3. Coba endpoint mana pun.** Misalnya `GET /v1/me/home` — inilah yang dipanggil
aplikasi mobile saat beranda dibuka.

> Token berlaku **1 jam**. Kalau tiba-tiba semua endpoint menjawab 401, ambil
> token baru dengan cara yang sama.

### Endpoint yang paling menjelaskan sistem ini

| Endpoint | Apa yang terjadi |
|---|---|
| `POST /v1/auth/login` | Masuk, menghasilkan access token + refresh token |
| `GET /v1/me/home` | Ringkasan beranda: absensi anak, menu yang boleh dibuka |
| `GET /v1/me/notifications` | Kotak masuk notifikasi dalam aplikasi |
| `POST /v1/devices/pair` | Tablet menukar kode 8 angka dengan token perangkat |
| `POST /v1/kiosk/recognize` | **Jalur absensi wajah** — menerima 128 angka, memutuskan hadir/terlambat |
| `GET /v1/panic/reports` | Beranda pengaduan (anonim) |

---

## 5. Mencoba dashboard Laravel

Buka http://localhost/admin/login dan masuk sebagai `superadmin`.

Halaman yang paling banyak menjelaskan:

| Menu | Isi |
|---|---|
| **Beranda** | Ringkasan kehadiran. Tampilannya **berbeda** untuk superadmin, Disdik, Capdis, dan Sekolah |
| **Biometrik → Pendaftaran** | Daftarkan wajah siswa lewat webcam, tiga pose |
| **Biometrik → Absensi** | Halaman kios: browser jadi perangkat absensi |
| **Absensi** | Daftar kehadiran, koreksi manual |
| **Pengaduan** | Laporan siswa beserta tingkat eskalasinya |
| **Perangkat** | Kode pemasangan untuk tablet dan halaman kios |

### Mencoba absensi wajah dari browser

1. Buka **Perangkat**, buat perangkat baru, catat **kode pemasangan 8 angka**
2. Buka **Biometrik → Pendaftaran**, pilih seorang siswa, daftarkan wajah
   (tiga pose: hadap depan, menoleh kanan, menoleh kiri)
3. Buka **Biometrik → Absensi**, masukkan kode pemasangan tadi
4. Hadapkan wajah yang tadi didaftarkan ke kamera

Kamera hanya bisa diakses lewat **`localhost` atau HTTPS** — itu aturan browser,
bukan batasan aplikasi ini. Membukanya lewat IP LAN tanpa HTTPS akan gagal
meminta izin kamera.

---

## 6. Perintah yang sering dipakai

```bash
# Melihat log
docker compose logs -f api
docker compose logs -f admin

# Masuk ke basis data
docker compose exec postgres psql -U absensi -d absensi

# Membangun ulang setelah mengubah kode Rust
docker compose up -d --build api

# Menghentikan semuanya (data tetap aman)
docker compose down

# Menghapus semuanya TERMASUK basis data
docker compose down -v
```

---

## 7. Kalau bermasalah

**`docker info` gagal**
Docker Desktop belum berjalan. Buka aplikasinya, tunggu sampai ikonnya hijau.

**Port sudah dipakai**
Setel `HTTP_PORT=8090` di `.env`, lalu `docker compose up -d`.

**API tidak menyala, log menyebut `JWT_SECRET`**
Kedua nilai rahasia di `.env` belum diisi. Lihat langkah 2 di bagian 1.

**Semua endpoint API menjawab 401**
Token kedaluwarsa (berlaku 1 jam). Ambil token baru lewat `/v1/auth/login`.

**Dashboard menampilkan angka nol semua**
Data contoh belum dijalankan. Lihat bagian 3.

**502 Bad Gateway setelah membangun ulang**
nginx masih memakai alamat container yang lama:
```bash
docker compose restart nginx
```

**Kamera tidak bisa diakses di halaman kios**
Buka lewat `http://localhost`, bukan alamat IP. Browser hanya mengizinkan
kamera pada `localhost` atau HTTPS.

---

## 8. Dokumentasi lain

| Berkas | Isi |
|---|---|
| [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md) | Alasan di balik rancangannya |
| [`docs/DEPLOYMENT.md`](docs/DEPLOYMENT.md) | Pemasangan di server dari nol |
| [`docs/UPGRADE.md`](docs/UPGRADE.md) | Memperbarui server yang sudah berjalan |

---

## 9. Struktur repositori

```
jargon-be/
├── api/                 API Rust (Axum)
│   ├── migrations/      Skema basis data, dijalankan berurutan
│   ├── seeds/           Data contoh, dijalankan manual
│   └── src/
│       ├── routes/      Endpoint HTTP
│       ├── services/    Aturan domain (absensi, pengenalan wajah)
│       ├── workers/     Tugas latar (partisi, rekap, notifikasi)
│       └── face/        Index wajah di memori
├── admin/               Dashboard Laravel
├── infra/nginx/         Reverse proxy penyatu
├── tools/               Skrip bantu (pembangkit seed sekolah)
└── docs/
```

### Tentang migrasi

Berkas di `api/migrations/` dijalankan **berurutan** dan hanya sekali. Jangan
mengubah berkas yang sudah pernah dijalankan — buat berkas baru dengan nomor
berikutnya.

| Nomor | Isi |
|---|---|
| 0001–0009 | Skema dasar: identitas, sekolah, akademik, wajah, absensi |
| 0010 | Peran, izin, akun superadmin |
| 0011–0013 | Akun siswa/orang tua, Panic Button, pemberkasan |
| 0014–0016 | Pengenalan wajah di browser, penajaman index |
| 0017–0019 | Peran Cabang Dinas, eskalasi laporan, nomor tiket |
| 0020 | 2.084 sekolah se-Sumut + akun Capdis & Disdik |
| 0021 | Notifikasi dalam aplikasi |
| 0022 | Penggabungan kabupaten/kota yang terduplikasi oleh 0020 |
| 0023 | Akun Sekolah & Admin untuk tiap sekolah |
