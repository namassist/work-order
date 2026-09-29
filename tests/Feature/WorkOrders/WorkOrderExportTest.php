<?php

use App\Actions\WorkOrders\TransitionWorkOrder;
use App\Enums\Permission;
use App\Models\Department;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderCategory;
use App\Models\WorkOrderInvoice;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Inertia\Testing\AssertableInertia as Assert;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\DateTimeCell;
use OpenSpout\Common\Entity\Cell\EmptyCell;
use OpenSpout\Common\Entity\Cell\NumericCell;
use OpenSpout\Reader\XLSX\Reader;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->department = Department::factory()->client()->create(['code' => 'IT', 'name' => 'Teknologi Informasi']);
    $this->category = WorkOrderCategory::factory()->create(['code' => 'PRB', 'name' => 'Perbaikan']);
    // Sees drafts too (work-orders.create), like Admin WO.
    $this->exporter = unggulUser(Permission::WorkOrdersView, Permission::WorkOrdersCreate, Permission::WorkOrdersExport);
});

/**
 * A work order in the test department and category.
 */
function exportableWorkOrder(array $attributes = []): WorkOrder
{
    return WorkOrder::factory()->create([
        'requester_department_id' => test()->department->id,
        'work_order_category_id' => test()->category->id,
        ...$attributes,
    ]);
}

/**
 * The downloaded workbook's first sheet, one list of cells per row.
 *
 * @return list<list<Cell>>
 */
function exportedRows(TestResponse $response): array
{
    $path = tempnam(sys_get_temp_dir(), 'wo-export');
    file_put_contents($path, $response->streamedContent());

    $reader = new Reader;
    $reader->open($path);

    $rows = [];

    foreach ($reader->getSheetIterator() as $sheet) {
        foreach ($sheet->getRowIterator() as $row) {
            $rows[] = array_values($row->cells);
        }

        break;
    }

    $reader->close();
    unlink($path);

    return $rows;
}

/**
 * The first sheet's raw XML, which is what Excel parses. openspout's reader
 * cannot tell a formula from a string that starts with "=", so formula
 * checks read the XML instead.
 */
function exportedSheetXml(TestResponse $response): string
{
    return exportedXml($response, 'xl/worksheets/sheet1.xml');
}

/**
 * One XML part of the downloaded workbook, e.g. xl/styles.xml.
 */
function exportedXml(TestResponse $response, string $part): string
{
    $path = tempnam(sys_get_temp_dir(), 'wo-export');
    file_put_contents($path, $response->streamedContent());

    $zip = new ZipArchive;
    $zip->open($path);
    $xml = (string) $zip->getFromName($part);
    $zip->close();
    unlink($path);

    return $xml;
}

/**
 * The number format id of the cell style at $index in styles.xml's cellXfs.
 */
function styleNumberFormat(string $styles, int $index): int
{
    preg_match('~<cellXfs[^>]*>(.*?)</cellXfs>~s', $styles, $cellXfs);
    preg_match_all('~<xf [^>]*numFmtId="(\d+)"~', $cellXfs[1], $formats);

    return (int) $formats[1][$index];
}

/**
 * The title (column B) of every data row.
 *
 * @return list<string>
 */
function exportedTitles(TestResponse $response): array
{
    return array_map(fn (array $cells): string => (string) $cells[1]->getValue(), array_slice(exportedRows($response), 1));
}

it('downloads the list as an xlsx named after the WITA time', function () {
    Carbon::setTestNow('2026-09-25 23:30:00');
    exportableWorkOrder();

    $response = $this->actingAs($this->exporter)->get(route('work-orders.export'));

    $response->assertOk()
        ->assertDownload('WOrder-WorkOrders-20260926-0730.xlsx')
        ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

    expect(array_map(fn (Cell $cell): mixed => $cell->getValue(), exportedRows($response)[0]))
        ->toBe([
            'Nomor', 'Judul', 'Deskripsi', 'Departemen pemohon', 'Departemen tujuan', 'Kategori', 'Kontak pemohon', 'PIC Work Order', 'Diinput oleh',
            'Status', 'Urgensi', 'Target', 'Dibuat', 'Diajukan', 'No. invoice', 'Tanggal invoice', 'Jumlah', 'Jatuh tempo', 'Tanggal bayar',
        ])
        ->and(exportedSheetXml($response))->toContain('<pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/>');
});

