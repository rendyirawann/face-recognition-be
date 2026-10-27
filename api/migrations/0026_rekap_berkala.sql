-- ==================================================================
-- 0026 : Rekap absensi berkala ke orang tua
--
-- `notification_policies.daily_recap_at` sudah ada sejak migrasi 0008,
-- tetapi tidak ada satu baris kode pun yang membacanya: kolomnya terisi,
-- rekapnya tidak pernah terkirim. Yang kurang ada tiga, dan ketiganya
-- ditambahkan di sini.
--
--   1. Jenis `monthly_recap` belum dikenali tabel template.
--   2. Rekap mingguan dan bulanan belum punya jadwal.
--   3. Tidak ada catatan periode mana yang sudah dikirim.
--
-- Nomor 3 yang paling menentukan. Worker notifikasi berjalan setiap
-- beberapa detik; tanpa catatan itu, rekap yang dijadwalkan pukul 17.00
-- akan dikirim ulang pada SETIAP putaran setelah pukul 17.00 — ratusan
-- pesan ke orang tua yang sama dalam satu sore.
-- ==================================================================

-- ------------------------------------------------------------------
-- 1. Rekap bulanan sebagai jenis template
-- ------------------------------------------------------------------
ALTER TABLE notification_templates
    DROP CONSTRAINT IF EXISTS notification_templates_key_check;
ALTER TABLE notification_templates
    ADD CONSTRAINT notification_templates_key_check
    CHECK (key IN ('check_in', 'check_out', 'late', 'absent', 'sick',
                   'permit', 'daily_recap', 'weekly_recap', 'monthly_recap',
                   'custom'));

-- ------------------------------------------------------------------
-- 2. Jadwal rekap mingguan dan bulanan
--
-- Seluruhnya NULL secara bawaan, dan NULL berarti rekap itu mati. Sekolah
-- yang hanya ingin pemberitahuan per absensi tidak perlu mematikan apa
-- pun; sebaliknya, sekolah yang ingin rekap saja tinggal mematikan
-- notify_on_* dan mengisi jam di sini.
-- ------------------------------------------------------------------
ALTER TABLE notification_policies
    ADD COLUMN IF NOT EXISTS weekly_recap_day SMALLINT
        CHECK (weekly_recap_day BETWEEN 1 AND 7),
    ADD COLUMN IF NOT EXISTS weekly_recap_at TIME,

    -- Dibatasi 28, bukan 31: tanggal 29-31 tidak ada di setiap bulan, dan
    -- rekap yang melewatkan Februari adalah jenis kesalahan yang baru
    -- disadari setahun kemudian.
    ADD COLUMN IF NOT EXISTS monthly_recap_day SMALLINT
        CHECK (monthly_recap_day BETWEEN 1 AND 28),
    ADD COLUMN IF NOT EXISTS monthly_recap_at TIME;

COMMENT ON COLUMN notification_policies.weekly_recap_day IS
    '1 = Senin ... 7 = Minggu, mengikuti ISODOW PostgreSQL';

-- ------------------------------------------------------------------
-- 3. Catatan rekap yang sudah dikirim
--
-- Kunci primernya yang menjadi penjaga: baris inilah yang MENGKLAIM satu
-- periode. Worker menyisipkannya lebih dulu, dan bila periode itu sudah
-- diklaim, penyisipannya gagal dan rekapnya dilewati. Memeriksa dengan
-- SELECT lalu memutuskan akan bocor begitu dua instance API berjalan
-- bersamaan — keduanya membaca "belum terkirim" pada saat yang sama.
-- ------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS notification_recap_runs (
    school_id    UUID NOT NULL REFERENCES schools (id) ON DELETE CASCADE,
    kind         VARCHAR(15) NOT NULL
                 CHECK (kind IN ('daily_recap', 'weekly_recap', 'monthly_recap')),

    -- Periode yang dirangkum, bukan waktu pengirimannya.
    period_start DATE NOT NULL,
    period_end   DATE NOT NULL,

    queued       INT NOT NULL DEFAULT 0,
    created_at   TIMESTAMPTZ NOT NULL DEFAULT NOW(),

    PRIMARY KEY (school_id, kind, period_start),
    CHECK (period_end >= period_start)
);

