<?php

use App\Enums\Permission;
use App\Models\Department;
use App\Models\Media;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderComment;
use App\Support\Comments\CommentHtml;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-09-25 02:00', 'UTC'));
    $this->disk = Storage::fake('attachments');
    $this->department = Department::factory()->create(['code' => 'IT']);
    $this->user = userInDepartment($this->department, Permission::WorkOrdersView, Permission::WorkOrdersComment);
    $this->workOrder = WorkOrder::factory()->submitted()->create();
});

/**
 * Upload a file for a comment on the test work order (or another one) and
 * return the response.
 */
function uploadForComment(string $kind, UploadedFile $file, ?User $user = null, ?WorkOrder $workOrder = null): TestResponse
{
    return test()->actingAs($user ?? test()->user)
        ->postJson(route('work-orders.comment-uploads.store', [$workOrder ?? test()->workOrder, $kind]), ['file' => $file]);
}

/**
 * Upload a file for a comment and return its media uuid.
 */
function uploadedForComment(string $kind, string $fixture, ?User $user = null, ?WorkOrder $workOrder = null): string
{
    return uploadForComment($kind, attachmentUpload($fixture), $user, $workOrder)->assertCreated()->json('id');
}

function inlineImage(string $uuid, string $alt = ''): string
{
    return '<img src="/attachments/'.$uuid.'" alt="'.$alt.'">';
}

describe('uploading', function () {
    it('keeps an image pending for its uploader without logging or touching the work order', function () {
        $updatedAt = $this->workOrder->updated_at;
        $this->travel(1)->minutes();

        $response = uploadForComment('gambar', attachmentUpload('foto.jpg', 'Pintu rusak.jpg'))
            ->assertCreated()
            ->assertJson(['name' => 'Pintu rusak.jpg', 'extension' => 'jpg', 'previewable' => true]);

        $media = Media::query()->where('uuid', $response->json('id'))->sole();
        expect($media)
            ->model_type->toBe($this->workOrder->getMorphClass())
            ->model_id->toBe($this->workOrder->id)
            ->collection_name->toBe(WorkOrder::COMMENT_IMAGE_UPLOADS)
            ->uploaded_by->toBe($this->user->id)
            ->and($response->json('url'))->toBe('/attachments/'.$media->uuid)
            ->and($this->workOrder->refresh()->updated_at)->toEqual($updatedAt)
            ->and(Activity::query()->where('event', 'attachment_added')->exists())->toBeFalse();
        $this->disk->assertExists($media->getPathRelativeToRoot());
    });

    it('accepts documents of every allowed type', function (string $fixture) {
        uploadForComment('lampiran', attachmentUpload($fixture))->assertCreated();
    })->with(['dokumen.pdf', 'laporan.docx', 'anggaran.xlsx', 'foto.png']);

    it('accepts only images as inline images', function (string $fixture, ?string $clientName) {
        uploadForComment('gambar', attachmentUpload($fixture, $clientName))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['file' => 'Jenis berkas tidak diizinkan. Gunakan JPG, JPEG, PNG, WEBP.']);

        expect(Media::query()->exists())->toBeFalse();
    })->with([
        'pdf' => ['dokumen.pdf', null],
        'svg' => ['gambar.svg', null],
        'html disguised as an image' => ['halaman.html', 'foto.jpg'],
        'docx' => ['laporan.docx', null],
    ]);

    it('refuses files outside the attachment allowlist as documents', function (string $fixture) {
        uploadForComment('lampiran', attachmentUpload($fixture))->assertJsonValidationErrors('file');

        expect(Media::query()->exists())->toBeFalse();
    })->with(['gambar.svg', 'halaman.html', 'makro.docm', 'program.exe', 'arsip.zip']);

    it('refuses files above the size limit', function (string $kind, int $limitKb) {
        uploadForComment($kind, UploadedFile::fake()->create('besar.jpg', $limitKb + 1))
            ->assertJsonValidationErrors('file');

        expect(Media::query()->exists())->toBeFalse();
    })->with([
        'image' => ['gambar', 5120],
        'document' => ['lampiran', 10240],
    ]);

    it('limits the pending uploads of each user on a work order', function () {
        config(['work_order.comments.pending_uploads.max_files' => 2]);
        uploadedForComment('gambar', 'foto.jpg');
        uploadedForComment('gambar', 'foto.png');

        uploadForComment('gambar', attachmentUpload('foto.webp'))
            ->assertJsonValidationErrors(['file' => 'Unggahan komentar yang belum dikirim sudah mencapai batas 2 berkas.']);

        $colleague = userInDepartment($this->department, Permission::WorkOrdersView, Permission::WorkOrdersComment);
        uploadForComment('gambar', attachmentUpload('foto.webp'), $colleague)->assertCreated();
    });

    it('answers 404 for a draft the user cannot see', function () {
        $other = WorkOrder::factory()->create();

        uploadForComment('gambar', attachmentUpload('foto.jpg'), workOrder: $other)->assertNotFound();
        expect(Media::query()->exists())->toBeFalse();
    });

    it('forbids users who may not comment', function () {
        $viewer = userInDepartment($this->department, Permission::WorkOrdersView);

        uploadForComment('gambar', attachmentUpload('foto.jpg'), $viewer)->assertForbidden();
    });

    it('refuses uploads once the work order is read-only', function () {
        $this->workOrder->forceFill(['status' => 'dibatalkan'])->save();

        uploadForComment('gambar', attachmentUpload('foto.jpg'))
            ->assertJsonValidationErrors(['file' => 'Work order dibatalkan; komentarnya hanya dapat dibaca.']);
    });

    it('answers 404 for an unknown kind', function () {
        uploadForComment('dokumen', attachmentUpload('foto.jpg'))->assertNotFound();
    });

    it('limits how often a user may upload', function () {
        foreach (range(1, 20) as $attempt) {
            uploadForComment('gambar', attachmentUpload('foto.jpg'))->assertCreated();
        }

        uploadForComment('gambar', attachmentUpload('foto.jpg'))->assertTooManyRequests();
    });

    it('shows a pending upload only to its uploader', function () {
        $uuid = uploadedForComment('gambar', 'foto.jpg');
        $colleague = userInDepartment($this->department, Permission::WorkOrdersView, Permission::WorkOrdersComment);

        $this->actingAs($this->user)->get(route('attachments.show', $uuid))->assertOk();
        $this->actingAs($colleague)->get(route('attachments.show', $uuid))->assertNotFound();
    });

    it('does not let the generic attachment endpoints change comment files', function () {
        $uuid = uploadedForComment('lampiran', 'dokumen.pdf');

        $this->post(route('attachments.store', ['work-order', $this->workOrder->id, WorkOrder::COMMENT_FILE_UPLOADS]), ['file' => attachmentUpload('dokumen.pdf')])
            ->assertForbidden();
        $this->delete(route('attachments.destroy', $uuid))->assertForbidden();

        $comment = WorkOrderComment::factory()->for($this->workOrder)->for($this->user, 'author')->create();
        $this->post(route('attachments.store', ['wo-comment', $comment->id, WorkOrderComment::DOCUMENTS]), ['file' => attachmentUpload('dokumen.pdf')])
            ->assertNotFound();
        expect(Media::query()->count())->toBe(1);
    });
});

