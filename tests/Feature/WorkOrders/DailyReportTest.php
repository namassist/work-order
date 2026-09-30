<?php

use App\Enums\Permission;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderDailyReport;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Spatie\Activitylog\Models\Activity;

/*
| Daily reports during Pelaksanaan (FLOW.md §7): posted and edited by holders
| of work-orders.report (PIC Timesheet), one per work order per date, with a
| note and at least one file (XLSX, PDF) or link.
|
| "Now" is Tuesday 29 September 2026, 10:00 WITA (02:00 UTC); the work order
| entered Pelaksanaan on Wednesday 23 September.
*/

beforeEach(function () {
    Storage::fake('attachments');
    $this->travelTo(CarbonImmutable::parse('2026-09-29 02:00', 'UTC'));
    $this->workOrder = enteredExecutionAt(WorkOrder::factory()->inProgress()->create(), '2026-09-23 03:00');
    $this->pic = userWithRole('pic-timesheet');
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function reportPayload(array $overrides = []): array
{
    return [
        'report_date' => '2026-09-29',
        'note' => 'Pemasangan panel lantai 2 selesai 60%.',
        'links' => ['https://unggul.sharepoint.com/sites/wo/timesheet-39.xlsx'],
        'files' => [],
        ...$overrides,
    ];
}

/**
 * @param  array<string, mixed>  $payload
 */
function postReport(User $user, WorkOrder $workOrder, array $payload): TestResponse
{
    return test()->actingAs($user)->post(route('work-orders.daily-reports.store', $workOrder), $payload);
}

/**
 * @param  array<string, mixed>  $payload
 */
function patchReport(User $user, WorkOrderDailyReport $report, array $payload): TestResponse
{
    return test()->actingAs($user)->patch(route('work-orders.daily-reports.update', [$report->work_order_id, $report]), $payload);
}

describe('posting', function () {
    it('lets PIC Timesheet post a report with a note, a link, and an Excel file', function () {
        $before = $this->workOrder->updated_at;
        $this->travel(5)->minutes();

        postReport($this->pic, $this->workOrder, reportPayload(['files' => [attachmentUpload('anggaran.xlsx', 'Timesheet 29 Sep.xlsx')]]))
            ->assertSessionHasNoErrors()
            ->assertRedirect()
            ->assertInertiaFlash('toast.type', 'success');

        $report = $this->workOrder->dailyReports()->sole();

        expect($report->report_date->toDateString())->toBe('2026-09-29')
            ->and($report->note)->toBe('Pemasangan panel lantai 2 selesai 60%.')
            ->and($report->links)->toBe(['https://unggul.sharepoint.com/sites/wo/timesheet-39.xlsx'])
            ->and($report->created_by)->toBe($this->pic->id)
            ->and($report->files()->pluck('name')->all())->toBe(['Timesheet 29 Sep.xlsx'])
            ->and($this->workOrder->refresh()->updated_at->greaterThan($before))->toBeTrue();
    });

    it('logs one entry on the work order with the report fields and file names', function () {
        postReport($this->pic, $this->workOrder, reportPayload(['files' => [attachmentUpload('anggaran.xlsx', 'Timesheet.xlsx')]]));

        $activity = Activity::query()->forSubject($this->workOrder)->where('event', 'daily_report_added')->sole();

        expect($activity->causer_id)->toBe($this->pic->id)
            ->and($activity->attribute_changes?->toArray())->toBe(['attributes' => [
                'tanggal_laporan' => '2026-09-29',
                'catatan' => 'Pemasangan panel lantai 2 selesai 60%.',
                'tautan' => 'https://unggul.sharepoint.com/sites/wo/timesheet-39.xlsx',
                'lampiran' => 'Timesheet.xlsx',
            ]])
            ->and(Activity::query()->where('event', 'attachment_added')->exists())->toBeFalse();
    });

    it('accepts a report with only a file, or only a link', function (array $overrides) {
        postReport($this->pic, $this->workOrder, reportPayload($overrides))->assertSessionHasNoErrors();

        expect($this->workOrder->dailyReports()->count())->toBe(1);
    })->with([
        'only a PDF' => [fn (): array => ['links' => [], 'files' => [attachmentUpload('dokumen.pdf')]]],
        'only a link' => [['files' => []]],
    ]);

    it('refuses a report without any file or link', function () {
        postReport($this->pic, $this->workOrder, reportPayload(['links' => []]))
            ->assertSessionHasErrors('links');

        expect($this->workOrder->dailyReports()->exists())->toBeFalse();
    });

    it('requires a short note', function (string $note) {
        postReport($this->pic, $this->workOrder, reportPayload(['note' => $note]))
            ->assertSessionHasErrors('note');
    })->with([
        'empty' => '',
        'blank' => '   ',
        'too long' => str_repeat('a', 1001),
    ]);

    it('allows one report per work order per date', function () {
        postReport($this->pic, $this->workOrder, reportPayload())->assertSessionHasNoErrors();

        postReport(userWithRole('pic-timesheet'), $this->workOrder, reportPayload())
            ->assertSessionHasErrors('report_date');

        $other = enteredExecutionAt(WorkOrder::factory()->inProgress()->create(), '2026-09-23 03:00');
        postReport($this->pic, $other, reportPayload())->assertSessionHasNoErrors();

        expect(WorkOrderDailyReport::query()->count())->toBe(2);
    });

    it('takes the date in WITA: at 23:30 UTC the next day is already today', function () {
        // 23:30 UTC on the 29th is 07:30 WITA on Wednesday the 30th.
        $this->travelTo(CarbonImmutable::parse('2026-09-29 23:30', 'UTC'));

        postReport($this->pic, $this->workOrder, reportPayload(['report_date' => '2026-09-30']))->assertSessionHasNoErrors();
        postReport($this->pic, $this->workOrder, reportPayload(['report_date' => '2026-10-01']))->assertSessionHasErrors('report_date');
    });

    it('refuses a date that is still tomorrow in WITA', function () {
        // 15:30 UTC is 23:30 WITA, still the 29th.
        $this->travelTo(CarbonImmutable::parse('2026-09-29 15:30', 'UTC'));

        postReport($this->pic, $this->workOrder, reportPayload(['report_date' => '2026-09-30']))
            ->assertSessionHasErrors('report_date');
    });
});

describe('dates', function () {
    it('allows back-dating up to two working days by default, days off in between included', function (string $date, bool $allowed) {
        $response = postReport($this->pic, $this->workOrder, reportPayload(['report_date' => $date]));

        $allowed ? $response->assertSessionHasNoErrors() : $response->assertSessionHasErrors('report_date');
    })->with([
        'Monday (1 working day back)' => ['2026-09-28', true],
        'Sunday (day off)' => ['2026-09-27', true],
        'Friday (2 working days back)' => ['2026-09-25', true],
        'Thursday (3 working days back)' => ['2026-09-24', false],
    ]);

    it('follows the back-dating setting', function () {
        config(['work_order.daily_reports.backdate_working_days' => 0]);

        postReport($this->pic, $this->workOrder, reportPayload(['report_date' => '2026-09-28']))->assertSessionHasErrors('report_date');
        postReport($this->pic, $this->workOrder, reportPayload(['report_date' => '2026-09-29']))->assertSessionHasNoErrors();
    });

    it('counts holidays as days off when back-dating', function () {
        config(['work_order.daily_reports.holidays' => ['2026-09-28']]);

        postReport($this->pic, $this->workOrder, reportPayload(['report_date' => '2026-09-24']))->assertSessionHasNoErrors();
    });

    it('refuses a date before the work order was first approved for execution', function () {
        $workOrder = enteredExecutionAt(WorkOrder::factory()->inProgress()->create(), '2026-09-28 01:00');

        postReport($this->pic, $workOrder, reportPayload(['report_date' => '2026-09-25']))
            ->assertSessionHasErrors('report_date');
    });

    it('allows reports on days that are not working days', function () {
        $this->travelTo(CarbonImmutable::parse('2026-10-03 02:00', 'UTC'));

        postReport($this->pic, $this->workOrder, reportPayload(['report_date' => '2026-10-03']))->assertSessionHasNoErrors();
    });
});

describe('files and links', function () {
    it('refuses types other than XLSX and PDF, whatever their name', function (string $fixture, string $clientName) {
        postReport($this->pic, $this->workOrder, reportPayload(['files' => [attachmentUpload($fixture, $clientName)]]))
            ->assertSessionHasErrors('files.0');

        expect($this->workOrder->dailyReports()->exists())->toBeFalse();
    })->with([
        'Word document' => ['laporan.docx', 'laporan.docx'],
        'image' => ['foto.jpg', 'foto.jpg'],
        'macro workbook' => ['makro.xlsm', 'makro.xlsx'],
        'executable named xlsx' => ['program.exe', 'timesheet.xlsx'],
        'HTML named csv' => ['halaman.html', 'timesheet.csv'],
    ]);

    it('refuses files above the size limit', function () {
        postReport($this->pic, $this->workOrder, reportPayload(['files' => [UploadedFile::fake()->create('besar.xlsx', 10241)]]))
            ->assertSessionHasErrors('files.0');
    });

    it('refuses more files or links than the limits', function (string $field, Closure $values) {
        postReport($this->pic, $this->workOrder, reportPayload([$field => $values()]))->assertSessionHasErrors($field);
    })->with([
        'six files' => ['files', fn (): array => array_map(fn (int $i): UploadedFile => attachmentUpload('anggaran.xlsx', "t{$i}.xlsx"), range(1, 6))],
        'six links' => ['links', fn (): array => array_map(fn (int $i): string => "https://example.com/{$i}", range(1, 6))],
    ]);

    it('refuses unsafe or invalid links', function (string $link) {
        postReport($this->pic, $this->workOrder, reportPayload(['links' => [$link]]))
            ->assertSessionHasErrors('links.0');
    })->with([
        'javascript' => 'javascript:alert(1)',
        'data' => 'data:text/html,<script>alert(1)</script>',
        'relative' => '/work-orders/1',
        'overlong' => 'https://example.com/'.str_repeat('a', 2048),
    ]);

    it('refuses the same link twice', function () {
        postReport($this->pic, $this->workOrder, reportPayload(['links' => ['https://example.com/a', 'https://example.com/a']]))
            ->assertSessionHasErrors('links.1');
    });

    it('stores no file when the report is refused', function () {
        postReport($this->pic, $this->workOrder, reportPayload(['report_date' => '2026-09-01', 'files' => [attachmentUpload('anggaran.xlsx')]]))
            ->assertSessionHasErrors('report_date');

        expect(Storage::disk('attachments')->allFiles())->toBe([]);
    });
});

describe('who and when', function () {
    it('lets only holders of work-orders.report post', function (string $role) {
        postReport(userWithRole($role), $this->workOrder, reportPayload())->assertForbidden();
    })->with(['admin-wo', 'lead-operational', 'rental', 'direktur', 'finance', 'viewer']);

    it('lets a user with only the report permission and view post', function () {
        postReport(unggulUser(Permission::WorkOrdersView, Permission::WorkOrdersReport), $this->workOrder, reportPayload())
            ->assertSessionHasNoErrors();
    });

    it('refuses client company users, whatever they hold', function () {
        // The requesting department may see its work order (the v1 safeguard), never act on it.
        $ic = userInDepartment($this->workOrder->requesterDepartment, ...Permission::cases());

        postReport($ic, $this->workOrder, reportPayload())->assertForbidden();
        postReport(icUser(...Permission::cases()), $this->workOrder, reportPayload())->assertNotFound();
    });

    it('accepts reports only in Pelaksanaan', function (string $state) {
        $workOrder = enteredExecutionAt(WorkOrder::factory()->submitted()->{$state}()->create(), '2026-09-23 03:00');

        postReport($this->pic, $workOrder, reportPayload())
            ->assertRedirect()
            ->assertInertiaFlash('toast.type', 'error');

        expect($workOrder->dailyReports()->exists())->toBeFalse();
    })->with(['submitted', 'inReview', 'awaitingBastApproval', 'closed', 'cancelled']);
});

describe('editing', function () {
    beforeEach(function () {
        postReport($this->pic, $this->workOrder, reportPayload(['files' => [attachmentUpload('anggaran.xlsx', 'Timesheet.xlsx')]]))
            ->assertSessionHasNoErrors();
        $this->report = $this->workOrder->dailyReports()->sole();
    });

    it('lets any holder of work-orders.report edit it, recording the editor', function () {
        $colleague = userWithRole('pic-timesheet');

        patchReport($colleague, $this->report, ['note' => 'Panel lantai 2 selesai 80%.', 'links' => ['https://unggul.sharepoint.com/sites/wo/timesheet-39b.xlsx']])
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('toast.type', 'success');

        $report = $this->report->refresh();

        expect($report->note)->toBe('Panel lantai 2 selesai 80%.')
            ->and($report->updated_by)->toBe($colleague->id)
            ->and($report->created_by)->toBe($this->pic->id);
    });

    it('keeps the report date', function () {
        patchReport($this->pic, $this->report, ['note' => 'Ubah', 'links' => $this->report->links, 'report_date' => '2026-09-28'])
            ->assertSessionHasNoErrors();

        expect($this->report->refresh()->report_date->toDateString())->toBe('2026-09-29');
    });

    it('logs only the changed fields, with file names before and after', function () {
        $old = $this->report->files()->sole();

        patchReport($this->pic, $this->report, [
            'note' => $this->report->note,
            'links' => $this->report->links,
            'files' => [attachmentUpload('dokumen.pdf', 'Absensi.pdf')],
            'remove_files' => [$old->uuid],
        ])->assertSessionHasNoErrors();

        $activity = Activity::query()->forSubject($this->workOrder)->where('event', 'daily_report_edited')->sole();

        expect($activity->attribute_changes?->toArray())->toBe([
            'attributes' => ['lampiran' => 'Absensi.pdf'],
            'old' => ['lampiran' => 'Timesheet.xlsx'],
        ])
            ->and($this->report->files()->pluck('name')->all())->toBe(['Absensi.pdf'])
            ->and(Activity::query()->whereIn('event', ['attachment_added', 'attachment_removed'])->exists())->toBeFalse();
    });

    it('swaps a file on a report at the file limit, but never exceeds it', function () {
        config(['work_order.daily_reports.files.max_files' => 1]);
        $old = $this->report->files()->sole();

        patchReport($this->pic, $this->report, ['note' => $this->report->note, 'links' => $this->report->links, 'files' => [attachmentUpload('anggaran.xlsx', 'Tambahan.xlsx')]])
            ->assertSessionHasErrors('files.0');

        patchReport($this->pic, $this->report, [
            'note' => $this->report->note,
            'links' => $this->report->links,
            'files' => [attachmentUpload('dokumen.pdf', 'Pengganti.pdf')],
            'remove_files' => [$old->uuid],
        ])->assertSessionHasNoErrors();

        expect($this->report->files()->pluck('name')->all())->toBe(['Pengganti.pdf']);
    });

    it('names the report date in the toast', function () {
        patchReport($this->pic, $this->report, ['note' => 'Ubah.', 'links' => $this->report->links])
            ->assertInertiaFlash('toast.message', 'Laporan harian 29 Sep 2026 diperbarui.');
    });

    it('records nothing for an edit that changes nothing', function () {
        patchReport($this->pic, $this->report, ['note' => $this->report->note, 'links' => $this->report->links])
            ->assertSessionHasNoErrors();

        expect(Activity::query()->where('event', 'daily_report_edited')->exists())->toBeFalse()
            ->and($this->report->refresh()->updated_by)->toBeNull();
    });

    it('refuses an edit that leaves no file and no link', function () {
        patchReport($this->pic, $this->report, [
            'note' => $this->report->note,
            'links' => [],
            'remove_files' => [$this->report->files()->sole()->uuid],
        ])->assertSessionHasErrors('links');

        expect($this->report->files()->count())->toBe(1)
            ->and($this->report->refresh()->links)->not->toBe([]);
    });

    it('allows edits until the end of the WITA day the report was created, plus the setting', function (int $extraDays, bool $allowed) {
        config(['work_order.daily_reports.edit_extra_days' => $extraDays]);
        // Wednesday 30 September, 07:30 WITA.
        $this->travelTo(CarbonImmutable::parse('2026-09-29 23:30', 'UTC'));

        $response = patchReport($this->pic, $this->report, ['note' => 'Terlambat diubah.', 'links' => $this->report->links]);

        if ($allowed) {
            $response->assertSessionHasNoErrors();
            expect($this->report->refresh()->note)->toBe('Terlambat diubah.');
        } else {
            $response->assertInertiaFlash('toast.type', 'error');
            expect($this->report->refresh()->note)->not->toBe('Terlambat diubah.');
        }
    })->with([
        'same day only' => [0, false],
        'one extra day' => [1, true],
    ]);

    it('allows edits at 23:59 WITA on the day it was created', function () {
        $this->travelTo(CarbonImmutable::parse('2026-09-29 15:59', 'UTC'));

        patchReport($this->pic, $this->report, ['note' => 'Tepat waktu.', 'links' => $this->report->links])->assertSessionHasNoErrors();

        expect($this->report->refresh()->note)->toBe('Tepat waktu.');
    });

    it('refuses edits once the work order left Pelaksanaan', function () {
        WorkOrder::query()->whereKey($this->workOrder->id)->update(['status' => 'review_dokumen']);

        patchReport($this->pic, $this->report, ['note' => 'Ubah', 'links' => $this->report->links])
            ->assertInertiaFlash('toast.type', 'error');

        expect($this->report->refresh()->note)->not->toBe('Ubah');
    });

    it('refuses editors without the permission', function () {
        patchReport(userWithRole('admin-wo'), $this->report, ['note' => 'Ubah', 'links' => $this->report->links])->assertForbidden();
    });

    it('answers 404 for a report of another work order', function () {
        $other = enteredExecutionAt(WorkOrder::factory()->inProgress()->create(), '2026-09-23 03:00');

        $this->actingAs($this->pic)
            ->patch(route('work-orders.daily-reports.update', [$other, $this->report]), ['note' => 'x', 'links' => ['https://example.com']])
            ->assertNotFound();
    });
});

describe('files of a report', function () {
    beforeEach(function () {
        postReport($this->pic, $this->workOrder, reportPayload(['files' => [attachmentUpload('anggaran.xlsx', 'Timesheet.xlsx')]]))
            ->assertSessionHasNoErrors();
        $this->report = $this->workOrder->dailyReports()->sole();
        $this->file = $this->report->files()->sole();
    });

    it('is downloaded by whoever may view the work order', function () {
        $response = $this->actingAs(userWithRole('viewer'))
            ->get(route('attachments.show', $this->file))
            ->assertOk();

        expect($response->headers->get('Content-Disposition'))->toStartWith('attachment;')->toContain('Timesheet.xlsx');
    });

    it('is hidden from users who may not view the work order', function (Closure $user) {
        $this->actingAs($user())
            ->get(route('attachments.show', $this->file))
            ->assertNotFound();
    })->with([
        'client company user of another department' => [fn (): User => icUser(...Permission::cases())],
        'internal user without work-orders.view' => [fn (): User => unggulUser(Permission::WorkOrdersReport)],
    ]);

    it('is hidden from client company users, even of the requesting department', function () {
        // The v1 isolation safeguard: IC sees its own work orders, never Unggul's internal reports.
        $ic = userInDepartment($this->workOrder->requesterDepartment, Permission::WorkOrdersView);

        $this->actingAs($ic)->get(route('attachments.show', $this->file))->assertNotFound();

        $this->actingAs($ic)
            ->get(route('work-orders.show', $this->workOrder))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('dailyReports', [])
                ->where('dailyReportDays', [])
                ->where('missingDailyReport', false)
                ->where('can.report', false));
    });

    it('is hidden once the work order is deleted', function () {
        $this->workOrder->delete();

        $this->actingAs(adminUser())->get(route('attachments.show', $this->file))->assertNotFound();
    });

    it('is never changed through the generic attachment endpoints', function () {
        $this->actingAs(adminUser())
            ->post(route('attachments.store', ['wo-daily-report', $this->report->id, 'berkas']), ['file' => attachmentUpload('anggaran.xlsx')])
            ->assertNotFound();

        $this->actingAs(adminUser())
            ->delete(route('attachments.destroy', $this->file))
            ->assertForbidden();

        expect($this->report->files()->count())->toBe(1);
    });
});

describe('documents during Pelaksanaan', function () {
    it('are added by holders of work-orders.report', function () {
        $this->actingAs($this->pic)
            ->post(route('attachments.store', ['work-order', $this->workOrder->id, 'dokumen']), ['file' => attachmentUpload('foto.jpg')])
            ->assertSessionHasNoErrors();

        expect($this->workOrder->attachmentsIn('dokumen'))->toHaveCount(1);
    });

    it('are refused to a user with only work-orders.submit-review', function () {
        $this->actingAs(unggulUser(Permission::WorkOrdersView, Permission::WorkOrdersSubmitReview))
            ->post(route('attachments.store', ['work-order', $this->workOrder->id, 'dokumen']), ['file' => attachmentUpload('foto.jpg')])
            ->assertForbidden();
    });
});
