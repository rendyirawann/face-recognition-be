//! Worker pemeliharaan harian.
//!
//! Empat tugas yang harus tetap berjalan tanpa campur tangan operator:
//!
//! 1. **Partisi** — membuat partisi bulan berikutnya sebelum dibutuhkan.
//!    Tanpa ini, INSERT absensi awal bulan akan jatuh ke partisi DEFAULT dan
//!    perlahan melambat.
//! 2. **Tandai alfa** — siswa yang tidak pernah discan hari itu belum punya
//!    baris absensi sama sekali; tanpa langkah ini orang tuanya tidak pernah
//!    diberi tahu dan rekap bulanan menghitungnya sebagai "tidak ada data".
//! 3. **Rollup** — mengisi `attendance_daily_summary` agar dashboard tidak
//!    perlu memindai tabel 160 juta baris.
//! 4. **Keterlambatan beruntun** — beri tahu sekolah, capdis, dan disdik
//!    bila seorang siswa telat beberapa hari sekolah berturut-turut.
//! 5. **Bersih-bersih** — buang token kedaluwarsa, heartbeat lama, dan
//!    kunci idempotensi yang sudah lewat.

use std::time::Duration;

use chrono::{NaiveTime, Timelike};
use uuid::Uuid;

use crate::services::notify::{self, AttendanceNotifyContext, NotifyEvent};
use crate::services::reports;
use crate::state::AppState;
use crate::util;

/// Seberapa sering worker bangun untuk memeriksa apakah ada tugas jatuh tempo.
const TICK: Duration = Duration::from_secs(60);

pub async fn run(state: AppState, shutdown: tokio::sync::watch::Receiver<bool>) {
    tracing::info!("worker pemeliharaan berjalan");

    // Sekali di awal, agar instance baru langsung punya partisi yang benar.
    if let Err(e) = ensure_partitions(&state).await {
        tracing::error!(error = ?e, "gagal menyiapkan partisi saat start");
    }

    let mut last_absentee_run: Option<chrono::NaiveDate> = None;
    let mut last_streak_run: Option<chrono::NaiveDate> = None;
    let mut last_rollup_hour: Option<u32> = None;
    let mut shutdown = shutdown;

    loop {
        tokio::select! {
            _ = shutdown.changed() => {
                if *shutdown.borrow() {
                    tracing::info!("worker pemeliharaan berhenti");
                    return;
                }
            }
            _ = tokio::time::sleep(TICK) => {
                let now = util::now_wib();
                let today = now.date_naive();

                // Tandai alfa setelah jam yang ditetapkan tiap sekolah.
                match mark_absentees_due(&state, last_absentee_run).await {
                    Ok(ran) if ran => last_absentee_run = Some(today),
                    Ok(_) => {}
                    Err(e) => tracing::error!(error = ?e, "gagal menandai siswa alfa"),
                }

                // Keterlambatan beruntun, sekali sehari setelah jam alfa
                // supaya absensi hari itu sudah lengkap.
                if now.hour() >= 10 {
                    match notify_late_streaks(&state, last_streak_run).await {
                        Ok(true) => last_streak_run = Some(today),
                        Ok(false) => {}
                        Err(e) => tracing::error!(
                            error = ?e, "gagal memeriksa keterlambatan beruntun"
                        ),
                    }
                }

                // Rollup tiap jam.
                if last_rollup_hour != Some(now.hour()) {
                    if let Err(e) = reports::rebuild_daily_rollup(&state.db, today).await {
                        tracing::error!(error = ?e, "gagal membangun rollup harian");
                    } else {
                        last_rollup_hour = Some(now.hour());
                    }
                }

                // Tugas tengah malam.
                if now.hour() == 0 && now.minute() < 2 {
                    if let Err(e) = ensure_partitions(&state).await {
                        tracing::error!(error = ?e, "gagal membuat partisi");
                    }
                    if let Err(e) = cleanup(&state).await {
                        tracing::error!(error = ?e, "gagal bersih-bersih");
                    }
                    // Rollup ulang hari sebelumnya setelah semua koreksi masuk.
                    let yesterday = today.pred_opt().unwrap_or(today);
                    if let Err(e) = reports::rebuild_daily_rollup(&state.db, yesterday).await {
                        tracing::error!(error = ?e, "gagal rollup hari sebelumnya");
                    }
                    // Buang index wajah yang lama tidak dipakai.
                    let evicted = state.face_index.evict_stale(Duration::from_secs(6 * 3600));
                    if evicted > 0 {
                        tracing::info!(evicted, "index wajah sekolah yang menganggur dibuang");
                    }
                }
            }
        }
    }
}

async fn ensure_partitions(state: &AppState) -> anyhow::Result<()> {
    sqlx::query("SELECT ensure_attendance_partitions(3)")
        .execute(&state.db)
        .await?;
    sqlx::query("SELECT ensure_outbox_partitions(3)")
        .execute(&state.db)
        .await?;
    tracing::info!("partisi bulanan dipastikan tersedia");
    Ok(())
}

