<?php

namespace Iyzico\IyzipayLaravel;

use Illuminate\Http\Request;
use Iyzico\IyzipayLaravel\Exceptions\Card\PayableMustHaveCreditCardException;
use Iyzico\IyzipayLaravel\Exceptions\Fields\BillFieldsException;
use Iyzico\IyzipayLaravel\Exceptions\Card\CardRemoveException;
use Iyzico\IyzipayLaravel\Exceptions\Fields\CreditCardFieldsException;
use Iyzico\IyzipayLaravel\Exceptions\Transaction\TransactionSaveException;
use Iyzico\IyzipayLaravel\Exceptions\Transaction\TransactionVoidException;
use Iyzico\IyzipayLaravel\Exceptions\Iyzipay\IyzipayAuthenticationException;
use Iyzico\IyzipayLaravel\Exceptions\Iyzipay\IyzipayConnectionException;
use Iyzico\IyzipayLaravel\Exceptions\Threeds\ThreedsCreateException;
use Iyzico\IyzipayLaravel\Exceptions\Threeds\ThreedsInitializeException;
use Iyzico\IyzipayLaravel\Exceptions\Threeds\BkmCreateException;
use Iyzico\IyzipayLaravel\Exceptions\Threeds\BkmInitializeException;
use Iyzico\IyzipayLaravel\Models\CreditCard;
use Iyzico\IyzipayLaravel\Models\Transaction;
use Iyzico\IyzipayLaravel\Traits\ManagesPlans;
use Iyzico\IyzipayLaravel\Traits\PreparesCreditCardRequest;
use Iyzico\IyzipayLaravel\Traits\PreparesTransactionRequest;
use Iyzico\IyzipayLaravel\Events\ThreedsCancelCallback;
use Iyzico\IyzipayLaravel\Events\ThreedsCallback;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Iyzipay\Model\ApiTest;
use Iyzipay\Model\Payment;
use Iyzipay\Model\BkmInitialize;
use Iyzipay\Model\ThreedsInitialize;
use Iyzipay\Options;
use Iyzipay\Model\Locale;
use Iyzipay\Model\PaymentResource;
use Iyzico\IyzipayLaravel\PayableContract as Payable;

class IyzipayLaravel
{

	use PreparesCreditCardRequest, PreparesTransactionRequest, ManagesPlans;

	/**
	 * @var Options
	 */
	protected $apiOptions;


	/**
	 * IyzipayLaravel constructor.
	 *
	 * @throws IyzipayAuthenticationException
	 * @throws IyzipayConnectionException
	 */
	public function __construct ()
	{

		$this->initializeApiOptions();
		$this->checkApiOptions();
	}


	/**
	 * Adds credit card for billable & payable model.
	 *
	 * @param PayableContract $payable
	 * @param array           $attributes
	 *
	 * @return CreditCard
	 * @throws BillFieldsException
	 * @throws CreditCardFieldsException
	 */
	public function addCreditCard (Payable $payable, array $attributes = []): CreditCard
	{

		$this->validateBillable( $payable );
		$this->validateCreditCardAttributes( $attributes );

		$card = $this->createCardOnIyzipay( $payable, $attributes );

		$creditCardModel = new CreditCard( [
			                                   'alias'  => $card->getCardAlias(),
			                                   'number' => $card->getBinNumber(),
			                                   'token'  => $card->getCardToken(),
			                                   'bank'   => $card->getCardBankName()
		                                   ] );
		$payable->creditCards()->save( $creditCardModel );

		$payable->iyzipay_key = $card->getCardUserKey();
		$payable->save();

		return $creditCardModel;
	}


	/**
	 * Remove credit card for billable & payable model.
	 *
	 * @param CreditCard $creditCard
	 *
	 * @return bool
	 * @throws CardRemoveException
	 */
	public function removeCreditCard (CreditCard $creditCard): bool
	{

		$this->removeCardOnIyzipay( $creditCard );
		$creditCard->delete();

		return TRUE;
	}


	/**
	 * @param PayableContract $payable
	 * @param Collection      $products
	 * @param                 $currency
	 * @param                 $installment
	 * @param bool            $subscription
	 *
	 * @return Transaction $transactionModel
	 * @throws TransactionSaveException
	 */
	public function singlePayment (
		Payable $payable, Collection $products, CreditCard $creditCard, $currency, $installment, $subscription = FALSE
	): Transaction {

		// @todo: products variable can be a model

		$this->validateBillable( $payable );
		$this->validateHasCreditCard( $payable );
		$this->validateCreditCard( $payable, $creditCard );

		$messages = []; // @todo: imporove here
//		foreach($payable->creditCards as $creditCard) {
			try {
				$transactionModel = $this->createTransactionModel( $payable, $products, $creditCard );

				$transaction = $this->createTransactionOnIyzipay(
					$payable,
					$creditCard,
					compact( 'products', 'currency', 'installment', 'transactionModel' ),
					$subscription
				);

				return $this->storeTransactionModel( $transactionModel, $transaction, $payable, $products,
				                                     $creditCard );
			} catch (TransactionSaveException $e) {
				$messages[]               = $creditCard->number . ': ' . $e->getMessage();
				$transactionModel->status = FALSE;
				$transactionModel->error  = $creditCard->number . ': ' . $e->getMessage();
				$transactionModel->save();
//				continue;
			}
//		}

		throw new TransactionSaveException( implode( ', ', $messages ) );
	}


