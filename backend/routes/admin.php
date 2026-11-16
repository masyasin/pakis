<?php

use App\Domain\Settings\SettingRegistry;
use App\Http\Controllers\Admin\ApprovalController;
use App\Http\Controllers\Admin\ApprovalDelegationController;
use App\Http\Controllers\Admin\ApprovalRuleController;
use App\Http\Controllers\Admin\AssignmentController;
use App\Http\Controllers\Admin\AttendanceController;
use App\Http\Controllers\Admin\AttendanceCorrectionController;
use App\Http\Controllers\Admin\AttendanceSummaryController;
use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\CustomerController;
use App\Http\Controllers\Admin\CustomerVerificationController;
use App\Http\Controllers\Admin\DepartmentController;
use App\Http\Controllers\Admin\DeviceController;
use App\Http\Controllers\Admin\EmployeeController;
use App\Http\Controllers\Admin\ExportController;
use App\Http\Controllers\Admin\FieldTripController;
use App\Http\Controllers\Admin\ForcePasswordChangeController;
use App\Http\Controllers\Admin\HolidayController;
use App\Http\Controllers\Admin\ImportController;
use App\Http\Controllers\Admin\ImportProfileController;
use App\Http\Controllers\Admin\LocationController;
use App\Http\Controllers\Admin\LookupController;
use App\Http\Controllers\Admin\MasterImportController;
use App\Http\Controllers\Admin\MfaSetupController;
use App\Http\Controllers\Admin\PositionController;
use App\Http\Controllers\Admin\PrincipalController;
use App\Http\Controllers\Admin\ProductBarcodeController;
use App\Http\Controllers\Admin\ProductCategoryController;
use App\Http\Controllers\Admin\ProductController;
use App\Http\Controllers\Admin\ProductRegistrationController;
use App\Http\Controllers\Admin\ProductUnitController;
use App\Http\Controllers\Admin\ProfileController;
use App\Http\Controllers\Admin\ReferenceController;
use App\Http\Controllers\Admin\RegionController;
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\SalesAreaController;
use App\Http\Controllers\Admin\ScheduleController;
use App\Http\Controllers\Admin\ScheduleOverrideController;
use App\Http\Controllers\Admin\SecurityEventController;
use App\Http\Controllers\Admin\SequenceController;
use App\Http\Controllers\Admin\SettingController;
use App\Http\Controllers\Admin\ShiftController;
use App\Http\Controllers\Admin\StockBinController;
use App\Http\Controllers\Admin\StockLocationController;
use App\Http\Controllers\Admin\SupplierController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Admin\VehicleController;
use App\Support\Import\ImportRegistry;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Support\Facades\Route;

/*
 * Route web admin (05 §1): nama `admin.<modul>.<aksi>`, path Bahasa Indonesia.
 * Middleware `active`, `password.changed`, `mfa.enforced` ditambahkan saat dibuat (T-013–T-016).
 */
