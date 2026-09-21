-- =====================================================================
-- 0021 : Kotak masuk notifikasi dalam aplikasi
--
-- Terpisah dari `notification_outbox`. Outbox adalah antrean PENGIRIMAN ke
-- kanal luar (WhatsApp, Telegram, email) dan barisnya boleh dibuang setelah
-- terkirim. Tabel ini adalah kotak masuk yang DIBACA pengguna di dalam
-- aplikasi, dan hidupnya sampai dibaca lalu dibersihkan terjadwal.
--
-- Tidak dipartisi: volumenya jauh di bawah absensi. Notifikasi keterlambatan
-- beruntun hanya muncul untuk siswa yang benar-benar telat tiga hari
-- berturut-turut, bukan untuk setiap absensi.
-- =====================================================================

CREATE TABLE IF NOT EXISTS app_notifications (
    id          UUID PRIMARY KEY DEFAULT uuid_generate_v7(),
    user_id     UUID NOT NULL REFERENCES users (id) ON DELETE CASCADE,

    -- Sekolah yang menjadi konteks notifikasi. NULL untuk notifikasi yang
    -- tidak terikat sekolah tertentu.
    school_id   UUID REFERENCES schools (id) ON DELETE CASCADE,

    kind        VARCHAR(40) NOT NULL,
    title       VARCHAR(160) NOT NULL,
    body        VARCHAR(400) NOT NULL,

    -- Tujuan saat notifikasi disentuh, mis. {"student_id": "...'"} atau
    -- {"ticket_no": "LP-2026-000123"}.
    payload     JSONB NOT NULL DEFAULT '{}'::jsonb,

    -- Kunci anti-ganda. Pekerja pemeliharaan berjalan berulang kali dalam
    -- sehari; tanpa ini satu keterlambatan beruntun menghasilkan notifikasi
    -- baru pada setiap putaran.
    dedupe_key  VARCHAR(120) NOT NULL UNIQUE,

    read_at     TIMESTAMPTZ,
    created_at  TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- Daftar notifikasi dan penghitung lencana keduanya menyaring per pengguna
-- lalu mengurutkan terbaru dulu.
CREATE INDEX IF NOT EXISTS app_notifications_user_idx
    ON app_notifications (user_id, created_at DESC);

-- Penghitung "belum dibaca" dipanggil setiap kali beranda dibuka. Partial
-- index membuatnya tetap kecil karena notifikasi yang sudah dibaca keluar
-- dari index.
CREATE INDEX IF NOT EXISTS app_notifications_unread_idx
    ON app_notifications (user_id)
    WHERE read_at IS NULL;

INSERT INTO settings (key, value, created_at, updated_at) VALUES
    -- Berapa hari telat berturut-turut sebelum sekolah, capdis, dan disdik
    -- diberi tahu.
    ('late_streak_days', '3', NOW(), NOW()),
    -- Notifikasi yang sudah dibaca dibuang setelah sekian hari.
    ('app_notification_retention_days', '60', NOW(), NOW())
ON CONFLICT (key) DO NOTHING;
