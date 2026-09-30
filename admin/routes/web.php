<?php

use Illuminate\Support\Facades\Route;

// Import Controller Dashboard
use App\Http\Controllers\Backend\Dashboard\DashboardAdminController; // Sesuaikan jika nama controllernya beda
// Import Controller PROFILE
use App\Http\Controllers\Backend\MyProfile\AccountController;
use App\Http\Controllers\Backend\MyProfile\ProfileController;
use App\Http\Controllers\Backend\MyProfile\SecurityController;
use App\Http\Controllers\Backend\MyProfile\ActivityController;
use App\Http\Controllers\Backend\MyProfile\LoginSessionController;

// Import Controller USER MANAGEMENT
use App\Http\Controllers\Backend\UserManagement\UserController;
use App\Http\Controllers\Backend\UserManagement\RoleController;

// Import Controller HELP/LOG
use App\Http\Controllers\Backend\Help\LogActivityController;
use App\Http\Controllers\Backend\Settings\SettingController;

// Import Controller ABSENSI FACE RECOGNITION
use App\Http\Controllers\Backend\MasterData\SchoolController;
use App\Http\Controllers\Backend\MasterData\ClassroomController;
use App\Http\Controllers\Backend\MasterData\StudentController;
use App\Http\Controllers\Backend\Biometric\FaceEnrollmentController;
use App\Http\Controllers\Backend\Attendance\AttendanceController;
use App\Http\Controllers\Backend\Device\DeviceController;
use App\Http\Controllers\Backend\Notification\NotificationController;

// Import Controller JARGON GO (Super Apps)
use App\Http\Controllers\Backend\Cbt\CbtController;
use App\Http\Controllers\Backend\Panic\PanicController;
use App\Http\Controllers\Backend\Pkl\PklController;
use App\Http\Controllers\Backend\Document\DocumentController;
use App\Http\Controllers\Backend\Account\AppAccountController;
use App\Http\Controllers\Backend\FileProxyController;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/

// Halaman Depan (Langsung diarahkan ke Login)
// Halaman Depan (Langsung diarahkan ke Login)
Route::any('/', function () {
    return redirect('/admin/login');
});

Route::any('/dine-sync-pos', function () {
    return redirect('/admin/login');
});



// Rute debug `/admin/debug-session` dulu berada di sini, sengaja di LUAR
// middleware auth. Itu membuatnya dapat dibuka siapa pun tanpa login, dan
// jawabannya menyebut driver sesi serta ID sesi pengunjung. Versi yang sama
// sudah ada di dalam grup auth di bawah, sehingga yang publik ini hanya
// menambah permukaan serang tanpa menambah kemampuan mendiagnosis.

// NOTE: Route /login POST dihapus dari sini karena sudah ada di auth.php
// agar tidak bentrok "Route [login] defined twice".