Route::middleware(['auth', 'web.access'])->name('admin.')->group(function () {
    Route::get('unduhan', [ExportController::class, 'index'])->name('exports.index');
    Route::get('unduhan/{file}', [ExportController::class, 'download'])
        ->middleware('signed')
        ->whereNumber('file')
        ->name('exports.download');

    // Wajib ganti password (05 §4.1, FR-007). Dibiarkan oleh middleware RequirePasswordChange.
    Route::get('ganti-password', [ForcePasswordChangeController::class, 'edit'])->name('password.force');
    Route::put('ganti-password', [ForcePasswordChangeController::class, 'update'])
        ->middleware('throttle:6,1')
        ->name('password.force.update');

    // Profil & Keamanan (05 §4.1, T-017): hanya akun sendiri, tanpa id pengguna di URL. Aksi yang
    // memeriksa password saat ini dibatasi agar tidak bisa dipakai menebak password.
    Route::get('profil', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::put('profil', [ProfileController::class, 'update'])->middleware('throttle:10,1')->name('profile.update');
    Route::get('profil/foto', [ProfileController::class, 'avatar'])->name('profile.avatar');
    Route::put('profil/password', [ProfileController::class, 'password'])->middleware('throttle:6,1')->name('profile.password');
    Route::get('profil/keamanan', [ProfileController::class, 'security'])->middleware(RequirePassword::class)->name('profile.security');
    Route::delete('profil/sesi', [ProfileController::class, 'destroyOtherSessions'])->middleware('throttle:10,1')->name('profile.sessions.destroy-others');
    Route::delete('profil/sesi/{session}', [ProfileController::class, 'destroySession'])->middleware('throttle:10,1')->name('profile.sessions.destroy');
    Route::post('profil/telegram', [ProfileController::class, 'linkTelegram'])->middleware('throttle:10,1')->name('profile.telegram.link');
    Route::delete('profil/telegram', [ProfileController::class, 'unlinkTelegram'])->name('profile.telegram.unlink');

    // Role & Hak Akses (05 §4, FR-017). Ubah role/permission = aksi sensitif → konfirmasi password
    // (06 §1.6). Aturan terkunci/role sendiri/role terpakai ada di SaveRole & DeleteRole.
    Route::get('role', [RoleController::class, 'index'])->middleware('can:roles.view')->name('roles.index');
    Route::middleware(['can:roles.manage', RequirePassword::class])->group(function () {
        Route::get('role/baru', [RoleController::class, 'create'])->name('roles.create');
        Route::post('role', [RoleController::class, 'store'])->name('roles.store');
        Route::get('role/{role}/ubah', [RoleController::class, 'edit'])->whereNumber('role')->name('roles.edit');
        Route::put('role/{role}', [RoleController::class, 'update'])->whereNumber('role')->name('roles.update');
        Route::delete('role/{role}', [RoleController::class, 'destroy'])->whereNumber('role')->name('roles.destroy');
    });

    // Pengguna (05 §4.4, FR-016). Permission dasar di route; wewenang atas akun tertentu (bukan diri
    // sendiri, Owner hanya oleh Owner, hak target ⊆ pelaku) di UserPolicy. `{user}` ber-scope data
    // (di luar scope → 404). `ekspor`/`baru` didaftarkan sebelum `{user}`.
    Route::get('pengguna', [UserController::class, 'index'])->middleware('can:users.view')->name('users.index');
    Route::get('pengguna/ekspor', [UserController::class, 'export'])->middleware('can:users.export')->name('users.export');
    Route::middleware('can:users.create')->group(function () {
        Route::get('pengguna/baru', [UserController::class, 'create'])->name('users.create');
        Route::post('pengguna', [UserController::class, 'store'])->middleware('throttle:30,1')->name('users.store');
    });
    Route::get('pengguna/{user}', [UserController::class, 'show'])->middleware('can:users.view')->whereNumber('user')->name('users.show');
    Route::middleware('can:users.update')->group(function () {
        Route::get('pengguna/{user}/ubah', [UserController::class, 'edit'])->whereNumber('user')->name('users.edit');
        Route::put('pengguna/{user}', [UserController::class, 'update'])->whereNumber('user')->name('users.update');
        Route::post('pengguna/{user}/cabut-sesi', [UserController::class, 'revokeSessions'])->whereNumber('user')->middleware('throttle:10,1')->name('users.revoke-sessions');
    });
    Route::patch('pengguna/{user}/status', [UserController::class, 'toggle'])
        ->middleware(['can:users.deactivate', 'throttle:20,1'])->whereNumber('user')->name('users.toggle');
    // Konfirmasi password admin di body request (AdminResetPasswordRequest), dibatasi agar tidak
    // bisa dipakai menebak password admin.
    Route::post('pengguna/{user}/reset-password', [UserController::class, 'resetPassword'])
        ->middleware(['can:users.reset_password', 'throttle:6,1'])->whereNumber('user')->name('users.reset-password');
    Route::post('pengguna/{user}/reset-mfa', [UserController::class, 'resetMfa'])
        ->middleware(['can:users.reset_mfa', 'throttle:10,1'])->whereNumber('user')->name('users.reset-mfa');

    // Shift (05 §4.9, FR-031, BR-060). `{shift}` ber-scope (06 §5). Hapus ditolak bila dipakai.
    Route::get('shift', [ShiftController::class, 'index'])->middleware('can:shifts.view')->name('shifts.index');
    Route::middleware('can:shifts.manage')->group(function () {
        Route::get('shift/baru', [ShiftController::class, 'create'])->name('shifts.create');
        Route::post('shift', [ShiftController::class, 'store'])->name('shifts.store');
        Route::get('shift/{shift}/ubah', [ShiftController::class, 'edit'])->whereNumber('shift')->name('shifts.edit');
        Route::put('shift/{shift}', [ShiftController::class, 'update'])->whereNumber('shift')->name('shifts.update');
        Route::delete('shift/{shift}', [ShiftController::class, 'destroy'])->whereNumber('shift')->name('shifts.destroy');
    });

    // Jadwal: pola, kalender, pengecualian (05 §4.9, FR-032, BR-061/BR-062). Ber-scope data lewat
    // pegawai (06 §5): pegawai sasaran di luar scope ditolak, `{override}` di luar scope → 404.
    Route::middleware('can:schedules.view')->group(function () {
        Route::get('jadwal', [ScheduleController::class, 'index'])->name('schedules.index');
        Route::get('jadwal/kalender', [ScheduleController::class, 'calendar'])->name('schedules.calendar');
        Route::get('jadwal/pengecualian', [ScheduleOverrideController::class, 'index'])->name('schedule-overrides.index');
        Route::get('jadwal/pengecualian/ekspor', [ScheduleOverrideController::class, 'export'])->name('schedule-overrides.export');
    });
    Route::middleware('can:schedules.manage')->group(function () {
        Route::post('jadwal/pola', [ScheduleController::class, 'storePatterns'])->name('schedules.patterns.store');
        Route::post('jadwal/pengecualian', [ScheduleOverrideController::class, 'store'])->name('schedule-overrides.store');
        Route::delete('jadwal/pengecualian/{override}', [ScheduleOverrideController::class, 'destroy'])->whereNumber('override')->name('schedule-overrides.destroy');
    });

    // Tugas luar kota (05 §4.9, BR-071, T-036A bagian 1). `{trip}` ber-scope (salah satu pegawai
    // dalam scope); ubah/batal juga FieldTripPolicy (semua pegawai dalam scope). `baru` sebelum `{trip}`.
    Route::get('tugas-luar-kota', [FieldTripController::class, 'index'])->middleware('can:field_trips.view')->name('field-trips.index');
    Route::middleware('can:field_trips.manage')->group(function () {
        Route::get('tugas-luar-kota/baru', [FieldTripController::class, 'create'])->name('field-trips.create');
        Route::post('tugas-luar-kota', [FieldTripController::class, 'store'])->name('field-trips.store');
        Route::get('tugas-luar-kota/{trip}/ubah', [FieldTripController::class, 'edit'])->whereNumber('trip')->name('field-trips.edit');
        Route::put('tugas-luar-kota/{trip}', [FieldTripController::class, 'update'])->whereNumber('trip')->name('field-trips.update');
        Route::post('tugas-luar-kota/{trip}/batalkan', [FieldTripController::class, 'cancel'])->whereNumber('trip')->name('field-trips.cancel');
    });
    // Putuskan pengajuan dari Pakis Pro (T-036A bagian 2, 06 §6 & §7.2a); `{trip}` ber-scope, wewenang dicek Action.
    Route::middleware('can:field_trips.approve')->group(function () {
        Route::post('tugas-luar-kota/{trip}/setujui', [FieldTripController::class, 'approve'])->whereNumber('trip')->name('field-trips.approve');
        Route::post('tugas-luar-kota/{trip}/tolak', [FieldTripController::class, 'reject'])->whereNumber('trip')->name('field-trips.reject');
    });

    // Hari libur (05 §4.9, FR-033, BR-062). Tidak ber-scope data. `baru` & `ekspor` sebelum `{holiday}`.
    Route::middleware('can:holidays.view')->group(function () {
        Route::get('hari-libur', [HolidayController::class, 'index'])->name('holidays.index');
        Route::get('hari-libur/ekspor', [HolidayController::class, 'export'])->name('holidays.export');
    });
    Route::middleware('can:holidays.manage')->group(function () {
        Route::get('hari-libur/baru', [HolidayController::class, 'create'])->name('holidays.create');
        Route::post('hari-libur', [HolidayController::class, 'store'])->name('holidays.store');
        Route::get('hari-libur/{holiday}/ubah', [HolidayController::class, 'edit'])->whereNumber('holiday')->name('holidays.edit');
        Route::put('hari-libur/{holiday}', [HolidayController::class, 'update'])->whereNumber('holiday')->name('holidays.update');
        Route::delete('hari-libur/{holiday}', [HolidayController::class, 'destroy'])->whereNumber('holiday')->name('holidays.destroy');
    });

    // Absensi harian (05 §4.10, FR-039, T-038). `{day}` ber-scope (06 §5: scope lokasi = snapshot);
    // selfie hanya lewat URL bertanda tangan 5 menit + `attendance.view_photo` (SEC-013).
    Route::get('absensi', [AttendanceController::class, 'index'])->middleware('can:attendance.view')->name('attendance.index');
    Route::get('absensi/ekspor', [AttendanceController::class, 'export'])->middleware('can:attendance.export')->name('attendance.export');
    // Rekap bulanan (05 §4.10, BR-072, T-039): agregasi per pegawai; `?kalender=<id>` = kalender pegawai.
    Route::get('absensi/rekap', [AttendanceSummaryController::class, 'index'])->middleware('can:attendance.view')->name('attendance-summary.index');
    Route::get('absensi/rekap/ekspor', [AttendanceSummaryController::class, 'export'])->middleware('can:attendance.export')->name('attendance-summary.export');
    Route::get('absensi/foto/{log}', [AttendanceController::class, 'selfie'])->whereNumber('log')
        ->middleware(['can:attendance.view_photo', 'signed', 'throttle:120,1'])->name('attendance.selfie');
    Route::get('absensi/{day}', [AttendanceController::class, 'show'])->whereNumber('day')->middleware('can:attendance.view')->name('attendance.show');

    // Koreksi presensi (05 §4.10, FR-038, BR-069, T-041). `{correction}` ber-scope (06 §5 + atasan
    // langsung); Setujui/Tolak juga memeriksa 06 §6 di Action. Presensi manual = koreksi langsung
    // disetujui yang diisi admin/atasan (keputusan Mas 16 Nov 2026).
    Route::middleware('can:attendance_corrections.view')->group(function () {
        Route::get('koreksi-absen', [AttendanceCorrectionController::class, 'index'])->name('attendance-corrections.index');
        Route::get('koreksi-absen/ekspor', [AttendanceCorrectionController::class, 'export'])->name('attendance-corrections.export');
        Route::get('koreksi-absen/{correction}', [AttendanceCorrectionController::class, 'show'])->whereNumber('correction')->name('attendance-corrections.show');
        Route::get('koreksi-absen/{correction}/lampiran', [AttendanceCorrectionController::class, 'attachment'])->whereNumber('correction')
            ->middleware('throttle:60,1')->name('attendance-corrections.attachment');
    });
    Route::middleware('can:attendance_corrections.approve')->group(function () {
        Route::post('koreksi-absen/manual', [AttendanceCorrectionController::class, 'manual'])->name('attendance-corrections.manual');
        Route::post('koreksi-absen/{correction}/setujui', [AttendanceCorrectionController::class, 'approve'])->whereNumber('correction')->name('attendance-corrections.approve');
        Route::post('koreksi-absen/{correction}/tolak', [AttendanceCorrectionController::class, 'reject'])->whereNumber('correction')->name('attendance-corrections.reject');
    });

    // Perangkat Pakis Pro (05 §4.8, BR-040, 06 §7.2). `{device}` ber-scope lewat pegawai pemilik akun.
    Route::middleware('can:devices.view')->group(function () {
        Route::get('perangkat', [DeviceController::class, 'index'])->name('devices.index');
        Route::get('perangkat/ekspor', [DeviceController::class, 'export'])->name('devices.export');
    });
    Route::middleware('can:devices.manage')->group(function () {
        Route::post('perangkat/{device}/setujui', [DeviceController::class, 'approve'])->whereNumber('device')->name('devices.approve');
        Route::post('perangkat/{device}/tolak', [DeviceController::class, 'reject'])->whereNumber('device')->name('devices.reject');
        Route::post('perangkat/{device}/cabut', [DeviceController::class, 'revoke'])->whereNumber('device')->name('devices.revoke');
    });

    // Wajib aktivasi MFA (05 §4.1, BR-015). Dibiarkan oleh middleware EnforceMfa.
    Route::get('aktivasi-mfa', MfaSetupController::class)->middleware(RequirePassword::class)->name('mfa.setup');

    // Lookup select/autocomplete (05 §4.11, BR-006). Izin per sumber dicek di controller.
    Route::get('lookup/{source}', [LookupController::class, 'show'])
        ->where('source', '[a-z_]+')
        ->middleware('throttle:120,1')
        ->name('lookup.show');

    // Penomoran (05 §4.3). Didaftarkan sebelum Pengaturan; `{group}` juga dibatasi ke grup registri.
    Route::get('pengaturan/penomoran', [SequenceController::class, 'index'])
        ->middleware('can:sequences.view')
        ->name('sequences.index');
    Route::post('pengaturan/penomoran/pratinjau', [SequenceController::class, 'preview'])
        ->middleware(['can:sequences.view', 'throttle:120,1'])
        ->name('sequences.preview');
    Route::put('pengaturan/penomoran/{sequence}', [SequenceController::class, 'update'])
        ->middleware('can:sequences.update')
        ->whereNumber('sequence')
        ->name('sequences.update');

    // Wilayah (05 §4.5, FR-021), hanya baca; diisi `php artisan regions:import`.
    Route::get('wilayah', [RegionController::class, 'index'])
        ->middleware('can:regions.view')
        ->name('regions.index');

    // Lokasi (05 §4.5, FR-022, BR-041). `baru` & `ekspor` sebelum `{location}`; lokasi di luar
    // scope 404 lewat route binding ber-scope.
    Route::middleware('can:locations.view')->group(function () {
        Route::get('lokasi', [LocationController::class, 'index'])->name('locations.index');
        Route::get('lokasi/ekspor', [LocationController::class, 'export'])->name('locations.export');
    });
    Route::middleware('can:locations.manage')->group(function () {
        Route::get('lokasi/baru', [LocationController::class, 'create'])->name('locations.create');
        Route::post('lokasi', [LocationController::class, 'store'])->name('locations.store');
        Route::get('lokasi/{location}/ubah', [LocationController::class, 'edit'])->whereNumber('location')->name('locations.edit');
        Route::put('lokasi/{location}', [LocationController::class, 'update'])->whereNumber('location')->name('locations.update');
        Route::delete('lokasi/{location}', [LocationController::class, 'destroy'])->whereNumber('location')->name('locations.destroy');
    });

    // Departemen (05 §4.5, FR-023). `baru` & `ekspor` sebelum `{department}`. Tidak ber-scope data.
    Route::middleware('can:departments.view')->group(function () {
        Route::get('departemen', [DepartmentController::class, 'index'])->name('departments.index');
        Route::get('departemen/ekspor', [DepartmentController::class, 'export'])->name('departments.export');
    });
    Route::middleware('can:departments.manage')->group(function () {
        Route::get('departemen/baru', [DepartmentController::class, 'create'])->name('departments.create');
        Route::post('departemen', [DepartmentController::class, 'store'])->name('departments.store');
        Route::get('departemen/{department}/ubah', [DepartmentController::class, 'edit'])->whereNumber('department')->name('departments.edit');
        Route::put('departemen/{department}', [DepartmentController::class, 'update'])->whereNumber('department')->name('departments.update');
        Route::delete('departemen/{department}', [DepartmentController::class, 'destroy'])->whereNumber('department')->name('departments.destroy');
    });

    // Jabatan (05 §4.5, FR-024, BR-042). `baru` & `ekspor` sebelum `{position}`. Tidak ber-scope data.
    Route::middleware('can:positions.view')->group(function () {
        Route::get('jabatan', [PositionController::class, 'index'])->name('positions.index');
        Route::get('jabatan/ekspor', [PositionController::class, 'export'])->name('positions.export');
    });
    Route::middleware('can:positions.manage')->group(function () {
        Route::get('jabatan/baru', [PositionController::class, 'create'])->name('positions.create');
        Route::post('jabatan', [PositionController::class, 'store'])->name('positions.store');
        Route::get('jabatan/{position}/ubah', [PositionController::class, 'edit'])->whereNumber('position')->name('positions.edit');
        Route::put('jabatan/{position}', [PositionController::class, 'update'])->whereNumber('position')->name('positions.update');
        Route::delete('jabatan/{position}', [PositionController::class, 'destroy'])->whereNumber('position')->name('positions.destroy');
    });

    // Pegawai (05 §4.6, FR-025, BR-050, BR-055). Pegawai di luar scope data → 404 lewat route
    // binding ber-scope (SEC-033). `baru` & `ekspor` sebelum `{employee}`. Buka NIK & buat akun
    // dibatasi laju: aksi sensitif yang tercatat, bukan untuk dipanggil berulang otomatis.
    Route::middleware('can:employees.view')->group(function () {
        Route::get('pegawai', [EmployeeController::class, 'index'])->name('employees.index');
        Route::get('pegawai/{employee}', [EmployeeController::class, 'show'])->whereNumber('employee')->name('employees.show');
        Route::get('pegawai/{employee}/foto', [EmployeeController::class, 'photo'])->whereNumber('employee')->name('employees.photo');
    });
    Route::get('pegawai/ekspor', [EmployeeController::class, 'export'])->middleware('can:employees.export')->name('employees.export');
    Route::middleware('can:employees.create')->group(function () {
        Route::get('pegawai/baru', [EmployeeController::class, 'create'])->name('employees.create');
        Route::post('pegawai', [EmployeeController::class, 'store'])->middleware('throttle:30,1')->name('employees.store');
    });
    Route::middleware('can:employees.update')->group(function () {
        Route::get('pegawai/{employee}/ubah', [EmployeeController::class, 'edit'])->whereNumber('employee')->name('employees.edit');
        Route::put('pegawai/{employee}', [EmployeeController::class, 'update'])->whereNumber('employee')->name('employees.update');
    });
    Route::delete('pegawai/{employee}', [EmployeeController::class, 'destroy'])->middleware('can:employees.delete')->whereNumber('employee')->name('employees.destroy');
    Route::post('pegawai/{employee}/nik', [EmployeeController::class, 'revealNik'])
        ->middleware(['can:employees.view_sensitive', 'throttle:30,1'])->whereNumber('employee')->name('employees.reveal-nik');
    Route::post('pegawai/{employee}/akun', [EmployeeController::class, 'account'])
        ->middleware(['can:users.create', 'throttle:30,1'])->whereNumber('employee')->name('employees.account');
    // Nonaktifkan / aktifkan kembali (05 §4.6, FR-027, BR-053): wajib password pelaku.
    Route::middleware(['can:employees.offboard', 'throttle:30,1'])->group(function () {
        Route::post('pegawai/{employee}/nonaktifkan', [EmployeeController::class, 'offboard'])->whereNumber('employee')->name('employees.offboard');
        Route::post('pegawai/{employee}/aktifkan', [EmployeeController::class, 'rehire'])->whereNumber('employee')->name('employees.rehire');
    });

    // Penugasan & mutasi (05 §4.6, FR-026, BR-051, BR-052). Penugasan/pegawai di luar scope → 404.
    Route::middleware('can:assignments.view')->group(function () {
        Route::get('penugasan', [AssignmentController::class, 'index'])->name('assignments.index');
        Route::get('penugasan/ekspor', [AssignmentController::class, 'export'])->name('assignments.export');
    });
    Route::middleware('can:assignments.manage')->group(function () {
        Route::post('pegawai/{employee}/penugasan', [AssignmentController::class, 'store'])->whereNumber('employee')->name('assignments.store');
        Route::put('penugasan/{assignment}', [AssignmentController::class, 'update'])->whereNumber('assignment')->name('assignments.update');
        Route::post('penugasan/{assignment}/akhiri', [AssignmentController::class, 'end'])->whereNumber('assignment')->name('assignments.end');
    });

    // Impor pegawai langkah 1 + riwayat & profil (05 §4.7, FR-028, BR-054, T-027). Rute statis
    // (`pegawai/*`, `profil`) didaftarkan sebelum `{batch}`.
    Route::middleware('can:employees.import')->group(function () {
        Route::get('impor', [ImportController::class, 'index'])->name('imports.index');
        Route::get('impor/pegawai/baru', [ImportController::class, 'create'])->name('imports.create');
        Route::get('impor/pegawai/templat', [ImportController::class, 'template'])->name('imports.template');
        Route::post('impor/pegawai', [ImportController::class, 'store'])->middleware('throttle:20,1')->name('imports.store');
        Route::get('impor/profil', [ImportProfileController::class, 'index'])->name('import-profiles.index');
        Route::delete('impor/profil/{profile}', [ImportProfileController::class, 'destroy'])->whereNumber('profile')->name('import-profiles.destroy');
    });

    // Impor tipe lain langkah 1 (BR-170, T-085A, 15 §5 `admin.master-imports.*`). `{type}` hanya slug
    // tipe terdaftar (ImportRegistry) agar tidak bentrok dengan `impor/pegawai…`, `impor/profil`, dan
    // `impor/{batch}`; izin = izin tipe itu (dicek controller).
    Route::get('impor/{type}/baru', [MasterImportController::class, 'create'])->where('type', ImportRegistry::routePattern())->name('master-imports.create');
    Route::get('impor/{type}/templat', [MasterImportController::class, 'template'])->where('type', ImportRegistry::routePattern())->name('master-imports.template');
    Route::post('impor/{type}', [MasterImportController::class, 'store'])->where('type', ImportRegistry::routePattern())->middleware('throttle:20,1')->name('master-imports.store');

    // Langkah per batch untuk SEMUA tipe impor (T-027/T-028, T-085A): izin tipe batch lewat
    // ImportBatchPolicy; batch di luar visibilitas → 404 di route binding (SEC-033).
    Route::middleware('can:manage,batch')->group(function () {
        Route::get('impor/{batch}', [ImportController::class, 'show'])->whereNumber('batch')->name('imports.show');
        Route::get('impor/{batch}/pemetaan', [ImportController::class, 'mapping'])->whereNumber('batch')->name('imports.mapping');
        Route::put('impor/{batch}/pemetaan', [ImportController::class, 'updateMapping'])->whereNumber('batch')->middleware('throttle:30,1')->name('imports.mapping.update');
        Route::get('impor/{batch}/baris', [ImportController::class, 'rows'])->whereNumber('batch')->name('imports.rows.index');
        Route::post('impor/{batch}/batal', [ImportController::class, 'cancel'])->whereNumber('batch')->name('imports.cancel');
        // Langkah 4 (T-028): proses hanya dari `validated`; unduh galat dari import_rows (NIK termasking).
        Route::post('impor/{batch}/proses', [ImportController::class, 'process'])->whereNumber('batch')->middleware('throttle:10,1')->name('imports.process');
        Route::get('impor/{batch}/galat', [ImportController::class, 'errors'])->whereNumber('batch')->middleware('throttle:30,1')->name('imports.errors');
    });

    // Referensi (05 §4.3, BR-026). `urutan` didaftarkan sebelum `{item}`.
    Route::get('referensi', [ReferenceController::class, 'index'])
        ->middleware('can:references.view')
        ->name('references.index');
    Route::middleware('can:references.manage')->group(function () {
        Route::post('referensi', [ReferenceController::class, 'store'])->name('references.store');
        Route::post('referensi/urutan', [ReferenceController::class, 'reorder'])->name('references.reorder');
        Route::put('referensi/{item}', [ReferenceController::class, 'update'])->whereNumber('item')->name('references.update');
        Route::delete('referensi/{item}', [ReferenceController::class, 'destroy'])->whereNumber('item')->name('references.destroy');
    });

    // Persetujuan (15 §5, T-067, BR-153, PA-27, PA-31). Tanpa `can:approvals.view`: calon penyetuju
    // langkah aktif selalu boleh membuka "Menunggu saya", detail, dan memutuskan (aturan di
    // ApprovalEngine/ApprovalVisibility; di luar itu `{approval}` → 404). `delegasi` didaftarkan
    // sebelum `{approval}`. Keputusan menerima `current_password` (konfirmasi bersyarat nominal), jadi
    // dibatasi agar tidak bisa dipakai menebak password.
    Route::get('persetujuan', [ApprovalController::class, 'index'])->name('approvals.index');
    Route::get('persetujuan/delegasi', [ApprovalDelegationController::class, 'index'])->name('approval-delegations.index');
    Route::post('persetujuan/delegasi', [ApprovalDelegationController::class, 'store'])->middleware('throttle:30,1')->name('approval-delegations.store');
    Route::delete('persetujuan/delegasi/{delegation}', [ApprovalDelegationController::class, 'destroy'])->whereNumber('delegation')->name('approval-delegations.destroy');
    Route::get('persetujuan/{approval}', [ApprovalController::class, 'show'])->whereNumber('approval')->name('approvals.show');
    Route::middleware('throttle:20,1')->group(function () {
        Route::post('persetujuan/{approval}/setujui', [ApprovalController::class, 'approve'])->whereNumber('approval')->name('approvals.approve');
        Route::post('persetujuan/{approval}/tolak', [ApprovalController::class, 'reject'])->whereNumber('approval')->name('approvals.reject');
        Route::post('persetujuan/{approval}/batalkan', [ApprovalController::class, 'cancel'])->whereNumber('approval')->name('approvals.cancel');
    });

    // Aturan Persetujuan (15 §5, FR-A02). Ubah aturan = aksi sensitif → konfirmasi password (rute
    // statis: RequirePassword, seperti Role). Pratinjau hanya membaca → tanpa konfirmasi.
    Route::middleware('can:approvals.configure')->group(function () {
        Route::get('pengaturan/aturan-persetujuan', [ApprovalRuleController::class, 'index'])->name('approval-rules.index');
        Route::post('pengaturan/aturan-persetujuan/pratinjau', [ApprovalRuleController::class, 'preview'])->middleware('throttle:60,1')->name('approval-rules.preview');
        Route::middleware(RequirePassword::class)->group(function () {
            Route::get('pengaturan/aturan-persetujuan/baru', [ApprovalRuleController::class, 'create'])->name('approval-rules.create');
            Route::post('pengaturan/aturan-persetujuan', [ApprovalRuleController::class, 'store'])->name('approval-rules.store');
            Route::get('pengaturan/aturan-persetujuan/{rule}/ubah', [ApprovalRuleController::class, 'edit'])->whereNumber('rule')->name('approval-rules.edit');
            Route::put('pengaturan/aturan-persetujuan/{rule}', [ApprovalRuleController::class, 'update'])->whereNumber('rule')->name('approval-rules.update');
            Route::delete('pengaturan/aturan-persetujuan/{rule}', [ApprovalRuleController::class, 'destroy'])->whereNumber('rule')->name('approval-rules.destroy');
        });
    });

    // Kategori produk (15 §5 `admin.product-categories.*`, FR-A06, BR-151, T-069). Form di dialog
    // halaman daftar; hapus hanya kategori tanpa sub-kategori & produk (selain itu nonaktifkan).
    Route::get('kategori-produk', [ProductCategoryController::class, 'index'])->middleware('can:products.view')->name('product-categories.index');
    Route::middleware('can:products.manage')->group(function () {
        Route::post('kategori-produk', [ProductCategoryController::class, 'store'])->name('product-categories.store');
        Route::put('kategori-produk/{category}', [ProductCategoryController::class, 'update'])->whereNumber('category')->name('product-categories.update');
        Route::delete('kategori-produk/{category}', [ProductCategoryController::class, 'destroy'])->whereNumber('category')->name('product-categories.destroy');
    });

    // Produk, satuan, barcode (15 §5 `admin.products.*`, FR-A07–A09, BR-150/151, T-070). `baru` &
    // `ekspor` didaftarkan sebelum `{product}`. Tanpa hapus: produk dinonaktifkan/dihentikan (status).
    Route::middleware('can:products.view')->group(function () {
        Route::get('produk', [ProductController::class, 'index'])->name('products.index');
        Route::get('produk/ekspor', [ProductController::class, 'export'])->name('products.export');
    });
    Route::middleware('can:products.manage')->group(function () {
        Route::get('produk/baru', [ProductController::class, 'create'])->name('products.create');
        Route::post('produk', [ProductController::class, 'store'])->name('products.store');
        Route::get('produk/{product}/ubah', [ProductController::class, 'edit'])->whereNumber('product')->name('products.edit');
        Route::put('produk/{product}', [ProductController::class, 'update'])->whereNumber('product')->name('products.update');
        Route::post('produk/{product}/satuan', [ProductUnitController::class, 'store'])->whereNumber('product')->name('products.units.store');
        Route::put('produk/{product}/satuan/{unit}', [ProductUnitController::class, 'update'])->whereNumber(['product', 'unit'])->name('products.units.update');
        Route::post('produk/{product}/barcode', [ProductBarcodeController::class, 'store'])->whereNumber('product')->middleware('throttle:60,1')->name('products.barcodes.store');
        Route::delete('produk/{product}/barcode/{barcode}', [ProductBarcodeController::class, 'destroy'])->whereNumber(['product', 'barcode'])->name('products.barcodes.destroy');
    });
    Route::get('produk/{product}', [ProductController::class, 'show'])->whereNumber('product')->middleware('can:products.view')->name('products.show');

    // Izin edar (15 §5 `admin.product-registrations.*`, FR-A10, BR-152, T-071). Cabut & unduh berkas
    // tersirat di task (RevokeRegistration, signed URL 5 menit) — PA-48. Tanpa hapus: riwayat utuh.
    Route::get('izin-edar', [ProductRegistrationController::class, 'index'])->middleware('can:products.view')->name('product-registrations.index');
    Route::get('izin-edar/{registration}/berkas', [ProductRegistrationController::class, 'document'])->whereNumber('registration')
        ->middleware(['can:products.view', 'signed:relative', 'throttle:120,1'])->name('product-registrations.document');
    Route::middleware('can:product_registrations.manage')->group(function () {
        Route::post('produk/{product}/izin-edar', [ProductRegistrationController::class, 'store'])->whereNumber('product')->name('product-registrations.store');
        Route::put('produk/{product}/izin-edar/{registration}', [ProductRegistrationController::class, 'update'])->whereNumber(['product', 'registration'])->name('product-registrations.update');
        Route::post('produk/{product}/izin-edar/{registration}/cabut', [ProductRegistrationController::class, 'revoke'])->whereNumber(['product', 'registration'])->name('product-registrations.revoke');
    });

    // Prinsipal & supplier (15 §5 `admin.principals.*`, `admin.suppliers.*`, FR-A11, SEC-108, T-072).
    // Produk dipasok disimpan lewat form supplier (tanpa rute terpisah). Tanpa hapus: dinonaktifkan.
    Route::get('prinsipal', [PrincipalController::class, 'index'])->middleware('can:suppliers.view')->name('principals.index');
    Route::middleware('can:suppliers.manage')->group(function () {
        Route::post('prinsipal', [PrincipalController::class, 'store'])->name('principals.store');
        Route::put('prinsipal/{principal}', [PrincipalController::class, 'update'])->whereNumber('principal')->name('principals.update');
        Route::get('supplier/baru', [SupplierController::class, 'create'])->name('suppliers.create');
        Route::post('supplier', [SupplierController::class, 'store'])->name('suppliers.store');
        Route::get('supplier/{supplier}/ubah', [SupplierController::class, 'edit'])->whereNumber('supplier')->name('suppliers.edit');
        Route::put('supplier/{supplier}', [SupplierController::class, 'update'])->whereNumber('supplier')->name('suppliers.update');
        Route::post('supplier/{supplier}/rekening', [SupplierController::class, 'revealAccount'])->whereNumber('supplier')
            ->middleware('throttle:30,1')->name('suppliers.reveal-account');
    });
    Route::middleware('can:suppliers.view')->group(function () {
        Route::get('supplier', [SupplierController::class, 'index'])->name('suppliers.index');
        Route::get('supplier/{supplier}', [SupplierController::class, 'show'])->whereNumber('supplier')->name('suppliers.show');
    });

    // Area penjualan (15 §5 `admin.sales-areas.*`, FR-A12, T-073): tambah/ubah lewat dialog di halaman
    // daftar. Tanpa hapus: area dinonaktifkan.
    Route::get('area-penjualan', [SalesAreaController::class, 'index'])->middleware('can:sales_areas.view')->name('sales-areas.index');
    Route::middleware('can:sales_areas.manage')->group(function () {
        Route::post('area-penjualan', [SalesAreaController::class, 'store'])->name('sales-areas.store');
        Route::put('area-penjualan/{salesArea}', [SalesAreaController::class, 'update'])->whereNumber('salesArea')->name('sales-areas.update');
    });

    // Pelanggan / kios (15 §5 `admin.customers.*`, FR-A13/A17, BR-167, T-073). `baru`, `ekspor`, dan
    // `alihkan` didaftarkan sebelum `{customer}`; kios di luar scope pelanggan (15 §6.2) → 404 lewat
    // binding ber-scope. Tanpa hapus (kios digabung/dinonaktifkan di T-074).
    Route::get('pelanggan', [CustomerController::class, 'index'])->middleware('can:customers.view')->name('customers.index');
    Route::get('pelanggan/ekspor', [CustomerController::class, 'export'])->middleware('can:customers.export')->name('customers.export');
    Route::middleware('can:customers.manage')->group(function () {
        Route::get('pelanggan/baru', [CustomerController::class, 'create'])->name('customers.create');
        Route::post('pelanggan', [CustomerController::class, 'store'])->name('customers.store');
        Route::post('pelanggan/alihkan', [CustomerController::class, 'reassign'])->middleware('throttle:30,1')->name('customers.reassign');
        Route::get('pelanggan/{customer}/ubah', [CustomerController::class, 'edit'])->whereNumber('customer')->name('customers.edit');
        Route::put('pelanggan/{customer}', [CustomerController::class, 'update'])->whereNumber('customer')->name('customers.update');
        // Verifikasi kios, cek duplikat, OTP (15 §5, BR-165/166, T-074). `cek-duplikat` & OTP = JSON.
        Route::post('pelanggan/cek-duplikat', [CustomerVerificationController::class, 'duplicates'])->middleware('throttle:customer-check')->name('customers.duplicates');
        Route::post('pelanggan/{customer}/verifikasi', [CustomerVerificationController::class, 'submit'])->whereNumber('customer')
            ->middleware('throttle:30,1')->name('customers.verify');
        Route::post('pelanggan/{customer}/verifikasi/telepon-kantor', [CustomerVerificationController::class, 'officeCall'])->whereNumber('customer')
            ->middleware('throttle:30,1')->name('customers.office-call');
        // Batas kirim/percobaan OTP dari setting `customer.otp_*` di CustomerOtpService (SEC-105).
        Route::post('pelanggan/{customer}/otp', [CustomerVerificationController::class, 'sendOtp'])->whereNumber('customer')->name('customers.otp.send');
        Route::post('pelanggan/{customer}/otp/cek', [CustomerVerificationController::class, 'checkOtp'])->whereNumber('customer')->name('customers.otp.check');
    });
    Route::get('pelanggan/verifikasi', [CustomerVerificationController::class, 'index'])->middleware('can:customers.verify')->name('customer-verifications.index');
    Route::get('pelanggan/{customer}', [CustomerController::class, 'show'])->whereNumber('customer')->middleware('can:customers.view')->name('customers.show');

    // Lokasi stok & rak (15 §5 `admin.stock-locations.*`, `admin.stock-bins.*`, FR-A03/A04, T-068).
    // `baru` & `rak` didaftarkan sebelum `{stockLocation}`; di luar dimensi Stok (15 §6.2) → 404 lewat
    // route binding ber-scope. Tanpa hapus: lokasi stok & rak dinonaktifkan. TRANSIT → 403 (Policy).
    Route::middleware('can:stock_locations.view')->group(function () {
        Route::get('lokasi-stok', [StockLocationController::class, 'index'])->name('stock-locations.index');
        Route::get('lokasi-stok/rak', [StockBinController::class, 'index'])->name('stock-bins.index');
    });
    Route::middleware('can:stock_locations.manage')->group(function () {
        Route::get('lokasi-stok/baru', [StockLocationController::class, 'create'])->name('stock-locations.create');
        Route::post('lokasi-stok', [StockLocationController::class, 'store'])->name('stock-locations.store');
        Route::post('lokasi-stok/rak', [StockBinController::class, 'store'])->name('stock-bins.store');
        Route::put('lokasi-stok/rak/{bin}', [StockBinController::class, 'update'])->whereNumber('bin')->name('stock-bins.update');
        Route::get('lokasi-stok/{stockLocation}/ubah', [StockLocationController::class, 'edit'])->whereNumber('stockLocation')->name('stock-locations.edit');
        Route::put('lokasi-stok/{stockLocation}', [StockLocationController::class, 'update'])->whereNumber('stockLocation')->name('stock-locations.update');
    });

    // Kendaraan minimal (15 §5 `admin.vehicles.*`, FR-A05, T-068). Centang canvas → lokasi stok
    // `vehicle` otomatis. Di luar scope lokasi asal → 404.
    Route::get('kendaraan', [VehicleController::class, 'index'])->middleware('can:vehicles.view')->name('vehicles.index');
    Route::middleware('can:vehicles.manage')->group(function () {
        Route::get('kendaraan/baru', [VehicleController::class, 'create'])->name('vehicles.create');
        Route::post('kendaraan', [VehicleController::class, 'store'])->name('vehicles.store');
        Route::get('kendaraan/{vehicle}/ubah', [VehicleController::class, 'edit'])->whereNumber('vehicle')->name('vehicles.edit');
        Route::put('kendaraan/{vehicle}', [VehicleController::class, 'update'])->whereNumber('vehicle')->name('vehicles.update');
    });

    // Pengaturan (05 §4.3). Grup sensitif minta konfirmasi password di controller.
    Route::post('pengaturan/uji-email', [SettingController::class, 'testMail'])
        ->middleware(['can:settings.update', 'throttle:3,1'])
        ->name('settings.test-mail');
    Route::get('pengaturan/{group?}', [SettingController::class, 'edit'])
        ->middleware('can:settings.view')
        ->whereIn('group', array_keys(SettingRegistry::GROUPS))
        ->name('settings.edit');
    Route::put('pengaturan/{group}', [SettingController::class, 'update'])
        ->middleware('can:settings.update')
        ->whereIn('group', array_keys(SettingRegistry::GROUPS))
        ->name('settings.update');

    // Audit & log keamanan (05 §4.4). Ekspor audit = aksi sensitif → konfirmasi ulang password (06 §1.6).
    Route::middleware('can:audit.view')->group(function () {
        Route::get('audit', [AuditLogController::class, 'index'])->name('audit.index');
        Route::get('audit/ekspor', [AuditLogController::class, 'export'])
            ->middleware(['can:audit.export', RequirePassword::class])
            ->name('audit.export');
        Route::get('audit/{log}', [AuditLogController::class, 'show'])->whereNumber('log')->name('audit.show');

        Route::get('log-keamanan', [SecurityEventController::class, 'index'])->name('security-events.index');
        Route::get('log-keamanan/ekspor', [SecurityEventController::class, 'export'])
            ->middleware(['can:audit.export', RequirePassword::class])
            ->name('security-events.export');
    });
});
