# Panduan Deploy untuk Satu Sekolah

Panduan ini untuk memasang sistem absensi wajah pada **satu sekolah**, di
satu server. Untuk pemasangan skala provinsi dengan banyak sekolah, lihat
[`DEPLOYMENT.md`](DEPLOYMENT.md) yang membahas partisi, replika, dan
penskalaan.

Urutannya sengaja dibuat berurutan. Melompati satu langkah umumnya tidak
menghasilkan galat yang jelas — melainkan sistem yang berjalan dengan
tenang sambil tidak mengirim apa-apa.

---

## 1. Yang perlu disiapkan lebih dulu

| | |
|---|---|
| Server | 4 core CPU, RAM 8 GB, SSD 100 GB, Ubuntu Server 22.04 atau 24.04 |
| Domain | Satu domain `.id`, sudah diarahkan ke IP server |
| Akses | SSH sebagai pengguna yang dapat `sudo` |
| Docker | Docker Engine + plugin Compose |
| Bot Telegram | Token bot, dibuat lewat [@BotFather](https://t.me/BotFather) |

Siapkan token botnya sebelum mulai. Tanpa itu sistem tetap berjalan,
tetapi notifikasi ke orang tua tidak akan pernah terkirim, dan itu
separuh dari nilai sistemnya.

```bash
# Docker, bila belum ada
curl -fsSL https://get.docker.com | sudo sh
sudo usermod -aG docker "$USER"     # keluar lalu masuk lagi agar berlaku
```

---

## 2. Ambil kode

Dua repositori, **berdampingan**. Service `web` dibangun dari repositori
frontend, jadi tanpa keduanya `docker compose build` akan gagal.

```bash
sudo mkdir -p /opt/absensi && sudo chown "$USER" /opt/absensi
cd /opt/absensi

git clone https://github.com/rendyirawann/face-recognition-be.git
git clone https://github.com/rendyirawann/face-recognition-fe.git

cd face-recognition-be
```

---

## 3. Buat berkas `.env`

```bash
cp .env.example .env
```

Lalu isi. Nilai yang **wajib** diubah:

```bash
# Rahasia — buat acak, jangan diketik sendiri.
#   JWT_SECRET yang bisa ditebak berarti siapa pun dapat menempa token
#   untuk peran apa pun, termasuk superadmin.
openssl rand -base64 48      # -> JWT_SECRET
openssl rand -hex 32         # -> SECRETS_KEY_HEX
openssl rand -base64 32      # -> APP_KEY  (awali dengan "base64:")
openssl rand -base64 24      # -> POSTGRES_PASSWORD
```

```dotenv
JWT_SECRET=<hasil perintah di atas>
SECRETS_KEY_HEX=<hasil perintah di atas>
APP_KEY=base64:<hasil perintah di atas>
POSTGRES_PASSWORD=<hasil perintah di atas>

APP_ENV=production
APP_DEBUG=false
PUBLIC_URL=https://absensi.sekolah.id
CORS_ALLOWED_ORIGINS=https://absensi.sekolah.id

# Notifikasi: BAWAANNYA MATI.
#
# Dibiarkan false, seluruh notifikasi dan rekap tidak akan pernah
# terkirim — tanpa galat, tanpa peringatan di log. Ini sumber keluhan
# "kenapa orang tua tidak menerima apa-apa" yang paling sering.
NOTIFY_ENABLED=true
TELEGRAM_BOT_TOKEN=<token dari BotFather>

# Pengenalan wajah. JANGAN dikembalikan ke faceapi-v1 / 0.82.
#
# Nilai lama itu berasal dari anggapan yang keliru tentang panjang
# descriptor, dan akibatnya wajah orang yang tidak terdaftar dapat
# dikenali sebagai siswa. Penjelasan lengkapnya ada di komentar
# `euclid_score` pada api/src/face/vector.rs.
FACE_MODEL_VERSION=faceapi-v2
FACE_MATCH_THRESHOLD=0.70
FACE_EMBEDDING_DIM=128
```

Kunci `.env` agar tidak terbaca pengguna lain di server:

```bash
chmod 600 .env
```

---

## 4. Bangun dan jalankan

```bash
docker compose -f docker-compose.prod.yml build
docker compose -f docker-compose.prod.yml up -d
docker compose -f docker-compose.prod.yml ps
```

Keenam layanan harus berstatus `running`: `postgres`, `redis`, `api`,
`admin`, `web`, `nginx`.

> **Migrasi disematkan ke dalam image saat kompilasi.** Setiap kali ada
> migrasi baru di repositori, `docker compose pull` atau `restart` tidak
> cukup — image `api` harus **dibangun ulang**. Gejala bila dilewati:
> migrasi terbaru seolah tidak pernah ada, dan tabel barunya tidak
> ditemukan.

Migrasi dijalankan sendiri saat API start, memakai advisory lock,
sehingga aman walau beberapa instance start bersamaan.

Periksa bahwa migrasi benar-benar masuk:

```bash
docker compose -f docker-compose.prod.yml exec postgres \
  psql -U absensi -d absensi -c \
  "SELECT version, description FROM _sqlx_migrations ORDER BY version DESC LIMIT 5;"
```

Baris teratas harus `0026` (rekap berkala).

---

## 5. Bersihkan cache izin — jangan dilewati

```bash
docker compose -f docker-compose.prod.yml exec admin \
  php artisan permission:cache-reset
```

Spatie menyimpan daftar izin di cache selama 24 jam, dan hanya
membuangnya sendiri bila izin diubah **lewat Laravel**. Migrasi menulis
langsung ke PostgreSQL, jadi cachenya tidak tahu apa-apa.

Tanpa perintah ini, menu dan tombol yang izinnya baru ditambahkan — di
antaranya tombol **Kirim Rekap** — tidak akan muncul sampai cachenya
kedaluwarsa sendiri keesokan harinya. Tidak ada galat apa pun; tombolnya
hanya tidak ada.

Ulangi perintah ini **setiap kali** menjalankan migrasi yang menyentuh
tabel `permissions`.

---

## 6. Isi data sekolah

```bash
# Peran, izin, dan akun superadmin pertama
docker compose -f docker-compose.prod.yml exec admin \
  php artisan db:seed --force
```

> **Ganti sandi superadmin sebelum melanjutkan.**
>
> Akun `superadmin` dibuat migrasi dengan sandi `Superadmin#2026` —
> **sama persis di setiap pemasangan** dan tertulis di dalam repositori.
> Server yang sudah dapat diakses dari internet dengan sandi itu masih
> aktif sama saja dengan tidak punya sandi.
>
> Masuk ke `/admin`, ganti sandinya saat diminta, lalu lanjutkan.
>
> Migrasi 0023 juga membuat dua akun untuk SETIAP sekolah: `<NPSN>`
> dengan sandi `Sekolah#2026` dan `admin.<NPSN>` dengan `Admin#2026`.
> Keduanya ikut harus diganti. Daftar lengkapnya di [AKUN.md](AKUN.md).

Lalu masuk ke `https://absensi.sekolah.id/admin` dan isi lewat dashboard:

1. **Sekolah** — nama, alamat, dan jam masuk/pulang.
2. **Kelas / Rombel** — seluruh rombel tahun ajaran berjalan.
3. **Siswa** — impor massal lewat berkas, atau tambah satu per satu.
4. **Wali murid** — pada tiap siswa, beserta **chat ID Telegram**-nya.
5. **Perangkat Tablet** — daftarkan tiap tablet, catat kode pairing-nya.

### Menautkan Telegram orang tua

Chat ID **tidak diketik operator**. Alurnya:

1. Buka halaman detail siswa, cari wali yang dituju, tekan **Buat Tautan
   Telegram**. Tautan `https://t.me/<bot>?start=<token>` muncul di bawah
   nama wali itu.
2. Kirimkan tautan itu kepada walinya — lewat pesan wali kelas, surat,
   atau kode QR yang ditempel di buku penghubung.
3. Orang tua menekan tautan, Telegram terbuka, dan ia menekan **Start**.
4. Chat ID-nya tersimpan sendiri, dan kartu walinya berubah menjadi
   **Telegram tertaut**.

Tokennya **sekali pakai dan hangus** setelah terpakai. Bila orang tua
berganti nomor atau ponsel, buat tautan baru — tautan lama tidak akan
berlaku lagi.

Agar langkah 3 berfungsi, webhook harus didaftarkan ke Telegram **satu
kali** setelah HTTPS aktif:

```bash
curl -X POST "https://api.telegram.org/bot<TOKEN>/setWebhook"   -d "url=https://absensi.sekolah.id/v1/notifications/telegram/webhook"   -d "secret_token=<isi TELEGRAM_WEBHOOK_SECRET>"
```

Periksa bahwa Telegram menerimanya:

```bash
curl "https://api.telegram.org/bot<TOKEN>/getWebhookInfo"
```

`pending_update_count` yang terus naik berarti server menolak update —
hampir selalu karena `TELEGRAM_WEBHOOK_SECRET` di `.env` berbeda dengan
yang didaftarkan ke Telegram.

> Webhook **menolak seluruh update** bila `TELEGRAM_WEBHOOK_SECRET`
> kosong. Itu disengaja: endpoint ini publik, dan yang terbuka bagi siapa
> saja berarti orang asing dapat mengirim update palsu berisi token
> tebakan lalu menautkan Telegram-nya ke wali mana pun.

---

## 7. Pendaftaran wajah

Buka **Biometrik → Pendaftaran Wajah** di dashboard, lalu daftarkan tiap
siswa dari webcam. Ambil 3 sampel per siswa, dengan pencahayaan yang
wajar dan tanpa masker.

> **Seluruh wajah harus didaftarkan ulang bila sistem sebelumnya pernah
> berjalan dengan `faceapi-v1`.** Embedding lama disimpan dalam keadaan
> ternormalisasi, yang baru disimpan apa adanya, dan keduanya tidak
> sebanding. Mencampurnya tidak menurunkan akurasi — ia menghasilkan
> pencocokan yang acak.
>
> Index hanya memuat embedding yang `model_version`-nya cocok dengan
> `FACE_MODEL_VERSION`, jadi data lama tidak akan ikut terpakai. Yang
> perlu dipastikan hanyalah seluruh siswa sudah punya pendaftaran baru
> sebelum sistem dipakai.

Periksa berapa yang sudah terdaftar:

```bash
docker compose -f docker-compose.prod.yml exec postgres \
  psql -U absensi -d absensi -c \
  "SELECT model_version, COUNT(DISTINCT student_id)
     FROM face_embeddings GROUP BY model_version;"
```

---

## 8. Atur notifikasi dan rekap

Dashboard → **Notifikasi → Kirim Pesan**, panel *Kebijakan*:

| Pengaturan | Arti |
|---|---|
| Notifikasi saat masuk / terlambat / alfa | Pesan terkirim pada tiap kejadian |
| Jam batas alfa | Setelah jam ini, siswa yang belum discan ditandai alfa |
| Jam senyap | Rentang jam yang tidak boleh ada pesan keluar |
| Jam rekap harian | Kosongkan bila tidak ingin rekap harian |
| Hari + jam rekap mingguan | Hari 1 = Senin … 7 = Minggu |
| Tanggal + jam rekap bulanan | Tanggal 1–28 saja |

Sekolah yang ingin **rekap saja** tanpa pesan per absensi: matikan
seluruh *notifikasi saat*, lalu isi jadwal rekapnya.

Periodenya dihitung server: harian = hari itu, mingguan = tujuh hari yang
berakhir pada hari kirim, bulanan = bulan kalender sebelumnya yang sudah
selesai.

---

## 9. HTTPS

```bash
sudo apt install -y certbot
sudo certbot certonly --standalone -d absensi.sekolah.id
```

Sertifikatnya dipasang pada nginx. Blok konfigurasinya ada di
[`DEPLOYMENT.md` bagian 2.10](DEPLOYMENT.md).

Tanpa HTTPS, data absensi dan foto wajah siswa melintas tanpa enkripsi.
Jangan dijalankan ke produksi tanpa ini.

---

## 10. Pasang aplikasi di tablet

```bash
# Di mesin pengembangan, bukan di server
cd face-recognition-fe
flutter build apk --release \
  --dart-define=API_BASE_URL=https://absensi.sekolah.id
```

Salin `build/app/outputs/flutter-apk/app-release.apk` ke tablet, pasang,
lalu masukkan kode pairing yang dibuat di dashboard.

---

## 11. Daftar periksa sebelum serah terima

- [ ] `docker compose ps` — keenam layanan `running`
- [ ] Sandi superadmin bawaan sudah diganti
- [ ] Migrasi teratas `0026`
- [ ] `permission:cache-reset` sudah dijalankan
- [ ] `NOTIFY_ENABLED=true` dan token Telegram terisi
- [ ] `FACE_MODEL_VERSION=faceapi-v2`, bukan v1
- [ ] Seluruh siswa punya pendaftaran wajah pada versi model yang berlaku
- [ ] Minimal satu wali sudah punya chat ID, dan pesan ujinya diterima
- [ ] Tablet sudah terpasang dan berhasil mencatat satu absensi
- [ ] HTTPS aktif, `http://` dialihkan ke `https://`
- [ ] Pencadangan harian berjalan (bagian 12)

Uji satu putaran penuh sebelum menyatakan selesai: satu siswa absen di
tablet, lalu pastikan orang tuanya menerima pesan Telegram.

---

## 12. Pencadangan

```bash
sudo mkdir -p /var/backups/absensi

sudo tee /etc/cron.daily/backup-absensi >/dev/null <<'EOF'
#!/bin/sh
cd /opt/absensi/face-recognition-be
docker compose -f docker-compose.prod.yml exec -T postgres \
  pg_dump -U absensi absensi | gzip \
  > /var/backups/absensi/absensi-$(date +\%F).sql.gz
find /var/backups/absensi -name '*.sql.gz' -mtime +30 -delete
EOF
sudo chmod +x /etc/cron.daily/backup-absensi
```

Cadangan yang belum pernah dipulihkan bukan cadangan. Sekali saja, coba
pulihkan ke basis data kosong dan pastikan jumlah barisnya masuk akal.

Foto pendaftaran wajah ada di volume `api-storage`, terpisah dari basis
data. Keduanya perlu dicadangkan bila ingin dapat memulihkan sistem tanpa
mendaftarkan ulang seluruh wajah.

---

## 13. Yang sering salah

| Gejala | Penyebab yang paling sering |
|---|---|
| Orang tua tidak menerima apa pun | `NOTIFY_ENABLED` masih `false`, atau chat ID wali kosong |
| Tombol Kirim Rekap tidak muncul | `permission:cache-reset` belum dijalankan |
| Tabel baru tidak ditemukan | Image `api` belum dibangun ulang setelah migrasi baru |
| Wajah orang lain tercatat hadir | `FACE_MODEL_VERSION` kembali ke `faceapi-v1`, atau ambang 0.82 |
| Siswa sah gagal dikenali | Ambang terlalu tinggi, atau wajahnya didaftarkan pada versi model lama |
| Rekap terkirim berulang-ulang | Tabel `notification_recap_runs` terhapus atau dikosongkan |
| Tablet tidak bisa pairing | Jam tablet meleset jauh, atau kode pairing sudah kedaluwarsa |

Melihat log:

```bash
docker compose -f docker-compose.prod.yml logs -f api | grep -i rekap
docker compose -f docker-compose.prod.yml logs -f api | grep -i notif
```

---

## 14. Catatan untuk pemasangan pertama ini

Migrasi `0026_rekap_berkala.sql` **belum pernah dijalankan terhadap basis
data mana pun**. Nama tabel, kolom, dan constraint-nya sudah diperiksa
satu per satu terhadap berkas migrasi sebelumnya, tetapi itu bukan
pengganti menjalankannya.

Jalankan lebih dulu di basis data percobaan, bukan langsung di server
sekolah:

```bash
docker compose -f docker-compose.prod.yml exec postgres \
  createdb -U absensi absensi_uji
# arahkan DATABASE_URL ke absensi_uji, start API, periksa _sqlx_migrations
```
