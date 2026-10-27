//! Rekap absensi berkala ke orang tua.
//!
//! Berbeda dari notifikasi per absensi, yang dirangkai saat kejadiannya
//! terjadi, rekap dirangkai dari hitungan beberapa hari sekaligus. Yang
//! disamakan hanya muaranya: keduanya berakhir di `notification_outbox`
//! dan dikirim worker yang sama, sehingga percobaan ulang, jeda antar
//! kirim, dan pencatatan galat tidak perlu ditulis dua kali.

use chrono::{Datelike, NaiveDate};
use sqlx::{Postgres, Transaction};
use uuid::Uuid;

use crate::domain::notification::render_template;
use crate::error::ApiResult;

use super::load_template;

/// Jenis rekap berkala.
#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub enum RecapKind {
    Harian,
    Mingguan,
    Bulanan,
}

impl RecapKind {
    /// Nilai kolom `kind` pada `notification_recap_runs`, sekaligus kunci
    /// template. Keduanya sengaja sama agar tidak ada dua daftar nama yang
    /// harus dijaga tetap sejalan.
    pub fn as_str(self) -> &'static str {
        match self {
            Self::Harian => "daily_recap",
            Self::Mingguan => "weekly_recap",
            Self::Bulanan => "monthly_recap",
        }
    }

    pub fn label(self) -> &'static str {
        match self {
            Self::Harian => "harian",
            Self::Mingguan => "mingguan",
            Self::Bulanan => "bulanan",
        }
    }
}

// =====================================================================
// Periode
// =====================================================================

/// Rentang tanggal yang dirangkum, dihitung dari tanggal pengiriman.
///
/// Fungsi murni, dan sengaja dipisah dari kueri supaya dapat diuji: salah
/// batas satu hari di sini berarti satu hari sekolah hilang dari setiap
/// rekap, dan itu tidak akan terlihat dari mana pun kecuali dengan
/// menghitung ulang dengan tangan.
pub fn periode(kind: RecapKind, kirim: NaiveDate) -> (NaiveDate, NaiveDate) {
    match kind {
        // Hari itu sendiri. Dikirim sore hari setelah gerbang absen tutup.
        RecapKind::Harian => (kirim, kirim),

        // Tujuh hari yang berakhir hari ini, bukan "minggu kalender".
        //
        // Dengan begitu sekolah bebas memilih hari kirimnya: dikirim Sabtu
        // berarti Minggu-Sabtu, dikirim Senin berarti Selasa-Senin. Keduanya
        // tetap tujuh hari penuh tanpa ada hari yang terlewat atau terhitung
        // dua kali di rekap berikutnya.
        RecapKind::Mingguan => (kirim - chrono::Duration::days(6), kirim),

        // Bulan kalender SEBELUMNYA, penuh.
        //
        // Bukan 30 hari ke belakang: orang tua membaca "rekap bulanan"
        // sebagai rekap satu bulan kalender, dan rekap yang dikirim tanggal
        // 5 seharusnya berbicara tentang bulan lalu yang sudah selesai,
        // bukan tentang bulan berjalan yang baru lima hari.
        RecapKind::Bulanan => {
            let awal_bulan_ini = NaiveDate::from_ymd_opt(kirim.year(), kirim.month(), 1)
                .expect("tanggal 1 selalu ada");
            let akhir = awal_bulan_ini - chrono::Duration::days(1);
            let awal = NaiveDate::from_ymd_opt(akhir.year(), akhir.month(), 1)
                .expect("tanggal 1 selalu ada");
            (awal, akhir)
        }
    }
}

const BULAN: [&str; 12] = [
    "Januari", "Februari", "Maret", "April", "Mei", "Juni",
    "Juli", "Agustus", "September", "Oktober", "November", "Desember",
];

fn tanggal_id(t: NaiveDate) -> String {
    format!("{} {} {}", t.day(), BULAN[(t.month() - 1) as usize], t.year())
}

