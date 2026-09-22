-- =====================================================================
-- Data contoh untuk mengisi dashboard.
--
-- BUKAN migrasi, dan itu disengaja. Migrasi berjalan otomatis saat API
-- start; siswa palsu tidak boleh pernah masuk ke basis data produksi tanpa
-- ada yang memutuskannya. Berkas ini dijalankan dengan tangan:
--
--   docker compose exec -T postgres psql -U absensi -d absensi \
--     < api/seeds/dummy_dashboard.sql
--
-- Prasyarat: migrasi 0020 (seed sekolah) sudah dijalankan.
--
-- CAKUPAN: tiga sekolah per wilayah Cabang Dinas, bukan seluruh 2.084.
-- Alasannya bukan kemalasan — mengisi semuanya berarti sekitar 400.000
-- siswa dan 12 juta baris absensi, yang membuat berkas ini tidak praktis
-- dijalankan sekaligus membuat dashboard demo lambat. Tiga per wilayah
-- sudah membuat ke-14 baris tabel Cabang Dinas terisi, dan itu justru
-- menyerupai keadaan sebenarnya pada peluncuran bertahap.
--
-- Seluruh data contoh ditandai `metadata->>'dummy' = 'true'` agar dapat
-- dihapus kembali tanpa menyentuh data asli. Lihat bagian akhir berkas.
-- =====================================================================

BEGIN;

-- Partisi bulan berjalan dan sebelumnya harus ada sebelum absensi disisipkan.
SELECT ensure_attendance_partitions(2);

-- Hasil yang dapat diulang: angka acak di bawah memakai benih tetap,
-- sehingga menjalankan ulang berkas ini menghasilkan sebaran yang sama.
SELECT setseed(0.4242);

-- ------------------------------------------------------------------
-- 1. Sekolah contoh: tiga per wilayah
-- ------------------------------------------------------------------
CREATE TEMP TABLE sekolah_contoh ON COMMIT DROP AS
SELECT id, name, jenjang
  FROM (
      SELECT s.id, s.name, s.jenjang,
             row_number() OVER (PARTITION BY w.id ORDER BY s.name) AS rn
        FROM schools s
        JOIN regions k ON k.id = s.region_id
        JOIN regions w ON w.id = k.parent_id AND w.kind = 'cabang_dinas'
       WHERE s.deleted_at IS NULL AND s.is_active
  ) t
 WHERE rn <= 3;

-- ------------------------------------------------------------------
-- 2. Kelas — empat per sekolah
-- ------------------------------------------------------------------
INSERT INTO classrooms (school_id, academic_year_id, name, grade_level, major, capacity)
SELECT sc.id,
       ay.id,
       k.tingkat || ' ' || k.rombel,
       k.angka,
       CASE WHEN sc.jenjang = 'SMK' THEN 'Teknik Komputer dan Jaringan'
            WHEN sc.jenjang = 'SMA' THEN 'IPA'
            ELSE NULL END,
       36
  FROM sekolah_contoh sc
 CROSS JOIN (SELECT id FROM academic_years WHERE is_active ORDER BY name DESC LIMIT 1) ay
 CROSS JOIN (VALUES ('X', '1', 10), ('X', '2', 10), ('XI', '1', 11), ('XII', '1', 12))
            AS k(tingkat, rombel, angka)
ON CONFLICT (school_id, academic_year_id, name) DO NOTHING;

-- ------------------------------------------------------------------
-- 3. Siswa — 28 per kelas
--
-- Nama disusun dari dua senarai. Tujuannya bukan realisme sempurna,
-- melainkan agar daftar di dashboard tidak berisi "Siswa 1, Siswa 2" yang
-- membuat setiap tangkapan layar demo terlihat palsu.
-- ------------------------------------------------------------------
INSERT INTO students (school_id, current_classroom_id, nisn, full_name, gender,
                      status, metadata)
