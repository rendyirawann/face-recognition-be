-- ==================================================================
-- 0029 : Peran `kios` dan satu akun kios per sekolah
--
-- Tablet absensi membuka dashboard lewat APK Jargon GO Kios, jadi ia
-- harus login. Sebelumnya satu-satunya akun yang cocok adalah akun
-- admin sekolah, yang dapat mengubah SELURUH data sekolah. Tablet
-- ditaruh di depan kelas dan dapat disentuh siapa saja; memberinya akun
-- admin sama dengan membiarkan pintu ruang TU terbuka.
--
-- Peran ini hanya dapat:
--   operate_face_kiosk      menjalankan halaman Absensi Wajah
--   view_face_enrollment    melihat daftar pendaftaran wajah
--   create_face_enrollment  mendaftarkan wajah siswa baru
--
-- Ia TIDAK dapat menghapus sampel wajah, mengoreksi absensi, melihat
-- data pribadi siswa di luar nama dan kelas, atau mengelola perangkat.
-- Melepas pairing tablet tempat ia berjalan tetap bisa, karena pairing
-- tersimpan di peramban tablet itu sendiri; mencabut token perangkat
-- lain hanya untuk admin dari menu Perangkat Tablet.
--
-- Sesudah login, dashboard mengalihkan peran ini langsung ke Absensi
-- Wajah (DashboardAdminController::index).
-- ==================================================================

INSERT INTO roles (name, guard_name, created_at, updated_at)
VALUES ('kios', 'web', NOW(), NOW())
ON CONFLICT (name, guard_name) DO NOTHING;

INSERT INTO role_has_permissions (role_id, permission_id)
SELECT r.id, p.id
  FROM roles r
  JOIN permissions p ON p.guard_name = 'web'
                    AND p.name IN ('operate_face_kiosk',
                                   'view_face_enrollment',
                                   'create_face_enrollment')
 WHERE r.name = 'kios' AND r.guard_name = 'web'
ON CONFLICT DO NOTHING;

-- Satu akun per sekolah, pola yang sama dengan 0023.
-- Sandi awal: Kios#2026 (wajib diganti saat login pertama).
-- Sekolah yang ditambahkan SESUDAH migrasi ini tidak mendapat akun
-- otomatis; buat lewat menu Pengguna dengan peran `kios`.
INSERT INTO users (name, username, email, password, school_id,
                   is_active, must_change_password, created_at, updated_at)
SELECT 'Kios ' || s.name,
       'kios.' || s.npsn,
       'kios.' || lower(s.npsn) || '@kios.local',
       '$2y$12$KaUMG/18ewgjUJG4pxSVw.0jD//.rEmcYI.LOOhtMKpRPxJS3CcHa',
       s.id,
       TRUE, TRUE, NOW(), NOW()
  FROM schools s
 WHERE s.deleted_at IS NULL
ON CONFLICT (username) DO NOTHING;

INSERT INTO model_has_roles (role_id, model_type, model_id)
SELECT r.id, 'App\Models\User', u.id
  FROM roles r
  JOIN users u ON u.username LIKE 'kios.%'
 WHERE r.name = 'kios' AND r.guard_name = 'web'
   AND EXISTS (SELECT 1 FROM schools sc
                WHERE sc.npsn = substring(u.username FROM 6)
                  AND sc.deleted_at IS NULL)
ON CONFLICT DO NOTHING;

-- SETELAH MIGRASI INI:  php artisan permission:cache-reset
