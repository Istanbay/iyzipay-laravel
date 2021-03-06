<?php

namespace Iyzico\IyzipayLaravel\Traits;

use Iyzico\IyzipayLaravel\Exceptions\Bkm\BkmCreateException;
use Iyzico\IyzipayLaravel\Exceptions\Bkm\BkmInitializeException;
use Iyzico\IyzipayLaravel\Exceptions\Threeds\ThreedsCreateException;
use Iyzico\IyzipayLaravel\Exceptions\Threeds\ThreedsInitializeException;
use Iyzico\IyzipayLaravel\Exceptions\Fields\TransactionFieldsException;
use Iyzico\IyzipayLaravel\Exceptions\Transaction\TransactionSaveException;
use Iyzico\IyzipayLaravel\Exceptions\Transaction\TransactionVoidException;
use Iyzico\IyzipayLaravel\Models\CreditCard;
use Iyzico\IyzipayLaravel\Models\Transaction;
use Iyzico\IyzipayLaravel\PayableContract as Payable;
use Iyzico\IyzipayLaravel\ProductContract;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Iyzipay\Model\Address;
use Iyzipay\Model\BasketItem;
use Iyzipay\Model\BkmInitialize;
use Iyzipay\Model\Buyer;
use Iyzipay\Model\Cancel;
use Iyzipay\Model\Currency;
use Iyzipay\Model\Payment;
use Iyzipay\Model\PaymentCard;
use Iyzipay\Model\PaymentChannel;
use Iyzipay\Model\PaymentGroup;
use Iyzipay\Model\ThreedsInitialize;
use Iyzipay\Model\ThreedsPayment;
use Iyzipay\Options;
use Iyzipay\Request\CreateCancelRequest;
use Iyzipay\Request\CreatePaymentRequest;
use Iyzipay\Request\CreateThreedsPaymentRequest;
use Iyzipay\Request\CreateBkmInitializeRequest;

trait PreparesTransactionRequest
{

    /**
     * Validation for the transaction
     *
     * @param $attributes
     */
    protected function validateTransactionFields($attributes): void
    {
        $totalPrice = 0;
        foreach ($attributes['products'] as $product) {
            if (! $product instanceof ProductContract) {
                throw new TransactionFieldsException();
            }
            $totalPrice += $product->getPrice();
        }

        $v = Validator::make($attributes, [
            'installment' => 'required|numeric|min:1',
            'currency'    => 'required|in:' . implode(',', [
                    Currency::TL,
                    Currency::EUR,
                    Currency::GBP,
                    Currency::IRR,
                    Currency::USD
                ]),
            'paid_price'  => 'numeric|max:' . $totalPrice
        ]);

        if ($v->fails()) {
            throw new TransactionFieldsException();
        }
    }

    /**
     * Creates transaction on iyzipay.
     *
     * @param Payable $payable
     * @param CreditCard $creditCard
     * @param array $attributes
     * @param bool $subscription
     *
     * @return Payment
     * @throws TransactionSaveException
     */
    protected function createTransactionOnIyzipay(
        Payable $payable,
        CreditCard $creditCard,
        array $attributes,
        $subscription = false
    ): Payment {
        $this->validateTransactionFields($attributes);
        $paymentRequest = $this->createPaymentRequest($attributes, $subscription);
        $paymentRequest->setPaymentCard($this->preparePaymentCard($payable, $creditCard));
        $paymentRequest->setBuyer($this->prepareBuyer($payable));
        $paymentRequest->setShippingAddress($this->prepareAddress($payable, 'shippingAddress'));
        $paymentRequest->setBillingAddress($this->prepareAddress($payable, 'billingAddress'));
        $paymentRequest->setBasketItems($this->prepareBasketItems($attributes['products']));

        try {
            $payment = Payment::create($paymentRequest, $this->getOptions());
        } catch (\Exception $e) {
            throw new TransactionSaveException();
        }

        unset($paymentRequest);

        if ($payment->getStatus() != 'success') {
            throw new TransactionSaveException($payment->getErrorMessage());
        }

        return $payment;
    }


