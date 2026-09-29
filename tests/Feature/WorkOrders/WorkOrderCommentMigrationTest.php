<?php

use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Support\Facades\DB;

/**
 * The migration that turned plain-text comments into HTML, run against rows
 * in the old shape.
 */
function htmlCommentsMigration(): object
{
    return require database_path('migrations/2026_09_28_131626_convert_work_order_comments_to_html.php');
}

it('converts plain-text comments to escaped HTML and keeps their text', function () {
    $migration = htmlCommentsMigration();
    $migration->down();

    $workOrder = WorkOrder::factory()->submitted()->create();
    $user = User::factory()->create();
    $plain = "Tolong <b>cek</b> & balas\nbaris kedua\n\nParagraf baru <script>alert(1)</script>";
    $id = DB::table('work_order_comments')->insertGetId([
        'work_order_id' => $workOrder->id,
        'user_id' => $user->id,
        'body' => $plain,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $migration->up();

    $row = DB::table('work_order_comments')->find($id);
    expect($row->body)->toBe('<p>Tolong &lt;b&gt;cek&lt;/b&gt; &amp; balas<br>baris kedua</p><p>Paragraf baru &lt;script&gt;alert(1)&lt;/script&gt;</p>')
        ->and($row->body_text)->toBe($plain);
});

it('restores the plain text when rolled back', function () {
    $migration = htmlCommentsMigration();
    $workOrder = WorkOrder::factory()->submitted()->create();
    $id = DB::table('work_order_comments')->insertGetId([
        'work_order_id' => $workOrder->id,
        'user_id' => User::factory()->create()->id,
        'body' => '<p><strong>Halo</strong></p>',
        'body_text' => 'Halo',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $migration->down();

    expect(DB::table('work_order_comments')->find($id)->body)->toBe('Halo');

    $migration->up();
});
