<?php

namespace App\Http\Controllers\Backend\MasterData;

use App\Http\Controllers\Controller;
use App\Models\Classroom;
use App\Models\School;
use App\Models\Student;
use App\Support\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Kartu siswa untuk absen dengan barcode batang atau QR.
 *
 * Kartu memuat `students.card_code`, kode acak 12 karakter (migrasi 0030),
 * BUKAN NISN: NISN tercetak di banyak dokumen dan tidak bisa diganti bila
 * kartunya hilang. Kode kartu bisa — lihat reissue().
 *
 * Barcode dan QR digambar di peramban (assets/vendor/kartu), jadi halaman
 * cetak tidak butuh pustaka PHP tambahan dan tetap jalan tanpa internet.
 */
class StudentCardController extends Controller implements HasMiddleware
{
    public const FORMATS = ['qr' => 'QR Code', 'barcode' => 'Barcode batang'];

    /** Batas satu halaman cetak: lebih dari ini peramban tablet/PC sekolah mulai tersendat. */
    private const MAX_PER_PRINT = 400;

    public static function middleware(): array
    {
        return [
            'auth',
            new Middleware('can:view_student', only: ['index', 'print']),
            new Middleware('can:update_student', only: ['reissue']),
        ];
    }

    /** Formulir cetak massal: pilih sekolah, kelas, dan jenis kode. */
    public function index(Request $request): View
    {
        $schoolId = Tenant::currentSchoolId($request->query('school_id'));

        $classrooms = collect();
        if ($schoolId) {
            $classrooms = Classroom::where('school_id', $schoolId)
                ->where('is_active', true)
                ->withCount(['students as aktif_count' => fn ($q) => $q->where('status', 'aktif')])
                ->orderBy('grade_level')
                ->orderBy('name')
                ->get(['id', 'name']);
        }

        return view('backend.master.student-card.index', [
            'schoolId' => $schoolId,
            'schools' => Tenant::isProvinceScope() ? Tenant::selectableSchools() : collect(),
            'classrooms' => $classrooms,
            'formats' => self::FORMATS,
        ]);
    }

    /**
     * Halaman siap cetak. Tiga cara memanggilnya:
     *   ?student_id=...                  satu kartu (dari detail siswa)
     *   ?school_id=...&classroom_id=...  satu kelas
     *   ?school_id=...                   seluruh sekolah, dikelompokkan per kelas
     */
    public function print(Request $request): View
    {
        $data = $request->validate([
            'student_id' => ['nullable', 'uuid'],
            'school_id' => ['nullable', 'uuid'],
            'classroom_id' => ['nullable', 'uuid'],
            'format' => ['nullable', 'in:'.implode(',', array_keys(self::FORMATS))],
        ]);
        $format = $data['format'] ?? 'qr';

        // Kolom diberi awalan tabel: kueri ini di-join ke classrooms, yang
        // juga punya school_id.
        $query = Student::query()
            ->where('students.status', 'aktif')
            ->whereNotNull('students.card_code')
            ->with(['classroom:id,name', 'school:id,name,npsn']);

        if (! empty($data['student_id'])) {
            $student = Student::findOrFail($data['student_id']);
            Tenant::authorizeSchool($student->school_id);
            $query->where('students.id', $student->id);
            $school = School::find($student->school_id);
        } else {
            $schoolId = Tenant::currentSchoolId($data['school_id'] ?? null);
            abort_unless($schoolId, 422, 'Pilih sekolah lebih dulu.');
            Tenant::authorizeSchool($schoolId);
            $school = School::findOrFail($schoolId);
            $query->where('students.school_id', $schoolId)
                ->when($data['classroom_id'] ?? null, fn ($q, $c) => $q->where('students.current_classroom_id', $c));
        }

        // Urut kelas lalu nama: tumpukan kartu yang keluar dari printer
        // langsung bisa dibagikan per kelas tanpa dipilah lagi.
        $students = $query
            ->leftJoin('classrooms as c', 'c.id', '=', 'students.current_classroom_id')
            ->orderByRaw('c.grade_level NULLS LAST, c.name NULLS LAST')
            ->orderBy('students.full_name')
            ->select('students.*')
            ->limit(self::MAX_PER_PRINT + 1)
            ->get();

        $terpotong = $students->count() > self::MAX_PER_PRINT;

        return view('backend.master.student-card.print', [
            'students' => $students->take(self::MAX_PER_PRINT),
            'school' => $school,
            'format' => $format,
            'terpotong' => $terpotong,
            'max' => self::MAX_PER_PRINT,
        ]);
    }

    /**
     * Terbitkan kode baru untuk kartu yang hilang atau dipinjamkan.
     * Kode lama langsung tidak dikenali tablet.
     */
    public function reissue(Request $request, Student $student): RedirectResponse
    {
        Tenant::authorizeSchool($student->school_id);

        // Diulang bila bentrok dengan indeks unik; peluangnya sangat kecil
        // tetapi bukan nol, dan kegagalan di sini tidak boleh jadi 500.
        for ($i = 0; $i < 5; $i++) {
            $kode = strtoupper(bin2hex(random_bytes(6)));
            if (! DB::table('students')->where('card_code', $kode)->exists()) {
                break;
            }
        }

        DB::table('students')->where('id', $student->id)->update([
            'card_code' => $kode,
            'card_issued_at' => now(),
            'updated_at' => now(),
        ]);

        activity()->performedOn($student)->causedBy($request->user())
            ->log('Menerbitkan ulang kartu siswa');

        return back()->with('success', 'Kode kartu baru diterbitkan. Kartu lama tidak berlaku lagi — cetak ulang kartunya.');
    }
}