	/**
	 * @param Payable    $payable
	 * @param CreditCard $creditCard
	 * @param array      $attributes
	 * @param bool       $subscription
	 *
	 * @return ThreedsInitialize
	 * @throws ThreedsInitializeException
	 */
    protected function initializeThreedsOnIyzipay(
        Payable $payable,
        CreditCard $creditCard,
        array $attributes,
        $subscription = false
    ): ThreedsInitialize {
        $this->validateTransactionFields($attributes);
        $paymentRequest = $this->createPaymentRequest($attributes, $subscription);
        $paymentRequest->setPaymentCard($this->preparePaymentCard($payable, $creditCard));
        $paymentRequest->setBuyer($this->prepareBuyer($payable));
        $paymentRequest->setShippingAddress($this->prepareAddress($payable, 'shippingAddress'));
        $paymentRequest->setBillingAddress($this->prepareAddress($payable, 'billingAddress'));
        $paymentRequest->setBasketItems($this->prepareBasketItems($attributes['products']));
        session()->flash('iyzico.products', $attributes['products']);

        try {
            $threedsInitialize = ThreedsInitialize::create($paymentRequest, $this->getOptions());
        } catch (\Exception $e) {
            throw new ThreedsInitializeException();
        }

        unset($paymentRequest);

        if ($threedsInitialize->getStatus() != 'success') {
            throw new ThreedsInitializeException($threedsInitialize->getErrorMessage());
        }

        return $threedsInitialize;
    }

	protected function initializeBKMOnIyzipay(
		Payable $payable,
		array $attributes,
		$subscription = false
	): BkmInitialize {
		$this->validateTransactionFields($attributes);
		$bkmRequest = $this->createBkmInitializeRequest($attributes, $subscription);
		$bkmRequest->setBuyer($this->prepareBuyer($payable));
		$bkmRequest->setShippingAddress($this->prepareAddress($payable, 'shippingAddress'));
		$bkmRequest->setBillingAddress($this->prepareAddress($payable, 'billingAddress'));
		$bkmRequest->setBasketItems($this->prepareBasketItems($attributes['products']));

		session()->flash('iyzico.products', $attributes['products']);

//		dd($bkmRequest);
		try {
			$bkmInitialize = BkmInitialize::create($bkmRequest, $this->getOptions());
		} catch (\Exception $e) {
			throw new BkmInitializeException();
		}

		unset($bkmRequest);

		if ($bkmInitialize->getStatus() != 'success') {
			throw new BkmInitializeException($bkmInitialize->getErrorMessage());
		}

		return $bkmInitialize;
	}


	/**
     * @param Transaction $transaction
     *
     * @return Cancel
     * @throws TransactionVoidException
     */
    protected function createCancelOnIyzipay(Transaction $transaction): Cancel
    {
        $cancelRequest = $this->prepareCancelRequest($transaction->iyzipay_key);

        try {
            $cancel = Cancel::create($cancelRequest, $this->getOptions());
        } catch (\Exception $e) {
            throw new TransactionVoidException();
        }

        unset($cancelRequest);

        if ($cancel->getStatus() != 'success') {
            throw new TransactionVoidException($cancel->getErrorMessage());
        }

        return $cancel;
    }

    /**
     * Prepares create payment request class for iyzipay.
     *
     * @param array $attributes
     * @param bool $subscription
     * @return CreatePaymentRequest
     */
    private function createPaymentRequest(array $attributes, $subscription = false): CreatePaymentRequest
    {
        $paymentRequest = new CreatePaymentRequest();
        $paymentRequest->setLocale($this->getLocale());

        $totalPrice = 0;
        foreach ($attributes['products'] as $product) {
            $totalPrice += $product->getPrice();
        }

        $paymentRequest->setPrice($totalPrice);
        $paymentRequest->setPaidPrice($totalPrice); // @todo this may change
        $paymentRequest->setCurrency($attributes['currency']);
        $paymentRequest->setInstallment($attributes['installment']);
        $paymentRequest->setPaymentChannel(PaymentChannel::WEB);
        $paymentRequest->setPaymentGroup(($subscription) ? PaymentGroup::SUBSCRIPTION : PaymentGroup::PRODUCT);
        if(array_key_exists('transactionModel', $attributes)) {
	        $paymentRequest->setConversationId( $attributes['transactionModel']->id );
        }
	    if(array_key_exists('callback', $attributes)) {
		    $paymentRequest->setCallbackUrl( $attributes['callback'] );
	    }

        return $paymentRequest;
    }