/// Untuk setiap sekolah yang jam batas absennya sudah lewat, tandai siswa
/// yang belum absen sebagai `alfa` dan antrikan notifikasi ke wali.
///
/// `last_run` mencegah pekerjaan sama diulang berkali-kali dalam sehari.
async fn mark_absentees_due(
    state: &AppState,
    last_run: Option<chrono::NaiveDate>,
) -> anyhow::Result<bool> {
    let now = util::now_wib();
    let today = now.date_naive();

    if last_run == Some(today) {
        return Ok(false);
    }

    // Sekolah yang jam `absent_notify_after`-nya sudah terlewati.
    let due: Vec<(Uuid, String, NaiveTime)> = sqlx::query_as(
        r#"
        SELECT s.id, s.name, COALESCE(p.absent_notify_after, TIME '09:30')
        FROM schools s
        LEFT JOIN notification_policies p ON p.school_id = s.id
        WHERE s.is_active AND s.deleted_at IS NULL
          AND COALESCE(p.absent_notify_after, TIME '09:30') <= $1
        "#,
    )
    .bind(now.time())
    .fetch_all(&state.db)
    .await?;

    if due.is_empty() {
        return Ok(false);
    }

    let mut total_marked = 0u64;
    let mut schools_done = 0usize;

    for (school_id, school_name, _) in due {
        let rule =
            crate::services::rules::resolve_rule(&state.db, school_id, None, today).await?;
        if !rule.is_active_day(today) {
            continue;
        }
        if crate::services::rules::holiday_name(&state.db, school_id, today)
            .await?
            .is_some()
        {
            continue;
        }

        let marked = reports::mark_absentees(&state.db, school_id, today).await?;
        total_marked += marked;
        schools_done += 1;

        if marked > 0 && state.cfg.notify.enabled {
            if let Err(e) = notify_absentees(state, school_id, &school_name, today).await {
                tracing::error!(%school_id, error = ?e, "gagal mengantrikan notifikasi alfa");
            }
        }
    }

    if schools_done > 0 {
        tracing::info!(
            schools = schools_done,
            marked = total_marked,
            "penandaan siswa alfa selesai"
        );
    }

    // Dianggap "sudah dijalankan hari ini" hanya bila ada sekolah yang benar
    // benar diproses; kalau semua masih di luar jam, coba lagi nanti.
    Ok(schools_done > 0)
}

async fn notify_absentees(
    state: &AppState,
    school_id: Uuid,
    school_name: &str,
    date: chrono::NaiveDate,
) -> anyhow::Result<()> {
    #[derive(sqlx::FromRow)]
    struct Row {
        id: Uuid,
        student_id: Uuid,
        student_name: String,
        student_nis: Option<String>,
        classroom_name: Option<String>,
    }

    let rows: Vec<Row> = sqlx::query_as(
        r#"
        SELECT id, student_id, student_name, student_nis, classroom_name
        FROM attendances
        WHERE school_id = $1
          AND attendance_date = $2
          AND status = 'alfa'
          AND notification_status = 'pending'
        LIMIT 5000
        "#,
    )
    .bind(school_id)
    .bind(date)
    .fetch_all(&state.db)
    .await?;

    for row in rows {
        let mut tx = state.db.begin().await?;
        let ctx = AttendanceNotifyContext {
            school_id,
            school_name: school_name.to_string(),
            student_id: row.student_id,
            student_name: row.student_name,
            student_nis: row.student_nis,
            classroom_name: row.classroom_name,
            attendance_id: row.id,
            attendance_date: date,
            status: crate::domain::attendance::AttendanceStatus::Alfa,
            check_in_at: None,
            check_out_at: None,
            late_minutes: 0,
        };
        let queued = notify::enqueue_attendance(&mut tx, &ctx, NotifyEvent::Absent).await?;
        if queued == 0 {
            // Tidak ada wali yang bisa dihubungi: tandai `skipped` agar baris
            // ini tidak diperiksa terus-menerus setiap siklus worker.
            sqlx::query(
                r#"
                UPDATE attendances SET notification_status = 'skipped'
                WHERE attendance_date = $1 AND id = $2
                "#,
            )
            .bind(date)
            .bind(row.id)
            .execute(&mut *tx)
            .await?;
        }
        tx.commit().await?;
    }

    Ok(())
}

