-- =====================================================================
-- 0019 : Nomor tiket laporan dan tenggat eskalasi 3x24 jam
-- =====================================================================

-- ------------------------------------------------------------------
-- Tenggat: 168 jam -> 72 jam, mengikuti alur yang disepakati.
-- ------------------------------------------------------------------
UPDATE settings SET value = '72', updated_at = NOW()
 WHERE key IN ('panic_process_deadline_hours', 'panic_resolve_deadline_hours');

-- Tenggat laporan yang sudah berjalan ikut disesuaikan supaya tidak ada
-- laporan yang menunggu dengan aturan lama.
UPDATE panic_reports
   SET process_deadline_at = created_at + INTERVAL '72 hours'
 WHERE status NOT IN ('selesai', 'ditolak');

UPDATE panic_reports
   SET resolve_deadline_at = handled_at + INTERVAL '72 hours'
 WHERE handled_at IS NOT NULL
   AND status NOT IN ('selesai', 'ditolak');

-- ------------------------------------------------------------------
-- Nomor tiket
--
-- Siswa dan petugas merujuk laporan lewat nomor ini, bukan UUID. Bentuknya
-- LP-<tahun>-<urut>, mis. LP-2026-000123.
--
-- Keunikan dijamin SEQUENCE, bukan unique index: `panic_reports` dipartisi
-- per created_at, dan PostgreSQL menuntut setiap unique constraint pada
-- tabel terpartisi menyertakan kolom partisinya — sehingga
-- UNIQUE (ticket_no) mustahil dibuat di sana.
-- ------------------------------------------------------------------
CREATE SEQUENCE IF NOT EXISTS panic_ticket_seq;

ALTER TABLE panic_reports
    ADD COLUMN IF NOT EXISTS ticket_no VARCHAR(20);

ALTER TABLE panic_reports
    ALTER COLUMN ticket_no SET DEFAULT
        'LP-' || to_char(NOW(), 'YYYY') || '-' ||
        lpad(nextval('panic_ticket_seq')::text, 6, '0');

-- Laporan yang sudah ada diberi nomor menurut urutan waktu dibuat, supaya
-- nomor tiket dan urutan kronologis tidak bertentangan.
WITH urut AS (
    SELECT id, created_at,
           row_number() OVER (ORDER BY created_at) AS n
      FROM panic_reports
     WHERE ticket_no IS NULL
)
UPDATE panic_reports p
   SET ticket_no = 'LP-' || to_char(u.created_at, 'YYYY') || '-' ||
                   lpad(u.n::text, 6, '0')
  FROM urut u
 WHERE p.id = u.id AND p.created_at = u.created_at;

SELECT setval(
    'panic_ticket_seq',
    GREATEST((SELECT count(*) FROM panic_reports), 1)
);

ALTER TABLE panic_reports ALTER COLUMN ticket_no SET NOT NULL;

CREATE INDEX IF NOT EXISTS panic_reports_ticket_idx
    ON panic_reports (ticket_no);
