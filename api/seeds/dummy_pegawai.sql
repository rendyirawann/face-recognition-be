-- =====================================================================
-- Data contoh: kepala sekolah, guru, staf TU, dan orang tua.
--
-- Melengkapi `dummy_dashboard.sql`, yang mengisi sekolah, kelas, siswa,
-- dan absensi. Berkas ini menambahkan ORANG di sekelilingnya — tanpa
-- mereka, dashboard terisi angka kehadiran tetapi kosong pada setiap
-- halaman yang menyangkut pegawai dan wali murid.
--
-- URUTAN MENJALANKAN:
--
--   docker compose exec -T postgres psql -U absensi -d absensi \
--     < api/seeds/dummy_dashboard.sql
--   docker compose exec -T postgres psql -U absensi -d absensi \
--     < api/seeds/dummy_pegawai.sql
--
-- BUKAN migrasi, dan itu disengaja. Migrasi berjalan otomatis saat API
-- start; pegawai palsu tidak boleh pernah masuk ke basis data produksi
-- tanpa ada yang memutuskannya.
--
-- Seluruh akun yang dibuat di sini ber-`username` berawalan yang khas
-- (`kepsek9`, `guru9`, `tu9`, `ortu9`) sehingga dapat dihapus kembali
-- tanpa menyentuh data asli. Lihat bagian akhir berkas.
--
-- KATA SANDI seragam untuk seluruh akun di berkas ini: Pegawai#2026
-- (hash bcrypt cost 12, diverifikasi dengan password_verify sebelum ditulis)
-- =====================================================================

BEGIN;

SELECT setseed(0.7311);

-- ------------------------------------------------------------------
-- 0. Sekolah yang digarap
--
-- Hanya sekolah yang SUDAH punya siswa contoh. Membuat kepala sekolah di
-- 2.085 sekolah kosong akan memenuhi daftar pengguna dengan akun yang
-- tidak pernah dipakai, dan menyulitkan mencari akun yang memang diuji.
-- ------------------------------------------------------------------
CREATE TEMP TABLE sekolah_garap ON COMMIT DROP AS
SELECT DISTINCT s.id, s.name, s.npsn
  FROM schools s
  JOIN students st ON st.school_id = s.id AND st.deleted_at IS NULL
 WHERE s.deleted_at IS NULL;

-- Nomor urut dipakai untuk membangun NIP dan nama yang berbeda-beda.
ALTER TABLE sekolah_garap ADD COLUMN urut INT;
UPDATE sekolah_garap g
   SET urut = q.rn
  FROM (SELECT id, row_number() OVER (ORDER BY npsn) AS rn FROM sekolah_garap) q
 WHERE q.id = g.id;

-- ------------------------------------------------------------------
-- 1. Kepala sekolah — satu per sekolah
--
-- `Pegawai#2026`, hash bcrypt cost 12.
-- ------------------------------------------------------------------
INSERT INTO users (name, username, email, password, school_id,
                   identity_number, identity_type, employee_no, position,
                   phone, is_active, must_change_password, created_at, updated_at)
SELECT 'Drs. ' || (ARRAY['Sutrisno','Marolop','Hasanuddin','Parlindungan',
                         'Zulkifli','Bonar','Syamsul','Togar','Ridwan','Maruli'
        ])[1 + (g.urut % 10)] || ' ' ||
        (ARRAY['Siregar','Nasution','Harahap','Lubis','Simanjuntak','Hutabarat',
               'Sinaga','Pohan','Rambe','Daulay'])[1 + ((g.urut * 3) % 10)],
       'kepsek9' || lpad(g.urut::text, 6, '0'),
       'kepsek9' || lpad(g.urut::text, 6, '0') || '@contoh.disdik.sumutprov.go.id',
       '$2y$12$K1cEBcgdz7fC6ijnsKG4AuVOdCmHtOuf.o4F1TEgyqzU7gnjxU6jO',
       g.id,
       -- NIK contoh berawalan 9 agar mudah dibedakan dari NIK asli.
       '9' || lpad((g.urut * 7)::text, 15, '0'),
       'nik',
       '19' || lpad((60 + (g.urut % 20))::text, 2, '0') ||
       lpad((1 + (g.urut % 12))::text, 2, '0') ||
       lpad((1 + (g.urut % 28))::text, 2, '0') || ' 1 001',
       'Kepala Sekolah',
       '0812' || lpad((g.urut * 13)::text, 8, '0'),
       TRUE, TRUE, NOW(), NOW()
  FROM sekolah_garap g
