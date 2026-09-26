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
    | DOCX, and XLSX (App\Enums\AttachmentType). The size limit also needs
    | PHP's upload_max_filesize/post_max_size and the web server's body limit
    | (nginx client_max_body_size) to be at least as large.
    |
    */

    'attachments' => [
        'dokumen' => [
            'max_files' => (int) env('WO_ATTACHMENT_MAX_FILES', 10),
            'max_size_kb' => (int) env('WO_ATTACHMENT_MAX_SIZE_KB', 10240),
        ],
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
    */

    'comments' => [
        'edit_window_minutes' => (int) env('WO_COMMENT_EDIT_MINUTES', 15),
    ],

];
