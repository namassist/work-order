<?php

use App\Enums\Permission;
use App\Models\BastTemplate;
use App\Models\BastTemplateVersion;
use App\Models\WorkOrder;
use App\Models\WorkOrderBast;
use App\Support\Bast\BastPlaceholder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

/*
| The BAST template page (FLOW.md §9): one template, its draft sanitized on
| save, published as immutable versions, exactly one active.
*/

beforeEach(function () {
    Storage::fake('attachments');
    $this->admin = userWithPermissions(Permission::BastTemplatesManage, Permission::WorkOrdersView);
});

function saveDraft(string $html): TestResponse
{
    return test()->actingAs(test()->admin)->put(route('admin.bast-template.update'), ['html' => $html])->assertRedirect();
}

function publishTemplate(): TestResponse
{
    return test()->actingAs(test()->admin)->post(route('admin.bast-template.publish'))->assertRedirect();
}

/**
 * Uploads a template image and returns its media uuid.
 */
function uploadTemplateImage(string $fixture = 'foto.png'): string
{
    test()->actingAs(test()->admin)
        ->post(route('attachments.store', ['bast-template', BastTemplate::current()->id, BastTemplate::IMAGES]), ['file' => attachmentUpload($fixture, 'kop.png')])
        ->assertSessionHasNoErrors();

    return (string) BastTemplate::current()->attachmentsIn(BastTemplate::IMAGES)->last()?->uuid;
}

describe('permissions', function () {
    it('refuses every endpoint without bast-templates.manage', function (string $method, Closure $route) {
        $version = BastTemplateVersion::factory()->create();
        $workOrder = WorkOrder::factory()->submitted()->create();

        $this->actingAs(userWithPermissions(Permission::WorkOrdersView, Permission::ActivityLogView))
            ->{$method}($route($version, $workOrder))
            ->assertForbidden();
    })->with([
        'page' => ['get', fn (): string => route('admin.bast-template.edit')],
        'save' => ['put', fn (): string => route('admin.bast-template.update')],
        'publish' => ['post', fn (): string => route('admin.bast-template.publish')],
        'activate' => ['post', fn (BastTemplateVersion $version): string => route('admin.bast-template.activate', $version)],
        'preview' => ['get', fn (BastTemplateVersion $version, WorkOrder $workOrder): string => route('admin.bast-template.preview', ['work_order' => $workOrder->id])],
    ]);

    it('refuses template images without bast-templates.manage', function () {
        $this->actingAs(userWithPermissions(Permission::WorkOrdersView))
            ->post(route('attachments.store', ['bast-template', BastTemplate::current()->id, BastTemplate::IMAGES]), ['file' => attachmentUpload('foto.png')])
            ->assertForbidden();
    });

    it('is held by the system admin only among the initial roles', function () {
        foreach (['admin-wo', 'lead-operational', 'pic-timesheet', 'rental', 'direktur', 'finance', 'viewer'] as $role) {
            expect(userWithRole($role)->can('manage', BastTemplate::class))->toBeFalse($role);
        }

        expect(adminUser()->can('manage', BastTemplate::class))->toBeTrue()
            ->and(Permission::BastTemplatesManage->isInternalOnly())->toBeTrue();
    });
});

describe('the page', function () {
    it('shows the draft, versions, placeholders, and work orders to preview with', function () {
        $version = activeBastTemplate('<p>{{nomor_bast}}</p>');
        $workOrder = WorkOrder::factory()->submitted()->create();
        WorkOrder::factory()->create(); // a draft: nothing to preview with

        $this->actingAs($this->admin)
            ->get(route('admin.bast-template.edit'))
            ->assertInertia(fn (Assert $page): Assert => $page
                ->component('admin/bast-template/Edit')
                ->where('template.draft_html', '')
                ->where('template.is_published', false)
                ->has('versions', 1)
                ->where('versions.0.version', $version->version)
                ->where('versions.0.is_active', true)
                ->has('placeholders', count(BastPlaceholder::cases()))
                ->where('placeholders.0.token', '{{nomor_bast}}')
                ->has('previewWorkOrders', 1)
                ->where('previewWorkOrders.0.id', $workOrder->id)
                ->where('images.target.type', 'bast-template')
                ->where('images.rules.type_list', 'PNG, JPG, JPEG'));
    });
});