ON CONFLICT (username) DO NOTHING;

INSERT INTO model_has_roles (role_id, model_type, model_id)
SELECT r.id, 'App\Models\User', u.id
  FROM roles r
  JOIN users u ON u.username LIKE 'kepsek9%'
 WHERE r.name = 'kepala_sekolah' AND r.guard_name = 'web'
ON CONFLICT DO NOTHING;

-- ------------------------------------------------------------------
-- 2. Guru — enam per sekolah
--
-- Enam, bukan satu: halaman monitoring guru dan pemberkasan baru terlihat
-- benar bila ada beberapa baris untuk dibandingkan dan disaring.
-- ------------------------------------------------------------------
INSERT INTO users (name, username, email, password, school_id,
                   identity_number, identity_type, employee_no, position,
                   phone, is_active, must_change_password, created_at, updated_at)
SELECT (ARRAY['Ahmad','Siti','Budi','Rina','Dedi','Maya','Fajar','Lestari',
              'Rizky','Putri','Andi','Dewi'])[1 + ((g.urut + n) % 12)] || ' ' ||
       (ARRAY['Siregar','Nasution','Harahap','Lubis','Simanjuntak','Hutapea',
              'Sinaga','Pohan','Rambe','Daulay','Panggabean','Tanjung'
       ])[1 + ((g.urut * 5 + n) % 12)] || ', S.Pd.',
       'guru9' || lpad(g.urut::text, 5, '0') || lpad(n::text, 2, '0'),
       'guru9' || lpad(g.urut::text, 5, '0') || lpad(n::text, 2, '0')
         || '@contoh.disdik.sumutprov.go.id',
       '$2y$12$K1cEBcgdz7fC6ijnsKG4AuVOdCmHtOuf.o4F1TEgyqzU7gnjxU6jO',
       g.id,
       '9' || lpad((g.urut * 100 + n)::text, 15, '0'),
       'nik',
       '19' || lpad((70 + ((g.urut + n) % 15))::text, 2, '0') ||
       lpad((1 + (n % 12))::text, 2, '0') ||
       lpad((1 + ((g.urut + n) % 28))::text, 2, '0') || ' 2 0' || lpad(n::text, 2, '0'),
       (ARRAY['Guru Matematika','Guru Bahasa Indonesia','Guru Fisika',
              'Guru Sejarah','Guru Bahasa Inggris','Guru Produktif'])[n],
       '0813' || lpad((g.urut * 17 + n)::text, 8, '0'),
       TRUE, TRUE, NOW(), NOW()
  FROM sekolah_garap g
 CROSS JOIN generate_series(1, 6) AS n
ON CONFLICT (username) DO NOTHING;

INSERT INTO model_has_roles (role_id, model_type, model_id)
SELECT r.id, 'App\Models\User', u.id
  FROM roles r
  JOIN users u ON u.username LIKE 'guru9%'
 WHERE r.name = 'guru' AND r.guard_name = 'web'
ON CONFLICT DO NOTHING;

-- ------------------------------------------------------------------
-- 3. Wali kelas
--
-- Guru dipasang sebagai wali kelas di sekolahnya sendiri. Tanpa ini kolom
-- "wali kelas" pada daftar rombel kosong seluruhnya, dan itu kolom yang
-- paling sering diperiksa saat menguji halaman kelas.
-- ------------------------------------------------------------------
UPDATE classrooms c
   SET homeroom_teacher_id = q.user_id, updated_at = NOW()
  FROM (
      SELECT c2.id AS classroom_id, u.id AS user_id,
             row_number() OVER (PARTITION BY c2.school_id ORDER BY c2.name) AS rn_kelas,
             row_number() OVER (PARTITION BY u.school_id ORDER BY u.username) AS rn_guru
        FROM classrooms c2
        JOIN users u ON u.school_id = c2.school_id AND u.username LIKE 'guru9%'
  ) q
 WHERE q.classroom_id = c.id
   AND q.rn_kelas = q.rn_guru
   AND c.homeroom_teacher_id IS NULL;

