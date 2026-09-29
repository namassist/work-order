<?php

namespace App\Actions\WorkOrders;

use RuntimeException;

/**
 * A payment track change the rules refuse although the user holds the
 * permission for it, usually because the page went stale. The message is
 * shown to the user.
 */
class InvoiceNotAllowed extends RuntimeException
{
    /**
     * Only a closed work order without an invoice is billed.
     */
    public static function notBillable(): self
    {
        return new self(__('Invoice hanya dapat diterbitkan untuk work order Closed yang belum ditagih.'));
    }

    /**
     * The invoice was paid (Lunas) since the page loaded.
     */
    public static function notCorrectable(): self
    {
        return new self(__('Invoice hanya dapat dikoreksi selama pembayarannya berstatus Ditagih.'));
    }

    /**
     * Only a billed, unpaid invoice has its payment confirmed.
     */
    public static function notPayable(): self
    {
        return new self(__('Pembayaran hanya dapat dikonfirmasi selama pembayarannya berstatus Ditagih.'));
    }

    /**
     * Segregation of duties, when turned on (FLOW.md §10): whoever issued or
     * last corrected the invoice does not confirm its payment.
     */
    public static function preparedByPayer(): self
    {
        return new self(__('Anda menerbitkan atau terakhir mengoreksi invoice ini, jadi pembayarannya harus dikonfirmasi oleh petugas keuangan lain.'));
    }
}
