-- =====================================================================
-- 0025 : Praktik Kerja Lapangan (PKL)
--
-- Siswa terbagi dua: yang sedang PKL, dan yang tidak. Yang PKL tidak
-- berada di sekolah, sehingga tidak dapat memakai gerbang absensi wajah —
-- ia mengabsenkan diri dari ponselnya, di tempat PKL, dalam radius yang
-- ditentukan sekolah.
--
-- YANG MEMBEDAKANNYA DARI ABSENSI BIASA
--
-- Absensi sekolah dijaga PERANGKAT: tabletnya terpasang di gerbang, dan
-- hanya wajah yang membuktikan siapa yang hadir. Absensi PKL dijaga
-- TEMPAT dan WAKTU: ponselnya milik siswa sendiri, jadi yang dibuktikan
-- adalah ia berada di lokasi yang benar, pada masa penempatan yang masih
-- berlaku, dengan foto sebagai bukti yang dapat diperiksa manusia.
--
-- Keduanya tercatat di tabel `attendances` yang sama dan terlabel
-- sumbernya, karena rekap kehadiran seorang siswa tidak boleh terpecah
-- menjadi dua laporan yang harus digabung tangan.
-- =====================================================================

-- Constraint EXCLUDE di bawah membandingkan UUID dengan `=` di dalam
-- index GiST, dan GiST tidak mengenal operator itu tanpa btree_gist.
-- Migrasi 0001 hanya memasang btree_gin. Ditambahkan DI SINI, bukan
-- dengan menyunting 0001: berkas migrasi yang sudah pernah dijalankan
-- tidak boleh berubah, atau sqlx menolak start dengan "migration was
-- previously applied but has been modified".
CREATE EXTENSION IF NOT EXISTS btree_gist;

-- ------------------------------------------------------------------
-- 1. Tempat PKL
--
-- Dipisah dari penempatan siswa karena satu tempat menampung banyak
-- siswa, dan sering dipakai lagi tahun berikutnya. Menyalin koordinat ke
-- tiap siswa berarti memperbaikinya satu per satu ketika alamatnya salah.
-- ------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS pkl_places (
    id              UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    school_id       UUID NOT NULL REFERENCES schools (id) ON DELETE CASCADE,

    name            VARCHAR(150) NOT NULL,
    address         TEXT NOT NULL,
    contact_name    VARCHAR(120),
    contact_phone   VARCHAR(20),

    -- Koordinat titik absen.
    --
    -- NUMERIC, bukan DOUBLE PRECISION: lintang/bujur dibandingkan dan
    -- ditampilkan apa adanya, dan pembulatan biner pada nilai desimal
    -- membuat angka yang diketik operator tidak sama dengan yang tersimpan.
    -- Tujuh angka di belakang koma setara ~1 cm, jauh lebih teliti daripada
    -- GPS ponsel mana pun.
    latitude        NUMERIC(10, 7) NOT NULL CHECK (latitude  BETWEEN -90  AND 90),
    longitude       NUMERIC(10, 7) NOT NULL CHECK (longitude BETWEEN -180 AND 180),

    -- Radius toleransi dalam METER.
    --
    -- Batas bawah 20 m bukan kerewelan: GPS ponsel di dalam gedung
    -- meleset 10-30 m secara wajar, dan radius yang lebih ketat daripada
    -- ketelitian alatnya akan menolak siswa yang benar-benar berada di
    -- sana. Batas atas 2 km mencegah salah ketik nol yang membuat seluruh
    -- kota masuk hitungan.
    radius_meters   INT NOT NULL DEFAULT 100
                    CHECK (radius_meters BETWEEN 20 AND 2000),

    is_active       BOOLEAN NOT NULL DEFAULT TRUE,
    created_by      UUID REFERENCES users (id) ON DELETE SET NULL,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    deleted_at      TIMESTAMPTZ
);

CREATE INDEX IF NOT EXISTS pkl_places_school_idx
    ON pkl_places (school_id) WHERE deleted_at IS NULL;

-- Nama tempat unik per sekolah: dua "PT Maju Jaya" di satu sekolah
-- membuat operator memilih yang salah tanpa cara membedakannya.
CREATE UNIQUE INDEX IF NOT EXISTS pkl_places_school_name_uniq
    ON pkl_places (school_id, lower(name)) WHERE deleted_at IS NULL;