-- ------------------------------------------------------------------
-- 4. Staf TU — satu per sekolah
-- ------------------------------------------------------------------
INSERT INTO users (name, username, email, password, school_id,
                   identity_number, identity_type, employee_no, position,
                   phone, is_active, must_change_password, created_at, updated_at)
SELECT (ARRAY['Herlina','Suryadi','Nurhayati','Iwan','Ratna','Bambang',
              'Yuliana','Firman'])[1 + (g.urut % 8)] || ' ' ||
       (ARRAY['Sitorus','Ginting','Sembiring','Barus','Tarigan','Purba',
              'Saragih','Damanik'])[1 + ((g.urut * 3) % 8)],
       'tu9' || lpad(g.urut::text, 6, '0'),
       'tu9' || lpad(g.urut::text, 6, '0') || '@contoh.disdik.sumutprov.go.id',
       '$2y$12$K1cEBcgdz7fC6ijnsKG4AuVOdCmHtOuf.o4F1TEgyqzU7gnjxU6jO',
       g.id,
       '9' || lpad((g.urut * 9 + 3)::text, 15, '0'),
       'nik',
       '19' || lpad((75 + (g.urut % 12))::text, 2, '0') || '0101 2 001',
       'Staf Tata Usaha',
       '0814' || lpad((g.urut * 19)::text, 8, '0'),
       TRUE, TRUE, NOW(), NOW()
  FROM sekolah_garap g
ON CONFLICT (username) DO NOTHING;

INSERT INTO model_has_roles (role_id, model_type, model_id)
SELECT r.id, 'App\Models\User', u.id
  FROM roles r
  JOIN users u ON u.username LIKE 'tu9%'
 WHERE r.name = 'staff_tu' AND r.guard_name = 'web'
ON CONFLICT DO NOTHING;

-- ------------------------------------------------------------------
-- 5. Wali murid — satu baris per siswa
--
-- Dua hal terpisah dibuat di sini:
--
--   student_guardians  data wali (nama, HP, kanal notifikasi)
--   users              AKUN aplikasi orang tua
--
-- Keduanya perlu: notifikasi absensi dikirim ke `student_guardians`,
-- sedangkan yang masuk ke aplikasi adalah `users`. Tautan di antara
-- keduanya adalah `student_guardians.user_id`.
-- ------------------------------------------------------------------
INSERT INTO student_guardians (student_id, school_id, relation, full_name,
                               phone, whatsapp, preferred_channel,
                               is_primary, notify_enabled)
SELECT s.id, s.school_id,
       CASE WHEN (q.rn % 2) = 0 THEN 'ayah' ELSE 'ibu' END,
       CASE WHEN (q.rn % 2) = 0 THEN 'Bapak ' ELSE 'Ibu ' END ||
       split_part(s.full_name, ' ', 2),
       '0852' || lpad(q.rn::text, 8, '0'),
       '0852' || lpad(q.rn::text, 8, '0'),
       'whatsapp', TRUE, TRUE
  FROM students s
  JOIN (SELECT id, row_number() OVER (ORDER BY nisn) AS rn
          FROM students WHERE metadata->>'dummy' = 'true') q ON q.id = s.id
 WHERE s.metadata->>'dummy' = 'true'
   AND NOT EXISTS (
       SELECT 1 FROM student_guardians g WHERE g.student_id = s.id
   );

INSERT INTO users (name, username, email, password, school_id,
                   identity_number, identity_type,
                   phone, is_active, must_change_password, created_at, updated_at)
