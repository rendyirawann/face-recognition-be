# Daftar Akun

Siapa saja yang dapat masuk ke sistem, lewat mana, dan dengan apa.

> **Seluruh kata sandi di dokumen ini adalah sandi AWAL yang sama di
> setiap pemasangan, dan tertulis di dalam repositori.** Server yang
> sudah dapat diakses dari internet dengan sandi ini masih aktif sama
> saja dengan tidak punya sandi. Ganti sebelum sistem dipakai.

---

## 1. Dua pintu masuk yang berbeda

| | Dashboard web | Aplikasi ponsel |
|---|---|---|
| Alamat | `https://<domain>/admin` | APK Jargon GO |
| Masuk dengan | username **atau** email | **NIK** (pegawai & wali) atau **NISN** (siswa) |
| Untuk | superadmin, admin, kepala sekolah, guru, TU | siswa, orang tua, guru |

Server menerima ketiga bentuk pada satu endpoint yang sama
(`identity_number`, `username`, atau `email`), jadi akun pegawai
sebenarnya bisa masuk lewat keduanya. Yang membedakan hanya apa yang
ditampilkan setelah masuk.

Tablet kios **tidak memakai akun sama sekali** — ia dipasangkan dengan
kode 8 digit dari menu **Perangkat Tablet**, lalu bekerja dengan device
token.

---

## 2. Akun bawaan sistem

Ada di **setiap** pemasangan, dibuat otomatis oleh migrasi saat API
pertama kali start.

| Peran | Username | Kata sandi awal | Dibuat oleh |
|---|---|---|---|
| Superadmin | `superadmin` | `Superadmin#2026` | migrasi `0010` |

Akun ini ditandai **wajib ganti sandi saat login pertama**, jadi sistem
akan memintanya sendiri.

> `SuperAdminSeeder` pada Laravel juga menyebut sandi
> `12qwaszx123!!@@##`. Sandi itu **tidak berlaku**: akunnya sudah dibuat
> lebih dulu oleh migrasi, dan seeder hanya melengkapi perannya tanpa
> menimpa sandinya. Yang berlaku adalah `Superadmin#2026`.

---

## 3. Akun per sekolah

Dibuat migrasi `0023` untuk **setiap** sekolah yang ada di tabel
`schools`, diturunkan dari NPSN-nya.

| Peran | Username | Kata sandi awal | Untuk apa |
|---|---|---|---|
| Sekolah | `<NPSN>` | `Sekolah#2026` | Memantau seluruh murid sekolahnya |
| Admin sekolah | `admin.<NPSN>` | `Admin#2026` | Mengelola data sekolahnya |

Contoh, untuk sekolah ber-NPSN `10258745`:

```
username : 10258745          sandi : Sekolah#2026
username : admin.10258745    sandi : Admin#2026
```

Keduanya terikat pada `users.school_id`, sehingga seluruh halaman
dashboard otomatis terbatas pada sekolah itu saja — tanpa perlu
menyaring apa pun dengan tangan.

**Peran `admin` inilah yang dipakai BK** untuk mengirim rekap absensi ke
orang tua.

---

## 4. Akun data contoh

**Hanya untuk uji coba.** Dibuat oleh dua berkas seed yang **tidak**
berjalan otomatis — harus dijalankan dengan sadar:

```bash
docker compose exec -T postgres psql -U absensi -d absensi \
  < api/seeds/dummy_dashboard.sql      # sekolah, kelas, siswa, absensi
docker compose exec -T postgres psql -U absensi -d absensi \
  < api/seeds/dummy_pegawai.sql        # kepala sekolah, guru, TU, orang tua
```

| Peran | Pola username | Pola NIK/NISN | Kata sandi |
|---|---|---|---|
| Kepala sekolah | `kepsek9…` | `91` + 14 digit | `Pegawai#2026` |
| Guru | `guru9…` | `92` + 14 digit | `Pegawai#2026` |
| Staf TU | `tu9…` | `93` + 14 digit | `Pegawai#2026` |
| Orang tua | `ortu9…` | `94` + 14 digit | `Wali#2026` |
| Siswa | `siswa<NISN>` | NISN | `Siswa#2026` |

Awalan dua digit pada NIK berbeda per peran dengan sengaja. Pada
percobaan sebelumnya NIK kepala sekolah dan guru dihitung dari rumus yang
dapat menghasilkan angka sama, dan tabrakannya baru ketahuan saat seeder
gagal di tengah jalan.

### Cara menemukan akun contoh yang bisa dipakai

Seluruh akun contoh ber-username berawalan khas, jadi mudah dicari:

```sql
SELECT username, identity_number, name
  FROM users
 WHERE username LIKE 'kepsek9%' OR username LIKE 'guru9%'
    OR username LIKE 'tu9%'     OR username LIKE 'ortu9%'
 ORDER BY username
 LIMIT 10;

-- Akun siswa beserta NISN-nya, untuk masuk lewat aplikasi
SELECT u.username, u.identity_number AS nisn, s.full_name, c.name AS kelas
  FROM users u
  JOIN students s   ON s.id = u.student_id
  LEFT JOIN classrooms c ON c.id = s.current_classroom_id
 WHERE u.username LIKE 'siswa%'
 ORDER BY s.full_name
 LIMIT 10;
```

### Membuangnya kembali

Awalan yang khas itu juga yang membuat data contoh dapat dihapus tanpa
menyentuh data asli. Perintahnya ada di bagian akhir
`api/seeds/dummy_pegawai.sql`.

---

## 5. Membuat akun sungguhan

Lewat dashboard, bukan lewat SQL:

| Untuk siapa | Menu |
|---|---|
| Pegawai sekolah | **Sistem → Pengguna** |
| Siswa & orang tua | **Layanan → Akun Aplikasi** |
| Banyak siswa sekaligus | **Layanan → Akun Siswa Massal** |

Akun orang tua **ditautkan ke anaknya saat dibuat** — dari halaman detail
siswa, bukan dari halaman pengguna. Tautan itulah yang menentukan kabar
absensi siapa yang ia terima; akun wali tanpa tautan tidak akan menerima
apa pun.

---

## 6. Yang harus dikerjakan sebelum serah terima

- [ ] Sandi `superadmin` sudah diganti
- [ ] Sandi setiap akun `sekolah` dan `admin.<NPSN>` sudah diganti
- [ ] Data contoh sudah dibuang bila pernah dijalankan di server ini
- [ ] Tidak ada akun yang masih memakai sandi dari dokumen ini

Periksa dengan:

```sql
-- Akun yang belum pernah mengganti sandi awalnya
SELECT username, name, last_login
  FROM users
 WHERE must_change_password = TRUE
 ORDER BY username;
```
