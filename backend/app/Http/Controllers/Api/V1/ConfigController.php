<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Settings\Branding\ImageProcessor;
use App\Http\Controllers\Controller;
use App\Support\Api\RequestId;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;

/**
 * Konfigurasi publik untuk Pakis Pro (05 §5.2, FR-042). Tanpa login.
 *
 * SEC-062: hanya kunci `isPublic` registri. Respons dibangun dari peta EKSPLISIT di bawah, dan
 * PublicConfigTest memastikan peta ini identik dengan daftar kunci publik registri (dua arah).
 */
class ConfigController extends Controller
{
    /** Kunci registri publik => path di `data`. */
    public const MAP = [
        'general.app_name' => 'app_name',
        'general.company_name' => 'company_name',
        'general.timezone' => 'timezone',
        'branding.logo_light' => 'logo_url',
        'branding.logo_dark' => 'logo_dark_url',
        'branding.favicon' => 'favicon_url',
        'branding.primary_color' => 'primary_color',
        'branding.login_tagline' => 'login_tagline',
        'mobile.min_version' => 'mobile.min_version',
        'mobile.latest_version' => 'mobile.latest_version',
        'mobile.download_url' => 'mobile.download_url',
        'mobile.update_message' => 'mobile.update_message',
        'attendance.require_selfie' => 'attendance.require_selfie',
        'attendance.max_accuracy_m' => 'attendance.max_accuracy_m',
        'attendance.offline_max_hours' => 'attendance.offline_max_hours',
        'attendance.correction_max_days' => 'attendance.correction_max_days',
        'attendance.correction_attachment_max_kb' => 'attendance.correction_attachment_max_kb',
        'security.password_min_length' => 'auth.password_min_length',
        'auth.forgot_channels' => 'auth.forgot_channels',
    ];

    private const IMAGE_KEYS = ['branding.logo_light', 'branding.logo_dark', 'branding.favicon'];

    public function __invoke(): JsonResponse
    {
        $data = [];
        foreach (self::MAP as $key => $path) {
            $value = setting($key);
            if (in_array($key, self::IMAGE_KEYS, true)) {
                $value = is_string($value) ? Storage::disk(ImageProcessor::DISK)->url($value) : null;
            }
            Arr::set($data, $path, $value);
        }

        // Logo gelap kosong = pakai logo terang (08 §2.1).
        $data['logo_dark_url'] ??= $data['logo_url'];

        return response()->json([
            'data' => $data,
            'meta' => ['request_id' => RequestId::current()],
        ]);
    }
}
