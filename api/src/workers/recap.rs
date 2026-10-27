//! Worker pengirim rekap absensi berkala.
//!
//! Membaca jadwal dari `notification_policies`, lalu mengantrikan rekap
//! ke `notification_outbox` begitu jadwalnya lewat. Pengirimannya sendiri
//! tetap dikerjakan worker outbox, sehingga percobaan ulang dan jeda antar
//! kirim tidak perlu ditulis dua kali.
//!
//! SATU PERIODE HANYA SEKALI
//!
//! Worker ini bangun setiap menit. Tanpa penjaga, rekap yang dijadwalkan
//! pukul 17.00 akan dikirim ulang pada setiap putaran setelah pukul 17.00.
//! Penjaganya adalah baris di `notification_recap_runs`: barisnya disisipkan
//! lebih dulu di dalam transaksi yang sama dengan pengantrean, dan kunci
//! primernya yang menolak percobaan kedua. Memeriksa dengan SELECT lalu
//! memutuskan akan bocor begitu dua instance API berjalan bersamaan.

use std::time::Duration;

use chrono::{Datelike, NaiveDate, NaiveTime};
use uuid::Uuid;

use crate::services::notify::recap::{self, RecapKind};
use crate::state::AppState;
use crate::util;

const TICK: Duration = Duration::from_secs(60);

#[derive(Debug, sqlx::FromRow)]
struct Jadwal {
    school_id: Uuid,
    school_name: String,
    daily_recap_at: Option<NaiveTime>,
    weekly_recap_day: Option<i16>,
    weekly_recap_at: Option<NaiveTime>,
    monthly_recap_day: Option<i16>,
    monthly_recap_at: Option<NaiveTime>,
}

/// Apakah satu jenis rekap jatuh tempo pada saat ini.
///
/// Fungsi murni supaya dapat diuji tanpa basis data maupun jam dinding.
fn jatuh_tempo(
    kind: RecapKind,
    j: &Jadwal,
    hari_ini: NaiveDate,
    sekarang: NaiveTime,
) -> bool {
    match kind {
        RecapKind::Harian => j.daily_recap_at.is_some_and(|t| sekarang >= t),

        // `number_from_monday` menghasilkan 1..7 dengan Senin = 1, sama
        // dengan ISODOW PostgreSQL yang dipakai kolomnya.
        RecapKind::Mingguan => match (j.weekly_recap_day, j.weekly_recap_at) {
            (Some(d), Some(t)) => {
                hari_ini.weekday().number_from_monday() as i16 == d && sekarang >= t
            }
            _ => false,
        },

        RecapKind::Bulanan => match (j.monthly_recap_day, j.monthly_recap_at) {
            (Some(d), Some(t)) => hari_ini.day() as i16 == d && sekarang >= t,
            _ => false,
        },
    }
}

pub async fn run(state: AppState, shutdown: tokio::sync::watch::Receiver<bool>) {
    if !state.cfg.notify.enabled {
        tracing::info!("worker rekap dimatikan (NOTIFY_ENABLED=false)");
        return;
    }

    tracing::info!("worker rekap berjalan");
    let mut shutdown = shutdown;

    loop {
        tokio::select! {
            _ = shutdown.changed() => {
                if *shutdown.borrow() {
                    tracing::info!("worker rekap berhenti");
                    return;
                }
            }
            _ = tokio::time::sleep(TICK) => {
                if let Err(e) = tick(&state).await {
                    tracing::error!(error = ?e, "putaran worker rekap gagal");
                }
            }
        }
    }
}

async fn tick(state: &AppState) -> anyhow::Result<()> {
    let now = util::now_wib();
    let hari_ini = now.date_naive();
    let sekarang = now.time();

    let jadwal: Vec<Jadwal> = sqlx::query_as(
        r#"
        SELECT p.school_id, s.name AS school_name,
               p.daily_recap_at, p.weekly_recap_day, p.weekly_recap_at,
               p.monthly_recap_day, p.monthly_recap_at
          FROM notification_policies p
          JOIN schools s ON s.id = p.school_id
         WHERE p.daily_recap_at   IS NOT NULL
            OR p.weekly_recap_at  IS NOT NULL
            OR p.monthly_recap_at IS NOT NULL
        "#,
    )
    .fetch_all(&state.db)
    .await?;

    for j in &jadwal {
        for kind in [RecapKind::Harian, RecapKind::Mingguan, RecapKind::Bulanan] {
            if !jatuh_tempo(kind, j, hari_ini, sekarang) {
                continue;
            }
            if let Err(e) = kirim(state, j, kind, hari_ini).await {
                tracing::error!(
                    error = ?e, school_id = %j.school_id, kind = kind.label(),
                    "gagal mengantrikan rekap"
                );
            }
        }
    }
    Ok(())
}

