<?php

namespace Iyzico\IyzipayLaravel\Events;

use Iyzico\IyzipayLaravel\Models\Transaction;

class ThreedsCallback
{

	/**
	 * @var Transaction
	 */
    public $transaction;

    public function __construct(Transaction $transaction)
    {
        $this->transaction = $transaction;
    }

}