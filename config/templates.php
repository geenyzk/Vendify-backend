<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Extra template variables
    |--------------------------------------------------------------------------
    |
    | Add supported {{placeholders}} without editing App\Support\TemplateVariables.
    | These merge on top of the built-in catalogue and are offered in the admin
    | template editor and to the AI Manager's template tools.
    |
    | IMPORTANT: adding a name here only makes it "known" (no unknown-variable
    | warning). The value must still be supplied by the code that sends the
    | template, or it will render literally as "{{name}}" in the message.
    |
    |   'global' => ['company_slogan' => 'Marketing tagline'],
    |   'events' => [
    |       'purchase' => ['delivery_eta' => 'Estimated delivery time'],
    |   ],
    |
    */

    'variables' => [
        'global' => [],
        'events' => [],
    ],

];