/// Penulisan periode sebagaimana dibaca orang tua di pesannya.
pub fn label_periode(kind: RecapKind, awal: NaiveDate, akhir: NaiveDate) -> String {
    match kind {
        RecapKind::Harian => tanggal_id(awal),
        RecapKind::Bulanan => format!("{} {}", BULAN[(awal.month() - 1) as usize], awal.year()),
        RecapKind::Mingguan => {
            if awal.month() == akhir.month() && awal.year() == akhir.year() {
                format!(
                    "{}\u{2013}{} {} {}",
                    awal.day(),
                    akhir.day(),
                    BULAN[(akhir.month() - 1) as usize],
                    akhir.year()
                )
            } else {
                format!("{} \u{2013} {}", tanggal_id(awal), tanggal_id(akhir))
            }
        }
    }
}

// =====================================================================
// Pengantrean
// =====================================================================

/// Satu baris hasil hitungan: satu siswa untuk satu wali.
///
/// Siswa dengan dua wali menghasilkan dua baris dengan hitungan yang sama.
/// Dihitung di basis data, bukan dikelompokkan di Rust, karena kueri yang
/// sama juga menyaring wali yang notifikasinya dimatikan — dan menyaringnya
/// belakangan berarti menghitung kehadiran siswa yang pesannya tidak akan
/// pernah dikirim.
#[derive(Debug, sqlx::FromRow)]
struct BarisRekap {
    student_id: Uuid,
    nama_siswa: String,
    kelas: Option<String>,
    guardian_id: Uuid,
    nama_wali: String,
    whatsapp: Option<String>,
    phone: Option<String>,
    email: Option<String>,
    telegram_chat_id: Option<String>,
    preferred_channel: String,
    hadir: i64,
    terlambat: i64,
    izin: i64,
    sakit: i64,
    alfa: i64,
    total: i64,
}

impl BarisRekap {
    /// Kanal dan alamat tujuan, mengikuti pilihan wali.
    fn tujuan(&self) -> Option<(&'static str, String)> {
        let ambil = |v: &Option<String>| {
            v.as_ref()
                .map(|s| s.trim().to_string())
                .filter(|s| !s.is_empty())
        };
        match self.preferred_channel.as_str() {
            "telegram" => ambil(&self.telegram_chat_id).map(|v| ("telegram", v)),
            "whatsapp" => ambil(&self.whatsapp)
                .or_else(|| ambil(&self.phone))
                .map(|v| ("whatsapp", v)),
            "email" => ambil(&self.email).map(|v| ("email", v)),
            _ => None,
        }
    }
}

