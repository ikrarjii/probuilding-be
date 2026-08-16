<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return response()->json([
        'name' => 'ProBuild INTIM Registration API',
        'health' => url('/up'),
    ]);
});