describe('saving the draft', function () {
    it('stores the sanitized draft and logs that it changed', function () {
        saveDraft('<h1 style="text-align:center" onclick="x()">BAST {{nomor_bast}}</h1><script>alert(1)</script><p>{{ $x }} @php</p>')
            ->assertSessionHasNoErrors();

        $template = BastTemplate::current();
        expect($template->draft_html)->toBe('<h1 style="text-align: center;">BAST {{nomor_bast}}</h1><p>{{ $x }} @php</p>')
            ->and($template->draft_updated_by)->toBe($this->admin->id)
            ->and(Activity::query()->forSubject($template)->where('event', 'bast_template_saved')->count())->toBe(1);

        saveDraft('<h1 style="text-align: center;">BAST {{nomor_bast}}</h1><p>{{ $x }} @php</p>');

        expect(Activity::query()->forSubject($template)->where('event', 'bast_template_saved')->count())->toBe(1);
    });

    it('refuses unknown placeholders and placeholders in attributes, keeping the old draft', function (string $html, string $message) {
        saveDraft('<p>lama</p>');

        saveDraft($html)->assertSessionHasErrors(['html' => $message]);

        expect(BastTemplate::current()->draft_html)->toBe('<p>lama</p>');
    })->with([
        'unknown' => ['<p>{{password}}</p>', 'Placeholder tidak dikenal: {{password}}.'],
        'in an attribute' => ['<img src="/attachments/{{nomor_bast}}">', 'Placeholder hanya boleh di dalam teks, tidak di atribut (mis. alamat atau teks alternatif gambar).'],
    ]);

    it('refuses a template larger than the limit', function () {
        saveDraft('<p>'.str_repeat('a', config()->integer('work_order.bast.template_max_html_bytes')).'</p>')
            ->assertSessionHasErrors(['html' => 'Template terlalu besar. Kurangi isi atau format.']);
    });

    it('keeps only the template\'s own images', function () {
        $uuid = uploadTemplateImage();
        $other = WorkOrder::factory()->create()->addMedia(attachmentUpload('foto.png'))->toMediaCollection(WorkOrder::DOCUMENTS)->uuid;

        saveDraft('<img src="/attachments/'.$uuid.'"><img src="/attachments/'.$other.'"><img src="https://evil.test/x.png">');

        expect(BastTemplate::current()->draft_html)->toBe('<img src="/attachments/'.$uuid.'" alt="kop.png">');
    });
});

describe('template images', function () {
    it('accepts PNG and JPEG only', function (string $fixture, bool $accepted) {
        $response = $this->actingAs($this->admin)
            ->post(route('attachments.store', ['bast-template', BastTemplate::current()->id, BastTemplate::IMAGES]), ['file' => attachmentUpload($fixture)]);

        $accepted ? $response->assertSessionHasNoErrors() : $response->assertSessionHasErrors('file');
        expect(BastTemplate::current()->attachmentsIn(BastTemplate::IMAGES))->toHaveCount($accepted ? 1 : 0);
    })->with([
        'png' => ['foto.png', true],
        'jpeg' => ['foto.jpg', true],
        'svg' => ['gambar.svg', false],
        'webp' => ['foto.webp', false],
        'pdf' => ['dokumen.pdf', false],
    ]);

    it('refuses an image larger than the pixel limit, however small the file', function () {
        config(['work_order.bast.images.max_side_px' => 100]);

        $this->actingAs($this->admin)
            ->post(route('attachments.store', ['bast-template', BastTemplate::current()->id, BastTemplate::IMAGES]), ['file' => UploadedFile::fake()->image('kop.png', 101, 10)])
            ->assertSessionHasErrors(['file' => 'Gambar terlalu besar. Maksimal 100 × 100 piksel.']);

        $this->actingAs($this->admin)
            ->post(route('attachments.store', ['bast-template', BastTemplate::current()->id, BastTemplate::IMAGES]), ['file' => UploadedFile::fake()->image('kop.png', 100, 100)])
            ->assertSessionHasNoErrors();
    });

    it('refuses an image over the size limit', function () {
        config(['work_order.bast.images.max_size_kb' => 1]);

        $this->actingAs($this->admin)
            ->post(route('attachments.store', ['bast-template', BastTemplate::current()->id, BastTemplate::IMAGES]), ['file' => UploadedFile::fake()->image('kop.png', 800, 800)->size(50)])
            ->assertSessionHasErrors('file');
    });
});

