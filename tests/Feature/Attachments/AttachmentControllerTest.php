<?php

use App\Actions\Attachments\AddAttachment;
use App\Enums\Permission;
use App\Models\Department;
use App\Models\Media;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->department = Department::factory()->create(['code' => 'IT']);
    $this->user = userInDepartment($this->department, Permission::WorkOrdersView, Permission::WorkOrdersUpdate, Permission::ActivityLogView);
    $this->workOrder = WorkOrder::factory()->create(['department_id' => $this->department->id]);
});

/**
 * The upload URL of the work order's documents.
 */
function documentsUploadUrl(WorkOrder $workOrder): string
{
    return route('attachments.store', ['work-order', $workOrder->id, WorkOrder::DOCUMENTS]);
}

/**
 * Attach a fixture to the work order's documents directly, as an earlier upload.
 */
function attachDocument(WorkOrder $workOrder, string $fixture, User $uploader, ?string $clientName = null): Media
{
    return app(AddAttachment::class)->handle($workOrder, $workOrder->documentsCollection(), attachmentUpload($fixture, $clientName), $uploader);
}

describe('upload', function () {
    it('stores the file under a random name on the private disk and logs it on the work order', function () {
        $disk = Storage::fake('attachments');

        $this->actingAs($this->user)
            ->post(documentsUploadUrl($this->workOrder), ['file' => attachmentUpload('dokumen.pdf', 'Surat Permohonan.pdf')])
            ->assertRedirect()
            ->assertInertiaFlash('toast.message', 'Lampiran Surat Permohonan.pdf diunggah.');

        $media = Media::query()->sole();
        expect($media)
            ->name->toBe('Surat Permohonan.pdf')
            ->file_name->toMatch('/^[0-9a-f-]{36}\.pdf$/')
            ->mime_type->toBe('application/pdf')
            ->uploaded_by->toBe($this->user->id)
            ->collection_name->toBe('dokumen')
            ->disk->toBe('attachments');
        $disk->assertExists($media->uuid.'/'.$media->file_name);
        expect($disk->allFiles())->toHaveCount(1);

        $activity = Activity::query()->forSubject($this->workOrder)->where('event', 'attachment_added')->sole();
        expect($activity->causer_id)->toBe($this->user->id)
            ->and($activity->attribute_changes?->toArray())->toBe(['attributes' => ['lampiran' => 'Surat Permohonan.pdf', 'ukuran' => $media->size]])
            ->and(json_encode($activity->toArray()))->not->toContain('%PDF');
    });

    it('stores the type detected from content, not the client filename', function () {
        Storage::fake('attachments');

        $this->actingAs($this->user)
            ->post(documentsUploadUrl($this->workOrder), ['file' => attachmentUpload('foto.png', 'scan.pdf')])
            ->assertRedirect();

        expect(Media::query()->sole())
            ->mime_type->toBe('image/png')
            ->file_name->toEndWith('.png');
    });

    it('rejects files whose content is not allowed and stores nothing', function (string $fixture, string $clientName) {
        $disk = Storage::fake('attachments');

        $this->actingAs($this->user)
            ->post(documentsUploadUrl($this->workOrder), ['file' => attachmentUpload($fixture, $clientName)])
            ->assertSessionHasErrors(['file' => 'Jenis berkas berkas tidak diizinkan. Gunakan PDF, JPG, JPEG, PNG, WEBP, DOCX, XLSX.']);

        expect(Media::query()->count())->toBe(0)
            ->and($disk->allFiles())->toBe([]);
    })->with([
        'executable renamed to .pdf' => ['program.exe', 'invoice.pdf'],
        'svg' => ['gambar.svg', 'gambar.svg'],
        'html renamed to .pdf' => ['halaman.html', 'halaman.pdf'],
        'macro-enabled word renamed to .docx' => ['makro.docm', 'laporan.docx'],
    ]);

    it('rejects a file over the size limit and stores nothing', function () {
        $disk = Storage::fake('attachments');
        config(['work_order.attachments.dokumen.max_size_kb' => 1]);

        $this->actingAs($this->user)
            ->post(documentsUploadUrl($this->workOrder), ['file' => attachmentUpload('dokumen.pdf')])
            ->assertSessionHasErrors(['file' => 'Berkas maksimal berukuran 1 kilobita.']);

        expect(Media::query()->count())->toBe(0)
            ->and($disk->allFiles())->toBe([]);
    });

    it('refuses a file beyond the collection limit and stores nothing', function () {
        $disk = Storage::fake('attachments');
        config(['work_order.attachments.dokumen.max_files' => 1]);
        attachDocument($this->workOrder, 'foto.jpg', $this->user);

        $this->actingAs($this->user)
            ->post(documentsUploadUrl($this->workOrder), ['file' => attachmentUpload('dokumen.pdf')])
            ->assertSessionHasErrors(['file' => 'Lampiran sudah mencapai batas 1 berkas.']);

        expect(Media::query()->count())->toBe(1)
            ->and($disk->allFiles())->toHaveCount(1);
    });

    it('refuses uploads once the work order is submitted', function () {
        $disk = Storage::fake('attachments');
        $submitted = WorkOrder::factory()->submitted()->create(['department_id' => $this->department->id]);

        $this->actingAs($this->user)
            ->post(documentsUploadUrl($submitted), ['file' => attachmentUpload('dokumen.pdf')])
            ->assertForbidden();

        expect($disk->allFiles())->toBe([]);
    });

    it('refuses uploads without work-orders.update', function () {
        Storage::fake('attachments');

        $this->actingAs(userInDepartment($this->department, Permission::WorkOrdersView))
            ->post(documentsUploadUrl($this->workOrder), ['file' => attachmentUpload('dokumen.pdf')])
            ->assertForbidden();
    });

    it('answers 404 for another department\'s work order', function () {
        Storage::fake('attachments');
        $other = WorkOrder::factory()->create();

        $this->actingAs($this->user)
            ->post(documentsUploadUrl($other), ['file' => attachmentUpload('dokumen.pdf')])
            ->assertNotFound();
    });

    it('answers 404 for an unknown collection or a model without attachments', function (array $parameters) {
        Storage::fake('attachments');
        $parameters = array_map(fn (mixed $value): mixed => $value === 'WO' ? $this->workOrder->id : $value, $parameters);

        $this->actingAs($this->user)
            ->post(route('attachments.store', $parameters), ['file' => attachmentUpload('dokumen.pdf')])
            ->assertNotFound();
    })->with([
        'unknown collection' => [['work-order', 'WO', 'bast']],
        'model without attachments' => [['department', 1, 'dokumen']],
        'unknown alias' => [['nope', 1, 'dokumen']],
    ]);

    it('limits each user to 30 uploads a minute', function () {
        Storage::fake('attachments');
        config(['work_order.attachments.dokumen.max_files' => 100]);
        $this->actingAs($this->user);

        foreach (range(1, 30) as $attempt) {
            $this->post(documentsUploadUrl($this->workOrder), ['file' => attachmentUpload('foto.png')])->assertRedirect();
        }

        $this->post(documentsUploadUrl($this->workOrder), ['file' => attachmentUpload('foto.png')])
            ->assertTooManyRequests();
    });

    it('redirects guests to login', function () {
        Storage::fake('attachments');

        $this->post(documentsUploadUrl($this->workOrder), ['file' => attachmentUpload('dokumen.pdf')])
            ->assertRedirect(route('login'));

        expect(Media::query()->count())->toBe(0);
    });
});

