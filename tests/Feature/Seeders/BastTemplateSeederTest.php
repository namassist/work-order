<?php

use App\Models\BastTemplate;
use App\Models\BastTemplateVersion;
use App\Support\Bast\BastTemplateHtml;
use Database\Seeders\BastTemplateSeeder;

it('publishes the default template as the active version 1, once', function () {
    $this->seed(BastTemplateSeeder::class);
    $this->seed(BastTemplateSeeder::class);

    $version = BastTemplateVersion::active();

    expect(BastTemplateVersion::query()->count())->toBe(1)
        ->and($version->version)->toBe(1)
        ->and($version->published_by)->toBeNull()
        ->and($version->html)->toBe(BastTemplateSeeder::DEFAULT_HTML)
        ->and(BastTemplate::current()->draft_html)->toBe(BastTemplateSeeder::DEFAULT_HTML);
});

it('uses only markup the editor keeps and known placeholders', function () {
    $sanitized = BastTemplateHtml::sanitize(BastTemplateSeeder::DEFAULT_HTML, fn (): ?string => null);

    expect($sanitized->html)->toBe(BastTemplateSeeder::DEFAULT_HTML)
        ->and($sanitized->problems)->toBe([]);
});

it('keeps a draft an admin saved before the first publish', function () {
    BastTemplate::current()->forceFill(['draft_html' => '<p>draf admin</p>'])->save();

    $this->seed(BastTemplateSeeder::class);

    expect(BastTemplateVersion::query()->count())->toBe(0)
        ->and(BastTemplate::current()->draft_html)->toBe('<p>draf admin</p>');
});

it('leaves an existing template alone', function () {
    $version = activeBastTemplate('<p>milik admin</p>');

    $this->seed(BastTemplateSeeder::class);

    expect(BastTemplateVersion::query()->count())->toBe(1)
        ->and(BastTemplateVersion::active()->is($version))->toBeTrue();
});
