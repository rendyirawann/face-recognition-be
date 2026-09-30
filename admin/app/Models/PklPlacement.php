<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Penempatan seorang siswa di satu tempat PKL, untuk satu rentang tanggal.
 *
 * INILAH yang menentukan seorang siswa "PKL" atau bukan. Tidak ada kolom
 * `is_pkl` pada tabel students, dan itu disengaja: status PKL selalu punya
 * masa berlaku, dan bendera boolean tidak dapat kedaluwarsa dengan
 * sendirinya. Siswa PKL adalah siswa yang HARI INI punya penempatan aktif.
 */
class PklPlacement extends Model
{
    use BelongsToSchool;
    use HasUuids;

    protected $table = 'pkl_placements';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'student_id', 'place_id', 'school_id', 'starts_on', 'ends_on',
        'check_in_after', 'check_out_before', 'supervisor_name',
        'supervisor_phone', 'notes', 'status', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function place(): BelongsTo
    {
        return $this->belongsTo(PklPlace::class, 'place_id');
    }

    public function proofs(): HasMany
    {
        return $this->hasMany(PklAttendanceProof::class, 'placement_id');
    }

    /** Penempatan yang masa berlakunya mencakup hari ini. */
    public function scopeBerlaku(Builder $query): Builder
    {
        return $query->where('pkl_placements.status', 'aktif')
            ->whereDate('pkl_placements.starts_on', '<=', now())
            ->whereDate('pkl_placements.ends_on', '>=', now());
    }

    /** Sisa hari termasuk hari ini; negatif bila sudah lewat. */
    public function getSisaHariAttribute(): int
    {
        return (int) now()->startOfDay()->diffInDays($this->ends_on, false);
    }
}
