<?php

use App\Enums\AttachmentType;
use App\Support\Attachments\AttachmentTypeDetector;

it('identifies allowed types by content', function (string $fixture, AttachmentType $expected) {
    expect((new AttachmentTypeDetector)->detect(attachmentFixture($fixture)))->toBe($expected);
})->with([
    'pdf' => ['dokumen.pdf', AttachmentType::Pdf],
    'jpg' => ['foto.jpg', AttachmentType::Jpeg],
    'png' => ['foto.png', AttachmentType::Png],
    'webp' => ['foto.webp', AttachmentType::Webp],
    'docx' => ['laporan.docx', AttachmentType::Docx],
    'xlsx' => ['anggaran.xlsx', AttachmentType::Xlsx],
]);

it('rejects files outside the allowlist', function (string $fixture) {
    expect((new AttachmentTypeDetector)->detect(attachmentFixture($fixture)))->toBeNull();
})->with([
    'windows executable' => 'program.exe',
    'svg' => 'gambar.svg',
    'html' => 'halaman.html',
    'plain zip' => 'arsip.zip',
    // libmagic reports these as plain docx/xlsx; only the main part type gives them away.
    'macro-enabled word (docm)' => 'makro.docm',
    'macro-enabled excel (xlsm)' => 'makro.xlsm',
]);

it('ignores the filename, so a renamed executable is not a pdf', function () {
    $disguised = tempnam(sys_get_temp_dir(), 'att').'.pdf';
    copy(attachmentFixture('program.exe'), $disguised);

    try {
        expect((new AttachmentTypeDetector)->detect($disguised))->toBeNull();
    } finally {
        unlink($disguised);
    }
});

it('rejects a docx that also carries a VBA project', function () {
    $path = tempnam(sys_get_temp_dir(), 'att');
    copy(attachmentFixture('laporan.docx'), $path);
    $zip = new ZipArchive;
    $zip->open($path);
    $zip->addFromString('word/vbaProject.bin', 'VBA');
    $zip->close();

    try {
        expect((new AttachmentTypeDetector)->detect($path))->toBeNull();
    } finally {
        unlink($path);
    }
});
