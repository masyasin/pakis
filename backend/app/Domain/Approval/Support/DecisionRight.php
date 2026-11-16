<?php

namespace App\Domain\Approval\Support;

/**
 * Hasil ApproverResolver::rightOf(): boleh (langsung / atas nama pemberi delegasi), ditolak karena
 * maker-checker (`selfDecision`, 403 `APPROVAL_SELF_DECISION`), atau bukan gilirannya.
 */
final class DecisionRight
{
    private function __construct(
        public readonly bool $allowed,
        public readonly ?int $actingForUserId,
        public readonly bool $selfDecision,
    ) {}

    public static function direct(): self
    {
        return new self(true, null, false);
    }

    public static function delegated(int $delegatorUserId): self
    {
        return new self(true, $delegatorUserId, false);
    }

    public static function selfDecision(): self
    {
        return new self(false, null, true);
    }

    public static function denied(): self
    {
        return new self(false, null, false);
    }
}
