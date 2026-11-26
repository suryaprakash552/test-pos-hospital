<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Legacy web inventory writes
    |--------------------------------------------------------------------------
    |
    | The legacy web panel moves stock by writing `products.productStock` and
    | never touches the purchase lots the rest of the system sells from, so its
    | writes drive the two apart. They are refused unless this is turned on for
    | a deployment that still depends on them.
    |
    | Off by default, deliberately: the safe state is the one you get by doing
    | nothing.
    |
    */

    'allow_legacy_web_writes' => env('ALLOW_LEGACY_WEB_WRITES', false),

];
