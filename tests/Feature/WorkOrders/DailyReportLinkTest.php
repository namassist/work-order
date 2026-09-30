<?php

use App\Rules\DailyReportLink;
use Illuminate\Support\Facades\Validator;

/*
| Links in daily reports (FLOW.md §7): absolute http(s) URLs only, length
| limited, optionally restricted to configured domains. The server never
| fetches them.
*/

function linkPasses(string $url): bool
{
    return Validator::make(['link' => $url], ['link' => [new DailyReportLink]])->passes();
}

it('accepts absolute http and https links', function (string $url) {
    expect(linkPasses($url))->toBeTrue();
})->with([
    'https' => 'https://unggul.sharepoint.com/sites/wo/Shared%20Documents/timesheet.xlsx',
    'http' => 'http://intranet.worder.test/timesheet?minggu=39',
    'upper-case scheme' => 'HTTPS://1drv.ms/x/s!AbCdEf',
]);

it('refuses other schemes, relative links, and credentials', function (string $url) {
    expect(linkPasses($url))->toBeFalse();
})->with([
    'javascript' => 'javascript:alert(1)',
    'javascript with slashes' => 'javascript://example.com/%0Aalert(1)',
    'data' => 'data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg==',
    'ftp' => 'ftp://files.example.com/timesheet.xlsx',
    'file' => 'file:///etc/passwd',
    'mailto' => 'mailto:pic@worder.test',
    'relative path' => '/work-orders/1',
    'protocol-relative' => '//evil.example.com/x',
    'no host' => 'https:///timesheet.xlsx',
    'credentials' => 'https://user:secret@example.com/x',
    'whitespace' => 'https://example.com/a b',
    'control character' => "https://example.com/\u{0007}",
]);

it('refuses links over the length limit', function () {
    $base = 'https://example.com/';

    expect(linkPasses($base.str_repeat('a', 2048 - strlen($base))))->toBeTrue()
        ->and(linkPasses($base.str_repeat('a', 2049 - strlen($base))))->toBeFalse();
});

it('accepts any host while no domain is configured', function () {
    config(['work_order.daily_reports.links.domains' => []]);

    expect(linkPasses('https://docs.google.com/spreadsheets/d/1'))->toBeTrue();
});

it('limits links to the configured domains and their subdomains', function (string $url, bool $passes) {
    config(['work_order.daily_reports.links.domains' => ['sharepoint.com', '1drv.ms']]);

    expect(linkPasses($url))->toBe($passes);
})->with([
    'subdomain' => ['https://unggul.sharepoint.com/sites/wo', true],
    'exact host' => ['https://1drv.ms/x/s!Ab', true],
    'upper-case host' => ['https://UNGGUL.SharePoint.com/x', true],
    'other host' => ['https://docs.google.com/x', false],
    'suffix without dot' => ['https://evilsharepoint.com/x', false],
    'domain as a subdomain of another' => ['https://sharepoint.com.evil.example/x', false],
]);