describe('download', function () {
    it('shows images and PDF inline with the detected type and nosniff', function (string $fixture, string $mimeType) {
        Storage::fake('attachments');
        $media = attachDocument($this->workOrder, $fixture, $this->user, 'Berkas Saya.'.pathinfo($fixture, PATHINFO_EXTENSION));

        $response = $this->actingAs($this->user)->get(route('attachments.show', $media->uuid));

        $response->assertOk()
            ->assertHeader('Content-Type', $mimeType)
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        expect($response->headers->get('Content-Disposition'))->toStartWith('inline;')->toContain('Berkas Saya.')
            ->and($response->streamedContent())->toBe(file_get_contents(attachmentFixture($fixture)));
    })->with([
        'pdf' => ['dokumen.pdf', 'application/pdf'],
        'jpg' => ['foto.jpg', 'image/jpeg'],
        'png' => ['foto.png', 'image/png'],
        'webp' => ['foto.webp', 'image/webp'],
    ]);

    it('always downloads office files as attachments', function (string $fixture, string $mimeType) {
        Storage::fake('attachments');
        $media = attachDocument($this->workOrder, $fixture, $this->user);

        $response = $this->actingAs($this->user)->get(route('attachments.show', $media->uuid));

        $response->assertOk()
            ->assertHeader('Content-Type', $mimeType)
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertDownload($fixture);
    })->with([
        'docx' => ['laporan.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
        'xlsx' => ['anggaran.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
    ]);

    it('downloads a previewable file on request, under a name with the detected extension', function () {
        Storage::fake('attachments');
        $media = attachDocument($this->workOrder, 'foto.png', $this->user, 'scan.pdf');

        $this->actingAs($this->user)
            ->get(route('attachments.show', [$media->uuid, 'download' => 1]))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png')
            ->assertDownload('scan.pdf.png');
    });

    it('follows work order visibility: submitted work orders stay readable', function () {
        Storage::fake('attachments');
        $submitted = WorkOrder::factory()->submitted()->create(['department_id' => $this->department->id]);
        $media = attachDocument($submitted, 'dokumen.pdf', $this->user);

        $this->actingAs(userInDepartment($this->department, Permission::WorkOrdersView))
            ->get(route('attachments.show', $media->uuid))
            ->assertOk();
    });

    it('answers 404 for another department\'s work order', function () {
        Storage::fake('attachments');
        $media = attachDocument(WorkOrder::factory()->create(), 'dokumen.pdf', $this->user);

        $this->actingAs($this->user)
            ->get(route('attachments.show', $media->uuid))
            ->assertNotFound();
    });

    it('answers 404 once the work order is deleted', function () {
        Storage::fake('attachments');
        $media = attachDocument($this->workOrder, 'dokumen.pdf', $this->user);
        $this->workOrder->delete();

        $this->actingAs($this->user)
            ->get(route('attachments.show', $media->uuid))
            ->assertNotFound();
    });

    it('redirects guests to login without sending the file', function () {
        Storage::fake('attachments');
        $media = attachDocument($this->workOrder, 'dokumen.pdf', $this->user);

        $response = $this->get(route('attachments.show', $media->uuid));

        $response->assertRedirect(route('login'));
        expect($response->getContent())->not->toContain('%PDF');
    });
});

describe('delete', function () {
    it('removes the attachment and its file and logs it on the work order', function () {
        $disk = Storage::fake('attachments');
        $media = attachDocument($this->workOrder, 'dokumen.pdf', $this->user, 'Surat.pdf');

        $this->actingAs($this->user)
            ->delete(route('attachments.destroy', $media->uuid))
            ->assertRedirect()
            ->assertInertiaFlash('toast.message', 'Lampiran Surat.pdf dihapus.');

        expect(Media::query()->count())->toBe(0)
            ->and($disk->allFiles())->toBe([]);
        $activity = Activity::query()->forSubject($this->workOrder)->where('event', 'attachment_removed')->sole();
        expect($activity->causer_id)->toBe($this->user->id)
            ->and($activity->attribute_changes?->toArray())->toBe(['old' => ['lampiran' => 'Surat.pdf', 'ukuran' => $media->size]]);
    });

    it('refuses once the work order is submitted and keeps the file', function () {
        $disk = Storage::fake('attachments');
        $submitted = WorkOrder::factory()->submitted()->create(['department_id' => $this->department->id]);
        $media = attachDocument($submitted, 'dokumen.pdf', $this->user);

        $this->actingAs($this->user)
            ->delete(route('attachments.destroy', $media->uuid))
            ->assertForbidden();

        expect(Media::query()->count())->toBe(1)
            ->and($disk->allFiles())->toHaveCount(1);
    });

    it('answers 404 for another department\'s work order', function () {
        Storage::fake('attachments');
        $media = attachDocument(WorkOrder::factory()->create(), 'dokumen.pdf', $this->user);

        $this->actingAs($this->user)
            ->delete(route('attachments.destroy', $media->uuid))
            ->assertNotFound();

        expect(Media::query()->count())->toBe(1);
    });
});

it('removes files already stored when the surrounding transaction rolls back', function () {
    $disk = Storage::fake('attachments');

    expect(fn () => DB::transaction(function (): never {
        attachDocument($this->workOrder, 'dokumen.pdf', $this->user);
        attachDocument($this->workOrder, 'foto.jpg', $this->user);

        throw new RuntimeException('A later step failed.');
    }))->toThrow(RuntimeException::class);

    expect(Media::query()->count())->toBe(0)
        ->and($disk->allFiles())->toBe([]);
});

it('keeps attachments and files when a work order is soft-deleted and restored', function () {
    $disk = Storage::fake('attachments');
    $media = attachDocument($this->workOrder, 'dokumen.pdf', $this->user);

    $this->workOrder->delete();
    expect(Media::query()->count())->toBe(1);
    $disk->assertExists($media->getPathRelativeToRoot());

    $this->workOrder->restore();

    $this->actingAs($this->user)
        ->get(route('attachments.show', $media->uuid))
        ->assertOk();
});

it('shows upload and removal with readable values in the work order history', function () {
    Storage::fake('attachments');
    attachDocument($this->workOrder, 'dokumen.pdf', $this->user, 'Surat.pdf');

    $this->actingAs($this->user)
        ->getJson(route('admin.activity-log.history', ['work-order', $this->workOrder->id]))
        ->assertOk()
        ->assertJsonPath('data.0.event_label', 'Lampiran ditambahkan')
        ->assertJsonPath('data.0.changes', [
            ['field' => 'lampiran', 'label' => 'Lampiran', 'old' => null, 'new' => 'Surat.pdf'],
            ['field' => 'ukuran', 'label' => 'Ukuran', 'old' => null, 'new' => '1,6 KB'],
        ]);
});