/// Antrikan rekap untuk satu periode.
///
/// `student_id` kosong berarti seluruh siswa aktif di sekolah itu — yang
/// dipakai penjadwal. Diisi berarti satu siswa saja, yang dipakai tombol
/// kirim manual. Satu fungsi untuk keduanya supaya isi pesan yang diterima
/// orang tua tidak pernah berbeda antara yang terjadwal dan yang dikirim
/// petugas.
///
/// Mengembalikan jumlah pesan yang masuk antrean.
pub async fn enqueue(
    tx: &mut Transaction<'_, Postgres>,
    school_id: Uuid,
    school_name: &str,
    kind: RecapKind,
    awal: NaiveDate,
    akhir: NaiveDate,
    student_id: Option<Uuid>,
) -> ApiResult<usize> {
    let baris: Vec<BarisRekap> = sqlx::query_as(
        r#"
        SELECT s.id                                                AS student_id,
               s.full_name                                         AS nama_siswa,
               c.name                                              AS kelas,
               g.id                                                AS guardian_id,
               g.full_name                                         AS nama_wali,
               g.whatsapp, g.phone, g.email, g.telegram_chat_id,
               g.preferred_channel,
               COUNT(*) FILTER (WHERE a.status = 'hadir')::bigint     AS hadir,
               COUNT(*) FILTER (WHERE a.status = 'terlambat')::bigint AS terlambat,
               COUNT(*) FILTER (WHERE a.status = 'izin')::bigint      AS izin,
               COUNT(*) FILTER (WHERE a.status = 'sakit')::bigint     AS sakit,
               COUNT(*) FILTER (WHERE a.status = 'alfa')::bigint      AS alfa,
               COUNT(a.id)::bigint                                    AS total
          FROM students s
          JOIN student_guardians g ON g.student_id = s.id
                                  AND g.notify_enabled
                                  AND g.preferred_channel <> 'none'
          LEFT JOIN classrooms c ON c.id = s.current_classroom_id
          LEFT JOIN attendances a ON a.student_id = s.id
                                 AND a.attendance_date BETWEEN $2 AND $3
         WHERE s.school_id = $1
           AND s.status = 'aktif'
           AND ($4::uuid IS NULL OR s.id = $4)
         GROUP BY s.id, s.full_name, c.name, g.id, g.full_name,
                  g.whatsapp, g.phone, g.email, g.telegram_chat_id,
                  g.preferred_channel
        HAVING COUNT(a.id) > 0
         ORDER BY s.full_name
        "#,
    )
    .bind(school_id)
    .bind(awal)
    .bind(akhir)
    .bind(student_id)
    .fetch_all(&mut **tx)
    .await?;

    let template_key = kind.as_str();
    let periode_label = label_periode(kind, awal, akhir);
    let mut queued = 0usize;

    for b in &baris {
        let Some((channel, recipient)) = b.tujuan() else {
            continue;
        };

        let Some((subject, body)) = load_template(tx, school_id, template_key, channel).await?
        else {
            tracing::warn!(
                school_id = %school_id, template_key, channel,
                "template rekap tidak ditemukan, pesan dilewati"
            );
            continue;
        };

        // Persen kehadiran menghitung terlambat sebagai hadir: siswa yang
        // datang terlambat tetap masuk sekolah hari itu, dan menyebutnya
        // tidak hadir akan membuat angkanya bertentangan dengan rincian
        // tepat di atasnya.
        let persen = if b.total > 0 {
            ((b.hadir + b.terlambat) as f64 * 100.0 / b.total as f64).round() as i64
        } else {
            0
        };

        let vars: Vec<(&'static str, String)> = vec![
            ("nama_siswa", b.nama_siswa.clone()),
            ("kelas", b.kelas.clone().unwrap_or_else(|| "-".into())),
            ("sekolah", school_name.to_string()),
            ("nama_wali", b.nama_wali.clone()),
            ("periode", periode_label.clone()),
            ("hadir", b.hadir.to_string()),
            ("terlambat", b.terlambat.to_string()),
            ("izin", b.izin.to_string()),
            ("sakit", b.sakit.to_string()),
            ("alfa", b.alfa.to_string()),
            ("total_hari", b.total.to_string()),
            ("persen_hadir", persen.to_string()),
        ];

        let rendered_body = render_template(&body, &vars);
        let rendered_subject = subject.map(|s| render_template(&s, &vars));

        sqlx::query(
            r#"
            INSERT INTO notification_outbox
                (school_id, student_id, guardian_id, channel, template_key,
                 recipient, subject, body, variables, status, scheduled_at)
            VALUES ($1,$2,$3,$4,$5,$6,$7,$8,$9,'queued', NOW())
            "#,
        )
        .bind(school_id)
        .bind(b.student_id)
        .bind(b.guardian_id)
        .bind(channel)
        .bind(template_key)
        .bind(&recipient)
        .bind(rendered_subject.as_deref())
        .bind(&rendered_body)
        .bind(serde_json::json!(vars
            .iter()
            .cloned()
            .collect::<std::collections::BTreeMap<_, _>>()))
        .execute(&mut **tx)
        .await?;

        queued += 1;
    }

    Ok(queued)
}

