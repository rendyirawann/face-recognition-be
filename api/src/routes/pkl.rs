//! Absensi siswa PKL dari ponsel.
//!
//! BEDANYA DENGAN ABSENSI SEKOLAH
//!
//! Absensi sekolah dijaga PERANGKAT: tabletnya terpasang di gerbang, dan
//! wajahlah yang membuktikan siapa yang hadir. Di sini perangkatnya ponsel
//! siswa sendiri, jadi yang dapat dibuktikan berbeda — bahwa ia berada di
//! tempat yang benar, pada masa penempatan yang masih berlaku, dengan foto
//! yang dapat diperiksa manusia bila ada yang meragukan.
//!
//! Tidak ada pengenalan wajah di jalur ini, dan itu disengaja: mencocokkan
//! wajah di ponsel pribadi hanya memindahkan titipan dari "menitip kartu"
//! menjadi "menitip ponsel", sementara biayanya jauh lebih besar.

use axum::{
    extract::State,
    routing::{get, post},
    Router,
};
use chrono::{Local, NaiveDate, Timelike, Utc};
use sqlx::Row;
use uuid::Uuid;
use validator::Validate;

use crate::auth::AuthUser;
use crate::domain::pkl::{ActivePlacement, PklCheckRequest, PklCheckResponse};
use crate::error::{ApiError, ApiResult};
use crate::services::storage::Storage;
use crate::state::AppState;
use crate::extract::ValidJson;
use crate::util::ApiResponse;

pub fn router() -> Router<AppState> {
    Router::new()
        .route("/me/pkl", get(active_placement))
        .route("/me/pkl/attendance", post(check))
}

/// Sisi terpanjang foto bukti setelah dikecilkan.
///
/// Foto ini dilihat manusia di layar untuk memastikan siswanya memang di
/// sana — 1280 px sudah lebih dari cukup untuk itu. Kamera ponsel kini
/// menghasilkan 4000 px lebih, dan menyimpannya utuh berarti ~4 MB per
/// absen; dengan dua absen per hari, satu siswa saja menghabiskan 1,5 GB
/// per tahun ajaran.
const SISI_MAKS: u32 = 1280;

/// Mutu JPEG hasil kompresi.
///
/// 82 adalah titik di mana selisihnya tidak lagi terlihat mata pada foto
/// biasa, sementara berkasnya tinggal seperlima. Di atas 90 ukurannya naik
/// tajam tanpa perbedaan yang dapat dilihat.
const MUTU_JPEG: u8 = 82;

/// Ketelitian GPS terburuk yang masih diterima, meter.
///
/// Koordinat dengan ketelitian 200 m tidak membuktikan apa pun tentang
/// berada di dalam radius 100 m. Menerimanya sama saja dengan tidak
/// memeriksa lokasi sama sekali.
const AKURASI_MAKS: f64 = 150.0;

// =====================================================================
// GET /v1/me/pkl
// =====================================================================

/// Penempatan PKL yang berlaku hari ini.
#[utoipa::path(
    get, path = "/v1/me/pkl", tag = "PKL",
    responses(
        (status = 200, description = "Penempatan aktif", body = ActivePlacement),
        (status = 404, description = "Tidak sedang PKL")
    )
)]
pub async fn active_placement(
    State(state): State<AppState>,
    user: AuthUser,
) -> ApiResult<ApiResponse<ActivePlacement>> {
    let student_id = student_id_dari(&user)?;
    let p = muat_penempatan(&state, student_id).await?;
    Ok(ApiResponse::new(p))
}

// =====================================================================
// POST /v1/me/pkl/attendance
// =====================================================================