SELECT c.school_id,
       c.id,
       -- NISN contoh berawalan 9 agar mudah dibedakan dari NISN asli.
       '9' || lpad((row_number() OVER ())::text, 9, '0'),
       depan.n || ' ' || belakang.n,
       CASE WHEN i % 2 = 0 THEN 'L' ELSE 'P' END,
       'aktif',
       '{"dummy": "true"}'::jsonb
  FROM classrooms c
  JOIN sekolah_contoh sc ON sc.id = c.school_id
 CROSS JOIN generate_series(1, 28) AS i
 CROSS JOIN LATERAL (
     SELECT (ARRAY['Ahmad','Siti','Budi','Rina','Dedi','Maya','Fajar','Lestari',
                   'Rizky','Putri','Andi','Dewi','Hendra','Sari','Iqbal','Nabila',
                   'Yoga','Indah','Bagus','Wulan'])[1 + ((i * 7) % 20)] AS n
 ) depan
 CROSS JOIN LATERAL (
     SELECT (ARRAY['Siregar','Nasution','Harahap','Lubis','Simanjuntak','Hutapea',
                   'Sinaga','Pohan','Rambe','Daulay','Panggabean','Tanjung',
                   'Batubara','Ritonga','Marpaung'])[1 + ((i * 11) % 15)] AS n
 ) belakang
 WHERE NOT EXISTS (
     SELECT 1 FROM students s WHERE s.current_classroom_id = c.id
 )
-- Pengaman menjalankan ulang: `nisn` punya indeks unik, dan nomor urut di
-- atas dimulai dari 1 pada setiap eksekusi. Penjaga NOT EXISTS sudah
-- menutup kasus umum; ini menutup sisanya bila ada sekolah baru masuk.
ON CONFLICT DO NOTHING;

-- Sebagian siswa dianggap sudah mendaftarkan wajah, supaya kartu
-- "cakupan biometrik" di dashboard tidak selalu 0%.
UPDATE students
   SET face_enrolled = TRUE, face_sample_count = 3
 WHERE metadata->>'dummy' = 'true'
   AND random() < 0.72;

-- ------------------------------------------------------------------
-- 4. Absensi — dua minggu terakhir, hari kerja saja
--
-- Sebarannya dibuat mendekati kenyataan: mayoritas hadir, sebagian kecil
-- terlambat, sisanya izin/sakit/alfa. Sebaran rata membuat grafik tren
-- terlihat datar dan tidak berguna untuk menguji tampilan.
-- ------------------------------------------------------------------
INSERT INTO attendances (attendance_date, school_id, student_id, classroom_id,
                         student_name, school_name, classroom_name,
                         status, check_in_at, check_out_at, late_minutes,
                         check_in_method, notification_status)
SELECT d.tanggal,
       s.school_id,
       s.id,
       s.current_classroom_id,
       s.full_name,
       sc.name,
       c.name,
       v.status,
       CASE WHEN v.status IN ('hadir','terlambat','dispensasi')
            THEN d.tanggal + TIME '06:40' + (v.telat || ' minutes')::interval
            END,
       CASE WHEN v.status IN ('hadir','terlambat','dispensasi')
            THEN d.tanggal + TIME '15:10'
            END,
       v.telat,
       CASE WHEN v.status IN ('hadir','terlambat','dispensasi') THEN 'face' END,
       'sent'
  FROM students s
  JOIN sekolah_contoh sc ON sc.id = s.school_id
  JOIN classrooms c ON c.id = s.current_classroom_id
 CROSS JOIN LATERAL (
     SELECT (CURRENT_DATE - g)::date AS tanggal
       FROM generate_series(0, 20) g
      WHERE EXTRACT(ISODOW FROM CURRENT_DATE - g) <= 5
 ) d
 CROSS JOIN LATERAL (
     SELECT r,
            CASE WHEN r < 0.80 THEN 'hadir'
                 WHEN r < 0.90 THEN 'terlambat'
                 WHEN r < 0.94 THEN 'izin'
                 WHEN r < 0.97 THEN 'sakit'
                 ELSE 'alfa' END AS status,
            CASE WHEN r >= 0.80 AND r < 0.90
                 THEN 5 + (r * 100)::int % 40 ELSE 0 END AS telat
       FROM (SELECT random() AS r) x
 ) v
 WHERE s.metadata->>'dummy' = 'true'