describe('posting with files', function () {
    it('claims the inline images and documents and logs their names without the text', function () {
        $image = uploadedForComment('gambar', 'foto.jpg');
        $document = uploadedForComment('lampiran', 'dokumen.pdf');

        $this->post(route('work-orders.comments.store', $this->workOrder), [
            'body' => '<p>Foto <strong>pintu</strong>:</p>'.inlineImage($image),
            'attachments' => [$document],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $comment = $this->workOrder->comments()->sole();
        expect($comment)
            ->body->toBe('<p>Foto <strong>pintu</strong>:</p>'.inlineImage($image, 'foto.jpg'))
            ->body_text->toBe('Foto pintu:')
            ->and($comment->attachmentsIn(WorkOrderComment::IMAGES)->pluck('uuid')->all())->toBe([$image])
            ->and($comment->attachmentsIn(WorkOrderComment::DOCUMENTS)->pluck('uuid')->all())->toBe([$document])
            ->and(Media::query()->whereMorphedTo('model', $this->workOrder)->exists())->toBeFalse();

        $activity = Activity::query()->forSubject($this->workOrder)->where('event', 'comment_added')->sole();
        expect($activity)
            ->properties->toArray()->toBe(['komentar_id' => $comment->id])
            ->attribute_changes->toArray()->toBe(['attributes' => ['lampiran' => 'foto.jpg, dokumen.pdf']])
            ->and(json_encode($activity->toArray()))->not->toContain('pintu');
    });

    it('accepts a comment with only an image or only a document', function (Closure $payload) {
        $this->post(route('work-orders.comments.store', $this->workOrder), $payload())->assertSessionHasNoErrors();

        expect($this->workOrder->comments()->sole()->body_text)->toBe('');
    })->with([
        'image' => fn (): array => ['body' => inlineImage(uploadedForComment('gambar', 'foto.jpg'))],
        'document' => fn (): array => ['body' => '<p></p>', 'attachments' => [uploadedForComment('lampiran', 'dokumen.pdf')]],
    ]);

    it('strips images the comment may not show and leaves them unclaimed', function (Closure $foreignImage) {
        $uuid = $foreignImage();

        $this->actingAs($this->user)
            ->post(route('work-orders.comments.store', $this->workOrder), ['body' => '<p>Lihat</p>'.inlineImage($uuid)])
            ->assertSessionHasNoErrors();

        $comment = WorkOrderComment::query()->latest('id')->first();
        expect($comment->body)->toBe('<p>Lihat</p>')
            ->and($comment->attachmentsIn(WorkOrderComment::IMAGES))->toBeEmpty();
    })->with([
        'the author\'s upload on another work order' => fn (): string => uploadedForComment(
            'gambar',
            'foto.jpg',
            workOrder: WorkOrder::factory()->submitted()->create(),
        ),
        'a colleague\'s upload on this work order' => fn (): string => uploadedForComment(
            'gambar',
            'foto.jpg',
            userInDepartment(test()->department, Permission::WorkOrdersView, Permission::WorkOrdersComment),
        ),
        'a document upload used as an image' => fn (): string => uploadedForComment('lampiran', 'foto.png'),
        'an image of another comment' => function (): string {
            $uuid = uploadedForComment('gambar', 'foto.jpg');
            test()->post(route('work-orders.comments.store', test()->workOrder), ['body' => inlineImage($uuid)]);

            return $uuid;
        },
    ]);

    it('refuses documents the author did not upload for this work order', function (Closure $foreignDocument) {
        $uuid = $foreignDocument();

        $this->actingAs($this->user)
            ->post(route('work-orders.comments.store', $this->workOrder), ['body' => '<p>x</p>', 'attachments' => [$uuid]])
            ->assertSessionHasErrors(['attachments' => 'Lampiran tidak ditemukan atau sudah kedaluwarsa. Unggah ulang berkasnya.']);

        expect($this->workOrder->comments()->exists())->toBeFalse();
    })->with([
        'another work order' => fn (): string => uploadedForComment(
            'lampiran',
            'dokumen.pdf',
            workOrder: WorkOrder::factory()->submitted()->create(),
        ),
        'a colleague\'s upload' => fn (): string => uploadedForComment(
            'lampiran',
            'dokumen.pdf',
            userInDepartment(test()->department, Permission::WorkOrdersView, Permission::WorkOrdersComment),
        ),
        'an image upload' => fn (): string => uploadedForComment('gambar', 'foto.jpg'),
        'an unknown uuid' => fn (): string => '0192f3a4-5b6c-7d8e-9f01-23456789abcd',
    ]);

    it('limits the images and documents of one comment', function (string $kind, string $fixture, string $message) {
        config(["work_order.comments.{$kind}.max_files" => 1]);
        $uploadKind = $kind === 'images' ? 'gambar' : 'lampiran';
        $uuids = [uploadedForComment($uploadKind, $fixture), uploadedForComment($uploadKind, $fixture)];

        $payload = $kind === 'images'
            ? ['body' => inlineImage($uuids[0]).inlineImage($uuids[1])]
            : ['body' => '<p>x</p>', 'attachments' => $uuids];

        $this->post(route('work-orders.comments.store', $this->workOrder), $payload)
            ->assertSessionHasErrors([$kind === 'images' ? 'body' : 'attachments' => $message]);

        expect($this->workOrder->comments()->exists())->toBeFalse();
    })->with([
        'images' => ['images', 'foto.jpg', 'Komentar berisi paling banyak 1 gambar.'],
        'documents' => ['documents', 'dokumen.pdf', 'Komentar berisi paling banyak 1 lampiran.'],
    ]);

    it('counts an image repeated in the body against the limit', function () {
        config(['work_order.comments.images.max_files' => 2]);
        $image = inlineImage(uploadedForComment('gambar', 'foto.jpg'));

        $this->post(route('work-orders.comments.store', $this->workOrder), ['body' => str_repeat($image, 3)])
            ->assertSessionHasErrors(['body' => 'Komentar berisi paling banyak 2 gambar.']);

        expect($this->workOrder->comments()->exists())->toBeFalse();
    });

    it('refuses an oversized body before locking the work order', function () {
        $locks = [];
        DB::listen(function (QueryExecuted $query) use (&$locks): void {
            if (str_contains($query->sql, 'for update')) {
                $locks[] = $query->sql;
            }
        });

        $this->actingAs($this->user)
            ->post(route('work-orders.comments.store', $this->workOrder), ['body' => str_repeat('a', CommentHtml::MAX_HTML_BYTES + 1)])
            ->assertSessionHasErrors(['body' => 'Komentar terlalu besar. Kurangi format atau pecah menjadi beberapa komentar.']);

        expect($locks)->toBe([]);
    });

    it('serves comment files to whoever sees the work order, and 404 to anyone else', function () {
        $image = uploadedForComment('gambar', 'foto.jpg');
        $this->post(route('work-orders.comments.store', $this->workOrder), ['body' => inlineImage($image)]);
        $otherClient = icUser(Permission::WorkOrdersView);

        $this->actingAs(unggulUser(Permission::WorkOrdersView))
            ->get(route('attachments.show', $image))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/jpeg');
        $this->actingAs(unggulUser())->get(route('attachments.show', $image))->assertNotFound();
        $this->actingAs($otherClient)->get(route('attachments.show', $image))->assertNotFound();
    });
});

describe('editing with files', function () {
    it('claims added files, deletes removed ones, and logs the change', function () {
        $kept = uploadedForComment('gambar', 'foto.jpg');
        $removed = uploadedForComment('gambar', 'foto.png');
        $document = uploadedForComment('lampiran', 'dokumen.pdf');
        $this->post(route('work-orders.comments.store', $this->workOrder), [
            'body' => inlineImage($kept).inlineImage($removed),
            'attachments' => [$document],
        ]);
        $comment = $this->workOrder->comments()->sole();
        $removedPath = Media::query()->where('uuid', $removed)->sole()->getPathRelativeToRoot();
        $added = uploadedForComment('gambar', 'foto.webp');

        $this->patch(route('work-orders.comments.update', [$this->workOrder, $comment]), [
            'body' => inlineImage($kept).'<p>Diganti</p>'.inlineImage($added),
            'attachments' => [$document],
        ])->assertSessionHasNoErrors()->assertInertiaFlash('toast.message', 'Komentar diperbarui.');

        expect($comment->attachmentsIn(WorkOrderComment::IMAGES)->pluck('uuid')->all())->toBe([$kept, $added])
            ->and($comment->attachmentsIn(WorkOrderComment::DOCUMENTS)->pluck('uuid')->all())->toBe([$document])
            ->and(Media::query()->where('uuid', $removed)->exists())->toBeFalse();
        $this->disk->assertMissing($removedPath);
        expect(Activity::query()->where('event', 'comment_edited')->sole()->attribute_changes->toArray())->toBe([
            'attributes' => ['lampiran' => 'foto.jpg, foto.webp, dokumen.pdf'],
            'old' => ['lampiran' => 'foto.jpg, foto.png, dokumen.pdf'],
        ]);
    });

    it('logs no file change when only the text changes', function () {
        $image = uploadedForComment('gambar', 'foto.jpg');
        $this->post(route('work-orders.comments.store', $this->workOrder), ['body' => '<p>a</p>'.inlineImage($image)]);
        $comment = $this->workOrder->comments()->sole();

        $this->patch(route('work-orders.comments.update', [$this->workOrder, $comment]), ['body' => '<p>b</p>'.inlineImage($image, 'foto.jpg')]);

        expect(Activity::query()->where('event', 'comment_edited')->sole())
            ->properties->toArray()->toBe(['komentar_id' => $comment->id])
            ->attribute_changes->toBeEmpty()
            ->and($comment->attachmentsIn(WorkOrderComment::IMAGES)->pluck('uuid')->all())->toBe([$image]);
    });

    it('keeps files when an edit is refused', function () {
        $image = uploadedForComment('gambar', 'foto.jpg');
        $this->post(route('work-orders.comments.store', $this->workOrder), ['body' => inlineImage($image)]);
        $comment = $this->workOrder->comments()->sole();
        $this->travel(16)->minutes();

        $this->patch(route('work-orders.comments.update', [$this->workOrder, $comment]), ['body' => '<p>Tanpa gambar</p>'])
            ->assertInertiaFlash('toast.type', 'error');

        expect(Media::query()->where('uuid', $image)->exists())->toBeTrue();
    });
});

describe('deleting with files', function () {
    it('deletes the comment\'s files and keeps their names in the audit log', function () {
        $image = uploadedForComment('gambar', 'foto.jpg');
        $document = uploadedForComment('lampiran', 'dokumen.pdf');
        $this->post(route('work-orders.comments.store', $this->workOrder), ['body' => '<p>Salah kirim</p>'.inlineImage($image), 'attachments' => [$document]]);
        $comment = $this->workOrder->comments()->sole();
        $paths = Media::query()->get()->map(fn (Media $media): string => $media->getPathRelativeToRoot());

        $this->delete(route('work-orders.comments.destroy', [$this->workOrder, $comment]))
            ->assertInertiaFlash('toast.message', 'Komentar dihapus.');

        $this->assertSoftDeleted($comment);
        expect(Media::query()->exists())->toBeFalse();
        $paths->each(fn (string $path) => $this->disk->assertMissing($path));
        expect(Activity::query()->where('event', 'comment_deleted')->sole())
            ->properties->toArray()->toBe(['komentar_id' => $comment->id])
            ->attribute_changes->toArray()->toBe(['old' => ['lampiran' => 'foto.jpg, dokumen.pdf']]);
        $this->get(route('attachments.show', $image))->assertNotFound();
    });
});

describe('timeline', function () {
    it('sends the sanitized body and the documents of each comment', function () {
        $image = uploadedForComment('gambar', 'foto.jpg');
        $document = uploadedForComment('lampiran', 'dokumen.pdf');
        $this->post(route('work-orders.comments.store', $this->workOrder), ['body' => '<p>Halo</p>'.inlineImage($image), 'attachments' => [$document]]);

        $this->get(route('work-orders.show', $this->workOrder))
            ->assertInertia(fn (Assert $page): AssertableInertia => $page
                ->where('timeline.0.body', '<p>Halo</p>'.inlineImage($image, 'foto.jpg'))
                ->has('timeline.0.attachments', 1)
                ->where('timeline.0.attachments.0.id', $document)
                ->where('timeline.0.attachments.0.name', 'dokumen.pdf')
                ->where('comments.max_images', 10)
                ->where('comments.max_documents', 5)
                ->where('comments.uploads.gambar.type_list', 'JPG, JPEG, PNG, WEBP')
                ->where('comments.uploads.lampiran.max_size_kb', 10240));
    });

    it('sends only sanitized HTML, even for a body stored without the actions', function () {
        WorkOrderComment::factory()->for($this->workOrder)->for($this->user, 'author')->create([
            'body' => '<p onclick="alert(1)">hai</p><script>alert(2)</script><img src="https://evil.test/x.png" onerror="alert(3)"><a href="javascript:alert(4)">klik</a>',
        ]);

        $this->actingAs($this->user)
            ->get(route('work-orders.show', $this->workOrder))
            ->assertInertia(fn (Assert $page): AssertableInertia => $page
                ->where('timeline.0.body', '<p>hai</p>klik'));
    });
});

describe('pruning abandoned uploads', function () {
    it('deletes pending uploads older than a day and keeps the rest', function () {
        $abandoned = uploadedForComment('gambar', 'foto.jpg');
        $abandonedPath = Media::query()->where('uuid', $abandoned)->sole()->getPathRelativeToRoot();
        $claimed = uploadedForComment('gambar', 'foto.png');
        $this->post(route('work-orders.comments.store', $this->workOrder), ['body' => inlineImage($claimed)]);
        uploadedForComment('lampiran', 'dokumen.pdf', workOrder: WorkOrder::factory()->submitted()->create());
        $this->travel(23)->hours();
        $recent = uploadedForComment('lampiran', 'dokumen.pdf');
        $this->travel(1)->hours();
        $this->travel(1)->seconds();

        $this->artisan('work-orders:prune-comment-uploads')
            ->expectsOutput('2 unggahan komentar yang tidak dipakai dihapus.')
            ->assertSuccessful();

        expect(Media::query()->pluck('uuid')->sort()->values()->all())->toBe(collect([$claimed, $recent])->sort()->values()->all());
        $this->disk->assertMissing($abandonedPath);
    });

    it('follows the configured age', function () {
        config(['work_order.comments.pending_uploads.prune_after_hours' => 1]);
        uploadedForComment('gambar', 'foto.jpg');
        $this->travel(61)->minutes();

        $this->artisan('work-orders:prune-comment-uploads')->assertSuccessful();

        expect(Media::query()->exists())->toBeFalse();
    });

    it('runs every hour', function () {
        $events = collect(app(Schedule::class)->events())
            ->filter(fn (Event $event): bool => str_contains((string) $event->command, 'work-orders:prune-comment-uploads'));

        expect($events)->toHaveCount(1)
            ->and($events->first()->expression)->toBe('0 * * * *');
    });
});
