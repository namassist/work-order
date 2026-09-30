<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Work Order Number Format
    |--------------------------------------------------------------------------
    |
    | The number a work order gets when it is submitted. Tokens:
    | {DEPT_CODE}, {YYYY}, {YY}, {MM}, and {SEQ:n} (sequence, zero-padded to
    | n digits; required). Dates are taken in the display timezone.
    |
    | Each distinct rendering of the other tokens has its own counter, so the
    | default format restarts at 0001 every month for every department.
    |
    */

    'number_format' => env('WO_NUMBER_FORMAT', 'WO/{DEPT_CODE}/{YYYY}/{MM}/{SEQ:4}'),

    /*
    |--------------------------------------------------------------------------
    | Attachments
    |--------------------------------------------------------------------------
    |
    | Limits per attachment collection. File types are PDF, JPG, PNG, WEBP,
    | DOCX, and XLSX (App\Enums\AttachmentType); invoices and proof of
    | payment take PDF and images only (WorkOrder::attachmentCollections()). The size limit also needs
    | PHP's upload_max_filesize/post_max_size and the web server's body limit
    | (nginx client_max_body_size) to be at least as large.
    |
    */

    'attachments' => [
        'dokumen' => [
            'max_files' => (int) env('WO_ATTACHMENT_MAX_FILES', 10),
            'max_size_kb' => (int) env('WO_ATTACHMENT_MAX_SIZE_KB', 10240),
        ],
        'invoice' => [
            'max_files' => (int) env('WO_INVOICE_MAX_FILES', 5),
            'max_size_kb' => (int) env('WO_ATTACHMENT_MAX_SIZE_KB', 10240),
        ],
        'bukti_bayar' => [
            'max_files' => (int) env('WO_PAYMENT_PROOF_MAX_FILES', 5),
            'max_size_kb' => (int) env('WO_ATTACHMENT_MAX_SIZE_KB', 10240),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Payment Track
    |--------------------------------------------------------------------------
    |
    | Segregation of duties (FLOW.md §10): when on, whoever issued or last
    | corrected an invoice may not confirm its payment. Off by default,
    | because the process has a single Finance lane.
    |
    */

    'payment' => [
        'segregation_of_duties' => (bool) env('WO_PAYMENT_SEGREGATION', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Export
    |--------------------------------------------------------------------------
    |
    | The most work orders one Excel export of the list may hold. Above it,
    | the user is asked to narrow the filters.
    |
    */

    'export' => [
        'max_rows' => (int) env('WO_EXPORT_MAX_ROWS', 5000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Comments
    |--------------------------------------------------------------------------
    |
    | PROVISIONAL: how long after posting the author may still edit or
    | delete a comment, in minutes.
    |
    | Files in comments: inline images (JPG, PNG, WEBP) and documents (every
    | attachment type), each with its own per-comment count and size limit.
    | Files are uploaded before the comment is posted and wait as pending
    | uploads of their uploader on the work order until a comment claims
    | them; each user may have `pending_uploads.max_files` waiting per work
    | order, and `work-orders:prune-comment-uploads` (hourly) deletes the
    | ones older than `prune_after_hours`.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Daily Reports
    |--------------------------------------------------------------------------
    |
    | Daily progress reports during Pelaksanaan (FLOW.md §7), read through
    | App\Support\DailyReports\ReportCalendar. PROVISIONAL defaults until the
    | business confirms them.
    |
    | working_days: ISO weekdays (1 = Monday … 7 = Sunday) a report is due.
    | holidays: ISO dates (Y-m-d) on which no report is due, e.g.
    |   "2026-12-25,2026-12-26". Reports may still be filed on any day.
    | cutoff: the time (H:i, display timezone) after which a working day
    |   without a report is flagged "Belum lapor".
    | backdate_working_days: how many working days back a report may be
    |   dated (0: today only).
    | edit_extra_days: a report may be edited until the end of the day it was
    |   created, plus this many calendar days.
    | files, links: per report. links.domains limits links to these hosts and
    |   their subdomains (e.g. "sharepoint.com,1drv.ms"); empty allows any.
    |
    */

    'daily_reports' => [
        'working_days' => array_values(array_map(intval(...), array_filter(explode(',', (string) env('WO_REPORT_WORKING_DAYS', '1,2,3,4,5')), is_numeric(...)))),
        'holidays' => array_values(array_filter(array_map(trim(...), explode(',', (string) env('WO_REPORT_HOLIDAYS', ''))))),
        'cutoff' => (string) env('WO_REPORT_CUTOFF', '17:00'),
        'backdate_working_days' => (int) env('WO_REPORT_BACKDATE_DAYS', 2),
        'edit_extra_days' => (int) env('WO_REPORT_EDIT_EXTRA_DAYS', 0),
        'note_max_length' => 1000,
        'files' => [
            'max_files' => (int) env('WO_REPORT_MAX_FILES', 5),
            'max_size_kb' => (int) env('WO_ATTACHMENT_MAX_SIZE_KB', 10240),
        ],
        'links' => [
            'max_links' => (int) env('WO_REPORT_MAX_LINKS', 5),
            'max_length' => 2048,
            'domains' => array_values(array_filter(array_map(fn (string $domain): string => mb_strtolower(trim($domain)), explode(',', (string) env('WO_REPORT_LINK_DOMAINS', ''))))),
        ],
    ],

    'comments' => [
        'edit_window_minutes' => (int) env('WO_COMMENT_EDIT_MINUTES', 15),
        'images' => [
            'max_files' => (int) env('WO_COMMENT_MAX_IMAGES', 10),
            'max_size_kb' => (int) env('WO_COMMENT_IMAGE_MAX_SIZE_KB', 5120),
        ],
        'documents' => [
            'max_files' => (int) env('WO_COMMENT_MAX_DOCUMENTS', 5),
            'max_size_kb' => (int) env('WO_ATTACHMENT_MAX_SIZE_KB', 10240),
        ],
        'pending_uploads' => [
            'max_files' => (int) env('WO_COMMENT_MAX_PENDING_UPLOADS', 20),
            'prune_after_hours' => (int) env('WO_COMMENT_UPLOAD_PRUNE_HOURS', 24),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | BAST
    |--------------------------------------------------------------------------
    |
    | The BAST (FLOW.md §8, §9). Its number is assigned when Rental submits
    | it. Tokens: {DEPT_CODE} (the requester department's code), {YYYY},
    | {YY}, {MM}, and {SEQ:n} (required). Dates are taken in the display
    | timezone, and each distinct rendering of the other tokens has its own
    | counter, so the default format restarts at 0001 every month.
    |
    | Template images (the letterhead) are PNG or JPEG only; they are embedded
    | in the PDF by the application, never fetched by the PDF engine.
    |
    */

    'bast' => [
        'number_format' => env('BAST_NUMBER_FORMAT', 'BAST/{YYYY}/{MM}/{SEQ:4}'),
        'template_max_html_bytes' => 131_072,
        // The deepest element nesting a template may have; bounds the sanitizer's cost.
        'template_max_depth' => 24,
        'images' => [
            'max_files' => (int) env('BAST_TEMPLATE_MAX_IMAGES', 3),
            'max_size_kb' => (int) env('BAST_TEMPLATE_IMAGE_MAX_SIZE_KB', 1024),
            // Pixels per side: a small PNG can declare a huge bitmap that the PDF engine would decode.
            'max_side_px' => 4000,
        ],
        'pdf_max_size_kb' => 20480,
    ],

];
