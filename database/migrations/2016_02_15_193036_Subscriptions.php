<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class Subscriptions extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('users', function ($table) {
            $table->string('braintree_id')->nullable()->after('remember_token');
            $table->string('payment_type', 25)->nullable()->after('braintree_id');
            $table->string('card_brand')->nullable()->after('payment_type');
            $table->string('card_last_four')->nullable()->after('card_brand');
        });


        Schema::create('subscriptions', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('user_id');
            $table->string('name');
            $table->string('braintree_id');
            $table->string('braintree_plan');
            $table->integer('quantity');
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->text('custom_properties');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('users', function($table){
            $table->dropColumn('braintree_id');
            $table->dropColumn('payment_type');
            $table->dropColumn('card_brand');
            $table->dropColumn('card_last_four');
        });
        Schema::drop('subscriptions');
    }
}