    /**
     * Prepares create payment request class for iyzipay.
     *
     * @param array $attributes
     * @param bool $subscription
     * @return CreatePaymentRequest
     */
    private function createBkmInitializeRequest(array $attributes, $subscription = false): CreateBkmInitializeRequest
    {
        $paymentRequest = new createBkmInitializeRequest();
        $paymentRequest->setLocale($this->getLocale());

        $totalPrice = 0;
        foreach ($attributes['products'] as $product) {
            $totalPrice += $product->getPrice();
        }

        $paymentRequest->setPrice($totalPrice);
        $paymentRequest->setCurrency($attributes['currency']);
        $paymentRequest->setPaymentGroup(($subscription) ? PaymentGroup::SUBSCRIPTION : PaymentGroup::PRODUCT);
        if(in_array('transactionModel', $attributes)) {
	        $paymentRequest->setConversationId( $attributes['transactionModel']->id );
        }
	    $paymentRequest->setCallbackUrl( $attributes['callback'] );

        return $paymentRequest;
    }

    private function createThreedsPayment(Request $request): ThreedsPayment
    {
	    $threedsPaymentRequest = $this->prepareThreedsPaymentRequest($request->conversationId, $request->paymentId, $request->conversationData);

	    try {
		    $threedsPayment = ThreedsPayment::create( $threedsPaymentRequest, $this->getOptions() );
	    } catch (\Exception $e) {
		    throw new ThreedsCreateException();
	    }

	    unset($threedsPaymentRequest);

	    if ($threedsPayment->getStatus() != 'success') {
		    throw new ThreedsCreateException($threedsPayment->getErrorMessage());
	    }

	    return $threedsPayment;
    }

    /**
     * Prepares cancel request class for iyzipay
     *
     * @param $iyzipayKey
     * @return CreateCancelRequest
     */
    private function prepareCancelRequest($iyzipayKey): CreateCancelRequest
    {
        $cancelRequest = new CreateCancelRequest();
        $cancelRequest->setPaymentId($iyzipayKey);
        $cancelRequest->setIp(request()->ip());
        $cancelRequest->setLocale($this->getLocale());

        return $cancelRequest;
    }

    /**
     * Prepares payment card class for iyzipay
     *
     * @param Payable $payable
     * @param CreditCard $creditCard
     * @return PaymentCard
     */
    private function preparePaymentCard(Payable $payable, CreditCard $creditCard): PaymentCard
    {
        $paymentCard = new PaymentCard();
        $paymentCard->setCardUserKey($payable->iyzipay_key);
        $paymentCard->setCardToken($creditCard->token);

        return $paymentCard;
    }

    /**
     * Prepares buyer class for iyzipay
     *
     * @param Payable $payable
     * @return Buyer
     */
    private function prepareBuyer(Payable $payable): Buyer
    {
        $buyer = new Buyer();
        $buyer->setId($payable->getKey());

        $billFields = $payable->bill_fields;
        $buyer->setName($billFields->firstName);
        $buyer->setSurname($billFields->lastName);
        $buyer->setEmail($billFields->email);
        $buyer->setGsmNumber($billFields->mobileNumber);
        $buyer->setIdentityNumber($billFields->identityNumber);
        $buyer->setCity($billFields->billingAddress->city);
        $buyer->setCountry($billFields->billingAddress->country);
        $buyer->setRegistrationAddress($billFields->billingAddress->address);

        return $buyer;
    }

    /**
     * Prepares address class for iyzipay.
     *
     * @param Payable $payable
     * @param string $type
     * @return Address
     */
    private function prepareAddress(Payable $payable, $type = 'shippingAddress'): Address
    {
        $address = new Address();

        $billFields = $payable->bill_fields;
        $address->setContactName($billFields->firstName . ' ' . $billFields->lastName);
        $address->setCountry($billFields->$type->country);
        $address->setAddress($billFields->$type->address);
        $address->setCity($billFields->$type->city);

        return $address;
    }

    /**
     * Prepares basket items class for iyzipay.
     *
     * @param Collection $products
     * @return array
     */
    private function prepareBasketItems(Collection $products): array
    {
        $basketItems = [];

        foreach ($products as $product) {
            $item = new BasketItem();
            $item->setId($product->getKey());
            $item->setName($product->getName());
            $item->setCategory1($product->getCategory());
            $item->setPrice($product->getPrice());
            $item->setItemType($product->getType());
            $basketItems[] = $item;
        }

        return $basketItems;
    }

	protected function prepareThreedsPaymentRequest ( $conversationId, $iyzipayKey, $conversationData): CreateThreedsPaymentRequest
	{
		$request = new CreateThreedsPaymentRequest();

		$request->setLocale($this->getLocale());
		$request->setConversationId($conversationId);
		$request->setPaymentId($iyzipayKey);
		$request->setConversationData($conversationData);

		return $request;
	}

    abstract protected function getLocale(): string;

    abstract protected function getOptions(): Options;
}
