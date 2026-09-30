<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Bukti absensi PKL: foto, koordinat, jarak, dan waktu.
 *
 * Ditulis HANYA oleh API Rust saat siswa menekan tombol absen di ponsel.
 * Dashboard membacanya untuk laporan dan pemeriksaan; tidak ada jalur
 * pembuatan dari sini, karena bukti yang dapat dibuat dari dashboard
 * bukan lagi bukti.
 */
class PklAttendanceProof extends Model
{
    use BelongsToSchool;
    use HasUuids;

    public $timestamps = false;

    protected $table = 'pkl_attendance_proofs';

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return [
            'proof_date' => 'date',
            'recorded_at' => 'datetime',
            'client_time' => 'datetime',
        ];
    }

    public function placement(): BelongsTo
    {
        return $this->belongsTo(PklPlacement::class, 'placement_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    /**
     * Selisih jam ponsel terhadap jam server, dalam detik.
     *
     * Jam ponsel dapat diubah pemiliknya. Selisih yang mencolok bukan bukti
     * kecurangan — zona waktu salah setel juga menghasilkannya — tetapi
     * cukup menjadi alasan untuk melihat baris itu lebih teliti.
     */
    public function getSelisihJamAttribute(): ?int
    {
        if (! $this->client_time) {
            return null;
        }

        return (int) $this->client_time->diffInSeconds($this->recorded_at, false);
    }
}
