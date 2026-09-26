<?php

use App\Actions\WorkOrders\TransitionWorkOrder;
use App\Enums\Permission;
use App\Models\Department;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderCategory;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Inertia\Testing\AssertableInertia as Assert;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\DateTimeCell;
use OpenSpout\Common\Entity\Cell\EmptyCell;
use OpenSpout\Reader\XLSX\Reader;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->department = Department::factory()->create(['code' => 'IT', 'name' => 'Teknologi Informasi']);
    $this->category = WorkOrderCategory::factory()->create(['code' => 'PRB', 'name' => 'Perbaikan']);
    $this->exporter = userInDepartment($this->department, Permission::WorkOrdersView, Permission::WorkOrdersExport);
});

/**
 * A work order in the test department and category.
 */
function exportableWorkOrder(array $attributes = []): WorkOrder
{
    return WorkOrder::factory()->create([
        'department_id' => test()->department->id,
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
    $path = tempnam(sys_get_temp_dir(), 'wo-export');
    file_put_contents($path, $response->streamedContent());

    $zip = new ZipArchive;
    $zip->open($path);
    $xml = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
    $zip->close();
    unlink($path);

    return $xml;
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
        ->toBe(['Nomor', 'Judul', 'Deskripsi', 'Departemen', 'Kategori', 'Pemohon', 'Status', 'Urgensi', 'Target', 'Dibuat', 'Diajukan'])
        ->and(exportedSheetXml($response))->toContain('<pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/>');
});

it('writes one row per work order in list order, with Draft as a draft\'s number', function () {
    $requester = User::factory()->create(['name' => 'Dewi Lestari']);
    exportableWorkOrder(['title' => 'Lama', 'created_at' => now()->subDay()]);
    exportableWorkOrder([
        'title' => 'AC bocor',
        'description' => 'Ruang rapat lantai 2',
        'created_by' => $requester->id,
        'urgency' => 'mendesak',
    ]);

    $rows = exportedRows($this->actingAs($this->exporter)->get(route('work-orders.export')));

    expect($rows)->toHaveCount(3)
        ->and(array_map(fn (Cell $cell): mixed => $cell->getValue(), array_slice($rows[1], 0, 8)))
        ->toBe(['Draft', 'AC bocor', 'Ruang rapat lantai 2', 'IT - Teknologi Informasi', 'PRB - Perbaikan', 'Dewi Lestari', 'Draft', 'Mendesak'])
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

it('filters the created date by WITA day', function () {
    exportableWorkOrder(['title' => 'Pagi WITA', 'created_at' => '2026-09-24 23:30:00']);
    exportableWorkOrder(['title' => 'Kemarin', 'created_at' => '2026-09-24 15:00:00']);

    $response = $this->actingAs($this->exporter)->get(route('work-orders.export', ['from' => '2026-09-25', 'to' => '2026-09-25']));

    expect(exportedTitles($response))->toBe(['Pagi WITA']);
});

it('never exports another department\'s work orders, even when filtering by that department', function () {
    exportableWorkOrder(['title' => 'Milik sendiri']);
    $other = WorkOrder::factory()->create(['title' => 'Milik departemen lain']);

    expect(exportedTitles($this->actingAs($this->exporter)->get(route('work-orders.export'))))->toBe(['Milik sendiri'])
        ->and(exportedTitles($this->get(route('work-orders.export', ['department' => $other->department_id]))))->toBe([]);
});

it('exports every department with work-orders.view-all', function () {
    exportableWorkOrder();
    WorkOrder::factory()->create();

    $user = userInDepartment($this->department, Permission::WorkOrdersView, Permission::WorkOrdersViewAll, Permission::WorkOrdersExport);

    expect(exportedTitles($this->actingAs($user)->get(route('work-orders.export'))))->toHaveCount(2);
});

it('exports only deleted work orders from the deleted list, which needs work-orders.restore', function () {
    exportableWorkOrder(['title' => 'Aktif']);
    exportableWorkOrder(['title' => 'Terhapus'])->delete();

    $this->actingAs($this->exporter)->get(route('work-orders.export', ['trashed' => 1]))->assertForbidden();

    $user = userInDepartment($this->department, Permission::WorkOrdersView, Permission::WorkOrdersExport, Permission::WorkOrdersRestore);

    expect(exportedTitles($this->actingAs($user)->get(route('work-orders.export', ['trashed' => 1]))))->toBe(['Terhapus']);
});

it('requires work-orders.export and work-orders.view', function (array $permissions) {
    $this->actingAs(userInDepartment($this->department, ...$permissions))
        ->get(route('work-orders.export'))
        ->assertForbidden();
})->with([
    'view only' => [[Permission::WorkOrdersView]],
    'export only' => [[Permission::WorkOrdersExport]],
]);

it('writes dates as Excel date cells in WITA', function () {
    Carbon::setTestNow('2026-09-25 23:30:00');
    $workOrder = exportableWorkOrder(['target_date' => '2026-10-01']);

    Carbon::setTestNow('2026-09-26 01:05:00');
    app(TransitionWorkOrder::class)->handle($workOrder, 'diajukan', $this->exporter);

    [, $row] = exportedRows($this->actingAs($this->exporter)->get(route('work-orders.export')));
    [$target, $created, $submitted] = array_slice($row, 8);

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

    expect($row[8])->toBeInstanceOf(EmptyCell::class)
        ->and($row[10])->toBeInstanceOf(EmptyCell::class);
});

it('writes user-entered text as plain strings, never formulas', function () {
    $requester = User::factory()->create(['name' => '+SUM(1,1)']);
    exportableWorkOrder([
        'title' => '=HYPERLINK("http://x","y")',
        'description' => '@SUM(A1)',
        'created_by' => $requester->id,
    ]);

    $xml = exportedSheetXml($this->actingAs($this->exporter)->get(route('work-orders.export')));

    expect($xml)->not->toContain('<f>')
        ->and($xml)->toMatch('~<c r="B2"[^>]* t="inlineStr"><is><t>=HYPERLINK\(&quot;http://x&quot;,&quot;y&quot;\)</t></is></c>~')
        ->and($xml)->toMatch('~<c r="C2"[^>]* t="inlineStr"><is><t>@SUM\(A1\)</t></is></c>~')
        ->and($xml)->toMatch('~<c r="F2"[^>]* t="inlineStr"><is><t>\+SUM\(1,1\)</t></is></c>~');
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

    $this->actingAs(userInDepartment($this->department, ...$permissions))
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