CREATE INDEX IF NOT EXISTS notification_recap_runs_waktu_idx
    ON notification_recap_runs (created_at DESC);

-- ------------------------------------------------------------------
-- 4. Template rekap
--
-- Ketiganya memakai kumpulan variabel yang sama sehingga sekolah dapat
-- menyalin isi template dari satu jenis ke jenis lain tanpa ada variabel
-- yang mendadak kosong.
--
--   {{nama_siswa}} {{kelas}} {{sekolah}} {{nama_wali}}
--   {{periode}} {{hadir}} {{terlambat}} {{izin}} {{sakit}} {{alfa}}
--   {{total_hari}} {{persen_hadir}}
-- ------------------------------------------------------------------
INSERT INTO notification_templates (school_id, key, channel, subject, body) VALUES

(NULL, 'daily_recap', 'telegram', NULL,
 E'<b>Rekap Absensi Harian</b>\n\nAnanda <b>{{nama_siswa}}</b> ({{kelas}})\nPeriode: {{periode}}\n\nHadir: {{hadir}}\nTerlambat: {{terlambat}}\nIzin: {{izin}}\nSakit: {{sakit}}\nTanpa keterangan: {{alfa}}\n\nDari {{total_hari}} hari sekolah. Kehadiran {{persen_hadir}}%.'),

(NULL, 'weekly_recap', 'telegram', NULL,
 E'<b>Rekap Absensi Mingguan</b>\n\nAnanda <b>{{nama_siswa}}</b> ({{kelas}})\nPeriode: {{periode}}\n\nHadir: {{hadir}}\nTerlambat: {{terlambat}}\nIzin: {{izin}}\nSakit: {{sakit}}\nTanpa keterangan: {{alfa}}\n\nDari {{total_hari}} hari sekolah. Kehadiran {{persen_hadir}}%.'),

(NULL, 'monthly_recap', 'telegram', NULL,
 E'<b>Rekap Absensi Bulanan</b>\n\nAnanda <b>{{nama_siswa}}</b> ({{kelas}})\nPeriode: {{periode}}\n\nHadir: {{hadir}}\nTerlambat: {{terlambat}}\nIzin: {{izin}}\nSakit: {{sakit}}\nTanpa keterangan: {{alfa}}\n\nDari {{total_hari}} hari sekolah. Kehadiran {{persen_hadir}}%.\n\nTerima kasih atas perhatian Bapak/Ibu terhadap kedisiplinan ananda.'),

(NULL, 'daily_recap', 'whatsapp', NULL,
 E'*Rekap Absensi Harian*\n\nAnanda *{{nama_siswa}}* ({{kelas}})\n📅 Periode: {{periode}}\n\n✅ Hadir: {{hadir}}\n⏱️ Terlambat: {{terlambat}}\n📄 Izin: {{izin}}\n🤒 Sakit: {{sakit}}\n❌ Tanpa keterangan: {{alfa}}\n\nDari {{total_hari}} hari sekolah. Kehadiran {{persen_hadir}}%.'),

(NULL, 'weekly_recap', 'whatsapp', NULL,
 E'*Rekap Absensi Mingguan*\n\nAnanda *{{nama_siswa}}* ({{kelas}})\n📅 Periode: {{periode}}\n\n✅ Hadir: {{hadir}}\n⏱️ Terlambat: {{terlambat}}\n📄 Izin: {{izin}}\n🤒 Sakit: {{sakit}}\n❌ Tanpa keterangan: {{alfa}}\n\nDari {{total_hari}} hari sekolah. Kehadiran {{persen_hadir}}%.'),