describe('publishing', function () {
    it('publishes the draft as the only active version', function () {
        $old = activeBastTemplate();
        saveDraft('<p>Versi baru {{nomor_bast}}</p>');

        publishTemplate()->assertSessionHasNoErrors();

        $version = BastTemplateVersion::active();
        expect($version->version)->toBe($old->version + 1)
            ->and($version->html)->toBe('<p>Versi baru {{nomor_bast}}</p>')
            ->and($version->published_by)->toBe($this->admin->id)
            ->and($old->refresh()->is_active)->toBeFalse()
            ->and(BastTemplateVersion::query()->where('is_active', true)->count())->toBe(1);

        $log = Activity::query()->forSubject(BastTemplate::current())->where('event', 'bast_template_published')->sole();
        expect($log->attribute_changes['attributes'])->toBe(['versi_aktif' => $version->version]);
    });

    it('refuses an empty draft, and a draft equal to the latest version', function () {
        publishTemplate()->assertSessionHasErrors(['html' => 'Template masih kosong.']);

        saveDraft('<p>isi</p>');
        publishTemplate()->assertSessionHasNoErrors();
        publishTemplate()->assertSessionHasErrors(['html' => 'Draf sama dengan versi 1; tidak ada yang diterbitkan.']);

        expect(BastTemplateVersion::query()->count())->toBe(1);
    });

    it('copies the images into the version, which keeps them after the upload is deleted', function () {
        $uuid = uploadTemplateImage();
        saveDraft('<img src="/attachments/'.$uuid.'" alt="Kop"><p>isi</p>');
        publishTemplate();

        $media = BastTemplate::current()->attachmentsIn(BastTemplate::IMAGES)->sole();
        $this->actingAs($this->admin)->delete(route('attachments.destroy', $media->uuid))->assertSessionHasNoErrors();

        $version = BastTemplateVersion::active();
        expect(BastTemplate::current()->attachmentsIn(BastTemplate::IMAGES))->toHaveCount(0)
            ->and($version->images[$uuid]['mime'])->toBe('image/png')
            ->and(base64_decode($version->images[$uuid]['data']))->toBe(file_get_contents(attachmentFixture('foto.png')))
            ->and($version->html)->toBe('<img src="/attachments/'.$uuid.'" alt="Kop"><p>isi</p>');
    });

    it('never changes a published version', function (Closure $change) {
        saveDraft('<p>isi</p>');
        publishTemplate();

        expect(fn () => DB::transaction(fn () => $change(BastTemplateVersion::active())))->toThrow(Exception::class)
            ->and(BastTemplateVersion::active()->html)->toBe('<p>isi</p>');
    })->with([
        'model update' => fn (BastTemplateVersion $version) => $version->forceFill(['html' => '<p>ubah</p>'])->save(),
        'model delete' => fn (BastTemplateVersion $version) => $version->delete(),
        'database update' => fn (BastTemplateVersion $version) => DB::table('bast_template_versions')->where('id', $version->id)->update(['html' => '<p>ubah</p>']),
        'database images update' => fn (BastTemplateVersion $version) => DB::table('bast_template_versions')->where('id', $version->id)->update(['images' => '{"x": 1}']),
        'database delete' => fn (BastTemplateVersion $version) => DB::table('bast_template_versions')->where('id', $version->id)->delete(),
        'a second active version' => fn (BastTemplateVersion $version) => BastTemplateVersion::factory()->active()->create(),
    ]);

    it('keeps a BAST on its version after the template is updated', function () {
        saveDraft('<p>satu</p>');
        publishTemplate();
        $bast = WorkOrderBast::factory()->create(['bast_template_version_id' => BastTemplateVersion::active()->id]);

        saveDraft('<p>dua</p>');
        publishTemplate();

        expect($bast->refresh()->templateVersion->html)->toBe('<p>satu</p>')
            ->and(BastTemplateVersion::active()->html)->toBe('<p>dua</p>');
    });

    it('reports on the page whether the draft is published', function () {
        saveDraft('<p>isi</p>');
        publishTemplate();

        $this->actingAs($this->admin)->get(route('admin.bast-template.edit'))
            ->assertInertia(fn (Assert $page): Assert => $page->where('template.is_published', true));

        saveDraft('<p>isi baru</p>');

        $this->actingAs($this->admin)->get(route('admin.bast-template.edit'))
            ->assertInertia(fn (Assert $page): Assert => $page->where('template.is_published', false));
    });
});

describe('activating a version', function () {
    it('makes an older version the only active one again', function () {
        $first = activeBastTemplate('<p>satu</p>');
        $second = activeBastTemplate('<p>dua</p>');

        $this->actingAs($this->admin)->post(route('admin.bast-template.activate', $first))->assertRedirect()->assertSessionHasNoErrors();

        expect($first->refresh()->is_active)->toBeTrue()
            ->and($second->refresh()->is_active)->toBeFalse()
            ->and($first->html)->toBe('<p>satu</p>')
            ->and(Activity::query()->where('event', 'bast_template_activated')->sole()->attribute_changes['attributes'])->toBe(['versi_aktif' => $first->version]);
    });
});

describe('preview', function () {
    it('renders the draft or a version, filled from a visible work order, as an inline PDF', function (bool $ofVersion) {
        saveDraft('<p>{{nomor_wo}}</p>');
        $version = activeBastTemplate();
        $workOrder = WorkOrder::factory()->inReview()->create();

        $response = $this->actingAs($this->admin)
            ->get(route('admin.bast-template.preview', ['work_order' => $workOrder->id, 'version' => $ofVersion ? $version->id : null]))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('X-Content-Type-Options', 'nosniff');

        expect($response->getContent())->toStartWith('%PDF-')
            ->and($response->headers->get('Content-Disposition'))->toStartWith('inline;');
    })->with(['draft' => false, 'version' => true]);

    it('refuses a draft work order and one the user cannot see', function () {
        $withoutView = userWithPermissions(Permission::BastTemplatesManage);

        $this->actingAs($withoutView)->get(route('admin.bast-template.preview', ['work_order' => WorkOrder::factory()->submitted()->create()->id]))->assertNotFound();
        $this->actingAs($this->admin)->get(route('admin.bast-template.preview', ['work_order' => WorkOrder::factory()->create()->id]))->assertNotFound();
    });
});
