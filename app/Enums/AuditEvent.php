<?php

namespace App\Enums;

/**
 * Event names written to the activity log, with their display labels.
 */
enum AuditEvent: string
{
    case Created = 'created';
    case Updated = 'updated';
    case Deleted = 'deleted';
    case Restored = 'restored';
    case Activated = 'activated';
    case Deactivated = 'deactivated';
    case RolesUpdated = 'roles_updated';
    case StatusChanged = 'status_changed';
    case AttachmentAdded = 'attachment_added';
    case AttachmentRemoved = 'attachment_removed';
    case Exported = 'exported';
    case CommentAdded = 'comment_added';
    case CommentEdited = 'comment_edited';
    case CommentDeleted = 'comment_deleted';
    case InvoiceIssued = 'invoice_issued';
    case InvoiceCorrected = 'invoice_corrected';
    case PaymentConfirmed = 'payment_confirmed';
    case Registered = 'registered';
    case RegistrationApproved = 'registration_approved';
    case RegistrationRejected = 'registration_rejected';

    case Login = 'login';
    case Logout = 'logout';
    case LoginFailed = 'login_failed';
    case PasswordInitialChanged = 'password_initial_changed';
    case PasswordChanged = 'password_changed';
    case PasswordReset = 'password_reset';

    /**
     * The label shown in the activity log.
     */
    public function label(): string
    {
        return match ($this) {
            self::Created => 'Dibuat',
            self::Updated => 'Diubah',
            self::Deleted => 'Dihapus',
            self::Restored => 'Dipulihkan',
            self::Activated => 'Diaktifkan',
            self::Deactivated => 'Dinonaktifkan',
            self::RolesUpdated => 'Role diubah',
            self::StatusChanged => 'Status diubah',
            self::AttachmentAdded => 'Lampiran ditambahkan',
            self::AttachmentRemoved => 'Lampiran dihapus',
            self::Exported => 'Diekspor',
            self::CommentAdded => 'Komentar ditambahkan',
            self::CommentEdited => 'Komentar diubah',
            self::CommentDeleted => 'Komentar dihapus',
            self::InvoiceIssued => 'Invoice diterbitkan',
            self::InvoiceCorrected => 'Invoice dikoreksi',
            self::PaymentConfirmed => 'Pembayaran dikonfirmasi',
            self::Registered => 'Mendaftar',
            self::RegistrationApproved => 'Pendaftaran disetujui',
            self::RegistrationRejected => 'Pendaftaran ditolak',
            self::Login => 'Login',
            self::Logout => 'Logout',
            self::LoginFailed => 'Login gagal',
            self::PasswordInitialChanged => 'Password awal diganti',
            self::PasswordChanged => 'Password diganti',
            self::PasswordReset => 'Password direset',
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $event): array => ['value' => $event->value, 'label' => $event->label()], self::cases());
    }
}