it('writes one row per work order in list order, with Draft as a draft\'s number', function () {
    $adminWo = User::factory()->create(['name' => 'Dewi Lestari']);
    exportableWorkOrder(['title' => 'Lama', 'created_at' => now()->subDay()]);
    exportableWorkOrder([
        'title' => 'AC bocor',
        'description' => 'Ruang rapat lantai 2',
        'created_by' => $adminWo->id,
        'requester_name' => 'Pak Andi',
        'urgency' => 'mendesak',
    ]);

    $rows = exportedRows($this->actingAs($this->exporter)->get(route('work-orders.export')));

    expect($rows)->toHaveCount(3)
        ->and(array_map(fn (Cell $cell): mixed => $cell->getValue(), array_slice($rows[1], 0, 11)))
        ->toBe(['Draft', 'AC bocor', 'Ruang rapat lantai 2', 'IT - Teknologi Informasi', '', 'PRB - Perbaikan', 'Pak Andi', '', 'Dewi Lestari', 'Draft', 'Mendesak'])
        ->and($rows[2][1]->getValue())->toBe('Lama');
});

it('applies the list\'s search and filters', function () {
    exportableWorkOrder(['title' => 'Pompa air rusak', 'status' => 'diajukan', 'number' => 'WO/IT/2026/09/0001']);
    exportableWorkOrder(['title' => 'Pompa air draft']);
    exportableWorkOrder(['title' => 'Lampu mati', 'status' => 'diajukan', 'number' => 'WO/IT/2026/09/0002']);
    exportableWorkOrder([
        'title' => 'Pompa lain kategori',
        'status' => 'diajukan',
        'number' => 'WO/IT/2026/09/0003',
        'work_order_category_id' => WorkOrderCategory::factory(),
    ]);

    $response = $this->actingAs($this->exporter)->get(route('work-orders.export', [
        'search' => 'pompa',
        'status' => 'diajukan',
        'category' => $this->category->id,
    ]));

    expect(exportedTitles($response))->toBe(['Pompa air rusak']);
});

it('applies the list\'s urgency filter and sort, and logs the urgency filter', function () {
    exportableWorkOrder(['title' => 'Tinggi lama', 'urgency' => 'tinggi', 'created_at' => now()->subDay()]);
    exportableWorkOrder(['title' => 'Mendesak', 'urgency' => 'mendesak', 'created_at' => now()->subDays(2)]);
    exportableWorkOrder(['title' => 'Tinggi baru', 'urgency' => 'tinggi']);
    exportableWorkOrder(['title' => 'Rendah', 'urgency' => 'rendah']);

    $this->actingAs($this->exporter);

    expect(exportedTitles($this->get(route('work-orders.export', ['sort' => 'urgensi']))))
        ->toBe(['Mendesak', 'Tinggi baru', 'Tinggi lama', 'Rendah'])
        ->and(exportedTitles($this->get(route('work-orders.export', ['urgency' => 'tinggi']))))
        ->toBe(['Tinggi baru', 'Tinggi lama'])
        ->and(Activity::query()->where('event', 'exported')->latest('id')->first()->properties['filter'])
        ->toBe('Urgensi: Tinggi');
});

it('follows the list\'s last-activity sort without logging it as a filter', function () {
    exportableWorkOrder(['title' => 'Lama, baru diubah', 'created_at' => now()->subDays(2), 'updated_at' => now()]);
    exportableWorkOrder(['title' => 'Baru', 'created_at' => now()->subDay(), 'updated_at' => now()->subDay()]);

    $this->actingAs($this->exporter);

    expect(exportedTitles($this->get(route('work-orders.export', ['sort' => 'diperbarui']))))
        ->toBe(['Lama, baru diubah', 'Baru'])
        ->and(Activity::query()->where('event', 'exported')->latest('id')->first()->properties['filter'])
        ->toBe('Semua');
});

