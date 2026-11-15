//! Webhook Telegram: menautkan akun orang tua lewat tautan sekali pakai.
//!
//! ALUR YANG DILIHAT ORANG TUA
//!
//! Sekolah membagikan tautan `https://t.me/<bot>?start=<token>` — lewat
//! surat, pesan wali kelas, atau kode QR yang ditempel. Orang tua
//! menekannya, Telegram terbuka dengan tombol Start, dan setelah ditekan
//! chat id-nya tersimpan. Tidak ada angka yang perlu dibaca atau diketik
//! siapa pun.
//!
//! MENGAPA TOKEN, BUKAN NISN
//!
//! Meminta orang tua mengirim NISN anaknya jauh lebih mudah ditulis, dan
//! salah. NISN tercetak di kartu pelajar dan beredar di grup kelas; siapa
//! pun yang mengetahuinya dapat mendaftarkan dirinya sebagai wali anak itu
//! lalu menerima kabar jam datang dan pulang anak orang lain setiap hari.
//!
//! Token di sini acak, milik satu baris wali, dan hangus begitu dipakai.

use axum::extract::State;
use axum::http::{HeaderMap, StatusCode};
use axum::routing::post;
use axum::{Json, Router};
use serde::Deserialize;
use uuid::Uuid;

use crate::services::notify::telegram;
use crate::state::AppState;

/// Header yang dikirim Telegram pada setiap update, berisi nilai yang
/// kita tetapkan sendiri saat memanggil `setWebhook`.
const HEADER_RAHASIA: &str = "x-telegram-bot-api-secret-token";

pub fn public_router() -> Router<AppState> {
    Router::new().route("/notifications/telegram/webhook", post(webhook))
}

#[derive(Debug, Deserialize)]
pub struct Update {
    message: Option<Message>,
}

#[derive(Debug, Deserialize)]
struct Message {
    chat: Chat,
    text: Option<String>,
    from: Option<From_>,
}

#[derive(Debug, Deserialize)]
struct Chat {
    id: i64,
}

#[derive(Debug, Deserialize)]
struct From_ {
    first_name: Option<String>,
}

/// Terima update dari Telegram.
///
/// SELALU MENJAWAB 200
///
/// Telegram mengulang pengiriman update yang dijawab selain 2xx, dengan
/// jeda yang makin panjang, sampai berhasil. Token yang tidak dikenal
/// bukan kegagalan sistem — jadi menjawabnya dengan galat hanya
/// menghasilkan antrean update yang terus diulang tanpa akhir. Yang perlu
/// tahu ada yang salah adalah orang tuanya, dan ia diberi tahu lewat
/// balasan di Telegram, bukan lewat kode status HTTP.
pub async fn webhook(
    State(state): State<AppState>,
    headers: HeaderMap,
    Json(update): Json<Update>,
) -> StatusCode {
    // Gagal-tertutup: tanpa rahasia yang dikonfigurasi, endpoint ini
    // ditolak. Endpoint publik yang menerima siapa saja berarti orang
    // asing dapat mengirim update palsu berisi token tebakan.
    let Some(harapan) = state.cfg.notify.telegram_webhook_secret.as_deref() else {
        tracing::warn!("webhook telegram dipanggil tetapi TELEGRAM_WEBHOOK_SECRET kosong");
        return StatusCode::FORBIDDEN;
    };

    let diberikan = headers.get(HEADER_RAHASIA).and_then(|v| v.to_str().ok());
    if diberikan != Some(harapan) {
        tracing::warn!("webhook telegram dengan rahasia yang tidak cocok");
        return StatusCode::FORBIDDEN;
    }

    let Some(pesan) = update.message else {
        return StatusCode::OK;
    };
    let chat_id = pesan.chat.id;
    let nama = pesan
        .from
        .as_ref()
        .and_then(|f| f.first_name.clone())
        .unwrap_or_else(|| "Bapak/Ibu".into());

    let Some(token) = ambil_token(pesan.text.as_deref()) else {
        balas(
            &state,
            chat_id,
            "Halo. Bot ini mengirimkan kabar kehadiran ananda di sekolah.\n\n\
             Untuk mulai menerimanya, buka tautan penautan yang diberikan \
             sekolah. Bila belum punya, silakan hubungi wali kelas.",
        )
        .await;
        return StatusCode::OK;
    };

    match tautkan(&state, &token, chat_id).await {
        Ok(Some(siswa)) => {
            balas(
                &state,
                chat_id,
                &format!(
                    "Terima kasih, {nama}.\n\nTelegram ini sudah tertaut dengan \
                     ananda <b>{siswa}</b>. Mulai sekarang kabar kehadiran \
                     ananda akan dikirim ke sini."
                ),
            )
            .await;
        }
        Ok(None) => {
            balas(
                &state,
                chat_id,
                "Tautan ini sudah pernah dipakai atau tidak berlaku lagi.\n\n\
                 Silakan minta tautan baru kepada wali kelas.",
            )
            .await;
        }
        Err(e) => {
            tracing::error!(error = ?e, "gagal menautkan telegram");
            balas(
                &state,
                chat_id,
                "Maaf, terjadi gangguan saat menautkan. Silakan coba lagi \
                 beberapa saat lagi.",
            )
            .await;
        }
    }

    StatusCode::OK
}

