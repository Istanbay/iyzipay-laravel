<?php
/**
 * Created by PhpStorm.
 * User: can
 * Date: 27/10/2017
 * Time: 01:33
 */

namespace Iyzico\IyzipayLaravel\Controller;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Iyzico\IyzipayLaravel\IyzipayLaravel;
use Illuminate\Support\Facades\Redirect;
use Iyzico\IyzipayLaravel\Exceptions\Threeds\ThreedsCallbackException;

class CallbackController extends Controller
{

	public function threedsCallback (Request $request)
	{

		if($request->status != 'success') {
			app( IyzipayLaravel::class )->cancelThreedsPayment( $request );
			return redirect()->route( 'iyzico.callback' )
			                 ->with( ['request' => $request->all()] );
		}

		$transaction = app( IyzipayLaravel::class )->threedsPayment( $request );

		return redirect()->route( 'iyzico.callback' )
		                 ->with( ['request' => $request->all(), 'transaction' => $transaction] );
	}


	public function bkmCallback (Request $request)
	{

		// TODO: BKM entegrasyonu
	}

}