-- =====================================================================
-- 0022 : Gabungkan kabupaten/kota duplikat dari seed 0020
--
-- Migrasi 0020 membuat 35 baris kabupaten/kota berkode sintetis K01..K35,
-- padahal 33 kabupaten/kota Sumatera Utara SUDAH ada sejak awal dengan kode
-- BPS yang benar (1201, 1207, dan seterusnya). Akibatnya setiap wilayah
-- punya dua baris, dan sekolah menunjuk ke salinan yang salah.
--
-- Tidak ketahuan saat menulis 0020 karena basis datanya belum pernah
-- dijalankan; baru terlihat begitu migrasinya benar-benar berjalan.
--
-- Dua dari 35 baris itu bahkan duplikat di dalam seed sendiri: xlsx menulis
-- "KOTA GUNUNGSITOLI" dan "Kota Gunungsitoli" sebagai dua nama berbeda,
-- sedangkan pembangkitnya membedakan huruf besar-kecil.
--
-- Yang dilakukan di sini: sekolah dipindahkan ke region resmi, induk
-- Cabang Dinas dipasang pada region resmi itu, lalu baris sintetisnya
-- dibuang.
-- =====================================================================

-- ------------------------------------------------------------------
-- 1. Region resmi mewarisi induk Cabang Dinas dari pasangan sintetisnya
-- ------------------------------------------------------------------
UPDATE regions o
   SET parent_id = s.parent_id, updated_at = NOW()
  FROM regions s
 WHERE s.code ~ '^K[0-9]{2}$'
   AND o.code !~ '^K[0-9]{2}$'
   AND o.kind IN ('kabupaten', 'kota')
   AND lower(o.name) = lower(s.name)
   AND s.parent_id IS NOT NULL;

-- ------------------------------------------------------------------
-- 2. Sekolah dipindahkan ke region resmi
-- ------------------------------------------------------------------
UPDATE schools sc
   SET region_id = o.id, updated_at = NOW()
  FROM regions s
  JOIN regions o
    ON lower(o.name) = lower(s.name)
   AND o.code !~ '^K[0-9]{2}$'
   AND o.kind IN ('kabupaten', 'kota')
 WHERE sc.region_id = s.id
   AND s.code ~ '^K[0-9]{2}$';

-- ------------------------------------------------------------------
-- 3. Pengguna yang terlanjur menunjuk region sintetis ikut dipindahkan
--
-- Belum ada akun capdis yang menunjuk ke sini — cakupannya ada di tingkat
-- cabang_dinas, bukan kabupaten. Disertakan sebagai pengaman bila ada akun
-- lain yang dibuat sebelum migrasi ini berjalan.
-- ------------------------------------------------------------------
UPDATE users u
   SET region_id = o.id, updated_at = NOW()
  FROM regions s
  JOIN regions o
    ON lower(o.name) = lower(s.name)
   AND o.code !~ '^K[0-9]{2}$'
   AND o.kind IN ('kabupaten', 'kota')
 WHERE u.region_id = s.id
   AND s.code ~ '^K[0-9]{2}$';

-- ------------------------------------------------------------------
-- 4. Buang baris sintetis
--
-- Dijalankan setelah seluruh penunjuk dipindahkan. `regions.parent_id`
-- memakai ON DELETE SET NULL, jadi baris yang masih tersisa sebagai induk
-- tidak akan menggagalkan penghapusan — karena itu urutannya penting.
-- ------------------------------------------------------------------
DELETE FROM regions WHERE code ~ '^K[0-9]{2}$';
