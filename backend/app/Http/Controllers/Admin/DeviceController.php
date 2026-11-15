<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Device\Actions\ApproveDevice;
use App\Domain\Device\Actions\RejectDevice;
use App\Domain\Device\Actions\RevokeDevice;
use App\Domain\Device\Listings\DeviceListing;
use App\Domain\Device\Models\Device;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Export\StartExport;
use App\Support\Listing\ListingQuery;
use App\Support\Listing\UsesListing;
use App\Support\Time\DisplayTime;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Perangkat Pakis Pro (05 §4.8, FR-034, BR-040, 06 §7.2). Izin dicek middleware route
 * (`can:devices.*`); perangkat di luar scope tidak tampil dan 404 di route ber-ID
 * (Device::resolveRouteBinding).
 */
class DeviceController extends Controller
{
    #[UsesListing(DeviceListing::class)]
    public function index(Request $request): Response
    {
        $user = self::user($request);
        $query = Device::query()->visibleTo($user)
            ->with(['user:id,name,username,employee_id', 'user.employee:id,employee_number,full_name']);

        $result = ListingQuery::apply($query, new DeviceListing, $request)->through(fn (Device $d) => self::row($d));

        return Inertia::render('devices/index', [
            ...$result->toInertia(),
            'can' => ['manage' => $user->can('devices.manage')],
        ]);
    }

    public function approve(Request $request, Device $device, ApproveDevice $action): RedirectResponse
    {
        $action->handle($device, self::user($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => "Perangkat {$device->user->name} disetujui. Minta pemilik masuk ulang di Pakis Pro."]);

        return back(fallback: route('admin.devices.index'));
    }

    public function reject(Request $request, Device $device, RejectDevice $action): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:255']], [
            'reason.required' => 'Tulis alasan penolakan.',
            'reason.min' => 'Alasan minimal 5 karakter.',
        ]);

        $action->handle($device, self::user($request), trim($data['reason']));

        Inertia::flash('toast', ['type' => 'success', 'message' => "Perangkat {$device->user->name} ditolak."]);

        return back(fallback: route('admin.devices.index'));
    }

    public function revoke(Request $request, Device $device, RevokeDevice $action): RedirectResponse
    {
        $action->handle($device, self::user($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => "Perangkat {$device->user->name} dicabut. Pakis Pro di HP itu langsung keluar."]);

        return back(fallback: route('admin.devices.index'));
    }

    public function export(Request $request, StartExport $export): HttpResponse
    {
        return $export->handle($request, 'devices', self::user($request));
    }

    /**
     * Baris perangkat untuk tabel (dipakai juga tab perangkat di detail pengguna/pegawai & profil).
     *
     * @return array<string, mixed>
     */
    public static function row(Device $device): array
    {
        $time = fn ($t) => $t === null ? null : DisplayTime::toLocal($t)->toIso8601String();

        return [
            'id' => $device->id,
            'user' => ['id' => $device->user->id, 'name' => $device->user->name, 'username' => $device->user->username],
            'employee' => $device->user->employee === null ? null : [
                'id' => $device->user->employee->id,
                'employee_number' => $device->user->employee->employee_number,
                'full_name' => $device->user->employee->full_name,
            ],
            'model' => $device->model,
            'os_version' => $device->os_version,
            'app_version' => $device->app_version,
            'status' => $device->status->value,
            'request_reason' => $device->request_reason,
            'created_at' => $time($device->created_at),
            'last_seen_at' => $time($device->last_seen_at),
        ];
    }

    /**
     * Ringkasan perangkat satu akun (Profil › Keamanan, detail Pengguna & Pegawai): yang aktif dan
     * menunggu dulu, lalu riwayat terbaru. Tanpa `device_uid` (pengenal teknis).
     *
     * @return list<array<string, mixed>>
     */
    public static function summaries(User $owner, int $limit = 10): array
    {
        $time = fn ($t) => $t === null ? null : DisplayTime::toLocal($t)->toIso8601String();

        $devices = Device::query()->where('user_id', $owner->id)
            ->orderByRaw("FIELD(status, 'pending', 'active') desc")
            ->latest('id')
            ->limit($limit)
            ->get();

        $rows = [];
        foreach ($devices as $d) {
            $rows[] = [
                'id' => $d->id,
                'model' => $d->model,
                'os_version' => $d->os_version,
                'app_version' => $d->app_version,
                'status' => $d->status->value,
                'request_reason' => $d->request_reason,
                'created_at' => $time($d->created_at),
                'last_seen_at' => $time($d->last_seen_at),
            ];
        }

        return $rows;
    }

    private static function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