SELECT g.full_name,
       'ortu9' || substring(st.nisn FROM 2),
       'ortu9' || substring(st.nisn FROM 2) || '@contoh.disdik.sumutprov.go.id',
       '$2y$12$K1cEBcgdz7fC6ijnsKG4AuVOdCmHtOuf.o4F1TEgyqzU7gnjxU6jO',
       -- Akun orang tua TIDAK diberi school_id: cakupannya mengikuti
       -- sekolah anaknya lewat student_guardians, dan anak-anaknya bisa
       -- berada di sekolah berbeda.
       NULL,
       '9' || lpad(substring(st.nisn FROM 2), 15, '0'),
       'nik',
       g.phone,
       TRUE, TRUE, NOW(), NOW()
  FROM student_guardians g
  JOIN students st ON st.id = g.student_id
 WHERE st.metadata->>'dummy' = 'true'
   AND g.user_id IS NULL
ON CONFLICT (username) DO NOTHING;

UPDATE student_guardians g
   SET user_id = u.id, updated_at = NOW()
  FROM students st
  JOIN users u ON u.username = 'ortu9' || substring(st.nisn FROM 2)
 WHERE g.student_id = st.id
   AND g.user_id IS NULL;

INSERT INTO model_has_roles (role_id, model_type, model_id)
SELECT r.id, 'App\Models\User', u.id
  FROM roles r
  JOIN users u ON u.username LIKE 'ortu9%'
 WHERE r.name = 'orang_tua' AND r.guard_name = 'web'
ON CONFLICT DO NOTHING;

-- ------------------------------------------------------------------
-- 6. Akun siswa
--
-- Dibuat di sini juga, bukan hanya di dummy_dashboard.sql, supaya SETIAP
-- siswa contoh punya akun. Versi di berkas itu hanya membuat sebagian.
-- ------------------------------------------------------------------
INSERT INTO users (name, username, email, password, student_id, school_id,
                   identity_number, identity_type,
                   is_active, must_change_password, created_at, updated_at)
SELECT s.full_name,
       'siswa' || s.nisn,
       s.nisn || '@siswa.contoh.disdik.sumutprov.go.id',
       '$2y$12$K1cEBcgdz7fC6ijnsKG4AuVOdCmHtOuf.o4F1TEgyqzU7gnjxU6jO',
       s.id, s.school_id,
       s.nisn, 'nisn',
       TRUE, TRUE, NOW(), NOW()
  FROM students s
 WHERE s.metadata->>'dummy' = 'true'
   AND NOT EXISTS (SELECT 1 FROM users u WHERE u.student_id = s.id)
ON CONFLICT (username) DO NOTHING;

INSERT INTO model_has_roles (role_id, model_type, model_id)
SELECT r.id, 'App\Models\User', u.id
  FROM roles r
  JOIN users u ON u.username LIKE 'siswa9%'
 WHERE r.name = 'siswa' AND r.guard_name = 'web'
ON CONFLICT DO NOTHING;

ANALYZE users;
ANALYZE student_guardians;
ANALYZE classrooms;

-- ------------------------------------------------------------------
-- Ringkasan
-- ------------------------------------------------------------------
SELECT 'kepala sekolah' AS peran, count(*) FROM users WHERE username LIKE 'kepsek9%'
UNION ALL SELECT 'guru',          count(*) FROM users WHERE username LIKE 'guru9%'
UNION ALL SELECT 'staf TU',       count(*) FROM users WHERE username LIKE 'tu9%'
UNION ALL SELECT 'orang tua',     count(*) FROM users WHERE username LIKE 'ortu9%'
UNION ALL SELECT 'akun siswa',    count(*) FROM users WHERE username LIKE 'siswa9%'
UNION ALL SELECT 'wali kelas terisi', count(*) FROM classrooms WHERE homeroom_teacher_id IS NOT NULL;

COMMIT;

-- =====================================================================
-- MENGHAPUS KEMBALI
--
-- Jalankan bila ingin membersihkan tanpa menyentuh data asli:
--
--   DELETE FROM users
--    WHERE username ~ '^(kepsek9|guru9|tu9|ortu9|siswa9)';
--
-- `student_guardians` ikut terhapus lewat ON DELETE CASCADE dari students
-- saat data siswa contoh dihapus; lihat dummy_dashboard.sql.
-- =====================================================================
