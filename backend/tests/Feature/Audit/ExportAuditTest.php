<?php

use App\Domain\Audit\Enums\SecurityEventType;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Audit\Models\SecurityEvent;
use App\Models\User;
use App\Support\Export\ExportRunner;
use App\Support\Export\StartExport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Tests\Fixtures\Listing\UserExportListing;

/*
 * BR-005 / SEC-051: setiap ekspor diaudit; ekspor besar menjadi event keamanan mass_export.
 */

beforeEach(function () {
    Storage::fake(ExportRunner::DISK);
    config(['pakis.exports.test_users' => UserExportListing::class]);
    Route::middleware(['web', 'auth'])->get('admin/__uji/ekspor', fn (Request $r) => app(StartExport::class)->handle($r, 'test_users', $r->user()));
    $this->user = User::factory()->create();
});

test('BR-005 ekspor dicatat di audit export.created dengan jumlah baris', function () {
    User::factory()->count(2)->create();

    $this->actingAs($this->user)->get('/admin/__uji/ekspor')->assertOk();

    $log = AuditLog::query()->where('event', 'export.created')->sole();
    expect($log->user_id)->toBe($this->user->id)
        ->and($log->new_values['listing'])->toBe('test_users')
        ->and($log->new_values['row_count'])->toBe(3);
    expect(SecurityEvent::query()->count())->toBe(0);
});

test('BR-005 ekspor di atas ambang mencatat security event mass_export', function () {
    setSetting('export.mass_export_alert_rows', 2);
    User::factory()->count(2)->create();

    $this->actingAs($this->user)->get('/admin/__uji/ekspor')->assertOk();

    $event = SecurityEvent::query()->sole();
    expect($event->event)->toBe(SecurityEventType::MassExport)
        ->and($event->user_id)->toBe($this->user->id)
        ->and($event->context['row_count'])->toBe(3)
        ->and($event->context['listing'])->toBe('test_users');
});