// Group Middleware untuk User yang sudah Login
// Kita tambahkan 'forbid-banned-user' agar user yang di-banned tidak bisa akses
Route::middleware(['auth', 'forbid-banned-user'])->group(function () {

    // --- SHARED ROLE ROUTES (generate-permissions helper, select) ---
    Route::post('/admin/roles/generate-permissions', [RoleController::class, 'generatePermissions'])->name('roles.generate');
    Route::get('/admin/select/role', [RoleController::class, 'select'])->name('role.select');

    // --- DASHBOARD (accessible by ALL authenticated roles) ---
    Route::get('/admin/dashboard', [DashboardAdminController::class, 'index'])->name('dashboard');

    // --- MY ACCOUNT / PROFILE (accessible by ALL authenticated users) ---
    Route::get('/admin/my-account', [AccountController::class, 'index'])->name('account.index');
    Route::get('/admin/my-account/{id}/avatar', [AccountController::class, 'editAvatar'])->name('avatar-edit')->whereUuid('id');
    Route::post('/admin/my-account/{id}/update-avatar', [AccountController::class, 'updateAvatar'])->name('avatar-update')->whereUuid('id');

    // Hanya aksi yang benar-benar ada di controller. Tanpa batasan ini,
    // Route::resource mendaftarkan create/store/show/destroy yang metodenya
    // tidak ada, dan URL-nya membalas 500 (bukan 404) bila diketik orang.
    Route::resource('/admin/my-profile', ProfileController::class)->only(['index', 'edit', 'update'])->whereUuid('my_profile');
    Route::resource('/admin/my-security', SecurityController::class)->only(['index', 'edit', 'update'])->whereUuid('my_security');
    Route::post('/admin/my-security', [SecurityController::class, 'store'])->name('change.password');
    Route::post('/admin/my-security/logout-other-devices', [SecurityController::class, 'logoutOtherDevices'])->name('security.logout-other-devices');

    Route::get('/admin/my-activity', [ActivityController::class, 'index'])->name('my-activity.index');
    Route::get('/admin/mget-my-activity', [ActivityController::class, 'getActivity'])->name('get-my-activity');

    Route::get('/admin/mmy-login-session', [LoginSessionController::class, 'index'])->name('my-login-session.index');
    Route::get('/admin/mget-my-login-session', [LoginSessionController::class, 'getLoginSession'])->name('get-my-login-session');

    // --- SETTINGS (accessible by ALL authenticated users) ---
    Route::get('/admin/settings', [SettingController::class, 'index'])->name('settings.index');
    Route::post('/admin/settings/update', [SettingController::class, 'update'])->name('settings.update');

    // --- DEBUG/CHECK AUTH ---
    Route::get('/admin/check-auth', function () {
        $u = auth()->user();
        return [
            'user' => $u,
            'roles' => $u?->getRoleNames(),
            'permissions' => $u?->getAllPermissions()->pluck('name'),
        ];
    });
    Route::get('/admin/debug-session', function () {
        $user = auth()->user();
        return ['user' => $user?->name, 'roles' => $user?->getRoleNames()];
    });

    // ====================================================
    // RESOURCES (User & Role Mgmt): view_resources — Superadmin only
    // ====================================================
    Route::middleware('can:view_resources')->group(function () {
        // Parameter dibatasi bentuknya supaya URL seperti /admin/users/create tidak
        // jatuh ke aksi show dan meledak saat id dicocokkan ke kolom uuid.
        Route::resource('/admin/users', UserController::class)->except(['create'])->whereUuid('user');
        Route::get('/admin/get-datauser', [UserController::class, 'getDataUsers'])->name('get-users');
        Route::post('/admin/users/mass-delete', [UserController::class, 'massDelete'])->name('users.mass-delete');
        Route::get('/admin/get-user-show-log/{id}', [UserController::class, 'getLoginSession'])->name('get-user-show-log')->whereUuid('id');
        Route::get('/admin/get-user-show-log-activity/{id}', [UserController::class, 'getActivity'])->name('get-user-show-log-activity')->whereUuid('id');
        Route::post('/admin/users/{id}/ban', [UserController::class, 'ban'])->name('users.ban')->whereUuid('id');
        Route::post('/admin/users/{id}/unban', [UserController::class, 'unban'])->name('users.unban')->whereUuid('id');

        Route::resource('/admin/roles', RoleController::class)->except(['create'])->whereNumber('role');
        Route::get('/admin/get-datarole', [RoleController::class, 'getDataRoles'])->name('get-datarole');
        Route::post('/admin/roles/mass-delete', [RoleController::class, 'massDelete'])->name('roles.mass-delete');
    });

    // ====================================================
    // ABSENSI FACE RECOGNITION
    //
    // Otorisasi per aksi ditetapkan di dalam masing-masing controller lewat
    // HasMiddleware::middleware() — lebih dekat ke kode yang dijaga sehingga
    // tidak mudah tertinggal saat menambah aksi baru. Penjagaan tenant
    // (sekolah mana yang boleh dilihat) ditangani App\Support\Tenant dan
    // global scope pada model.
    // ====================================================

    // --- Master: Sekolah ---
    Route::get('/admin/schools/data', [SchoolController::class, 'data'])->name('schools.data');
    Route::resource('/admin/schools', SchoolController::class)->names('schools');

    // --- Master: Kelas (rombel) ---
    Route::get('/admin/classrooms', [ClassroomController::class, 'index'])->name('classrooms.index');
    Route::post('/admin/classrooms', [ClassroomController::class, 'store'])->name('classrooms.store');
    Route::put('/admin/classrooms/{classroom}', [ClassroomController::class, 'update'])->name('classrooms.update')->whereUuid('classroom');
    Route::delete('/admin/classrooms/{classroom}', [ClassroomController::class, 'destroy'])->name('classrooms.destroy')->whereUuid('classroom');

    // --- Master: Siswa & wali murid ---
    Route::get('/admin/students/data', [StudentController::class, 'data'])->name('students.data');
    Route::get('/admin/students/classrooms', [StudentController::class, 'classroomsBySchool'])->name('students.classrooms');
    Route::resource('/admin/students', StudentController::class)->names('students');
    Route::post('/admin/students/{student}/guardians', [StudentController::class, 'storeGuardian'])->name('students.guardians.store')->whereUuid('student');
    Route::put('/admin/students/{student}/guardians/{guardian}', [StudentController::class, 'updateGuardian'])->name('students.guardians.update')->whereUuid(['student', 'guardian']);
    Route::delete('/admin/students/{student}/guardians/{guardian}', [StudentController::class, 'destroyGuardian'])->name('students.guardians.destroy')->whereUuid(['student', 'guardian']);

    // --- Biometrik: pendaftaran wajah ---
    Route::get('/admin/biometric', [FaceEnrollmentController::class, 'index'])->name('biometric.index');
    // Absensi wajah dari browser. Rute LITERAL ini harus berada SEBELUM
    // /admin/biometric/{student}, kalau tidak Laravel akan mencoba
    // mengikat "scan" sebagai model Student dan menghasilkan 404.
    Route::get('/admin/biometric/scan', [FaceEnrollmentController::class, 'scan'])->name('biometric.scan');
    Route::get('/admin/biometric/{student}/capture', [FaceEnrollmentController::class, 'capture'])->name('biometric.capture')->whereUuid('student');
    Route::post('/admin/biometric/{student}', [FaceEnrollmentController::class, 'store'])->name('biometric.store')->whereUuid('student');
    Route::post('/admin/biometric/{student}/batch', [FaceEnrollmentController::class, 'storeBatch'])->name('biometric.store-batch')->whereUuid('student');
    Route::get('/admin/biometric/{student}', [FaceEnrollmentController::class, 'show'])->name('biometric.show')->whereUuid('student');
    Route::delete('/admin/biometric/sample/{enrollment}', [FaceEnrollmentController::class, 'destroy'])->name('biometric.destroy')->whereUuid('enrollment');
    Route::delete('/admin/biometric/{student}/samples', [FaceEnrollmentController::class, 'reset'])->name('biometric.reset')->whereUuid('student');

    // --- Absensi ---
    Route::get('/admin/attendances', [AttendanceController::class, 'index'])->name('attendances.index');
    Route::get('/admin/attendances/data', [AttendanceController::class, 'data'])->name('attendances.data');
    Route::get('/admin/attendances/live', [AttendanceController::class, 'live'])->name('attendances.live');
    Route::get('/admin/attendances/by-classroom', [AttendanceController::class, 'byClassroom'])->name('attendances.by-classroom');
    Route::get('/admin/attendances/recap', [AttendanceController::class, 'recap'])->name('attendances.recap');
    Route::post('/admin/attendances/manual', [AttendanceController::class, 'manual'])->name('attendances.manual');
    Route::post('/admin/attendances/bulk', [AttendanceController::class, 'bulk'])->name('attendances.bulk');
    // Hanya superadmin; lihat AttendanceController::destroy.
    Route::delete('/admin/attendances/{attendance}', [AttendanceController::class, 'destroy'])->name('attendances.destroy')->whereUuid('attendance');
    Route::get('/admin/attendance-rules', [AttendanceController::class, 'rules'])->name('attendance-rules.index');
    Route::post('/admin/attendance-rules', [AttendanceController::class, 'storeRule'])->name('attendance-rules.store');

    // --- Perangkat tablet ---
    Route::get('/admin/devices', [DeviceController::class, 'index'])->name('devices.index');
    Route::post('/admin/devices', [DeviceController::class, 'store'])->name('devices.store');
    Route::get('/admin/devices/{device}', [DeviceController::class, 'show'])->name('devices.show')->whereUuid('device');
    Route::put('/admin/devices/{device}', [DeviceController::class, 'update'])->name('devices.update')->whereUuid('device');
    Route::post('/admin/devices/{device}/pairing-code', [DeviceController::class, 'pairingCode'])->name('devices.pairing-code')->whereUuid('device');
    Route::post('/admin/devices/{device}/revoke', [DeviceController::class, 'revoke'])->name('devices.revoke')->whereUuid('device');
    Route::delete('/admin/devices/{device}', [DeviceController::class, 'destroy'])->name('devices.destroy')->whereUuid('device');

    // --- Notifikasi wali murid ---
    Route::get('/admin/notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::get('/admin/notifications/outbox', [NotificationController::class, 'outbox'])->name('notifications.outbox');
    Route::get('/admin/notifications/outbox/data', [NotificationController::class, 'outboxData'])->name('notifications.outbox.data');
    Route::get('/admin/notifications/templates', [NotificationController::class, 'templates'])->name('notifications.templates');
    Route::post('/admin/notifications/templates', [NotificationController::class, 'storeTemplate'])->name('notifications.templates.store');
    Route::post('/admin/notifications/policy', [NotificationController::class, 'updatePolicy'])->name('notifications.policy');
    Route::post('/admin/notifications/send', [NotificationController::class, 'send'])->name('notifications.send');
    Route::post('/admin/notifications/{outbox}/retry', [NotificationController::class, 'retry'])->name('notifications.retry')->whereUuid('outbox');

    // ====================================================
    // JARGON GO — Praktik Kerja Lapangan
    //
    // Ditulis langsung lewat Eloquent, sama seperti Kelas dan Siswa. API
    // Rust hanya memiliki absensinya, karena aturan absen itulah yang juga
    // dipakai aplikasi ponsel. Keutuhan datanya dijaga basis data: CHECK
    // untuk batas radius, EXCLUDE untuk masa penempatan yang bertumpuk.
    // ====================================================
    Route::get('/admin/pkl', [PklController::class, 'index'])->name('pkl.index');
    Route::post('/admin/pkl/places', [PklController::class, 'storePlace'])->name('pkl.places.store');
    Route::put('/admin/pkl/places/{id}', [PklController::class, 'updatePlace'])->name('pkl.places.update')->whereUuid('id');
    Route::delete('/admin/pkl/places/{id}', [PklController::class, 'destroyPlace'])->name('pkl.places.destroy')->whereUuid('id');
    Route::post('/admin/pkl/placements', [PklController::class, 'storePlacement'])->name('pkl.placements.store');
    Route::put('/admin/pkl/placements/{id}', [PklController::class, 'updatePlacement'])->name('pkl.placements.update')->whereUuid('id');
    Route::post('/admin/pkl/placements/{id}/end', [PklController::class, 'endPlacement'])->name('pkl.placements.end')->whereUuid('id');
    Route::get('/admin/pkl/placements/{id}', [PklController::class, 'show'])->name('pkl.placements.show')->whereUuid('id');

    // ====================================================
    // JARGON GO — CBT/TKA
    //
    // Modulnya belum dibangun; halamannya masih penanda "sedang
    // dikembangkan". Dipasangi `can:view_cbt` sejak sekarang supaya
    // pembatasan aksesnya tidak menjadi hal yang mudah terlupa ketika
    // isinya nanti ditambahkan.
    // ====================================================
    Route::middleware('can:view_cbt')->group(function () {
        Route::get('/admin/cbt', [CbtController::class, 'index'])->name('cbt.index');
    });

    // ====================================================
    // JARGON GO — Panic Button (pengaduan anonim)
    //
    // Identitas pelapor tidak pernah ditampilkan di halaman mana pun.
    // Rute `unmask` adalah satu-satunya jalan membukanya, dan setiap
    // pemakaiannya tercatat permanen di panic_unmask_logs.
    // ====================================================
    Route::get('/admin/panic', [PanicController::class, 'index'])->name('panic.index');
    Route::get('/admin/panic/unmask-logs', [PanicController::class, 'unmaskLogs'])->name('panic.unmask-logs');
    Route::get('/admin/panic/{id}', [PanicController::class, 'show'])->name('panic.show')->whereUuid('id');
    Route::post('/admin/panic/{id}/moderate', [PanicController::class, 'moderate'])->name('panic.moderate')->whereUuid('id');
    Route::post('/admin/panic/{id}/status', [PanicController::class, 'updateStatus'])->name('panic.status')->whereUuid('id');
    Route::post('/admin/panic/{id}/comment', [PanicController::class, 'comment'])->name('panic.comment')->whereUuid('id');
    Route::post('/admin/panic/{id}/unmask', [PanicController::class, 'unmask'])->name('panic.unmask')->whereUuid('id');

    // ====================================================
    // JARGON GO — Pemberkasan kepegawaian
    // ====================================================
    Route::get('/admin/documents', [DocumentController::class, 'index'])->name('documents.index');
    Route::get('/admin/documents/types', [DocumentController::class, 'types'])->name('documents.types');
    Route::post('/admin/documents/types', [DocumentController::class, 'storeType'])->name('documents.types.store');
    Route::get('/admin/documents/{id}', [DocumentController::class, 'show'])->name('documents.show')->whereUuid('id');
    Route::post('/admin/documents/{id}/review', [DocumentController::class, 'review'])->name('documents.review')->whereUuid('id');
    Route::post('/admin/documents/files/{fileId}/review', [DocumentController::class, 'reviewFile'])->name('documents.files.review')->whereUuid('fileId');

    // ====================================================
    // JARGON GO — Akun aplikasi
    //
    // Pendaftaran akun tidak swalayan: hubungan orang tua-anak hanya bisa
    // diverifikasi sekolah, jadi seluruh pembuatan akun lewat sini.
    // ====================================================
    Route::get('/admin/app-accounts', [AppAccountController::class, 'index'])->name('app-accounts.index');
    Route::get('/admin/app-accounts/create', [AppAccountController::class, 'create'])->name('app-accounts.create');
    Route::post('/admin/app-accounts', [AppAccountController::class, 'store'])->name('app-accounts.store');
    Route::get('/admin/app-accounts/bulk', [AppAccountController::class, 'bulk'])->name('app-accounts.bulk');
    Route::post('/admin/app-accounts/bulk', [AppAccountController::class, 'bulkStore'])->name('app-accounts.bulk.store');
    Route::get('/admin/app-accounts/students', [AppAccountController::class, 'searchStudents'])->name('app-accounts.students');
    // `{id}` dibatasi berbentuk UUID. Tanpa itu, alamat seperti
    // /admin/app-accounts/abc diteruskan ke findOrFail dan Postgres menjawab
    // "invalid input syntax for type uuid" — muncul sebagai 500, padahal
    // yang benar 404.
    Route::get('/admin/app-accounts/{id}', [AppAccountController::class, 'show'])->name('app-accounts.show')->whereUuid('id');
    Route::post('/admin/app-accounts/{id}/reset-password', [AppAccountController::class, 'resetPassword'])->name('app-accounts.reset-password')->whereUuid('id');
    Route::post('/admin/app-accounts/{id}/children', [AppAccountController::class, 'linkChild'])->name('app-accounts.children.link')->whereUuid('id');
    Route::delete('/admin/app-accounts/{id}/children/{studentId}', [AppAccountController::class, 'unlinkChild'])->name('app-accounts.children.unlink')->whereUuid(['id', 'studentId']);

    // --- Berkas tersimpan (foto wajah, pemberkasan, lampiran pengaduan) ---
    //
    // Browser tidak memegang access token API, jadi permintaan berkas lewat
    // sini agar token sesi pengguna bisa ditempelkan. Otorisasinya tetap
    // dilakukan API.
    Route::get('/admin/files/{key}', FileProxyController::class)
        ->where('key', '.*')
        ->name('files.show');

    // --- Pemilih sekolah aktif (untuk peran tingkat provinsi) ---
    Route::post('/admin/switch-school', function (\Illuminate\Http\Request $request) {
        $schoolId = $request->input('school_id');
        // Tenant::currentSchoolId() sekaligus memvalidasi hak akses dan
        // menyimpan pilihan ke session.
        \App\Support\Tenant::currentSchoolId($schoolId === null ? 'all' : (string) $schoolId);

        return back();
    })->name('switch-school');

    // ====================================================
    // HELP (Log Activity): view_help — Superadmin, admin
    // ====================================================
    Route::middleware('can:view_help')->group(function () {
        Route::resource('/admin/log-activity', LogActivityController::class)->only(['index', 'show'])->whereNumber('log_activity');
        Route::get('/admin/get-datalogactivity', [LogActivityController::class, 'getDataLogActivity'])->name('get-datalogactivity');
    });
});

// Load Routes Authentication (Login, Register, Reset Password)
require __DIR__ . '/auth.php';