ON CONFLICT DO NOTHING;

-- ------------------------------------------------------------------
-- 5. Siswa yang telat berturut-turut
--
-- Dipaksakan pada beberapa siswa agar notifikasi keterlambatan beruntun
-- punya sesuatu untuk ditemukan. Tanpa ini, fitur itu tidak akan pernah
-- terlihat di demo — sebaran acak di atas hampir tidak pernah menghasilkan
-- tiga hari beruntun pada siswa yang sama.
-- ------------------------------------------------------------------
WITH korban AS (
    SELECT id FROM students
     WHERE metadata->>'dummy' = 'true'
     ORDER BY id
     LIMIT 25
)
UPDATE attendances a
   SET status = 'terlambat',
       late_minutes = 15 + (extract(day from a.attendance_date)::int % 25),
       check_in_at = a.attendance_date + TIME '07:20'
  FROM korban k
 WHERE a.student_id = k.id
   AND a.attendance_date >= CURRENT_DATE - 2
   AND a.attendance_date <= CURRENT_DATE;

-- ------------------------------------------------------------------
-- 6. Akun siswa
--
-- Dibuat hanya untuk sebagian kecil siswa: laporan Panic Button butuh
-- penulis yang nyata, dan akun-akun ini juga dipakai menguji aplikasi.
--
-- Sandi seragam `Siswa#2026`, wajib diganti saat login pertama.
-- ------------------------------------------------------------------
INSERT INTO users (name, username, email, password, student_id, school_id,
                   identity_number, identity_type,
                   is_active, must_change_password, created_at, updated_at)
SELECT s.full_name,
       'siswa' || s.nisn,
       -- `email` NOT NULL dan UNIQUE di tabel users; diisi berpola dari NISN.
       s.nisn || '@siswa.disdik.sumutprov.go.id',
       '$2y$12$UFSN7N07W03urA.ctA.e1.HifjMEfVGj1rfNdbKQd5fyLyslVGJNy',
       s.id,
       s.school_id,
       s.nisn,
       'nisn',
       TRUE, TRUE, NOW(), NOW()
  FROM students s
 WHERE s.metadata->>'dummy' = 'true'
 ORDER BY s.id
 LIMIT 120
ON CONFLICT (username) DO NOTHING;

INSERT INTO model_has_roles (role_id, model_type, model_id)
SELECT r.id, 'App\Models\User', u.id
  FROM roles r, users u
 WHERE r.name = 'siswa' AND r.guard_name = 'web'
   AND u.username LIKE 'siswa9%'
ON CONFLICT DO NOTHING;

-- ------------------------------------------------------------------
-- 7. Laporan Panic Button pada berbagai tingkat eskalasi
--
-- Empat keadaan sekaligus supaya dashboard sekolah, capdis, dan disdik
-- ketiganya punya isi: baru, sedang ditangani sekolah, sudah naik ke
-- capdis, dan sudah naik ke disdik.
-- ------------------------------------------------------------------
INSERT INTO panic_reports (school_id, category_id, author_user_id, author_role,
                           anonymous_handle, title, body, severity, status,
                           escalation_level, moderation_status, visibility,
                           process_deadline_at, created_at)
