-- =====================================================================
-- 0018 : Rapikan izin mobile untuk orang tua dan guru
--
-- Orang tua hanya memantau absensi anaknya; guru hanya mengurus berkas.
-- Keduanya tidak punya akses dashboard web, sehingga pencabutan di sini
-- tidak memengaruhi alur penanganan laporan di sekolah/capdis/disdik.
-- =====================================================================

-- Orang tua: lepas seluruh akses Panic Button.
DELETE FROM role_has_permissions
WHERE role_id = (SELECT id FROM roles WHERE name = 'orang_tua' AND guard_name = 'web')
  AND permission_id IN (
      SELECT id FROM permissions
      WHERE name IN ('view_panic_feed', 'create_panic_report', 'comment_panic_report')
  );

-- Guru: lepas Panic Button, sisakan pemberkasan.
DELETE FROM role_has_permissions
WHERE role_id = (SELECT id FROM roles WHERE name = 'guru' AND guard_name = 'web')
  AND permission_id IN (
      SELECT id FROM permissions WHERE name = 'view_panic_feed'
  );

-- Admin sekolah ikut memantau laporan dari aplikasi.
INSERT INTO role_has_permissions (role_id, permission_id)
SELECT r.id, p.id
  FROM roles r
  JOIN permissions p ON p.name = 'view_panic_feed'
 WHERE r.name = 'admin' AND r.guard_name = 'web'
ON CONFLICT DO NOTHING;