	/**
	 * @param PayableContract $payable
	 * @param Collection      $products
	 * @param CreditCard      $creditCard
	 * @param                 $currency
	 * @param                 $installment
	 * @param bool            $subscription
	 *
	 * @return ThreedsInitialize
	 * @throws PayableMustHaveCreditCardException
	 * @throws TransactionSaveException
	 */
	public function initializeThreeds (
		Payable $payable, Collection $products, CreditCard $creditCard, $currency, $installment, $subscription = FALSE
	): ThreedsInitialize {

		$this->validateBillable( $payable );
		$this->validateHasCreditCard( $payable );
		$this->validateCreditCard( $payable, $creditCard );

		$callback = route( 'threeds.callback' );

		$messages = []; // @todo: imporove here
		//		foreach ($payable->creditCards as $creditCard) {
		try {
			$transactionModel = $this->createTransactionModel( $payable, $products, $creditCard );

			$threedsInitialize = $this->initializeThreedsOnIyzipay(
				$payable,
				$creditCard,
				compact( 'products', 'currency', 'installment', 'callback', 'transactionModel' ),
				$subscription
			);

			return $threedsInitialize;
		} catch (ThreedsInitializeException $e) {
			$messages[]               = $creditCard->number . ': ' . $e->getMessage();
			$transactionModel->status = FALSE;
			$transactionModel->error  = $messages;
			$transactionModel->save();
			//				continue;
		}
		//		}

		throw new ThreedsInitializeException( implode( ', ', $messages ) );

	}

	/**
	 * @param Request $request
	 *
	 * @return Transaction
	 */
	public function threedsPayment (Request $request): Transaction
	{

		try {
			$transactionModel = Transaction::findOrFail( $request->conversationId );

			$threedsPayment = $this->createThreedsPayment( $request );

			$transactionModel = $this->storeTransactionModel( $transactionModel, $threedsPayment,
			                                                           $transactionModel->billable,
			                                                           session()->get('iyzico.products'),
			                                                           $transactionModel->creditCard );
			$this->storeSubscription($transactionModel);

			event( new ThreedsCallback( $transactionModel ) );

			return $transactionModel;
		} catch (ThreedsCreateException $e) {
			$transactionModel->status = FALSE;
			$transactionModel->error  = $transactionModel->creditCard->number . ': ' . $e->getMessage();
			$transactionModel->save();

			$this->removeSubscription();

			return $transactionModel;
		}

	}

	public function initializeBkm (
		Payable $payable, Collection $products, $currency, $installment, $subscription = FALSE
	): BkmInitialize {

		$this->validateBillable( $payable );

		$callback = route( 'bkm.callback' );

		$messages = []; // @todo: imporove here
		try {
			$transactionModel = $this->createTransactionModel( $payable, $products );

			$bkmInitialize = $this->initializeBKMOnIyzipay(
				$payable,
				compact( 'products', 'currency', 'installment', 'callback', 'transactionModel' ),
				$subscription
			);

			return $bkmInitialize;

		} catch (BkmInitializeException $e) {
			$messages[]               = $e->getMessage();
			$transactionModel->status = FALSE;
			$transactionModel->error  = $messages;
			$transactionModel->save();
		}

		throw new TransactionSaveException( implode( ', ', $messages ) );

	}


	/**
	 * @param Transaction $transactionModel
	 *
	 * @return Transaction
	 * @throws TransactionVoidException
	 */
	public function cancel (Transaction $transactionModel): Transaction
	{

		$cancel = $this->createCancelOnIyzipay( $transactionModel );

		$transactionModel->voided_at = Carbon::now();
		$refunds                     = $transactionModel->refunds;
		$refunds[]                   = [
			'type'        => 'void',
			'amount'      => $cancel->getPrice(),
			'iyzipay_key' => $cancel->getPaymentId()
		];

		$transactionModel->refunds = $refunds;
		$transactionModel->save();

		return $transactionModel;
	}


	/**
	 * Initializing API options with the given credentials.
	 */
	private function initializeApiOptions ()
	{

		$this->apiOptions = new Options();
		$this->apiOptions->setBaseUrl( config( 'iyzipay.baseUrl' ) );
		$this->apiOptions->setApiKey( config( 'iyzipay.apiKey' ) );
		$this->apiOptions->setSecretKey( config( 'iyzipay.secretKey' ) );
	}