-- ------------------------------------------------------------------
-- 2. Penempatan siswa
--
-- INILAH yang menentukan seorang siswa "PKL" atau bukan. Tidak ada kolom
-- `is_pkl` pada tabel students, dan itu disengaja: status PKL selalu
-- punya masa berlaku, dan bendera boolean tidak dapat kedaluwarsa dengan
-- sendirinya. Siswa berstatus PKL adalah siswa yang HARI INI punya
-- penempatan aktif — pertanyaan yang dijawab tanggal, bukan bendera yang
-- harus diingat seseorang untuk mematikannya.
-- ------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS pkl_placements (
    id              UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    student_id      UUID NOT NULL REFERENCES students (id) ON DELETE CASCADE,
    place_id        UUID NOT NULL REFERENCES pkl_places (id) ON DELETE RESTRICT,
    school_id       UUID NOT NULL REFERENCES schools (id) ON DELETE CASCADE,

    starts_on       DATE NOT NULL,
    ends_on         DATE NOT NULL,

    -- Jam kerja di tempat PKL, yang biasanya berbeda dari jam sekolah.
    -- Kosong berarti memakai aturan jam sekolahnya.
    check_in_after  TIME,
    check_out_before TIME,

    supervisor_name  VARCHAR(120),
    supervisor_phone VARCHAR(20),
    notes           TEXT,

    status          VARCHAR(12) NOT NULL DEFAULT 'aktif'
                    CHECK (status IN ('aktif', 'selesai', 'dibatalkan')),

    created_by      UUID REFERENCES users (id) ON DELETE SET NULL,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),

    CONSTRAINT pkl_placements_masa_benar CHECK (ends_on >= starts_on)
);

CREATE INDEX IF NOT EXISTS pkl_placements_student_idx
    ON pkl_placements (student_id, starts_on DESC);
CREATE INDEX IF NOT EXISTS pkl_placements_school_idx
    ON pkl_placements (school_id, status);
CREATE INDEX IF NOT EXISTS pkl_placements_place_idx
    ON pkl_placements (place_id);

-- Satu siswa tidak boleh punya dua penempatan aktif yang masa berlakunya
-- bertumpuk. Tanpa ini, absensi hari itu tidak punya jawaban tunggal atas
-- pertanyaan "koordinat mana yang berlaku".
--
-- Memakai EXCLUDE dengan rentang tanggal, bukan UNIQUE: yang dilarang
-- bukan duplikat baris, melainkan dua masa yang beririsan.
ALTER TABLE pkl_placements
    DROP CONSTRAINT IF EXISTS pkl_placements_tidak_bertumpuk;
ALTER TABLE pkl_placements
    ADD CONSTRAINT pkl_placements_tidak_bertumpuk
    EXCLUDE USING gist (
        student_id WITH =,
        daterange(starts_on, ends_on, '[]') WITH &&
    ) WHERE (status = 'aktif');