/// Absen masuk atau pulang dari tempat PKL.
#[utoipa::path(
    post, path = "/v1/me/pkl/attendance", tag = "PKL",
    request_body = PklCheckRequest,
    responses(
        (status = 200, description = "Absensi tercatat", body = PklCheckResponse),
        (status = 403, description = "Di luar radius, di luar masa PKL, atau GPS tidak teliti"),
        (status = 404, description = "Tidak sedang PKL"),
        (status = 409, description = "Sudah absen untuk jenis ini hari ini")
    )
)]
pub async fn check(
    State(state): State<AppState>,
    user: AuthUser,
    ValidJson(body): ValidJson<PklCheckRequest>,
) -> ApiResult<ApiResponse<PklCheckResponse>> {
    body.validate()?;

    if body.kind != "masuk" && body.kind != "pulang" {
        return Err(ApiError::field("kind", "hanya `masuk` atau `pulang`"));
    }

    let student_id = student_id_dari(&user)?;

    // Penahan laju: absen itu dua kali sehari, bukan berkali-kali semenit.
    // Batas longgar supaya percobaan ulang setelah sinyal putus tidak ikut
    // terhalang, tetapi cukup untuk menghentikan pengiriman beruntun.
    state
        .rate_limit(
            &format!("pkl:{student_id}"),
            10,
            std::time::Duration::from_secs(60),
        )
        .await?;

    // --- 1. Penempatan harus masih berlaku -----------------------------
    //
    // Inilah yang membuat "masa PKL habis, tidak bisa absen lagi" bekerja
    // tanpa pekerjaan terjadwal apa pun: penempatannya tidak lagi memenuhi
    // syarat tanggal, jadi kuerinya tidak mengembalikan apa-apa.
    let p = muat_penempatan(&state, student_id).await?;

    // --- 2. GPS harus cukup teliti untuk dipercaya ---------------------
    if let Some(akurasi) = body.accuracy_meters {
        if akurasi > AKURASI_MAKS {
            return Err(ApiError::Forbidden(format!(
                "Ketelitian GPS {akurasi:.0} m terlalu rendah untuk dipakai \
                 sebagai bukti lokasi. Keluar ke tempat terbuka lalu coba lagi."
            )));
        }
    }

    // --- 3. Harus berada di dalam radius -------------------------------
    //
    // Jaraknya dihitung PostgreSQL dengan fungsi yang sama yang dipakai
    // laporan, bukan dihitung ulang di Rust. Dua rumus jarak yang ditulis
    // terpisah pasti berbeda di angka desimalnya, dan perbedaan itu muncul
    // sebagai absen yang diterima aplikasi tetapi ditolak laporan.
    let jarak: f64 = sqlx::query_scalar(
        "SELECT jarak_meter($1::numeric, $2::numeric, $3::numeric, $4::numeric)::float8",
    )
    .bind(p.latitude.parse::<f64>().unwrap_or_default())
    .bind(p.longitude.parse::<f64>().unwrap_or_default())
    .bind(body.latitude)
    .bind(body.longitude)
    .fetch_one(&state.db)
    .await?;

    if jarak > p.radius_meters as f64 {
        return Err(ApiError::Forbidden(format!(
            "Anda berada {jarak:.0} m dari {} — di luar radius {} m yang \
             ditetapkan sekolah. Absen hanya dapat dilakukan di tempat PKL.",
            p.place_name, p.radius_meters
        )));
    }

    // --- 4. Foto: dikecilkan dan dikompresi sebelum disimpan -----------
    let (foto, lebar, tinggi) = kompres_foto(&body.photo_base64)?;

    let hari_ini = Local::now().date_naive();
    let key = Storage::pkl_key(
        p_school_id(&state, p.placement_id).await?,
        &hari_ini.format("%Y-%m-%d").to_string(),
        student_id,
    );
    state.storage.put(&key, &foto).await?;

    // --- 5. Simpan bukti ------------------------------------------------
    let school_id = p_school_id(&state, p.placement_id).await?;

    let tersimpan = sqlx::query(
        r#"
        INSERT INTO pkl_attendance_proofs
            (placement_id, student_id, school_id, proof_date, kind,
             client_time, latitude, longitude, distance_meters,
             accuracy_meters, photo_key, photo_bytes, photo_width, photo_height)
        VALUES ($1, $2, $3, $4, $5, $6, $7::numeric, $8::numeric,
                $9::numeric, $10::numeric, $11, $12, $13, $14)
        ON CONFLICT (placement_id, proof_date, kind) DO NOTHING
        RETURNING recorded_at
        "#,
    )
    .bind(p.placement_id)
    .bind(student_id)
    .bind(school_id)
    .bind(hari_ini)
    .bind(&body.kind)
    .bind(body.client_time)
    .bind(body.latitude)
    .bind(body.longitude)
    .bind(jarak)
    .bind(body.accuracy_meters)
    .bind(&key)
    .bind(foto.len() as i32)
    .bind(lebar as i32)
    .bind(tinggi as i32)
    .fetch_optional(&state.db)
    .await?;

    // ON CONFLICT DO NOTHING mengembalikan nol baris bila sudah ada. Fotonya
    // sudah terlanjur ditulis, jadi dibuang lagi — berkas yatim yang tidak
    // dirujuk baris mana pun tidak akan pernah terhapus dengan sendirinya.
    let Some(row) = tersimpan else {
        let _ = state.storage.delete(&key).await;
        return Err(ApiError::Conflict(format!(
            "Anda sudah absen {} hari ini.",
            body.kind
        )));
    };
    let recorded_at: chrono::DateTime<Utc> = row.try_get("recorded_at")?;

    // --- 6. Catat ke tabel absensi yang sama dengan absensi sekolah -----
    let status = catat_absensi(&state, &p, student_id, school_id, &body.kind, hari_ini).await?;

    Ok(ApiResponse::new(PklCheckResponse {
        kind: body.kind.clone(),
        recorded_at,
        distance_meters: jarak,
        photo_url: state.storage.public_url(&key),
        status,
    }))
}

