<?php

Route::group(['middleware' => 'web', 'prefix' => 'panel/addons', 'as' => 'panel.addons.', 'middleware' => ['web', 'role:admin'], 'namespace' => 'Modules\Stripe\Http\Controllers\Admin'], function(){
    Route::resource('stripe', 'StripeController');
});

Route::group(['prefix' => 'payments', 'as' => 'payments.', 'middleware' => ['web'], 'namespace' => 'Modules\Stripe\Http\Controllers'], function(){

    Route::get('stripe/{checkout_session}', 'PaymentController@index')->name('stripe.index');
    Route::post('stripe/{checkout_session}', 'PaymentController@store')->name('stripe.store');

});

Route::group(['prefix' => 'connect', 'as' => 'connect.', 'middleware' => ['web'], 'namespace' => 'Modules\Stripe\Http\Controllers'], function(){
    Route::resource('stripe', 'ConnectController');
});