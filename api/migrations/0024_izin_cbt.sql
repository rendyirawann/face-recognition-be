-- =====================================================================
-- 0024 : Izin melihat modul CBT/TKA
--
-- Modul ini belum dibangun; halamannya masih berupa penanda "sedang
-- dikembangkan". Izinnya tetap dibuat sekarang, bukan nanti, karena
-- menu di dashboard ditampilkan berdasarkan IZIN dan bukan peran —
-- tanpa baris ini menunya tidak akan muncul untuk siapa pun.
--
-- Diberikan kepada peran yang berkepentingan atas penilaian akademik:
--
--   superadmin, admin_dinas  memantau seluruh provinsi
--   capdis                   memantau wilayahnya
--   admin, sekolah           menyelenggarakan di sekolahnya
--   kepala_sekolah, guru     menyusun dan menilai
--
-- TIDAK diberikan kepada siswa dan orang_tua: keduanya tidak dapat
-- masuk dashboard sama sekali. Juga tidak kepada petugas_pengaduan dan
-- staff_tu, yang tugasnya di luar penilaian — keduanya masih bisa
-- diberi izin ini dari /admin/roles bila ternyata diperlukan.
--
-- SETELAH MIGRASI INI, BERSIHKAN CACHE IZIN:
--
--     docker compose exec admin php artisan permission:cache-reset
--
-- Spatie menyimpan daftar izin di cache selama 24 jam dan hanya
-- membuangnya sendiri bila izin diubah LEWAT Laravel. Migrasi ini menulis
-- langsung ke Postgres, jadi cache-nya tidak tahu apa-apa — tanpa perintah
-- di atas, menu CBT/TKA tidak akan muncul sampai cache kedaluwarsa dengan
-- sendirinya. Berlaku juga untuk 0011, 0015, dan 0017 yang menambah izin
-- dengan cara yang sama.
-- =====================================================================

INSERT INTO permissions (name, category, guard_name, created_at, updated_at)
VALUES ('view_cbt', 'CBT/TKA', 'web', NOW(), NOW())
ON CONFLICT (name, guard_name) DO UPDATE SET category = EXCLUDED.category;

INSERT INTO role_has_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
JOIN permissions p ON p.name = 'view_cbt' AND p.guard_name = 'web'
WHERE r.guard_name = 'web'
  AND r.name IN ('superadmin', 'admin_dinas', 'capdis', 'admin',
                 'sekolah', 'kepala_sekolah', 'guru')
ON CONFLICT DO NOTHING;
