<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Self-Service Registration
    |--------------------------------------------------------------------------
    |
    | Public registration (FLOW.md §3). New accounts are pending until an
    | admin approves them on the Pendaftaran page. Turning this off answers
    | 404 on /register and hides the "Daftar" link on the login page: the
    | first response to a wave of spam registrations. Pending accounts stay
    | pending and can still be reviewed.
    |
    */

    'enabled' => (bool) env('REGISTRATION_ENABLED', true),

];
