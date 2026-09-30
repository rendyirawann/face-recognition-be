<?php

namespace App\Http\Controllers\Backend\Pkl;

use App\Http\Controllers\Controller;
use App\Models\PklPlace;
use App\Models\PklPlacement;
use App\Models\Student;
use App\Support\Tenant;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\View\View;

/**
 * Praktik Kerja Lapangan: tempat, penempatan siswa, dan bukti absensinya.
 *
 * DITULIS LANGSUNG, BUKAN LEWAT API
 *
 * Mengikuti pola Kelas dan Siswa. API Rust memiliki apa yang aturannya juga
 * dipakai aplikasi ponsel — di modul ini hanya absensinya. Tempat dan
 * penempatan adalah data master biasa, dan lompatan ke API tidak menambah
 * jaminan apa pun.
 *
 * Yang menjaga keutuhannya ada di BASIS DATA, bukan di kelas ini: batas
 * radius dijaga CHECK, dan masa penempatan yang bertumpuk dijaga EXCLUDE.
 * Keduanya berlaku dari jalur mana pun — Eloquent, API, atau psql langsung.
 * Validasi di bawah hanya supaya operator mendapat pesan yang dapat dibaca
 * sebelum basis data menolaknya dengan bahasa Postgres.
 */
class PklController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            'auth',
            new Middleware('can:view_pkl', only: ['index', 'show', 'proofs']),
            new Middleware('can:manage_pkl', only: [
                'storePlace', 'updatePlace', 'destroyPlace',
                'storePlacement', 'updatePlacement', 'endPlacement',
            ]),
        ];
    }

    // =================================================================
    // Halaman
    // =================================================================

    public function index(Request $request): View
    {
        $schoolId = Tenant::currentSchoolId($request->query('school_id'));

        $places = collect();
        $placements = collect();

        if ($schoolId) {
            $places = PklPlace::query()
                ->where('school_id', $schoolId)
                ->withCount(['activePlacements as siswa_aktif'])
                ->orderBy('name')
                ->get();

            $placements = PklPlacement::query()
                ->where('pkl_placements.school_id', $schoolId)
                ->with(['student:id,full_name,nisn,current_classroom_id',
                        'student.classroom:id,name',
                        'place:id,name,address'])
                // Penyaring status: `berlaku` bukan nilai kolom, melainkan
                // pertanyaan tentang tanggal — lihat komentar di model.
                ->when($request->query('status') === 'berlaku',
                    fn ($q) => $q->berlaku())
                ->when($request->query('status') === 'selesai',
                    fn ($q) => $q->where('pkl_placements.status', 'selesai'))
                ->when($request->filled('q'), function ($q) use ($request) {
                    $term = trim($request->query('q'));
                    $q->whereHas('student', fn ($s) => $s
                        ->where('full_name', 'ILIKE', "%{$term}%")
                        ->orWhere('nisn', 'ILIKE', "%{$term}%"));
                })
                ->orderByDesc('pkl_placements.starts_on')
                ->paginate(25)
                ->withQueryString();
        }

        return view('backend.pkl.index', [
            'places' => $places,
            'placements' => $placements,
            'schoolId' => $schoolId,
            'schools' => Tenant::isProvinceScope() ? Tenant::selectableSchools() : collect(),
            'stats' => $this->stats($schoolId),
        ]);
    }

    /** Detail satu penempatan beserta bukti absensinya. */
    public function show(Request $request, string $id): View
    {
        $placement = PklPlacement::query()
            ->with(['student:id,full_name,nisn,school_id', 'place'])
            ->findOrFail($id);

        Tenant::authorizeSchool($placement->school_id);

        $proofs = $placement->proofs()
            ->orderByDesc('proof_date')
            ->orderBy('kind')
            ->paginate(30);

        return view('backend.pkl.show', [
            'placement' => $placement,
            'proofs' => $proofs,
        ]);
    }

    // =================================================================
    // Tempat PKL
    // =================================================================

    public function storePlace(Request $request): RedirectResponse
    {
        $data = $this->validatePlace($request);
        Tenant::authorizeSchool($data['school_id']);

        $data['created_by'] = $request->user()->id;
        PklPlace::create($data);

        return back()->with('success', 'Tempat PKL ditambahkan.');
    }

    public function updatePlace(Request $request, string $id): RedirectResponse
    {
        $place = PklPlace::findOrFail($id);
        Tenant::authorizeSchool($place->school_id);

        $data = $this->validatePlace($request, $id);
        unset($data['school_id']);   // sekolah tidak berpindah
        $place->update($data);

        return back()->with('success', 'Tempat PKL diperbarui.');
    }

    public function destroyPlace(string $id): RedirectResponse
    {
        $place = PklPlace::findOrFail($id);
        Tenant::authorizeSchool($place->school_id);

        // Tempat yang masih dipakai tidak dihapus.
        //
        // Basis data sudah menolaknya lewat ON DELETE RESTRICT, tetapi
        // pesannya berupa galat constraint Postgres yang tidak berarti apa
        // pun bagi operator. Diperiksa di sini supaya yang muncul adalah
        // kalimat yang menyebut berapa siswa yang masih di sana.
        $dipakai = $place->placements()->where('status', 'aktif')->count();
        if ($dipakai > 0) {
            return back()->withErrors([
                'place' => "Tempat ini masih dipakai {$dipakai} penempatan aktif. "
                    .'Selesaikan atau batalkan penempatannya lebih dulu.',
            ]);
        }

        $place->delete();

        return back()->with('success', 'Tempat PKL dihapus.');
    }

    // =================================================================
    // Penempatan siswa
    // =================================================================

    public function storePlacement(Request $request): RedirectResponse
    {
        $data = $this->validatePlacement($request);

        $student = Student::withoutTenantScope()
            ->select('id', 'school_id', 'full_name')
            ->find($data['student_id']);

        if (! $student) {
            return back()->withInput()->withErrors([
                'student_id' => 'Data siswa tidak ditemukan.',
            ]);
        }

        // Sekolah diambil dari SISWANYA, bukan dari input. Keduanya harus
        // selalu sama, dan mengambilnya dari input membuka celah untuk
        // menempatkan siswa sekolah lain.
        $data['school_id'] = $student->school_id;
        Tenant::authorizeSchool($data['school_id']);

        $place = PklPlace::find($data['place_id']);
        if (! $place || $place->school_id !== $student->school_id) {
            return back()->withInput()->withErrors([
                'place_id' => 'Tempat PKL itu bukan milik sekolah siswa ini.',
            ]);
        }

        $data['created_by'] = $request->user()->id;

        try {
            PklPlacement::create($data);
        } catch (QueryException $e) {
            // Constraint EXCLUDE menolak masa yang bertumpuk. Pesannya
            // menyebut nama constraint, jadi diterjemahkan di sini.
            if (str_contains($e->getMessage(), 'pkl_placements_tidak_bertumpuk')) {
                return back()->withInput()->withErrors([
                    'starts_on' => "{$student->full_name} sudah punya penempatan PKL "
                        .'yang aktif pada rentang tanggal itu. Satu siswa tidak dapat '
                        .'berada di dua tempat pada hari yang sama.',
                ]);
            }
            throw $e;
        }

        return back()->with('success', "Penempatan PKL {$student->full_name} dibuat.");
    }

    public function updatePlacement(Request $request, string $id): RedirectResponse
    {
        $placement = PklPlacement::findOrFail($id);
        Tenant::authorizeSchool($placement->school_id);

        $data = $this->validatePlacement($request, wajibSiswa: false);
        unset($data['student_id'], $data['school_id']);

        try {
            $placement->update($data);
        } catch (QueryException $e) {
            if (str_contains($e->getMessage(), 'pkl_placements_tidak_bertumpuk')) {
                return back()->withInput()->withErrors([
                    'starts_on' => 'Rentang tanggal itu bertumpuk dengan penempatan '
                        .'lain milik siswa yang sama.',
                ]);
            }
            throw $e;
        }

        return back()->with('success', 'Penempatan diperbarui.');
    }

    /** Akhiri penempatan lebih awal, atau batalkan. */
    public function endPlacement(Request $request, string $id): RedirectResponse
    {
        $placement = PklPlacement::findOrFail($id);
        Tenant::authorizeSchool($placement->school_id);

        $status = $request->input('status') === 'dibatalkan' ? 'dibatalkan' : 'selesai';
        $placement->update(['status' => $status]);

        // Catatan untuk yang membaca ini nanti: baris absensi yang sudah
        // tercatat TIDAK disentuh. Menghapusnya akan mengubah rekap
        // kehadiran bulan-bulan sebelumnya, dan kehadiran yang sudah
        // terjadi tetap terjadi walau penempatannya kemudian dibatalkan.
        return back()->with('success', "Penempatan ditandai {$status}.");
    }

    // =================================================================
    // Pembantu
    // =================================================================

    /** @return array<string, mixed> */
    private function validatePlace(Request $request, ?string $abaikan = null): array
    {
        return $request->validate([
            'school_id' => ['required', 'uuid'],
            'name' => ['required', 'string', 'min:3', 'max:150'],
            'address' => ['required', 'string', 'min:10', 'max:500'],
            'contact_name' => ['nullable', 'string', 'max:120'],
            'contact_phone' => ['nullable', 'string', 'max:20'],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            // Batas sama dengan CHECK di basis data. Ditulis dua kali
            // dengan sengaja: yang di sini memberi pesan yang dapat dibaca,
            // yang di sana berlaku walau jalurnya bukan halaman ini.
            'radius_meters' => ['required', 'integer', 'between:20,2000'],
            'is_active' => ['nullable', 'boolean'],
        ], [], [
            'latitude' => 'lintang',
            'longitude' => 'bujur',
            'radius_meters' => 'radius',
        ]);
    }

    /** @return array<string, mixed> */
    private function validatePlacement(Request $request, bool $wajibSiswa = true): array
    {
        return $request->validate([
            'student_id' => [$wajibSiswa ? 'required' : 'nullable', 'uuid'],
            'place_id' => ['required', 'uuid'],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['required', 'date', 'after_or_equal:starts_on'],
            'check_in_after' => ['nullable', 'date_format:H:i'],
            'check_out_before' => ['nullable', 'date_format:H:i'],
            'supervisor_name' => ['nullable', 'string', 'max:120'],
            'supervisor_phone' => ['nullable', 'string', 'max:20'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ], [], [
            'student_id' => 'siswa',
            'place_id' => 'tempat PKL',
            'starts_on' => 'tanggal mulai',
            'ends_on' => 'tanggal selesai',
            'check_in_after' => 'jam masuk',
            'check_out_before' => 'jam pulang',
        ]);
    }

    /**
     * Angka ringkasan. Satu kueri, bukan satu per angka.
     *
     * @return array<string, int>
     */
    private function stats(?string $schoolId): array
    {
        if (! $schoolId) {
            return ['tempat' => 0, 'berlaku' => 0, 'akan_datang' => 0, 'selesai' => 0];
        }

        $row = PklPlacement::query()
            ->where('pkl_placements.school_id', $schoolId)
            ->selectRaw(
                "count(*) FILTER (WHERE status = 'aktif'
                     AND CURRENT_DATE BETWEEN starts_on AND ends_on) AS berlaku,
                 count(*) FILTER (WHERE status = 'aktif'
                     AND starts_on > CURRENT_DATE)                   AS akan_datang,
                 count(*) FILTER (WHERE status <> 'aktif'
                     OR ends_on < CURRENT_DATE)                      AS selesai"
            )
            ->first();

        return [
            'tempat' => PklPlace::where('school_id', $schoolId)->count(),
            'berlaku' => (int) ($row->berlaku ?? 0),
            'akan_datang' => (int) ($row->akan_datang ?? 0),
            'selesai' => (int) ($row->selesai ?? 0),
        ];
    }
}
