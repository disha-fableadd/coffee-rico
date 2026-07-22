<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Bulk Send Enabled
    |--------------------------------------------------------------------------
    |
    | When false, bulk message campaigns cannot be created and no WhatsApp
    | messages are sent via bulk send (no charges applied). Use this for
    | testing. When true, bulk send works normally.
    |
    */

    'bulk_send_enabled' => env('BULK_SEND_ENABLED', true),

];
