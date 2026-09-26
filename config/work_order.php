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

];