it('applies the list\'s aktif, target department, and overdue filters, and logs them', function () {
    // 07:30 WITA on 25 Sep, still 24 Sep in UTC.
    $this->travelTo(Carbon::parse('2026-09-24 23:30', 'UTC'));
    $engineering = Department::factory()->create(['code' => 'ENG']);
    $late = ['target_date' => '2026-09-24', 'target_department_id' => $engineering->id];
    exportableWorkOrder([...$late, 'title' => 'Terlambat di ENG', 'status' => 'dikerjakan', 'number' => 'WO/IT/2026/09/0001']);
    exportableWorkOrder([...$late, 'title' => 'Tepat waktu', 'status' => 'dikerjakan', 'number' => 'WO/IT/2026/09/0002', 'target_date' => '2026-09-25']);
    exportableWorkOrder([...$late, 'title' => 'Departemen lain', 'status' => 'dikerjakan', 'number' => 'WO/IT/2026/09/0003', 'target_department_id' => Department::factory()->create()->id]);

    $response = $this->actingAs($this->exporter)->get(route('work-orders.export', [
        'status' => 'aktif',
        'target' => $engineering->id,
        'overdue' => 1,
    ]));

    expect(exportedTitles($response))->toBe(['Terlambat di ENG'])
        ->and(Activity::query()->where('event', 'exported')->sole()->properties['filter'])
        ->toBe('Status: Aktif; Dept. tujuan: ENG; Terlambat: Ya');
});

it('filters the created date by WITA day', function () {
    exportableWorkOrder(['title' => 'Pagi WITA', 'created_at' => '2026-09-24 23:30:00']);
    exportableWorkOrder(['title' => 'Kemarin', 'created_at' => '2026-09-24 15:00:00']);

    $response = $this->actingAs($this->exporter)->get(route('work-orders.export', ['from' => '2026-09-25', 'to' => '2026-09-25']));

    expect(exportedTitles($response))->toBe(['Pagi WITA']);
});

it('filters by the requester department', function () {
    exportableWorkOrder(['title' => 'Milik IT']);
    $other = WorkOrder::factory()->create(['title' => 'Milik departemen lain']);

    expect(exportedTitles($this->actingAs($this->exporter)->get(route('work-orders.export', ['department' => $this->department->id]))))->toBe(['Milik IT'])
        ->and(exportedTitles($this->get(route('work-orders.export', ['department' => $other->requester_department_id]))))->toBe(['Milik departemen lain']);
});

it('exports every submitted work order, but no drafts, without work-orders.create', function () {
    exportableWorkOrder();
    WorkOrder::factory()->submitted()->count(2)->create();

    $user = unggulUser(Permission::WorkOrdersView, Permission::WorkOrdersExport);

    expect(exportedTitles($this->actingAs($user)->get(route('work-orders.export'))))->toHaveCount(2);
});

it('never exports to a client company user, whatever they hold', function () {
    exportableWorkOrder();

    $this->actingAs(userInDepartment($this->department, ...Permission::cases()))
        ->get(route('work-orders.export'))
        ->assertForbidden();
});

it('exports only deleted work orders from the deleted list, which needs work-orders.restore', function () {
    exportableWorkOrder(['title' => 'Aktif']);
    exportableWorkOrder(['title' => 'Terhapus'])->delete();

    $this->actingAs($this->exporter)->get(route('work-orders.export', ['trashed' => 1]))->assertForbidden();

    $user = unggulUser(Permission::WorkOrdersView, Permission::WorkOrdersCreate, Permission::WorkOrdersExport, Permission::WorkOrdersRestore);

    expect(exportedTitles($this->actingAs($user)->get(route('work-orders.export', ['trashed' => 1]))))->toBe(['Terhapus']);
});

it('requires work-orders.export and work-orders.view', function (array $permissions) {
    $this->actingAs(unggulUser(...$permissions))
        ->get(route('work-orders.export'))
        ->assertForbidden();
})->with([
    'view only' => [[Permission::WorkOrdersView]],
    'export only' => [[Permission::WorkOrdersExport]],
]);

it('writes dates as Excel date cells in WITA', function () {
    Carbon::setTestNow('2026-09-25 23:30:00');
    $workOrder = exportableWorkOrder(['target_date' => '2026-10-01', 'target_department_id' => Department::factory()->create()->id]);

    Carbon::setTestNow('2026-09-26 01:05:00');
    app(TransitionWorkOrder::class)->handle($workOrder, 'diajukan', $this->exporter);

    [, $row] = exportedRows($this->actingAs($this->exporter)->get(route('work-orders.export')));
    [$target, $created, $submitted] = array_slice($row, 11, 3);

    expect($target)->toBeInstanceOf(DateTimeCell::class)
        ->and($target->getValue()->format('Y-m-d'))->toBe('2026-10-01')
        ->and($created)->toBeInstanceOf(DateTimeCell::class)
        ->and($created->getValue()->format('Y-m-d H:i'))->toBe('2026-09-26 07:30')
        ->and($submitted)->toBeInstanceOf(DateTimeCell::class)
        ->and($submitted->getValue()->format('Y-m-d H:i'))->toBe('2026-09-26 09:05')
        ->and($row[0]->getValue())->toBe($workOrder->refresh()->number);
});

