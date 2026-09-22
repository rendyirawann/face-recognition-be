-- =====================================================================
-- 0023 : Akun Sekolah dan Admin untuk setiap sekolah
--
-- Dua akun per sekolah, dibangkitkan dari daftar sekolah yang ada:
--
--   sekolah  login dengan NPSN         memantau seluruh muridnya
--   admin    login dengan admin.<NPSN> mengelola data sekolahnya
--
-- Keduanya terikat `users.school_id`, sehingga `Tenant::schoolIds()` di
-- dashboard otomatis membatasi seluruh halaman ke sekolah itu saja.
--
-- Kata sandi awal seragam dan wajib diganti saat login pertama:
--
--   sekolah : Sekolah#2026
--   admin   : Admin#2026
--
-- Sandi berbeda per sekolah tidak menambah keamanan selama semuanya
-- diserahkan lewat kanal yang sama, dan mustahil dibagikan ke 2.000 sekolah
-- tanpa daftar yang justru lebih berbahaya daripada sandi seragam.
-- =====================================================================

-- ------------------------------------------------------------------
-- Akun sekolah — username = NPSN
--
-- `email` NOT NULL dan UNIQUE, jadi tidak bisa dikosongkan. Diisi alamat
-- berpola dari NPSN pada subdomain `sekolah.` supaya jelas dibangkitkan
-- sistem, bukan alamat yang benar-benar dipakai sekolahnya. Sekolah yang
-- ingin memasang alamat aslinya tinggal menggantinya dari dashboard.
-- ------------------------------------------------------------------
INSERT INTO users (name, username, email, password, school_id,
                   is_active, must_change_password, created_at, updated_at)
SELECT s.name,
       s.npsn,
       lower(s.npsn) || '@sekolah.disdik.sumutprov.go.id',
       '$2y$12$JY1tJRneZGGiabjY1ShTpuuJke4X5lzrsuo3tK9lvSW3fB6M3nO7y',
       s.id,
       TRUE, TRUE, NOW(), NOW()
  FROM schools s
 WHERE s.deleted_at IS NULL
ON CONFLICT (username) DO NOTHING;

INSERT INTO model_has_roles (role_id, model_type, model_id)
SELECT r.id, 'App\Models\User', u.id
  FROM roles r
  JOIN users u ON u.username IN (SELECT npsn FROM schools WHERE deleted_at IS NULL)
 WHERE r.name = 'sekolah' AND r.guard_name = 'web'
ON CONFLICT DO NOTHING;

-- ------------------------------------------------------------------
-- Akun admin sekolah — username = admin.<NPSN>
-- ------------------------------------------------------------------
INSERT INTO users (name, username, email, password, school_id,
                   is_active, must_change_password, created_at, updated_at)
SELECT 'Admin ' || s.name,
       'admin.' || s.npsn,
       'admin.' || lower(s.npsn) || '@sekolah.disdik.sumutprov.go.id',
       '$2y$12$nzImtrguvpC.hN1I72VN3.jJPkmIPmYBujjD9YwQ6JvPxyBAXsFTi',
       s.id,
       TRUE, TRUE, NOW(), NOW()
  FROM schools s
 WHERE s.deleted_at IS NULL
ON CONFLICT (username) DO NOTHING;

INSERT INTO model_has_roles (role_id, model_type, model_id)
SELECT r.id, 'App\Models\User', u.id
  FROM roles r
  JOIN users u ON u.username LIKE 'admin.%'
 WHERE r.name = 'admin' AND r.guard_name = 'web'
   AND EXISTS (SELECT 1 FROM schools sc
                WHERE sc.npsn = substring(u.username FROM 7)
                  AND sc.deleted_at IS NULL)
ON CONFLICT DO NOTHING;

-- Indeks pencarian akun per sekolah. Halaman pengelolaan akun menyaring
-- dengan school_id, dan kini ada lebih dari 4.000 baris pengguna.
CREATE INDEX IF NOT EXISTS users_school_idx
    ON users (school_id) WHERE school_id IS NOT NULL AND deleted_at IS NULL;

ANALYZE users;
