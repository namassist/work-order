<?php

namespace App\Actions\WorkOrders;

use RuntimeException;

/**
 * An invoice change the rules refuse although the user is on the right side
 * in principle. The message is shown to the user.
 */
class InvoiceNotAllowed extends RuntimeException
{
    /**
     * The work order left Penagihan (it was paid) since the page loaded.
     */
    public static function notCorrectable(): self
    {
        return new self(__('Invoice hanya dapat dikoreksi selama work order dalam penagihan.'));
    }

    /**
     * Segregation of duties (FLOW.md §8): whoever issued or last corrected
     * the invoice does not confirm its payment.
     */
    public static function preparedByPayer(): self
    {
        return new self(__('Anda menerbitkan atau terakhir mengoreksi invoice ini, jadi pembayarannya harus dikonfirmasi oleh petugas keuangan lain.'));
    }
}
