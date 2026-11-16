<?php

namespace App\Domain\Approval\Support;

use App\Domain\Access\Support\PermissionCatalog;
use App\Domain\Approval\ApproverResolver;
use App\Domain\Approval\Enums\ApproverType;

/** Teks penyetuju langkah untuk UI (kotak masuk, detail, daftar aturan, pratinjau). */
final class StepDescriber
{
    /** @var array<string, string>|null */
    private static ?array $labels = null;

    public static function permissionLabel(?string $permission): ?string
    {
        if ($permission === null) {
            return null;
        }
        if (self::$labels === null) {
            self::$labels = [];
            foreach (PermissionCatalog::grouped() as $group) {
                foreach ($group['permissions'] as $p) {
                    self::$labels[$p['name']] = $group['label'].' — '.$p['label'];
                }
            }
        }

        return self::$labels[$permission] ?? $permission;
    }

    public static function approver(ApproverType $type, ?string $permission): string
    {
        return match ($type) {
            ApproverType::Permission => 'Pemegang izin: '.self::permissionLabel($permission),
            ApproverType::DirectSupervisor => 'Atasan langsung (penugasan utama)',
            ApproverType::LocationHead => 'Kepala lokasi',
        };
    }

    public static function escalation(?string $permission): string
    {
        return 'Eskalasi: '.self::permissionLabel($permission ?? ApproverResolver::FALLBACK_PERMISSION);
    }
}
