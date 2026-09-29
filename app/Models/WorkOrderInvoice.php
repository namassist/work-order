<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\WorkOrderInvoiceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The invoice of a closed work order, the data of its payment track
 * (FLOW.md §10, PaymentStatus). Written only through BillWorkOrder,
 * CorrectInvoice, and ConfirmWorkOrderPayment, which log the changes on the
 * work order (so they show in its Riwayat); this model logs nothing itself.
 * Its files are the work order's 'invoice' and 'bukti_bayar' collections.
 *
 * @property int $id
 * @property int $work_order_id
 * @property string $number
 * @property CarbonImmutable $invoice_date
 * @property string|null $amount decimal string, e.g. "1500000.00"
 * @property CarbonImmutable|null $due_date
 * @property CarbonImmutable|null $paid_on
 * @property int $issued_by
 * @property int|null $corrected_by
 * @property int|null $paid_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read WorkOrder $workOrder
 * @property-read User $issuer
 * @property-read User|null $corrector
 * @property-read User|null $payer
 */
#[Fillable(['number', 'invoice_date', 'amount', 'due_date'])]
class WorkOrderInvoice extends Model
{
    /** @use HasFactory<WorkOrderInvoiceFactory> */
    use HasFactory;

    public const int MAX_NUMBER_LENGTH = 100;

    /**
     * The largest amount decimal(15,2) holds.
     */
    public const string MAX_AMOUNT = '9999999999999.99';

    /**
     * The fields the invoice form writes, in the order they are logged.
     */
    public const array FORM_FIELDS = ['number', 'invoice_date', 'amount', 'due_date'];

    /**
     * @return BelongsTo<WorkOrder, $this>
     */
    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class)->withTrashed();
    }

    /**
     * The Finance user who issued the invoice.
     *
     * @return BelongsTo<User, $this>
     */
    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by')->withTrashed();
    }

    /**
     * The Finance user who last corrected the invoice, if anyone did.
     *
     * @return BelongsTo<User, $this>
     */
    public function corrector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'corrected_by')->withTrashed();
    }

    /**
     * The Finance user who confirmed the payment.
     *
     * @return BelongsTo<User, $this>
     */
    public function payer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by')->withTrashed();
    }

    public function isPaid(): bool
    {
        return $this->paid_on !== null;
    }

    /**
     * Whether the user issued the invoice or last corrected it.
     */
    public function wasPreparedBy(User $user): bool
    {
        return $this->issued_by === $user->id || $this->corrected_by === $user->id;
    }

    /**
     * Whether segregation of duties keeps the user from confirming this
     * invoice's payment: only when the setting is on
     * (work_order.payment.segregation_of_duties, default off, FLOW.md §10:
     * the process has a single Finance lane), and then for whoever prepared it.
     */
    public function segregationBlocks(User $user): bool
    {
        return config()->boolean('work_order.payment.segregation_of_duties') && $this->wasPreparedBy($user);
    }

    /**
     * The invoice with this number, whatever its casing.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function withNumber(Builder $query, string $number): void
    {
        $query->whereRaw('lower(number) = ?', [mb_strtolower(trim($number))]);
    }

    /**
     * The invoice fields as logged on the work order, with the log's keys.
     *
     * @return array{no_invoice: string, tanggal_invoice: string, jumlah: string|null, jatuh_tempo: string|null}
     */
    public function auditValues(): array
    {
        return [
            'no_invoice' => $this->number,
            'tanggal_invoice' => $this->invoice_date->toDateString(),
            'jumlah' => $this->amount,
            'jatuh_tempo' => $this->due_date?->toDateString(),
        ];
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'invoice_date' => 'date:Y-m-d',
            'amount' => 'decimal:2',
            'due_date' => 'date:Y-m-d',
            'paid_on' => 'date:Y-m-d',
        ];
    }
}
