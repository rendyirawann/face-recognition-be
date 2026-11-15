-- ==================================================================
-- 0027 : Penautan Telegram orang tua lewat tautan sekali pakai
--
-- Sebelum ini, `telegram_chat_id` hanya bisa diisi operator dengan
-- tangan: orang tua menekan Start di bot, operator membuka getUpdates,
-- membaca angka chat id, lalu mengetiknya ke data wali. Untuk 1.300
-- siswa yang sebagian punya dua wali, itu bukan pekerjaan yang akan
-- selesai.
--
-- MENGAPA TOKEN, BUKAN NISN
--
-- Cara yang lebih mudah ditulis adalah meminta orang tua mengirim NISN
-- anaknya ke bot. Jangan. NISN bukan rahasia — tercetak di kartu pelajar,
-- beredar di grup kelas — sehingga siapa pun yang mengetahuinya dapat
-- mendaftarkan dirinya sebagai wali anak itu dan mulai menerima kabar
-- jam datang dan pulang anak orang lain setiap hari.
--
-- Token di sini acak, milik SATU baris wali, dan hangus begitu dipakai.
-- ==================================================================

ALTER TABLE student_guardians
    -- Acak, bukan turunan dari data wali. Token yang dapat dihitung dari
    -- NISN atau nomor telepon sama saja dengan tidak ada token.
    ADD COLUMN IF NOT EXISTS telegram_link_token VARCHAR(48),

    -- Diisi saat penautan berhasil. Dipakai dashboard untuk membedakan
    -- "belum pernah ditautkan" dari "tautannya dicabut".
    ADD COLUMN IF NOT EXISTS telegram_linked_at TIMESTAMPTZ;

-- Unik di seluruh tabel: token inilah yang menentukan baris mana yang
-- akan diisi chat id, dan dua baris dengan token sama berarti pesan
-- absensi seorang anak bisa mendarat di Telegram wali anak lain.
--
-- Parsial, karena token yang sudah terpakai dikosongkan menjadi NULL dan
-- NULL tidak boleh saling bertabrakan.
CREATE UNIQUE INDEX IF NOT EXISTS student_guardians_telegram_token_uniq
    ON student_guardians (telegram_link_token)
    WHERE telegram_link_token IS NOT NULL;

COMMENT ON COLUMN student_guardians.telegram_link_token IS
    'Token sekali pakai untuk deep link t.me/<bot>?start=<token>. '
    'Dikosongkan setelah penautan berhasil.';
