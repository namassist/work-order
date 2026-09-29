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
        'bast' => [
            'max_files' => (int) env('WO_BAST_MAX_FILES', 5),
            'max_size_kb' => (int) env('WO_ATTACHMENT_MAX_SIZE_KB', 10240),
        ],
        'bukti_bayar' => [
            'max_files' => (int) env('WO_PAYMENT_PROOF_MAX_FILES', 5),
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
    | Files in comments: inline images (JPG, PNG, WEBP) and documents (every
    | attachment type), each with its own per-comment count and size limit.
    | Files are uploaded before the comment is posted and wait as pending
    | uploads of their uploader on the work order until a comment claims
    | them; each user may have `pending_uploads.max_files` waiting per work
    | order, and `work-orders:prune-comment-uploads` (hourly) deletes the
    | ones older than `prune_after_hours`.
    |
    */

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

];
