<?php

namespace App\Enums;

/**
 * Review state of an account (FLOW.md §3). Self-registered accounts start
 * pending; admin-created accounts are approved from the start. Separate from
 * `is_active`, which an admin toggles to deactivate an approved account.
 */
enum AccountStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';

    /**
     * The label shown on the Pendaftaran and Users pages and in the activity log.
     */
    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Menunggu review',
            self::Approved => 'Disetujui',
            self::Rejected => 'Ditolak',
        };
    }
}