(NULL, 'monthly_recap', 'whatsapp', NULL,
 E'*Rekap Absensi Bulanan*\n\nAnanda *{{nama_siswa}}* ({{kelas}})\n📅 Periode: {{periode}}\n\n✅ Hadir: {{hadir}}\n⏱️ Terlambat: {{terlambat}}\n📄 Izin: {{izin}}\n🤒 Sakit: {{sakit}}\n❌ Tanpa keterangan: {{alfa}}\n\nDari {{total_hari}} hari sekolah. Kehadiran {{persen_hadir}}%.'),

(NULL, 'daily_recap', 'email', 'Rekap Absensi Harian - {{nama_siswa}} ({{periode}})',
 E'Yang terhormat Bapak/Ibu {{nama_wali}},\n\nBerikut rekap kehadiran ananda {{nama_siswa}} kelas {{kelas}} di {{sekolah}} pada periode {{periode}}.\n\nHadir            : {{hadir}}\nTerlambat        : {{terlambat}}\nIzin             : {{izin}}\nSakit            : {{sakit}}\nTanpa keterangan : {{alfa}}\n\nDari {{total_hari}} hari sekolah, kehadiran ananda {{persen_hadir}}%.\n\nHormat kami,\n{{sekolah}}'),

(NULL, 'weekly_recap', 'email', 'Rekap Absensi Mingguan - {{nama_siswa}} ({{periode}})',
 E'Yang terhormat Bapak/Ibu {{nama_wali}},\n\nBerikut rekap kehadiran ananda {{nama_siswa}} kelas {{kelas}} di {{sekolah}} pada periode {{periode}}.\n\nHadir            : {{hadir}}\nTerlambat        : {{terlambat}}\nIzin             : {{izin}}\nSakit            : {{sakit}}\nTanpa keterangan : {{alfa}}\n\nDari {{total_hari}} hari sekolah, kehadiran ananda {{persen_hadir}}%.\n\nHormat kami,\n{{sekolah}}'),

(NULL, 'monthly_recap', 'email', 'Rekap Absensi Bulanan - {{nama_siswa}} ({{periode}})',
 E'Yang terhormat Bapak/Ibu {{nama_wali}},\n\nBerikut rekap kehadiran ananda {{nama_siswa}} kelas {{kelas}} di {{sekolah}} pada periode {{periode}}.\n\nHadir            : {{hadir}}\nTerlambat        : {{terlambat}}\nIzin             : {{izin}}\nSakit            : {{sakit}}\nTanpa keterangan : {{alfa}}\n\nDari {{total_hari}} hari sekolah, kehadiran ananda {{persen_hadir}}%.\n\nHormat kami,\n{{sekolah}}')

ON CONFLICT DO NOTHING;

-- ------------------------------------------------------------------
-- 5. Izin mengirim rekap manual
--
-- Dipisahkan dari `send_notification` yang sudah ada. Mengirim satu pesan
-- bebas ke satu kelas dan mengirim rekap kehadiran seorang siswa adalah
-- dua kewenangan berbeda: yang kedua membuka riwayat ketidakhadiran anak
-- kepada siapa pun yang memegangnya.
-- ------------------------------------------------------------------
-- SETELAH MIGRASI INI DIJALANKAN:  php artisan permission:cache-reset
--
-- Spatie menyimpan daftar izin di cache selama 24 jam dan hanya
-- membuangnya sendiri bila izin diubah LEWAT Laravel. Migrasi ini menulis
-- langsung ke Postgres, jadi cache-nya tidak tahu apa-apa — tanpa
-- perintah di atas, tombol kirim rekap tidak akan muncul sampai cache
-- kedaluwarsa dengan sendirinya.
INSERT INTO permissions (name, category, guard_name, created_at, updated_at)
VALUES ('send_recap', 'Notifikasi', 'web', NOW(), NOW())
ON CONFLICT (name, guard_name) DO UPDATE SET category = EXCLUDED.category;

INSERT INTO role_has_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
JOIN permissions p ON p.name = 'send_recap' AND p.guard_name = 'web'
WHERE r.guard_name = 'web'
  AND r.name IN ('superadmin', 'admin', 'sekolah', 'kepala_sekolah')
ON CONFLICT DO NOTHING;
