-- ==================================================================
-- 0028 : Buang sisa Panic Button, Pemberkasan, dan CBT
--
-- Kode ketiga modul ini sudah dihapus: rute, controller, model, view,
-- dan layar aplikasinya tidak ada lagi. Yang tertinggal hanya tabel,
-- fungsi, dan izinnya di basis data.
--
-- MENGAPA DIBUANG, BUKAN DIBIARKAN
--
-- Tabel yang tidak dipakai bukan sekadar tidak rapi. `panic_reports`
-- dipartisi per bulan, dan worker pemeliharaan membuat partisi baru
-- setiap bulan untuk tabel yang tidak akan pernah menerima satu baris
-- pun. Trigger penghitungnya ikut berjalan di setiap tulis. Dan izin
-- yang masih melekat pada peran membuat daftar Peran & Izin memuat
-- belasan baris yang tidak menuju ke mana-mana — operator yang
-- memeriksanya tidak punya cara tahu mana yang berlaku.
--
-- PKL TIDAK TERMASUK. Modul itu dikembalikan karena sekolahnya SMK.
--
-- TIDAK DAPAT DIBATALKAN. Data pengaduan dan pemberkasan yang masih
-- tersimpan akan hilang bersama tabelnya. Bila riwayatnya masih
-- diperlukan, ekspor lebih dulu sebelum menjalankan migrasi ini.
-- ==================================================================

-- ------------------------------------------------------------------
-- 1. Trigger dan fungsi
--
-- Dibuang lebih dulu: trigger yang menempel pada tabel akan ikut
-- terbuang bersama tabelnya, tetapi fungsinya tidak. Fungsi yatim tidak
-- merusak apa pun, hanya tidak akan pernah ada yang membuangnya nanti.
-- ------------------------------------------------------------------
DROP TRIGGER IF EXISTS trg_panic_supports_count ON panic_supports;
DROP TRIGGER IF EXISTS trg_panic_comments_count ON panic_comments;
DROP TRIGGER IF EXISTS trg_document_files_count ON document_files;
DROP TRIGGER IF EXISTS trg_document_submissions_updated ON document_submissions;

DROP FUNCTION IF EXISTS sync_panic_support_count();
DROP FUNCTION IF EXISTS sync_panic_comment_count();
DROP FUNCTION IF EXISTS sync_document_counts();

-- Dipanggil worker pemeliharaan? TIDAK — worker hanya memanggil
-- ensure_attendance_partitions dan ensure_outbox_partitions. Diperiksa
-- sebelum dibuang; fungsi partisi yang masih dipanggil akan membuat
-- worker gagal setiap hari tanpa ada yang memperhatikan.
DROP FUNCTION IF EXISTS ensure_panic_partitions(INTEGER);

DROP SEQUENCE IF EXISTS panic_ticket_seq;

-- ------------------------------------------------------------------
-- 2. View
-- ------------------------------------------------------------------
DROP VIEW IF EXISTS panic_active_reports;

-- ------------------------------------------------------------------
-- 3. Tabel
--
-- Urutannya mengikuti arah kunci asing: yang menunjuk dibuang lebih
-- dulu, yang ditunjuk belakangan. CASCADE sengaja TIDAK dipakai — ia
-- akan ikut membuang apa pun yang kebetulan menunjuk ke sini tanpa
-- memberitahu, termasuk yang tidak kita ketahui ada.
-- ------------------------------------------------------------------
DROP TABLE IF EXISTS panic_report_media;
DROP TABLE IF EXISTS panic_supports;
DROP TABLE IF EXISTS panic_comments;
DROP TABLE IF EXISTS panic_report_events;
DROP TABLE IF EXISTS panic_unmask_logs;
DROP TABLE IF EXISTS panic_reports_default;
DROP TABLE IF EXISTS panic_reports;
DROP TABLE IF EXISTS panic_categories;

DROP TABLE IF EXISTS document_submission_events;
DROP TABLE IF EXISTS document_files;
DROP TABLE IF EXISTS document_submissions;
DROP TABLE IF EXISTS document_types;

-- ------------------------------------------------------------------
-- 4. Izin
--
-- Barisnya di role_has_permissions dan model_has_permissions ikut
-- terbuang lewat kunci asing ON DELETE CASCADE milik Spatie.
-- ------------------------------------------------------------------
DELETE FROM permissions WHERE guard_name = 'web' AND name IN (
    -- Panic Button
    'view_panic_feed', 'view_panic_scope', 'create_panic_report',
    'comment_panic_report', 'moderate_panic_report', 'unmask_panic_report',
    'handle_panic_report', 'handle_panic_sekolah', 'handle_panic_capdis',
    'handle_panic_disdik', 'reply_panic', 'resolve_panic',
    -- Pemberkasan
    'view_document_submission', 'create_document_submission',
    'verify_document_submission', 'manage_document_type',
    -- CBT
    'view_cbt'
);

-- ------------------------------------------------------------------
-- 5. Pengaturan
-- ------------------------------------------------------------------
DELETE FROM settings WHERE key IN (
    'panic_pre_moderation', 'panic_feed_scope',
    'panic_process_deadline_hours', 'panic_resolve_deadline_hours',
    'panic_urgent_notify_roles', 'panic_ticket_seq'
);

-- ------------------------------------------------------------------
-- 6. Peran yang kehilangan seluruh pekerjaannya
--
-- `petugas_pengaduan` hanya ada untuk menangani laporan Panic Button.
-- Dengan modulnya hilang, peran itu tidak punya satu pun izin yang
-- menuju ke mana-mana — dan peran kosong di daftar Peran & Izin adalah
-- undangan untuk memberikannya kepada seseorang.
--
-- Dibuang HANYA bila tidak ada pengguna yang memakainya. Menghapus peran
-- yang masih melekat pada akun akan membuat akun itu kehilangan seluruh
-- aksesnya tanpa ada yang menyadarinya.
-- ------------------------------------------------------------------
DELETE FROM roles r
 WHERE r.name = 'petugas_pengaduan'
   AND r.guard_name = 'web'
   AND NOT EXISTS (
        SELECT 1 FROM model_has_roles m WHERE m.role_id = r.id
   );

-- SETELAH MIGRASI INI DIJALANKAN:  php artisan permission:cache-reset
--
-- Spatie menyimpan daftar izin di cache selama 24 jam. Tanpa perintah
-- itu, izin yang baru saja dihapus masih dianggap ada sampai cachenya
-- kedaluwarsa dengan sendirinya.
