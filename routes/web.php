<?php

use Illuminate\Support\Facades\Route;


if (app()->environment('local')) {
    // Route::view('/swagger', 'swagger', ['swaggerPath' => '/docs.openapi']); // /docs.openapi is default route generate by scribe if add_routes is true 
    Route::view('/swagger', 'swagger', ['swaggerPath' => '/openapi']);
    Route::get('/openapi', function(\Request $req){
        return response()->file(storage_path('app/private/scribe/openapi.yaml'));
    });
}


Route::get('/{any}', function () {
    return view('app');
})->where('any', '.*');