	/**
	 * Check if api options has been configured successfully.
	 *
	 * @throws IyzipayAuthenticationException
	 * @throws IyzipayConnectionException
	 */
	private function checkApiOptions ()
	{

		try {
			$check = ApiTest::retrieve( $this->apiOptions );
		} catch (\Exception $e) {
			throw new IyzipayConnectionException();
		}

		if($check->getStatus() != 'success') {
			throw new IyzipayAuthenticationException();
		}
	}


	/**
	 * @param PayableContract $payable
	 *
	 * @throws BillFieldsException
	 */
	private function validateBillable (Payable $payable): void
	{

		if( ! $payable->isBillable()) {
			throw new BillFieldsException();
		}
	}


	/**
	 * @param PayableContract $payable
	 *
	 * @throws PayableMustHaveCreditCardException
	 */
	private function validateHasCreditCard (Payable $payable): void
	{

		if($payable->creditCards->isEmpty()) {
			throw new PayableMustHaveCreditCardException();
		}
	}


	/**
	 * @param PayableContract $payable
	 * @param CreditCard      $creditCard
	 *
	 * @throws PayableMustHaveCreditCardException
	 */
	private function validateCreditCard (Payable $payable, CreditCard $creditCard): void
	{

		if($creditCard->owner->id != $payable->id) {
			throw new PayableMustHaveCreditCardException();
		}
	}


	/**
	 * @param Payment         $transaction
	 * @param PayableContract $payable
	 * @param Collection      $products
	 * @param CreditCard      $creditCard
	 *
	 * @return Transaction
	 */
	private function storeTransactionModel (
		Transaction $transactionModel,
		PaymentResource $transaction,
		Payable $payable,
		Collection $products,
		CreditCard $creditCard
	): Transaction {

		// TODO: $products ProductContract'tan türemesi gerekiyor. getKeyName vs yok callback'ten sonra array olarak geldiği için.
		$iyzipayProducts = [];
		foreach($transaction->getPaymentItems() as $paymentItem) {
			$iyzipayProducts[] = [
				'iyzipay_key' => $paymentItem->getPaymentTransactionId(),
				'paidPrice'   => $paymentItem->getPaidPrice(),
				'product'     => $products->where(
					$products[0]->getKeyName(),
					$paymentItem->getItemId()
				)->first()->toArray()
			];
		}

		$transactionModel->fill( [
			                         'amount'      => $transaction->getPaidPrice(),
			                         'products'    => $iyzipayProducts,
			                         'iyzipay_key' => $transaction->getPaymentId(),
			                         'currency'    => $transaction->getCurrency(),
			                         'status'      => TRUE,
		                         ] );
		//        $transactionModel = new Transaction();

		$transactionModel->creditCard()->associate( $creditCard );
		$payable->transactions()->save( $transactionModel );

		return $transactionModel->fresh();
	}


	/**
	 * @param Transaction $transaction
	 */
	private function storeSubscription (Transaction $transaction) {
		if(session()->has('iyzico.subscription')) {
			$subscription = session()->get('iyzico.subscription');
			$subscription->next_charge_at = $subscription->next_charge_at->addMonths(($subscription->plan->interval == 'yearly') ? 12 : 1);
			$subscription->save();

			$transaction->subscription()->associate($subscription);
			$transaction->save();
		}
	}


	/**
	 * @param PayableContract $payable
	 * @param Collection      $products
	 * @param CreditCard      $creditCard
	 *
	 * @return Transaction
	 */
	private function createTransactionModel (
		Payable $payable,
		Collection $products,
		$creditCard = NULL
	): Transaction {

		$totalPrice = 0;
		foreach($products as $product) {
			$totalPrice += $product->getPrice();
		}

		$transactionModel = new Transaction( [
			                                     'amount'   => $totalPrice,
			                                     'products' => $products,
		                                     ] );

		if(!empty($creditCard)) {
			$transactionModel->creditCard()->associate( $creditCard );
		}

		$payable->transactions()->save( $transactionModel );

		return $transactionModel->fresh();
	}


	/**
	 * @param Request $request
	 *
	 * @return Transaction
	 */
	public function cancelThreedsPayment (Request $request): Transaction
	{

		$transactionModel         = Transaction::findOrFail( $request->conversationId );
		$transactionModel->status = FALSE;
		$transactionModel->error  = 'Ödeme iptal edildi';
		$transactionModel->save();

		$this->removeSubscription();

		event( new ThreedsCancelCallback( $transactionModel ) );

		return $transactionModel;
	}

	protected function removeSubscription (): void
	{
		if(session()->has('iyzico.subscription')) {
			$subscription = session()->get('iyzico.subscription');
			$subscription->delete();
		}
	}

	/**
	 * @return string
	 */
	protected function getLocale (): string
	{

		return (config( 'app.locale' ) == 'tr') ? Locale::TR : Locale::EN;
	}


	/**
	 * @return Options
	 */
	protected function getOptions (): Options
	{

		return $this->apiOptions;
	}
}
