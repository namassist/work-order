<?php

namespace App\Support\Bast;

/**
 * Which BAST PDF is generated (FLOW.md §8): a preview from the template
 * page, the draft generated when Rental submits the BAST, or the final one
 * generated with the Direktur's approval. Only the final one is unmarked.
 */
enum BastDocumentKind: string
{
    case Preview = 'preview';
    case Draft = 'draft';
    case Final = 'final';

    /**
     * The large text across every page, or null for none.
     */
    public function watermark(): ?string
    {
        return match ($this) {
            self::Preview => 'PRATINJAU',
            self::Draft => 'DRAF',
            self::Final => null,
        };
    }

    /**
     * The line at the top of every page, or null for none.
     */
    public function banner(): ?string
    {
        return match ($this) {
            self::Preview => 'PRATINJAU TEMPLATE: bukan dokumen resmi.',
            self::Draft => 'DRAF: menunggu persetujuan Direktur, belum berlaku.',
            self::Final => null,
        };
    }
}