/// Klaim periode lalu antrikan rekapnya, dalam satu transaksi.
///
/// Keduanya menyatu dengan sengaja: bila pengantrean gagal di tengah
/// jalan, klaimnya ikut batal dan putaran berikutnya mencobanya lagi.
/// Kalau klaim di-commit terpisah, satu kegagalan berarti periode itu
/// tercatat terkirim padahal tidak ada pesan yang pernah keluar.
async fn kirim(
    state: &AppState,
    j: &Jadwal,
    kind: RecapKind,
    hari_ini: NaiveDate,
) -> anyhow::Result<()> {
    let (awal, akhir) = recap::periode(kind, hari_ini);

    let mut tx = state.db.begin().await?;

    let klaim: Option<(Uuid,)> = sqlx::query_as(
        r#"
        INSERT INTO notification_recap_runs
            (school_id, kind, period_start, period_end)
        VALUES ($1, $2, $3, $4)
        ON CONFLICT (school_id, kind, period_start) DO NOTHING
        RETURNING school_id
        "#,
    )
    .bind(j.school_id)
    .bind(kind.as_str())
    .bind(awal)
    .bind(akhir)
    .fetch_optional(&mut *tx)
    .await?;

    if klaim.is_none() {
        // Sudah dikirim pada putaran sebelumnya, atau oleh instance lain.
        tx.rollback().await?;
        return Ok(());
    }

    let queued =
        recap::enqueue(&mut tx, j.school_id, &j.school_name, kind, awal, akhir).await?;

    sqlx::query(
        r#"
        UPDATE notification_recap_runs
           SET queued = $5
         WHERE school_id = $1 AND kind = $2 AND period_start = $3 AND period_end = $4
        "#,
    )
    .bind(j.school_id)
    .bind(kind.as_str())
    .bind(awal)
    .bind(akhir)
    .bind(queued as i32)
    .execute(&mut *tx)
    .await?;

    tx.commit().await?;

    tracing::info!(
        school_id = %j.school_id, kind = kind.label(),
        %awal, %akhir, queued,
        "rekap diantrikan"
    );
    Ok(())
}

#[cfg(test)]
mod tests {
    use super::*;

    fn jam(h: u32, m: u32) -> NaiveTime {
        NaiveTime::from_hms_opt(h, m, 0).unwrap()
    }

    fn tgl(y: i32, mo: u32, d: u32) -> NaiveDate {
        NaiveDate::from_ymd_opt(y, mo, d).unwrap()
    }

    fn jadwal_kosong() -> Jadwal {
        Jadwal {
            school_id: Uuid::nil(),
            school_name: "SMKS Uji".into(),
            daily_recap_at: None,
            weekly_recap_day: None,
            weekly_recap_at: None,
            monthly_recap_day: None,
            monthly_recap_at: None,
        }
    }

    #[test]
    fn jadwal_kosong_tidak_pernah_jatuh_tempo() {
        let j = jadwal_kosong();
        for k in [RecapKind::Harian, RecapKind::Mingguan, RecapKind::Bulanan] {
            assert!(!jatuh_tempo(k, &j, tgl(2026, 10, 27), jam(23, 59)));
        }
    }

    #[test]
    fn harian_jatuh_tempo_pada_dan_setelah_jamnya() {
        let mut j = jadwal_kosong();
        j.daily_recap_at = Some(jam(17, 0));
        let h = tgl(2026, 10, 27);

        assert!(!jatuh_tempo(RecapKind::Harian, &j, h, jam(16, 59)));
        assert!(jatuh_tempo(RecapKind::Harian, &j, h, jam(17, 0)));
        assert!(jatuh_tempo(RecapKind::Harian, &j, h, jam(21, 30)));
    }

    #[test]
    fn mingguan_hanya_pada_hari_yang_ditetapkan() {
        let mut j = jadwal_kosong();
        j.weekly_recap_day = Some(6); // Sabtu
        j.weekly_recap_at = Some(jam(16, 0));

        // 2026-10-31 adalah Sabtu, 2026-10-30 Jumat.
        assert_eq!(tgl(2026, 10, 31).weekday().number_from_monday(), 6);
        assert!(jatuh_tempo(RecapKind::Mingguan, &j, tgl(2026, 10, 31), jam(16, 0)));
        assert!(!jatuh_tempo(RecapKind::Mingguan, &j, tgl(2026, 10, 30), jam(23, 0)));
        assert!(!jatuh_tempo(RecapKind::Mingguan, &j, tgl(2026, 10, 31), jam(15, 59)));
    }

    #[test]
    fn mingguan_butuh_hari_dan_jam_sekaligus() {
        // Hari terisi tetapi jamnya tidak: tidak boleh jatuh tempo, karena
        // tanpa jam tidak ada batas yang jelas dan rekapnya akan terkirim
        // pada putaran pertama lewat tengah malam.
        let mut j = jadwal_kosong();
        j.weekly_recap_day = Some(6);
        assert!(!jatuh_tempo(RecapKind::Mingguan, &j, tgl(2026, 10, 31), jam(16, 0)));
    }

    #[test]
    fn bulanan_hanya_pada_tanggal_yang_ditetapkan() {
        let mut j = jadwal_kosong();
        j.monthly_recap_day = Some(5);
        j.monthly_recap_at = Some(jam(8, 0));

        assert!(jatuh_tempo(RecapKind::Bulanan, &j, tgl(2026, 11, 5), jam(8, 0)));
        assert!(!jatuh_tempo(RecapKind::Bulanan, &j, tgl(2026, 11, 4), jam(23, 0)));
        assert!(!jatuh_tempo(RecapKind::Bulanan, &j, tgl(2026, 11, 6), jam(8, 0)));
    }

    #[test]
    fn jenis_rekap_tidak_saling_memicu() {
        // Hanya jadwal harian yang terisi: mingguan dan bulanan harus diam,
        // meski hari dan jamnya kebetulan cocok dengan apa pun.
        let mut j = jadwal_kosong();
        j.daily_recap_at = Some(jam(17, 0));
        let h = tgl(2026, 10, 31);

        assert!(jatuh_tempo(RecapKind::Harian, &j, h, jam(17, 0)));
        assert!(!jatuh_tempo(RecapKind::Mingguan, &j, h, jam(17, 0)));
        assert!(!jatuh_tempo(RecapKind::Bulanan, &j, h, jam(17, 0)));
    }
}
