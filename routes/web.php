<?php
use Illuminate\Support\Facades\Route;

Route::group( ['middleware' => 'web'], function() {

	Route::any( '/iyzipay/threeds/callback', 'Iyzico\IyzipayLaravel\Controller\CallbackController@threedsCallback' )
	     ->name( 'threeds.callback' );
	Route::any( '/iyzipay/bkm/callback', 'Iyzico\IyzipayLaravel\Controller\CallbackController@bkmCallback' )
	     ->name( 'bkm.callback' );
});