/// Beri tahu sekolah, capdis, dan disdik tentang siswa yang telat berturut-turut.
///
/// Rentetan dihitung dari BARIS ABSENSI, bukan hari kalender: hanya hari
/// sekolah yang punya baris, sehingga akhir pekan dan libur tidak memutus
/// rentetan, sementara satu hari hadir tepat waktu memutusnya.
///
/// Ambang harinya diambil dari setelan `late_streak_days`.
async fn notify_late_streaks(
    state: &AppState,
    last_run: Option<chrono::NaiveDate>,
) -> anyhow::Result<bool> {
    let today = util::now_wib().date_naive();
    if last_run == Some(today) {
        return Ok(false);
    }

    let days: i64 = sqlx::query_scalar(
        "SELECT COALESCE((SELECT value::int FROM settings WHERE key = 'late_streak_days'), 3)",
    )
    .fetch_one(&state.db)
    .await?;

    // Satu pernyataan: cari rentetan, tentukan penerimanya, lalu sisipkan.
    // Dipilih begitu karena jumlah penerimanya kecil per siswa, dan
    // memisahkannya menjadi query-per-siswa akan menghasilkan ribuan
    // perjalanan bolak-balik pada pagi setelah libur panjang.
    let inserted = sqlx::query(
        r#"
        WITH terakhir AS (
            SELECT a.student_id,
                   a.school_id,
                   a.attendance_date,
                   a.status,
                   row_number() OVER (
                       PARTITION BY a.student_id ORDER BY a.attendance_date DESC
                   ) AS rn
              FROM attendances a
             WHERE a.attendance_date > $1::date - INTERVAL '45 days'
               AND a.attendance_date <= $1::date
        ),
        rentetan AS (
            SELECT student_id, school_id, max(attendance_date) AS tanggal
              FROM terakhir
             WHERE rn <= $2
             GROUP BY student_id, school_id
            HAVING count(*) = $2
               AND bool_and(status = 'terlambat')
               -- Hanya rentetan yang hari terakhirnya HARI INI. Tanpa ini,
               -- rentetan lama ikut terkirim setiap kali pekerja berjalan.
               AND max(attendance_date) = $1::date
        ),
        sasaran AS (
            SELECT r.student_id, r.school_id, r.tanggal, s.full_name, sc.name AS sekolah,
                   u.id AS user_id
              FROM rentetan r
              JOIN students s  ON s.id = r.student_id
              JOIN schools sc  ON sc.id = r.school_id
              JOIN users u ON (
                       -- Sekolah dan admin sekolah itu sendiri.
                       (u.school_id = r.school_id
                        AND EXISTS (SELECT 1 FROM model_has_roles mr
                                      JOIN roles ro ON ro.id = mr.role_id
                                     WHERE mr.model_id = u.id
                                       AND ro.name IN ('sekolah', 'admin', 'kepala_sekolah')))
                       -- Capdis: region_id-nya induk dari region sekolah.
                    OR (u.region_id IS NOT NULL
                        AND u.region_id = (SELECT rg.parent_id FROM regions rg
                                            WHERE rg.id = sc.region_id)
                        AND EXISTS (SELECT 1 FROM model_has_roles mr
                                      JOIN roles ro ON ro.id = mr.role_id
                                     WHERE mr.model_id = u.id AND ro.name = 'capdis'))
                       -- Disdik: seluruh provinsi.
                    OR EXISTS (SELECT 1 FROM model_has_roles mr
                                 JOIN roles ro ON ro.id = mr.role_id
                                WHERE mr.model_id = u.id AND ro.name = 'admin_dinas')
                   )
             WHERE u.is_active AND u.deleted_at IS NULL
        )
        INSERT INTO app_notifications
              (user_id, school_id, kind, title, body, payload, dedupe_key)
        SELECT user_id,
               school_id,
               'telat_beruntun',
               full_name || ' terlambat ' || $2 || ' hari berturut-turut',
               full_name || ' di ' || sekolah || ' tercatat terlambat ' || $2 ||
                   ' hari sekolah berturut-turut hingga ' ||
                   to_char(tanggal, 'DD-MM-YYYY') || '.',
               jsonb_build_object('student_id', student_id, 'tanggal', tanggal),
               'telat' || $2 || ':' || student_id || ':' || tanggal || ':' || user_id
          FROM sasaran
        ON CONFLICT (dedupe_key) DO NOTHING
        "#,
    )
    .bind(today)
    .bind(days)
    .execute(&state.db)
    .await?
    .rows_affected();

    if inserted > 0 {
        tracing::info!(inserted, days, "notifikasi keterlambatan beruntun dibuat");
    }
    Ok(true)
}

async fn cleanup(state: &AppState) -> anyhow::Result<()> {
    let tokens = sqlx::query(
        "DELETE FROM refresh_tokens WHERE expires_at < NOW() - INTERVAL '30 days'",
    )
    .execute(&state.db)
    .await?
    .rows_affected();

    let heartbeats = sqlx::query(
        "DELETE FROM device_heartbeats WHERE reported_at < NOW() - INTERVAL '30 days'",
    )
    .execute(&state.db)
    .await?
    .rows_affected();

    let idem = sqlx::query("DELETE FROM idempotency_keys WHERE expires_at < NOW()")
        .execute(&state.db)
        .await?
        .rows_affected();

    let exports = sqlx::query(
        "DELETE FROM report_exports WHERE expires_at IS NOT NULL AND expires_at < NOW()",
    )
    .execute(&state.db)
    .await?
    .rows_affected();

    // Kode pairing yang tidak pernah dipakai jangan dibiarkan hidup.
    let pairings = sqlx::query(
        r#"
        UPDATE devices SET pairing_code = NULL, pairing_expires_at = NULL
        WHERE pairing_code IS NOT NULL AND pairing_expires_at < NOW()
        "#,
    )
    .execute(&state.db)
    .await?
    .rows_affected();

    tracing::info!(
        tokens, heartbeats, idem, exports, pairings,
        "bersih-bersih harian selesai"
    );
    Ok(())
}