// =====================================================================
// Pembantu
// =====================================================================

/// Siswa yang sedang login.
///
/// Akun guru atau operator tidak punya `student_id`, dan menolaknya di sini
/// membuat sisa fungsi tidak perlu memikirkan kasus itu lagi.
fn student_id_dari(user: &AuthUser) -> ApiResult<Uuid> {
    // `students` memuat SEMUA siswa yang boleh diakses akun ini. Untuk akun
    // siswa isinya tepat satu; akun orang tua bisa beberapa, dan akun
    // pegawai tidak bercakupan siswa sama sekali (daftar kosong, karena
    // cakupannya sekolah). Absensi PKL hanya untuk siswa itu sendiri.
    if !user.roles.iter().any(|r| r == "siswa") {
        return Err(ApiError::Forbidden(
            "Absensi PKL hanya untuk akun siswa.".into(),
        ));
    }

    user.students.first().copied().ok_or_else(|| {
        ApiError::Forbidden(
            "Akun Anda belum ditautkan ke data siswa. Hubungi operator sekolah.".into(),
        )
    })
}

/// Penempatan yang berlaku hari ini, beserta status absen hari ini.
async fn muat_penempatan(state: &AppState, student_id: Uuid) -> ApiResult<ActivePlacement> {
    let row = sqlx::query(
        r#"
        SELECT v.placement_id, v.student_id,
               v.place_name, v.place_address,
               v.latitude::text  AS latitude,
               v.longitude::text AS longitude,
               v.radius_meters,
               v.starts_on, v.ends_on,
               (v.ends_on - CURRENT_DATE)         AS days_left,
               v.check_in_after, v.check_out_before,
               EXISTS (SELECT 1 FROM pkl_attendance_proofs b
                        WHERE b.placement_id = v.placement_id
                          AND b.proof_date = CURRENT_DATE
                          AND b.kind = 'masuk')   AS checked_in,
               EXISTS (SELECT 1 FROM pkl_attendance_proofs b
                        WHERE b.placement_id = v.placement_id
                          AND b.proof_date = CURRENT_DATE
                          AND b.kind = 'pulang')  AS checked_out
          FROM pkl_penempatan_berlaku v
         WHERE v.student_id = $1
        "#,
    )
    .bind(student_id)
    .fetch_optional(&state.db)
    .await?;

    // ApiError::NotFound menerima KATA BENDA, bukan kalimat: pembentuknya
    // menambahkan "tidak ditemukan" di belakang, sehingga kalimat utuh di
    // sini menghasilkan pesan yang canggung. Penjelasan lengkapnya ada di
    // aplikasi, yang tahu konteksnya dan punya ruang untuk itu.
    let row = row.ok_or_else(|| ApiError::NotFound("penempatan PKL yang berlaku".into()))?;

    Ok(ActivePlacement {
        placement_id: row.try_get("placement_id")?,
        student_id: row.try_get("student_id")?,
        place_name: row.try_get("place_name")?,
        place_address: row.try_get("place_address")?,
        latitude: row.try_get("latitude")?,
        longitude: row.try_get("longitude")?,
        radius_meters: row.try_get("radius_meters")?,
        starts_on: row.try_get("starts_on")?,
        ends_on: row.try_get("ends_on")?,
        days_left: row.try_get::<i32, _>("days_left")? as i64,
        check_in_after: row.try_get("check_in_after")?,
        check_out_before: row.try_get("check_out_before")?,
        checked_in: row.try_get("checked_in")?,
        checked_out: row.try_get("checked_out")?,
    })
}

async fn p_school_id(state: &AppState, placement_id: Uuid) -> ApiResult<Uuid> {
    Ok(
        sqlx::query_scalar("SELECT school_id FROM pkl_placements WHERE id = $1")
            .bind(placement_id)
            .fetch_one(&state.db)
            .await?,
    )
}

