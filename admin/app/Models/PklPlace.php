<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Tempat PKL: perusahaan atau instansi tempat siswa magang.
 *
 * Dipisah dari penempatan siswa karena satu tempat menampung banyak siswa
 * dan sering dipakai lagi tahun berikutnya. Menyalin koordinat ke tiap
 * siswa berarti memperbaikinya satu per satu ketika alamatnya salah.
 */
class PklPlace extends Model
{
    use BelongsToSchool;
    use HasUuids;
    use SoftDeletes;

    protected $table = 'pkl_places';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'school_id', 'name', 'address', 'contact_name', 'contact_phone',
        'latitude', 'longitude', 'radius_meters', 'is_active', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            // Koordinat TIDAK di-cast ke float.
            //
            // Kolomnya NUMERIC(10,7); mengubahnya menjadi float membuat
            // digit terakhir bergeser saat dibaca lalu disimpan kembali,
            // dan titik yang berpindah beberapa sentimeter setiap
            // penyuntingan terlihat seperti data yang rusak.
            'radius_meters' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function placements(): HasMany
    {
        return $this->hasMany(PklPlacement::class, 'place_id');
    }

    /** Penempatan yang masa berlakunya mencakup hari ini. */
    public function activePlacements(): HasMany
    {
        return $this->placements()
            ->where('status', 'aktif')
            ->whereDate('starts_on', '<=', now())
            ->whereDate('ends_on', '>=', now());
    }
}
