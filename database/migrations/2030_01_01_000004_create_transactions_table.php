<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateTransactionsTable extends Migration
{
    public function up()
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->increments('id');
            $table->string('billable_id')->index();
            $table->unsignedInteger('credit_card_id')->nullable();
            $table->foreign('credit_card_id')->references('id')->on('credit_cards');
            $table->unsignedInteger('subscription_id')->nullable();
            $table->foreign('subscription_id')->references('id')->on('subscriptions');
            $table->double('amount');
            $table->string('currency', 3)->default('TRY');
            $table->longText('products')->nullable();
            $table->string('iyzipay_key')->nullable();
            $table->longText('refunds')->nullable();
            $table->boolean('status')->nullable();
            $table->longText('error')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->timestamps();
	        $table->softDeletes();
        });
    }

    public function down()
    {
        Schema::dropIfExists('transactions');
    }
}