/// Kecilkan lalu kompresi foto bukti.
///
/// Selalu dikeluarkan sebagai JPEG, apa pun format masuknya: PNG dari
/// sebagian ponsel berukuran berkali lipat untuk foto kamera, dan menyimpan
/// dua format berarti dua jalur yang harus dijaga saat menampilkannya.
fn kompres_foto(base64_str: &str) -> ApiResult<(Vec<u8>, u32, u32)> {
    use base64::Engine as _;

    let mentah = base64::engine::general_purpose::STANDARD
        .decode(buang_data_uri(base64_str))
        .map_err(|_| ApiError::field("photo_base64", "bukan base64 yang valid"))?;

    // Batas sebelum decoding: gambar 100 MP yang sah secara format tetap
    // dapat menghabiskan memori saat dibuka.
    if mentah.len() > 12 * 1024 * 1024 {
        return Err(ApiError::field(
            "photo_base64",
            "foto melebihi 12 MB. Kecilkan resolusi kamera lalu coba lagi.",
        ));
    }

    let img = image::load_from_memory(&mentah)
        .map_err(|_| ApiError::field("photo_base64", "berkas ini bukan gambar yang dikenali"))?;

    // `thumbnail` mempertahankan rasio dan hanya MENGECILKAN; gambar yang
    // sudah lebih kecil dari batas dibiarkan apa adanya, bukan diperbesar
    // menjadi buram.
    let kecil = if img.width() > SISI_MAKS || img.height() > SISI_MAKS {
        img.thumbnail(SISI_MAKS, SISI_MAKS)
    } else {
        img
    };

    let (lebar, tinggi) = (kecil.width(), kecil.height());

    let mut keluar = std::io::Cursor::new(Vec::new());
    kecil
        .to_rgb8()
        .write_with_encoder(image::codecs::jpeg::JpegEncoder::new_with_quality(
            &mut keluar,
            MUTU_JPEG,
        ))
        .map_err(|e| ApiError::Internal(anyhow::anyhow!("gagal mengompresi foto: {e}")))?;

    Ok((keluar.into_inner(), lebar, tinggi))
}

fn buang_data_uri(s: &str) -> &str {
    let t = s.trim();
    match t.find(";base64,") {
        Some(i) if t.starts_with("data:") => &t[i + 8..],
        _ => t,
    }
}

/// Tulis atau perbarui baris di `attendances`.
///
/// Tabel yang SAMA dengan absensi sekolah, dibedakan `source = 'pkl'`.
/// Rekap kehadiran seorang siswa tidak boleh terpecah menjadi dua laporan
/// yang harus digabung tangan.
async fn catat_absensi(
    state: &AppState,
    p: &ActivePlacement,
    student_id: Uuid,
    school_id: Uuid,
    kind: &str,
    hari_ini: NaiveDate,
) -> ApiResult<String> {
    let sekarang = Local::now();

    // Terlambat bila lewat jam masuk yang ditetapkan tempat PKL. Bila
    // sekolah tidak mengisinya, tidak ada dasar untuk menyatakan terlambat
    // — dan menebak jam kerja tempat orang lain bukan tugas sistem ini.
    let (status, telat) = match (kind, p.check_in_after) {
        ("masuk", Some(batas)) => {
            let kini = chrono::NaiveTime::from_hms_opt(
                sekarang.hour(),
                sekarang.minute(),
                sekarang.second(),
            )
            .unwrap_or(batas);
            if kini > batas {
                let menit = (kini - batas).num_minutes().max(0) as i32;
                ("terlambat".to_string(), menit)
            } else {
                ("hadir".to_string(), 0)
            }
        }
        _ => ("hadir".to_string(), 0),
    };

    if kind == "masuk" {
        sqlx::query(
            r#"
            INSERT INTO attendances
                (attendance_date, school_id, student_id, classroom_id,
                 student_name, school_name, classroom_name,
                 check_in_at, status, late_minutes,
                 check_in_method, source, pkl_placement_id)
            SELECT $1, $2, s.id, s.current_classroom_id,
                   s.full_name, sc.name, c.name,
                   NOW(), $3, $4, 'pkl', 'pkl', $5
              FROM students s
              JOIN schools sc ON sc.id = s.school_id
              LEFT JOIN classrooms c ON c.id = s.current_classroom_id
             WHERE s.id = $6
            ON CONFLICT (attendance_date, student_id) DO UPDATE
                SET check_in_at = COALESCE(attendances.check_in_at, EXCLUDED.check_in_at),
                    status      = EXCLUDED.status,
                    late_minutes = EXCLUDED.late_minutes,
                    check_in_method = 'pkl',
                    source      = 'pkl',
                    pkl_placement_id = EXCLUDED.pkl_placement_id,
                    updated_at  = NOW()
            "#,
        )
        .bind(hari_ini)
        .bind(school_id)
        .bind(&status)
        .bind(telat)
        .bind(p.placement_id)
        .bind(student_id)
        .execute(&state.db)
        .await?;
    } else {
        sqlx::query(
            r#"
            UPDATE attendances
               SET check_out_at = NOW(),
                   check_out_method = 'pkl',
                   updated_at = NOW()
             WHERE student_id = $1 AND attendance_date = $2
            "#,
        )
        .bind(student_id)
        .bind(hari_ini)
        .execute(&state.db)
        .await?;
    }

    Ok(status)
}
