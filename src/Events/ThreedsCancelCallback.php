<?php

namespace Iyzico\IyzipayLaravel\Events;

use Iyzico\IyzipayLaravel\Models\Transaction;

class ThreedsCancelCallback
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