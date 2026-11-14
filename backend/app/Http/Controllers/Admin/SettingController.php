<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Settings\Actions\UpdateSettingGroup;
use App\Domain\Settings\Branding\ImageProcessor;
use App\Domain\Settings\Mail\TestMail;
use App\Domain\Settings\SettingDefinition;
use App\Domain\Settings\SettingRegistry;
use App\Domain\Settings\SettingStore;
use App\Domain\Settings\SettingType;
use App\Http\Controllers\Controller;
use App\Support\Auth\PasswordConfirmation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Halaman Pengaturan (05 §4.3, 07 §4): satu tab per grup registri, form dibuat otomatis.
 */
class SettingController extends Controller
{
    /** Grup yang nilainya dicetak di dokumen HTML awal (judul, favicon, CSS warna). */
    private const DOCUMENT_GROUPS = ['general', 'branding'];

    public function edit(Request $request, SettingStore $store, ?string $group = null): Response|RedirectResponse
    {
        $group ??= array_key_first(SettingRegistry::GROUPS);
        abort_unless(isset(SettingRegistry::GROUPS[$group]), 404);

        // Grup sensitif: konfirmasi password SEBELUM form dibuka, agar isian tidak hilang setelah konfirmasi.
        if ($this->needsPasswordConfirmation($request, $group)) {
            return redirect()->guest(route('password.confirm'));
        }

        return Inertia::render('site-settings/edit', [
            'groups' => collect(SettingRegistry::GROUPS)->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values(),
            'group' => $group,
            'sensitive' => in_array($group, SettingRegistry::SENSITIVE_GROUPS, true),
            'fields' => collect(SettingRegistry::group($group))
                ->map(fn (SettingDefinition $d) => $this->field($d, $store))
                ->values(),
            'canUpdate' => $request->user()?->can('settings.update') ?? false,
        ]);
    }

    public function update(Request $request, string $group, UpdateSettingGroup $action): RedirectResponse|SymfonyResponse
    {
        abort_unless(isset(SettingRegistry::GROUPS[$group]), 404);

        if ($this->needsPasswordConfirmation($request, $group)) {
            return redirect()->guest(route('password.confirm'));
        }

        /** @var array<string, UploadedFile> $files */
        $files = array_filter($request->allFiles(), fn ($f) => $f instanceof UploadedFile);

        $action->handle(
            $group,
            (array) $request->input('values', []),
            array_values(array_filter((array) $request->input('reset', []), 'is_string')),
            $files,
            $request->user()?->getAuthIdentifier(),
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Pengaturan disimpan.']);

        // Nama aplikasi, favicon, dan warna utama ada di dokumen HTML awal (app.blade.php), bukan
        // di props Inertia; muat ulang penuh agar perubahan langsung terlihat (T-008).
        if (in_array($group, self::DOCUMENT_GROUPS, true) && $request->header('X-Inertia')) {
            return Inertia::location(route('admin.settings.edit', ['group' => $group]));
        }

        return to_route('admin.settings.edit', ['group' => $group]);
    }

    /**
     * Kirim email uji ke pengguna yang sedang login, untuk memastikan SMTP berfungsi.
     */
    public function testMail(Request $request): RedirectResponse
    {
        $user = $request->user();
        $email = $user?->email;

        if (! is_string($email) || $email === '') {
            Inertia::flash('toast', ['type' => 'error', 'message' => 'Akun Anda belum punya email.']);

            return back();
        }

        Mail::to($email)->send(new TestMail);

        Inertia::flash('toast', ['type' => 'success', 'message' => "Email uji dikirim ke {$email}."]);

        return back();
    }

    private function needsPasswordConfirmation(Request $request, string $group): bool
    {
        if (! in_array($group, SettingRegistry::SENSITIVE_GROUPS, true)) {
            return false;
        }

        return ! PasswordConfirmation::isRecent($request);
    }

    /**
     * @return array<string, mixed>
     */
    private function field(SettingDefinition $d, SettingStore $store): array
    {
        $value = $store->get($d->key);

        return [
            'field' => $d->field(),
            'key' => $d->key,
            'type' => $d->type->value,
            'label' => $d->label,
            'help' => $d->help,
            'value' => $value,
            'default' => $d->cast($d->default),
            'customized' => $store->isCustomized($d->key),
            'options' => $d->options,
            'min' => $d->min,
            'max' => $d->max,
            'nullable' => $d->nullable,
            'public' => $d->isPublic,
            'image_url' => $d->type === SettingType::Image && is_string($value)
                ? ImageProcessor::publicUrl($value)
                : null,
        ];
    }
}