it('leaves Target and Diajukan empty when a work order has neither', function () {
    exportableWorkOrder();

    [, $row] = exportedRows($this->actingAs($this->exporter)->get(route('work-orders.export')));

    expect($row[11])->toBeInstanceOf(EmptyCell::class)
        ->and($row[13])->toBeInstanceOf(EmptyCell::class);
});

it('writes the target department, the requester contact, the PIC, and the Admin WO who entered it', function () {
    $adminWo = unggulUser(Permission::WorkOrdersCreate);
    $adminWo->update(['name' => 'Dewi Lestari']);
    WorkOrder::factory()->by($adminWo)->create([
        'requester_department_id' => $this->department->id,
        'requester_name' => 'Pak Andi, Produksi',
        'pic_name' => 'Bu Sari',
        'target_department_id' => Department::factory()->create(['code' => 'ENG', 'name' => 'Engineering'])->id,
    ]);

    [, $row] = exportedRows($this->actingAs($this->exporter)->get(route('work-orders.export')));

    expect(array_map(fn (Cell $cell): mixed => $cell->getValue(), array_slice($row, 3, 6)))
        ->toBe(['IT - Teknologi Informasi', 'ENG - Engineering', $row[5]->getValue(), 'Pak Andi, Produksi', 'Bu Sari', 'Dewi Lestari']);
});

it('writes the invoice: number, dates as calendar dates, and the amount as a Rupiah number', function () {
    WorkOrderInvoice::factory()->paid('2026-09-24')->for(exportableWorkOrder([
        'title' => 'Lunas',
        'created_at' => now()->subDay(),
        'target_department_id' => Department::factory()->create()->id,
        'number' => 'WO/IT/2026/09/0001',
        'status' => 'selesai',
    ]))->create([
        'number' => 'INV/ENG/2026/001',
        'invoice_date' => '2026-09-20',
        'amount' => '1500000.50',
        'due_date' => '2026-09-30',
    ]);
    exportableWorkOrder(['title' => 'Belum ditagih']);

    $response = $this->actingAs($this->exporter)->get(route('work-orders.export'));
    [, $withoutInvoice, $paid] = exportedRows($response);
    [$number, $invoiceDate, $amount, $dueDate, $paidOn] = array_slice($paid, 14);

    expect($number->getValue())->toBe('INV/ENG/2026/001')
        ->and($invoiceDate)->toBeInstanceOf(DateTimeCell::class)
        ->and($invoiceDate->getValue()->format('Y-m-d'))->toBe('2026-09-20')
        ->and($amount)->toBeInstanceOf(NumericCell::class)
        ->and($amount->getValue())->toBe(1500000.5)
        ->and($dueDate->getValue()->format('Y-m-d'))->toBe('2026-09-30')
        ->and($paidOn->getValue()->format('Y-m-d'))->toBe('2026-09-24')
        ->and(array_map(fn (Cell $cell): string => $cell::class, array_slice($withoutInvoice, 14)))
        ->toBe(array_fill(0, 5, EmptyCell::class));

    $styles = exportedXml($response, 'xl/styles.xml');
    preg_match('~<c r="Q3" s="(\d+)"[^>]*><v>1500000.5</v></c>~', exportedSheetXml($response), $cell);
    preg_match('~<numFmt numFmtId="(\d+)" formatCode="&quot;Rp &quot;#,##0\.00"/>~', $styles, $format);

    expect($cell)->not->toBeEmpty()
        ->and($format)->not->toBeEmpty()
        ->and(styleNumberFormat($styles, (int) $cell[1]))->toBe((int) $format[1]);
});