#[cfg(test)]
mod tests {
    use super::*;

    fn tgl(y: i32, m: u32, d: u32) -> NaiveDate {
        NaiveDate::from_ymd_opt(y, m, d).unwrap()
    }

    #[test]
    fn periode_harian_adalah_hari_itu_sendiri() {
        let t = tgl(2026, 10, 27);
        assert_eq!(periode(RecapKind::Harian, t), (t, t));
    }

    #[test]
    fn periode_mingguan_tujuh_hari_penuh() {
        let (a, b) = periode(RecapKind::Mingguan, tgl(2026, 10, 27));
        assert_eq!((a, b), (tgl(2026, 10, 21), tgl(2026, 10, 27)));
        // Tujuh hari, bukan enam: batasnya inklusif di kedua ujung.
        assert_eq!((b - a).num_days(), 6);
    }

    #[test]
    fn periode_mingguan_menyambung_tanpa_celah() {
        // Rekap minggu ini dan minggu berikutnya harus bersentuhan persis:
        // tidak ada hari yang terlewat, tidak ada yang terhitung dua kali.
        let (_, akhir_ini) = periode(RecapKind::Mingguan, tgl(2026, 10, 27));
        let (awal_depan, _) = periode(RecapKind::Mingguan, tgl(2026, 11, 3));
        assert_eq!(awal_depan, akhir_ini + chrono::Duration::days(1));
    }

    #[test]
    fn periode_bulanan_adalah_bulan_sebelumnya_penuh() {
        let (a, b) = periode(RecapKind::Bulanan, tgl(2026, 10, 5));
        assert_eq!((a, b), (tgl(2026, 9, 1), tgl(2026, 9, 30)));
    }

    #[test]
    fn periode_bulanan_melewati_pergantian_tahun() {
        let (a, b) = periode(RecapKind::Bulanan, tgl(2026, 1, 3));
        assert_eq!((a, b), (tgl(2025, 12, 1), tgl(2025, 12, 31)));
    }

    #[test]
    fn periode_bulanan_februari_kabisat() {
        // 2028 kabisat: Februari berakhir tanggal 29, bukan 28.
        let (a, b) = periode(RecapKind::Bulanan, tgl(2028, 3, 10));
        assert_eq!((a, b), (tgl(2028, 2, 1), tgl(2028, 2, 29)));
    }

    #[test]
    fn label_periode_terbaca_manusia() {
        assert_eq!(
            label_periode(RecapKind::Harian, tgl(2026, 10, 27), tgl(2026, 10, 27)),
            "27 Oktober 2026"
        );
        assert_eq!(
            label_periode(RecapKind::Mingguan, tgl(2026, 10, 21), tgl(2026, 10, 27)),
            "21\u{2013}27 Oktober 2026"
        );
        assert_eq!(
            label_periode(RecapKind::Bulanan, tgl(2026, 9, 1), tgl(2026, 9, 30)),
            "September 2026"
        );
    }

    #[test]
    fn label_mingguan_lintas_bulan_menyebut_kedua_bulannya() {
        assert_eq!(
            label_periode(RecapKind::Mingguan, tgl(2026, 10, 29), tgl(2026, 11, 4)),
            "29 Oktober 2026 \u{2013} 4 November 2026"
        );
    }

    #[test]
    fn kunci_template_sama_dengan_nilai_kolom_kind() {
        // Migrasi 0026 memakai nilai yang sama untuk CHECK kolom `kind` dan
        // untuk CHECK kunci template. Bila keduanya berbeda, rekap tercatat
        // terkirim tetapi templatenya tidak pernah ketemu.
        for k in [RecapKind::Harian, RecapKind::Mingguan, RecapKind::Bulanan] {
            assert!(k.as_str().ends_with("_recap"), "{}", k.as_str());
        }
        assert_eq!(RecapKind::Bulanan.as_str(), "monthly_recap");
    }
}
