<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * BAST templates and the BAST of a work order (FLOW.md §8, §9).
 *
 * There is exactly one template: its draft is edited, and publishing copies
 * it into an immutable version. Exactly one version is active; an older one
 * may be activated again (rollback). A BAST records the version it was
 * generated from, its number, and, once the Direktur approved it, who and
 * when plus the SHA-256 of the final PDF. Triggers keep published versions,
 * approved BASTs, and the final PDF's media row from being changed or
 * deleted, whatever code path tries.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // A work order already waiting in Approval BAST has no BAST, so it could never be approved.
        if (DB::table('work_orders')->where('status', 'approval_bast')->exists()) {
            throw new RuntimeException('Work orders are waiting in Approval BAST without a generated BAST. '
                .'They cannot be approved after this step: start from a fresh database (see docs/DEPLOY.md).');
        }

        Schema::create('bast_templates', function (Blueprint $table): void {
            $table->id();
            $table->text('draft_html')->default('');
            $table->foreignId('draft_updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('draft_updated_at')->nullable();
            $table->timestamps();
        });

        // Exactly one template (FLOW.md §9): a second row would collide on the constant.
        DB::statement('CREATE UNIQUE INDEX bast_templates_single_row ON bast_templates ((true))');

        Schema::create('bast_template_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('bast_template_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->text('html');
            // The images the version shows, keyed by their upload's uuid: {name, mime, data (base64)}.
            $table->jsonb('images')->default('{}');
            // Null for the default template published by the seeder.
            $table->foreignId('published_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('published_at');
            $table->boolean('is_active')->default(false);
            $table->timestamps();

            $table->unique(['bast_template_id', 'version']);
        });

        DB::statement('CREATE UNIQUE INDEX bast_template_versions_one_active ON bast_template_versions (is_active) WHERE is_active');
        DB::statement('ALTER TABLE bast_template_versions ADD CONSTRAINT bast_template_versions_version_check CHECK (version > 0)');

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION bast_template_versions_immutable() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'A published BAST template version cannot be deleted.';
                END IF;

                IF (NEW.bast_template_id, NEW.version, NEW.html, NEW.images, NEW.published_by, NEW.published_at, NEW.created_at)
                    IS DISTINCT FROM (OLD.bast_template_id, OLD.version, OLD.html, OLD.images, OLD.published_by, OLD.published_at, OLD.created_at) THEN
                    RAISE EXCEPTION 'A published BAST template version cannot be changed; only whether it is active.';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER bast_template_versions_immutable
                BEFORE UPDATE OR DELETE ON bast_template_versions
                FOR EACH ROW EXECUTE FUNCTION bast_template_versions_immutable();
            SQL);

        Schema::create('work_order_basts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('work_order_id')->unique()->constrained()->restrictOnDelete();
            $table->string('number', 100)->unique();
            $table->foreignId('bast_template_version_id')->constrained()->restrictOnDelete();
            $table->foreignId('submitted_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('submitted_at');
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            // The Direktur's name as printed on the final PDF.
            $table->string('approver_name')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->char('final_sha256', 64)->nullable();
            $table->timestamps();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE work_order_basts ADD CONSTRAINT work_order_basts_approval_check CHECK (
                (approved_by IS NULL AND approver_name IS NULL AND approved_at IS NULL AND final_sha256 IS NULL)
                OR (approved_by IS NOT NULL AND approver_name IS NOT NULL AND approved_at IS NOT NULL AND final_sha256 IS NOT NULL)
            )
            SQL);
        DB::statement("ALTER TABLE work_order_basts ADD CONSTRAINT work_order_basts_final_sha256_check CHECK (final_sha256 IS NULL OR final_sha256 ~ '^[0-9a-f]{64}$')");

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION work_order_basts_immutable() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'A BAST cannot be deleted.';
                END IF;

                IF OLD.approved_at IS NOT NULL THEN
                    RAISE EXCEPTION 'An approved BAST cannot be changed.';
                END IF;

                IF (NEW.work_order_id, NEW.number, NEW.bast_template_version_id, NEW.submitted_by, NEW.submitted_at, NEW.created_at)
                    IS DISTINCT FROM (OLD.work_order_id, OLD.number, OLD.bast_template_version_id, OLD.submitted_by, OLD.submitted_at, OLD.created_at) THEN
                    RAISE EXCEPTION 'A BAST keeps its work order, number, template version, and submission.';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER work_order_basts_immutable
                BEFORE UPDATE OR DELETE ON work_order_basts
                FOR EACH ROW EXECUTE FUNCTION work_order_basts_immutable();

            CREATE OR REPLACE FUNCTION media_bast_final_immutable() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'The final BAST PDF cannot be changed or deleted.';
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER media_bast_final_immutable
                BEFORE UPDATE OR DELETE ON media
                FOR EACH ROW
                WHEN (OLD.model_type = 'wo-bast' AND OLD.collection_name = 'final')
                EXECUTE FUNCTION media_bast_final_immutable();
            SQL);

        Schema::create('bast_number_sequences', function (Blueprint $table): void {
            $table->string('scope')->primary();
            $table->unsignedInteger('last_value');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS media_bast_final_immutable ON media');
        DB::unprepared('DROP FUNCTION IF EXISTS media_bast_final_immutable()');
        Schema::dropIfExists('bast_number_sequences');
        Schema::dropIfExists('work_order_basts');
        DB::unprepared('DROP FUNCTION IF EXISTS work_order_basts_immutable()');
        Schema::dropIfExists('bast_template_versions');
        DB::unprepared('DROP FUNCTION IF EXISTS bast_template_versions_immutable()');
        Schema::dropIfExists('bast_templates');
    }
};