/// Ambil token dari teks `/start <token>`.
///
/// Telegram mengirim perintah dalam grup sebagai `/start@NamaBot <token>`,
/// jadi bagian setelah `@` ikut dibuang.
fn ambil_token(teks: Option<&str>) -> Option<String> {
    let teks = teks?.trim();
    let mut bagian = teks.split_whitespace();
    let perintah = bagian.next()?;

    let perintah = perintah.split('@').next().unwrap_or(perintah);
    if perintah != "/start" {
        return None;
    }

    let token = bagian.next()?.trim();
    // Token dibuat dengan panjang tetap; apa pun di luar itu tidak perlu
    // menyentuh basis data.
    if token.is_empty() || token.len() > 48 || !token.chars().all(|c| c.is_ascii_alphanumeric()) {
        return None;
    }
    Some(token.to_string())
}

/// Tukarkan token dengan penautan. Mengembalikan nama siswa bila berhasil.
async fn tautkan(
    state: &AppState,
    token: &str,
    chat_id: i64,
) -> anyhow::Result<Option<String>> {
    // Satu pernyataan: menemukan, mengisi, dan menghanguskan token
    // sekaligus. Memisahkan SELECT dan UPDATE membuka celah bagi dua
    // permintaan yang datang bersamaan untuk sama-sama menemukan token
    // yang sama dan sama-sama menganggap dirinya berhasil.
    let baris: Option<(Uuid, String)> = sqlx::query_as(
        r#"
        WITH tertaut AS (
            UPDATE student_guardians g
               SET telegram_chat_id   = $2,
                   telegram_linked_at = NOW(),
                   telegram_link_token = NULL,
                   preferred_channel  = CASE
                       WHEN g.preferred_channel = 'none' THEN 'telegram'
                       ELSE g.preferred_channel
                   END,
                   notify_enabled     = TRUE,
                   updated_at         = NOW()
             WHERE g.telegram_link_token = $1
         RETURNING g.id, g.student_id
        )
        SELECT t.id, s.full_name
          FROM tertaut t
          JOIN students s ON s.id = t.student_id
        "#,
    )
    .bind(token)
    .bind(chat_id.to_string())
    .fetch_optional(&state.db)
    .await?;

    Ok(baris.map(|(_, nama)| nama))
}

async fn balas(state: &AppState, chat_id: i64, teks: &str) {
    if let Err(e) = telegram::send(state, &chat_id.to_string(), teks).await {
        tracing::warn!(error = ?e, chat_id, "gagal membalas ke telegram");
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn token_diambil_dari_perintah_start() {
        assert_eq!(ambil_token(Some("/start abc123")), Some("abc123".into()));
        assert_eq!(ambil_token(Some("  /start abc123  ")), Some("abc123".into()));
    }

    #[test]
    fn perintah_dalam_grup_membawa_nama_bot() {
        // Telegram menulis `/start@NamaBot <token>` bila bot dipanggil di
        // dalam grup. Tanpa membuang bagian setelah @, penautan lewat grup
        // tidak akan pernah berhasil.
        assert_eq!(
            ambil_token(Some("/start@AbsensiSekolahBot abc123")),
            Some("abc123".into())
        );
    }

    #[test]
    fn start_tanpa_token_bukan_penautan() {
        assert_eq!(ambil_token(Some("/start")), None);
        assert_eq!(ambil_token(Some("/start   ")), None);
    }

    #[test]
    fn perintah_lain_diabaikan() {
        assert_eq!(ambil_token(Some("/help abc123")), None);
        assert_eq!(ambil_token(Some("halo pak")), None);
        assert_eq!(ambil_token(None), None);
    }

    #[test]
    fn token_aneh_tidak_menyentuh_basis_data() {
        // Hanya huruf dan angka. Apa pun selain itu tidak mungkin cocok
        // dengan token yang kita buat, jadi tidak perlu ditanyakan ke
        // basis data sama sekali.
        assert_eq!(ambil_token(Some("/start abc-123")), None);
        assert_eq!(ambil_token(Some("/start ' OR 1=1 --")), None);
        assert_eq!(ambil_token(Some(&format!("/start {}", "a".repeat(49)))), None);
        assert_eq!(ambil_token(Some(&format!("/start {}", "a".repeat(48)))),
                   Some("a".repeat(48)));
    }
}