it('writes user-entered text as plain strings, never formulas', function () {
    WorkOrderInvoice::factory()->for(exportableWorkOrder([
        'title' => '=HYPERLINK("http://x","y")',
        'description' => '@SUM(A1)',
        'requester_name' => '+SUM(1,1)',
        'pic_name' => '=cmd',
        'target_department_id' => Department::factory()->create()->id,
        'number' => 'WO/IT/2026/09/0001',
        'status' => 'penagihan',
    ]))->create(['number' => '-2+3']);
    WorkOrder::factory()->create([
        'requester_department_id' => $this->department->id,
        'requester_name' => '=1+1',
        'created_at' => now()->subDay(),
    ]);

    $xml = exportedSheetXml($this->actingAs($this->exporter)->get(route('work-orders.export')));

    expect($xml)->not->toContain('<f>')
        ->and($xml)->toMatch('~<c r="B2"[^>]* t="inlineStr"><is><t>=HYPERLINK\(&quot;http://x&quot;,&quot;y&quot;\)</t></is></c>~')
        ->and($xml)->toMatch('~<c r="C2"[^>]* t="inlineStr"><is><t>@SUM\(A1\)</t></is></c>~')
        ->and($xml)->toMatch('~<c r="G2"[^>]* t="inlineStr"><is><t>\+SUM\(1,1\)</t></is></c>~')
        ->and($xml)->toMatch('~<c r="H2"[^>]* t="inlineStr"><is><t>=cmd</t></is></c>~')
        ->and($xml)->toMatch('~<c r="O2"[^>]* t="inlineStr"><is><t>-2\+3</t></is></c>~')
        ->and($xml)->toMatch('~<c r="G3"[^>]* t="inlineStr"><is><t>=1\+1</t></is></c>~');
});

it('refuses an export above the row cap and asks to narrow the filters', function () {
    config(['work_order.export.max_rows' => 2]);
    exportableWorkOrder();
    exportableWorkOrder();
    exportableWorkOrder();

    $this->actingAs($this->exporter)
        ->from(route('work-orders.index', ['search' => 'x']))
        ->get(route('work-orders.export'))
        ->assertRedirect(route('work-orders.index', ['search' => 'x']))
        ->assertInertiaFlash('toast.type', 'error')
        ->assertInertiaFlash('toast.message', 'Hasil filter berisi 3 work order, melebihi batas ekspor 2. Persempit filter lalu coba lagi.');

    expect(Activity::query()->where('event', 'exported')->exists())->toBeFalse();
});

it('exports exactly the row cap', function () {
    config(['work_order.export.max_rows' => 2]);
    exportableWorkOrder();
    exportableWorkOrder();

    expect(exportedTitles($this->actingAs($this->exporter)->get(route('work-orders.export'))))->toHaveCount(2);
});

it('logs who exported, the filters used, and the row count', function () {
    exportableWorkOrder(['title' => 'Pompa rusak']);
    exportableWorkOrder(['title' => 'Lampu mati']);

    $this->actingAs($this->exporter)
        ->get(route('work-orders.export', ['search' => 'pompa', 'status' => 'draft', 'from' => '2026-01-01']))
        ->streamedContent();

    $activity = Activity::query()->where('event', 'exported')->sole();

    expect($activity->log_name)->toBe('audit')
        ->and($activity->causer?->is($this->exporter))->toBeTrue()
        ->and($activity->subject_type)->toBeNull()
        ->and($activity->properties->all())->toMatchArray([
            'filter' => 'Cari: pompa; Status: Draft; Dari: 2026-01-01',
            'jumlah_baris' => '1',
        ]);
});

it('logs an export without filters as Semua', function () {
    $this->actingAs($this->exporter)->get(route('work-orders.export'))->streamedContent();

    expect(Activity::query()->where('event', 'exported')->sole()->properties['filter'])->toBe('Semua');
});

it('offers the export on the list only with work-orders.export, with the row cap', function (array $permissions, bool $canExport) {
    config(['work_order.export.max_rows' => 250]);

    $this->actingAs(unggulUser(...$permissions))
        ->get(route('work-orders.index'))
        ->assertInertia(fn (Assert $page): AssertableInertia => $page
            ->where('can.export', $canExport)
            ->where('exportMaxRows', 250));
})->with([
    'with export' => [[Permission::WorkOrdersView, Permission::WorkOrdersExport], true],
    'without export' => [[Permission::WorkOrdersView], false],
]);

it('limits exports to 10 a minute per user', function () {
    $this->actingAs($this->exporter);

    foreach (range(1, 10) as $attempt) {
        $this->get(route('work-orders.export'))->assertOk();
    }

    $this->get(route('work-orders.export'))->assertTooManyRequests();
});
