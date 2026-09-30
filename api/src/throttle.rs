//! Penahan laju menyeluruh untuk seluruh endpoint.
//!
//! Endpoint yang paling rawan sudah punya penahannya sendiri dengan angka
//! yang jauh lebih ketat — login 10/menit, absen PKL 10/menit, pembuatan
//! laporan, pemasangan perangkat. Lapisan ini TIDAK menggantikan mereka;
//! ia menutup sisanya, supaya satu skrip tidak dapat menarik daftar siswa
//! atau rekap absensi ribuan kali semenit.
//!
//! KUNCINYA PER-PENGGUNA, BUKAN PER-IP
//!
//! Ini keputusan yang paling menentukan di berkas ini. Satu sekolah berada
//! di balik satu NAT: ratusan siswa membuka aplikasi dari alamat IP yang
//! sama. Menghitung per-IP berarti sekolah yang ramai menjerat dirinya
//! sendiri pada jam masuk — persis jam ketika sistem ini paling dibutuhkan.
//!
//! Karena itu permintaan yang membawa token dihitung per TOKEN, dan hanya
//! yang tidak membawa token jatuh ke IP. Lalu lintas tanpa token di sistem
//! ini hampir seluruhnya login, yang penahannya sendiri sudah jauh lebih
//! ketat.
//!
//! Tokennya di-hash sebelum dipakai sebagai kunci: token utuh di dalam
//! kunci Redis berarti kredensial yang dapat dibaca siapa pun yang dapat
//! menjalankan `KEYS *`.

use axum::{
    extract::{Request, State},
    middleware::Next,
    response::Response,
};
use sha2::{Digest, Sha256};

use crate::error::ApiResult;
use crate::state::AppState;

/// Endpoint yang dilewati.
///
/// `/health` dipanggil Docker setiap beberapa detik dan tidak boleh ikut
/// terhitung; menahannya berarti container dinyatakan tidak sehat lalu
/// dibunuh. Swagger dan skema OpenAPI dilewati karena satu halaman
/// dokumentasi memuat banyak berkas sekaligus.
fn dilewati(path: &str) -> bool {
    path.starts_with("/health")
        || path.starts_with("/docs")
        || path.starts_with("/api-docs")
        || path == "/"
}

/// Identitas pemanggil untuk penahan laju.
///
/// Diambil dari SUBJEK token, bukan dari tokennya.
///
/// Versi pertama berkas ini memakai hash token, dan itu punya lubang yang
/// terbukti saat diuji: login ulang menghasilkan token baru, dan token baru
/// berarti penghitung baru. Siapa pun yang tertahan cukup login lagi untuk
/// menyetel ulang jatahnya. Login sendiri dibatasi 10 kali per menit,
/// sehingga lubangnya melipatgandakan batas ini sepuluh kali.
///
/// Subjek token tidak berubah oleh login ulang, jadi jatahnya melekat pada
/// ORANGNYA. Verifikasi HMAC-nya sendiri hitungan mikrodetik dan tetap
/// dilakukan lagi di hilir oleh ekstraktor `AuthUser` — pengulangan yang
/// tidak berarti apa pun dibanding satu perjalanan ke Redis pada baris
/// berikutnya.
fn identitas(state: &AppState, req: &Request) -> String {
    if let Some(token) = req
        .headers()
        .get(axum::http::header::AUTHORIZATION)
        .and_then(|v| v.to_str().ok())
        .and_then(|v| v.strip_prefix("Bearer "))
    {
        if let Ok(klaim) = state.jwt.verify(token) {
            return format!("u:{}", klaim.sub);
        }

        // Token ada tetapi tidak sah. Tetap dihitung — per hash tokennya,
        // supaya pengiriman token sampah bertubi-tubi ikut tertahan alih-alih
        // jatuh ke penghitung IP yang dibagi bersama seluruh sekolah.
        let mut h = Sha256::new();
        h.update(token.as_bytes());
        let ringkas: String = h.finalize()[..8].iter().map(|b| format!("{b:02x}")).collect();
        return format!("x:{ringkas}");
    }

    format!("ip:{}", ip_pemanggil(req))
}

/// Alamat asli pemanggil.
///
/// Di belakang nginx, `remote_addr` selalu alamat nginx itu sendiri —
/// memakainya akan menyatukan SELURUH lalu lintas menjadi satu penghitung.
/// Alamat aslinya diteruskan nginx lewat X-Real-IP.
fn ip_pemanggil(req: &Request) -> String {
    req.headers()
        .get("x-real-ip")
        .or_else(|| req.headers().get("x-forwarded-for"))
        .and_then(|v| v.to_str().ok())
        .map(|v| v.split(',').next().unwrap_or(v).trim().to_string())
        .unwrap_or_else(|| "tanpa-ip".to_string())
}

/// Lapisan penahan laju.
pub async fn layer(
    State(state): State<AppState>,
    req: Request,
    next: Next,
) -> ApiResult<Response> {
    if dilewati(req.uri().path()) {
        return Ok(next.run(req).await);
    }

    state
        .rate_limit(
            &format!("global:{}", identitas(&state, &req)),
            state.cfg.rate_limit_per_minute,
            std::time::Duration::from_secs(60),
        )
        .await?;

    Ok(next.run(req).await)
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn health_dan_docs_dilewati() {
        assert!(dilewati("/health/live"));
        assert!(dilewati("/docs"));
        assert!(dilewati("/api-docs/openapi.json"));
        assert!(!dilewati("/v1/students"));
        assert!(!dilewati("/v1/me/home"));
    }

    /// `identitas` butuh AppState untuk memverifikasi token, jadi jalur
    /// tanpa token diuji lewat pembantunya yang terpisah.
    #[test]
    fn tanpa_token_memakai_ip_asli() {
        let req = Request::builder()
            .uri("/v1/auth/login")
            .header("x-real-ip", "203.0.113.7")
            .body(axum::body::Body::empty())
            .unwrap();
        assert_eq!(ip_pemanggil(&req), "203.0.113.7");
    }

    #[test]
    fn forwarded_for_berantai_memakai_yang_pertama() {
        let req = Request::builder()
            .uri("/v1/auth/login")
            .header("x-forwarded-for", "203.0.113.7, 10.0.0.1, 10.0.0.2")
            .body(axum::body::Body::empty())
            .unwrap();
        assert_eq!(ip_pemanggil(&req), "203.0.113.7");
    }
}
