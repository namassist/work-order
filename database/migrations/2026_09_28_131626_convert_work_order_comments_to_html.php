<?php

use App\Support\Comments\CommentHtml;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Rich-text comments (FLOW.md §9): `body` becomes sanitized HTML and
 * `body_text` its plain text. Existing plain-text comments are escaped, with
 * their line breaks kept; their text stays as it was.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('work_order_comments', function (Blueprint $table): void {
            $table->text('body_text')->nullable()->after('body');
        });

        DB::table('work_order_comments')->select(['id', 'body'])->chunkById(500, function ($comments): void {
            foreach ($comments as $comment) {
                DB::table('work_order_comments')->where('id', $comment->id)->update([
                    'body' => CommentHtml::fromPlainText($comment->body),
                    'body_text' => $comment->body,
                ]);
            }
        });

        Schema::table('work_order_comments', function (Blueprint $table): void {
            $table->text('body_text')->nullable(false)->change();
        });
    }

    /**
     * Reverse the migrations. Comments go back to their plain text, so any
     * formatting and inline images are lost.
     */
    public function down(): void
    {
        DB::table('work_order_comments')->update(['body' => DB::raw('body_text')]);

        Schema::table('work_order_comments', function (Blueprint $table): void {
            $table->dropColumn('body_text');
        });
    }
};