SELECT u.school_id,
       kat.id,
       u.id,
       'siswa',
       'Siswa-' || substr(md5(u.id::text), 1, 6),
       kat.judul,
       kat.isi,
       kat.severity,
       kat.status,
       kat.tingkat,
       'approved',
       'publik',
       NOW() - (kat.umur || ' days')::interval + INTERVAL '72 hours',
       NOW() - (kat.umur || ' days')::interval
  FROM (
      SELECT u.*, row_number() OVER (ORDER BY u.id) AS rn
        FROM users u
       WHERE u.username LIKE 'siswa9%' AND u.school_id IS NOT NULL
  ) u
  JOIN LATERAL (
      SELECT c.id, v.judul, v.isi, v.severity, v.status, v.tingkat, v.umur
        FROM (VALUES
            ('perundungan', 'Diejek terus-menerus di kelas',
             'Saya sering diejek soal fisik oleh beberapa teman sekelas. Sudah beberapa minggu dan saya jadi malas berangkat.',
             'tinggi', 'baru', 'sekolah', 1),
            ('pelanggaran_siswa', 'Ada yang merokok di belakang sekolah',
             'Beberapa siswa merokok di area belakang saat jam istirahat.',
             'sedang', 'ditindaklanjuti', 'sekolah', 2),
            ('pelanggaran_guru', 'Guru sering tidak masuk tanpa kabar',
             'Pelajaran kosong hampir setiap minggu tanpa tugas pengganti.',
             'tinggi', 'ditindaklanjuti', 'capdis', 5),
            ('pelanggaran_sekolah', 'Ada pungutan di luar ketentuan',
             'Diminta membayar biaya tambahan yang tidak ada di daftar resmi sekolah.',
             'tinggi', 'ditindaklanjuti', 'disdik', 9)
        ) AS v(kode, judul, isi, severity, status, tingkat, umur)
        JOIN panic_categories c ON c.code = v.kode
       WHERE ((u.rn - 1) % 4) + 1 = (
           CASE v.kode WHEN 'perundungan' THEN 1
                       WHEN 'pelanggaran_siswa' THEN 2
                       WHEN 'pelanggaran_guru' THEN 3
                       ELSE 4 END)
  ) kat ON TRUE
 WHERE u.rn <= 40;

-- Kunci "satu laporan aktif per siswa" ikut diisi agar aturannya konsisten
-- dengan data yang baru dibuat.
INSERT INTO panic_active_reports (author_user_id, report_id, report_created_at, school_id)
SELECT DISTINCT ON (author_user_id)
       author_user_id, id, created_at, school_id
  FROM panic_reports
 WHERE status NOT IN ('selesai', 'ditolak')
 ORDER BY author_user_id, created_at DESC
ON CONFLICT (author_user_id) DO NOTHING;

COMMIT;

-- Ringkasan
SELECT 'sekolah terisi' AS keterangan, count(DISTINCT school_id)::text AS jumlah
  FROM students WHERE metadata->>'dummy' = 'true'
UNION ALL SELECT 'siswa', count(*)::text FROM students WHERE metadata->>'dummy' = 'true'
UNION ALL SELECT 'absensi', count(*)::text FROM attendances
UNION ALL SELECT 'akun siswa', count(*)::text FROM users WHERE username LIKE 'siswa9%'
UNION ALL SELECT 'laporan', count(*)::text FROM panic_reports;

-- =====================================================================
-- MENGHAPUS KEMBALI
--
-- Seluruh siswa contoh bertanda metadata->>'dummy' = 'true', dan absensi
-- serta akunnya ikut terhapus lewat ON DELETE CASCADE:
--
--   DELETE FROM users WHERE username LIKE 'siswa9%';
--   DELETE FROM students WHERE metadata->>'dummy' = 'true';
--   DELETE FROM classrooms c WHERE NOT EXISTS (
--       SELECT 1 FROM students s WHERE s.current_classroom_id = c.id);
--
-- Sekolah dan wilayah dari migrasi 0020 TIDAK ikut terhapus — itu data
-- master, bukan data contoh.
-- =====================================================================
