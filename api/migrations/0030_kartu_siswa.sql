-- ==================================================================
-- 0030 : Kartu siswa (barcode / QR) sebagai cara absen kedua
--
-- Selain wajah, siswa dapat absen dengan memindai kartunya di tablet
-- kios. Aturan jam masuk/pulang, terlambat, libur, dan notifikasi ke
-- orang tua SAMA PERSIS dengan absen wajah — hanya cara mengenali
-- siswanya yang berbeda.
--
-- MENGAPA KODE ACAK, BUKAN NISN
--
-- NISN tercantum di rapor, ijazah, dan situs Dapodik. Kartu yang isinya
-- NISN dapat dibuat siapa saja untuk siapa saja. Kode acak hanya ada di
-- kartu yang dicetak sekolah, dan kartu yang hilang cukup diterbitkan
-- ulang: kode lama langsung tidak berlaku, NISN tidak bisa diganti.
--
-- 12 karakter heksadesimal huruf besar: cukup pendek untuk barcode
-- batang yang masih terbaca pemindai murah pada lebar kartu (85,6 mm),
-- dan 16^12 kemungkinan membuat menebak kode siswa lain tidak praktis.
--
-- KETERBATASAN: kartu dapat difotokopi atau dipinjamkan. Absen kartu
-- tidak membuktikan siapa yang berdiri di depan tablet; absen wajah
-- membuktikannya. Sekolah memilih sendiri mana yang dipakai.
-- ==================================================================

ALTER TABLE students
    ADD COLUMN IF NOT EXISTS card_code VARCHAR(16),
    ADD COLUMN IF NOT EXISTS card_issued_at TIMESTAMPTZ;

UPDATE students
   SET card_code = upper(substr(md5(gen_random_uuid()::text), 1, 12)),
       card_issued_at = NOW()
 WHERE card_code IS NULL;

ALTER TABLE students
    ALTER COLUMN card_code SET DEFAULT upper(substr(md5(gen_random_uuid()::text), 1, 12)),
    ALTER COLUMN card_issued_at SET DEFAULT NOW();

CREATE UNIQUE INDEX IF NOT EXISTS students_card_code_uq
    ON students (card_code) WHERE card_code IS NOT NULL;

-- Cara absen bertambah satu: 'card'.
ALTER TABLE attendances DROP CONSTRAINT IF EXISTS attendances_check_in_method_check;
ALTER TABLE attendances DROP CONSTRAINT IF EXISTS attendances_check_out_method_check;
ALTER TABLE attendances
    ADD CONSTRAINT attendances_check_in_method_check
    CHECK (check_in_method IN ('face', 'manual', 'import', 'pkl', 'card'));
ALTER TABLE attendances
    ADD CONSTRAINT attendances_check_out_method_check
    CHECK (check_out_method IN ('face', 'manual', 'import', 'pkl', 'card'));
