//! Praktik Kerja Lapangan: bentuk data yang diseberangkan ke aplikasi.
//!
//! Pengelolaan tempat dan penempatan TIDAK ada di sini — keduanya ditulis
//! langsung oleh dashboard lewat Eloquent, sama seperti kelas dan siswa,
//! dan penjaganya ada di basis data (`CHECK` radius, `EXCLUDE` masa
//! bertumpuk) sehingga berlaku dari jalur mana pun.
//!
//! Yang melewati API hanyalah absensinya, karena aturannya dipakai aplikasi:
//! penempatan harus masih berlaku, posisi harus di dalam radius, dan
//! fotonya harus benar-benar sampai ke penyimpanan.

use chrono::{DateTime, NaiveDate, NaiveTime, Utc};
use serde::{Deserialize, Serialize};
use sqlx::FromRow;
use utoipa::ToSchema;
use uuid::Uuid;
use validator::Validate;

/// Penempatan PKL yang berlaku hari ini, untuk ditampilkan di aplikasi.
#[derive(Debug, Serialize, FromRow, ToSchema)]
pub struct ActivePlacement {
    pub placement_id: Uuid,
    pub student_id: Uuid,

    pub place_name: String,
    pub place_address: String,

    /// Dikirim sebagai string, bukan angka.
    ///
    /// `NUMERIC(10,7)` tidak punya padanan persis di JSON: f64 membulatkan
    /// digit terakhir, dan koordinat yang bergeser beberapa sentimeter
    /// pada tampilan peta terlihat seperti data yang salah.
    pub latitude: String,
    pub longitude: String,
    pub radius_meters: i32,

    pub starts_on: NaiveDate,
    pub ends_on: NaiveDate,

    /// Sisa hari termasuk hari ini. Nol berarti hari terakhir.
    pub days_left: i64,

    pub check_in_after: Option<NaiveTime>,
    pub check_out_before: Option<NaiveTime>,

    /// Sudah absen masuk / pulang hari ini.
    pub checked_in: bool,
    pub checked_out: bool,
}

/// Permintaan absen dari aplikasi.
#[derive(Debug, Deserialize, Validate, ToSchema)]
pub struct PklCheckRequest {
    /// `masuk` atau `pulang`.
    pub kind: String,

    pub latitude: f64,
    pub longitude: f64,

    /// Ketelitian GPS yang dilaporkan ponsel, dalam meter.
    ///
    /// Ikut dinilai: koordinat dengan ketelitian 500 m tidak membuktikan
    /// apa pun tentang berada di dalam radius 100 m, dan menerimanya sama
    /// saja dengan tidak memeriksa lokasi.
    pub accuracy_meters: Option<f64>,

    /// Foto bukti, base64 (boleh berawalan `data:image/...;base64,`).
    #[validate(length(min = 100, message = "foto bukti wajib dilampirkan"))]
    pub photo_base64: String,

    /// Waktu menurut ponsel. Disimpan untuk dibandingkan dengan waktu
    /// server, bukan untuk dipercaya.
    pub client_time: Option<DateTime<Utc>>,
}

/// Jawaban setelah absen tersimpan.
#[derive(Debug, Serialize, ToSchema)]
pub struct PklCheckResponse {
    pub kind: String,
    pub recorded_at: DateTime<Utc>,

    /// Jarak dari titik tempat PKL, meter.
    pub distance_meters: f64,
    pub photo_url: String,

    /// Status absensi yang tercatat: `hadir` atau `terlambat`.
    pub status: String,
}
