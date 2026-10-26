//! Operasi vektor untuk embedding wajah.
//!
//! Konvensi: semua embedding disimpan dan dibandingkan dalam bentuk
//! L2-normalized. Dengan begitu cosine similarity = dot product, sehingga
//! pencocokan hanya butuh satu perkalian-jumlah tanpa akar kuadrat.

use sha2::{Digest, Sha256};

use crate::error::{ApiError, ApiResult};

/// Memastikan embedding masuk akal sebelum dipakai:
/// dimensi benar, tidak ada NaN/Inf, dan norm-nya tidak nol.
pub fn validate(embedding: &[f32], expected_dim: usize) -> ApiResult<()> {
    if embedding.len() != expected_dim {
        return Err(ApiError::field(
            "embedding",
            &format!(
                "dimensi embedding harus {expected_dim}, diterima {}",
                embedding.len()
            ),
        ));
    }
    if embedding.iter().any(|v| !v.is_finite()) {
        return Err(ApiError::field("embedding", "berisi nilai NaN/Infinity"));
    }
    if norm(embedding) < 1e-6 {
        return Err(ApiError::field("embedding", "vektor nol tidak valid"));
    }
    Ok(())
}

pub fn norm(v: &[f32]) -> f32 {
    v.iter().map(|x| x * x).sum::<f32>().sqrt()
}

/// Normalisasi in-place. Idempotent untuk vektor yang sudah normal.
pub fn l2_normalize(v: &mut [f32]) {
    let n = norm(v);
    if n > 1e-12 {
        let inv = 1.0 / n;
        for x in v.iter_mut() {
            *x *= inv;
        }
    }
}

pub fn normalized(v: &[f32]) -> Vec<f32> {
    let mut out = v.to_vec();
    l2_normalize(&mut out);
    out
}

/// Jarak Euclid antara dua descriptor APA ADANYA (tanpa normalisasi).
#[inline]
pub fn euclid(a: &[f32], b: &[f32]) -> f32 {
    let n = a.len().min(b.len());
    let mut s = 0.0_f32;
    for i in 0..n {
        let d = a[i] - b[i];
        s += d * d;
    }
    s.sqrt()
}

/// Skor kemiripan dari jarak Euclid: 1.0 berarti identik, makin kecil makin
/// berbeda. Dipakai supaya seluruh jalur keputusan tetap berbentuk
/// "makin besar makin cocok", sementara METRIKNYA kini jarak Euclid.
///
/// KENAPA BUKAN COSINE — INI PENYEBAB SALAH-KENAL YANG DILAPORKAN
///
/// Kode lama menormalkan descriptor lalu memakai cosine dengan ambang 0,82.
/// Ambang itu diturunkan dari aturan baku face-api (jarak Euclid < 0,6)
/// dengan anggapan descriptor ber-norm 1. Anggapan itu KELIRU: norm
/// descriptor face-api.js sesungguhnya sekitar 1,41, sehingga kesetaraan
/// 0,6 <-> 0,82 tidak berlaku.
///
/// Diukur pada 12 wajah berbeda (66 pasangan, semuanya orang berbeda):
///
///   cosine >= 0,82  ..... 59 dari 66 pasangan LOLOS  (rata-rata 0,859)
///   Euclid  < 0,6   .....  1 dari 66 pasangan lolos  (rata-rata 0,778)
///
/// Dengan kata lain, dengan cosine hampir semua orang asing dinyatakan
/// cocok. Normalisasi membuang informasi panjang vektor yang justru
/// membedakan — karena itu descriptor kini disimpan dan dibandingkan
/// apa adanya.
///
/// `cosine_normalized` beserta `dot` yang melayaninya SUDAH DIBUANG,
/// bukan sekadar tidak dipakai. Fungsi yang masih tersedia adalah
/// undangan untuk memakainya lagi, dan angka di atas menunjukkan apa
/// akibatnya.
///
/// Pembagi 2,0 memetakan jarak 0..2 ke skor 1..0, jadi ambang jarak 0,6
/// setara skor 0,70 dan selisih jarak 0,05 setara selisih skor 0,025.
#[inline]
pub fn euclid_score(a: &[f32], b: &[f32]) -> f32 {
    1.0 - euclid(a, b) / 2.0
}

/// Hash stabil sebuah embedding, untuk deteksi replay.
///
/// Nilai dikuantisasi ke 4 desimal sebelum di-hash sehingga embedding yang
/// dikirim ulang apa adanya menghasilkan hash sama, sementara wajah yang
/// benar-benar difoto ulang (selalu sedikit berbeda) tidak.
pub fn hash_embedding(embedding: &[f32]) -> Vec<u8> {
    let mut hasher = Sha256::new();
    for v in embedding {
        let q = (v * 10_000.0).round() as i32;
        hasher.update(q.to_le_bytes());
    }
    hasher.finalize().to_vec()
}

pub fn sha256(bytes: &[u8]) -> Vec<u8> {
    let mut hasher = Sha256::new();
    hasher.update(bytes);
    hasher.finalize().to_vec()
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn normalisasi_menghasilkan_norm_satu() {
        let mut v = vec![3.0, 4.0];
        l2_normalize(&mut v);
        assert!((norm(&v) - 1.0).abs() < 1e-6);
    }

    #[test]
    fn hash_stabil_dan_peka_perubahan() {
        let a = vec![0.1234_f32, -0.5, 0.9];
        assert_eq!(hash_embedding(&a), hash_embedding(&a.clone()));
        let b = vec![0.1235_f32, -0.5, 0.9];
        assert_ne!(hash_embedding(&a), hash_embedding(&b));
    }

    #[test]
    fn validasi_menolak_dimensi_salah() {
        assert!(validate(&[1.0, 0.0], 3).is_err());
        assert!(validate(&[1.0, 0.0], 2).is_ok());
    }
}