-- ------------------------------------------------------------------
-- 3. Bukti absensi PKL
--
-- Foto, waktu, dan koordinat saat tombol absen ditekan. Dipisah dari
-- `attendances` karena hanya absensi PKL yang punya bukti seperti ini,
-- dan menambahkan enam kolom yang selalu NULL untuk 400.000 siswa
-- non-PKL membebani tabel yang paling sering dibaca di sistem ini.
-- ------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS pkl_attendance_proofs (
    id              UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    placement_id    UUID NOT NULL REFERENCES pkl_placements (id) ON DELETE CASCADE,
    student_id      UUID NOT NULL REFERENCES students (id) ON DELETE CASCADE,
    school_id       UUID NOT NULL REFERENCES schools (id) ON DELETE CASCADE,

    -- Tanggal absen dan jenisnya. Satu penempatan menghasilkan paling
    -- banyak dua bukti per hari: datang dan pulang.
    proof_date      DATE NOT NULL,
    kind            VARCHAR(10) NOT NULL CHECK (kind IN ('masuk', 'pulang')),

    -- Waktu menurut SERVER, bukan menurut ponsel.
    --
    -- `client_time` disimpan terpisah justru untuk dibandingkan: jam
    -- ponsel dapat diubah pemiliknya, dan selisih yang mencolok antara
    -- keduanya adalah tanda yang perlu dilihat manusia.
    recorded_at     TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    client_time     TIMESTAMPTZ,

    latitude        NUMERIC(10, 7) NOT NULL,
    longitude       NUMERIC(10, 7) NOT NULL,

    -- Jarak ke titik tempat PKL, dalam meter, dihitung server saat
    -- menyimpan. Disimpan supaya laporan tidak perlu menghitung ulang
    -- jarak ribuan baris setiap kali dibuka.
    distance_meters NUMERIC(8, 2) NOT NULL,

    -- Ketelitian GPS yang dilaporkan ponsel, dalam meter. Nilai besar
    -- berarti koordinatnya sendiri tidak dapat dipercaya, dan itu perlu
    -- terlihat oleh yang memeriksa.
    accuracy_meters NUMERIC(8, 2),

    -- Berkas foto. Disimpan sebagai kunci objek, bukan isi berkasnya:
    -- gambar di dalam baris basis data membuat setiap SELECT pada tabel
    -- ini menyeret megabyte yang tidak diminta.
    photo_key       VARCHAR(255) NOT NULL,
    photo_bytes     INT,
    photo_width     INT,
    photo_height    INT,

    -- Diisi bila absennya DITERIMA di luar radius oleh operator sekolah.
    -- Penolakan tidak menghasilkan baris sama sekali.
    override_by     UUID REFERENCES users (id) ON DELETE SET NULL,
    override_reason TEXT,

    created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- Satu bukti per jenis per hari per penempatan.
CREATE UNIQUE INDEX IF NOT EXISTS pkl_proofs_sekali_sehari
    ON pkl_attendance_proofs (placement_id, proof_date, kind);

CREATE INDEX IF NOT EXISTS pkl_proofs_student_idx
    ON pkl_attendance_proofs (student_id, proof_date DESC);
CREATE INDEX IF NOT EXISTS pkl_proofs_school_idx
    ON pkl_attendance_proofs (school_id, proof_date DESC);

-- ------------------------------------------------------------------
-- 4. Label sumber pada absensi
--
-- Absensi PKL masuk ke tabel yang sama, sehingga rekap seorang siswa
-- tetap satu. Yang membedakannya kolom di bawah, supaya laporan dapat
-- menjawab "hadir di sekolah atau di tempat PKL" tanpa menggabung dua
-- sumber dengan tangan.
-- ------------------------------------------------------------------
ALTER TABLE attendances
    ADD COLUMN IF NOT EXISTS source VARCHAR(10) NOT NULL DEFAULT 'sekolah';

ALTER TABLE attendances
    DROP CONSTRAINT IF EXISTS attendances_source_check;
ALTER TABLE attendances
    ADD CONSTRAINT attendances_source_check
    CHECK (source IN ('sekolah', 'pkl'));

ALTER TABLE attendances
    ADD COLUMN IF NOT EXISTS pkl_placement_id UUID;

-- `check_in_method` bertambah satu nilai: absensi PKL bukan 'face',
-- bukan 'manual', dan bukan 'import'.
ALTER TABLE attendances DROP CONSTRAINT IF EXISTS attendances_check_in_method_check;
ALTER TABLE attendances DROP CONSTRAINT IF EXISTS attendances_check_out_method_check;
ALTER TABLE attendances
    ADD CONSTRAINT attendances_check_in_method_check
    CHECK (check_in_method IN ('face', 'manual', 'import', 'pkl'));
ALTER TABLE attendances
    ADD CONSTRAINT attendances_check_out_method_check
    CHECK (check_out_method IN ('face', 'manual', 'import', 'pkl'));

-- Index parsial: baris PKL jumlahnya kecil dibanding absensi sekolah,
-- dan hampir setiap kueri yang menyebut `source` mencari yang PKL.
CREATE INDEX IF NOT EXISTS attendances_pkl_idx
    ON attendances (school_id, attendance_date) WHERE source = 'pkl';

-- ------------------------------------------------------------------
-- 5. Jarak antar koordinat
--
-- Haversine, bukan pustaka geospasial. PostGIS akan lebih tepat, tetapi
-- menambah satu ekstensi berat ke image basis data hanya untuk satu
-- perhitungan jarak titik-ke-titik. Pada jarak di bawah beberapa
-- kilometer, selisih Haversine terhadap perhitungan elipsoid jauh lebih
-- kecil daripada ketelitian GPS ponselnya sendiri.
--
-- IMMUTABLE supaya dapat dipakai di dalam index bila suatu saat perlu.
-- ------------------------------------------------------------------
CREATE OR REPLACE FUNCTION jarak_meter(
    lat1 NUMERIC, lon1 NUMERIC, lat2 NUMERIC, lon2 NUMERIC
) RETURNS NUMERIC AS $$
DECLARE
    r CONSTANT DOUBLE PRECISION := 6371000;  -- jari-jari bumi, meter
    p1 DOUBLE PRECISION := radians(lat1::double precision);
    p2 DOUBLE PRECISION := radians(lat2::double precision);
    dp DOUBLE PRECISION := radians((lat2 - lat1)::double precision);
    dl DOUBLE PRECISION := radians((lon2 - lon1)::double precision);
    a  DOUBLE PRECISION;
BEGIN
    a := sin(dp / 2) ^ 2 + cos(p1) * cos(p2) * sin(dl / 2) ^ 2;
    RETURN round((r * 2 * atan2(sqrt(a), sqrt(1 - a)))::numeric, 2);
END;
$$ LANGUAGE plpgsql IMMUTABLE;

-- ------------------------------------------------------------------
-- 6. Penempatan yang sedang berlaku
--
-- Satu tempat untuk menjawab "siswa ini hari ini PKL di mana", dipakai
-- API mobile maupun dashboard. Menyalin logikanya ke beberapa kueri
-- berarti salah satunya akan tertinggal saat aturannya berubah.
-- ------------------------------------------------------------------
CREATE OR REPLACE VIEW pkl_penempatan_berlaku AS
SELECT p.id            AS placement_id,
       p.student_id,
       p.school_id,
       p.starts_on,
       p.ends_on,
       p.check_in_after,
       p.check_out_before,
       t.id            AS place_id,
       t.name          AS place_name,
       t.address       AS place_address,
       t.latitude,
       t.longitude,
       t.radius_meters
  FROM pkl_placements p
  JOIN pkl_places t ON t.id = p.place_id AND t.deleted_at IS NULL
 WHERE p.status = 'aktif'
   AND CURRENT_DATE BETWEEN p.starts_on AND p.ends_on;

-- ------------------------------------------------------------------
-- 7. Izin
-- ------------------------------------------------------------------
INSERT INTO permissions (name, category, guard_name, created_at, updated_at) VALUES
    ('view_pkl',   'PKL', 'web', NOW(), NOW()),
    ('manage_pkl', 'PKL', 'web', NOW(), NOW())
ON CONFLICT (name, guard_name) DO UPDATE SET category = EXCLUDED.category;

-- Mengelola tempat dan penempatan: superadmin dan admin sekolah, sesuai
-- permintaan. Kepala sekolah ikut karena ia yang menandatangani
-- penempatan siswanya.
INSERT INTO role_has_permissions (role_id, permission_id)
SELECT r.id, p.id
  FROM roles r
  JOIN permissions p ON p.name = 'manage_pkl' AND p.guard_name = 'web'
 WHERE r.guard_name = 'web'
   AND r.name IN ('superadmin', 'admin_dinas', 'admin', 'kepala_sekolah')
ON CONFLICT DO NOTHING;

-- Melihat saja: pemantau berjenjang dan guru pembimbing.
INSERT INTO role_has_permissions (role_id, permission_id)
SELECT r.id, p.id
  FROM roles r
  JOIN permissions p ON p.name = 'view_pkl' AND p.guard_name = 'web'
 WHERE r.guard_name = 'web'
   AND r.name IN ('superadmin', 'admin_dinas', 'capdis', 'sekolah',
                  'admin', 'kepala_sekolah', 'guru', 'staff_tu')
ON CONFLICT DO NOTHING;

ANALYZE attendances;
