<?php

use Braintree\Configuration;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Database\Eloquent\Model as Eloquent;

abstract class BaseTest extends PHPUnit_Framework_TestCase
{
    public static function setUpBeforeClass()
    {
        if (file_exists(__DIR__.'/../.env')) {
            $dotenv = new Dotenv\Dotenv(__DIR__.'/../');
            $dotenv->load();
        }
    }

    public function setUp()
    {
        Eloquent::unguard();

        $db = new DB();
        $db->addConnection([
            'driver'   => 'sqlite',
            'database' => ':memory:',
        ]);
        $db->bootEloquent();
        $db->setAsGlobal();

        $this->schema()->create('users', function ($table) {
            $table->increments('id');
            $table->string('email');
            $table->string('name');
            $table->string('braintree_id')->nullable();
            $table->string('payment_type', 25)->nullable();
            $table->string('card_brand')->nullable();
            $table->string('card_last_four')->nullable();
            $table->timestamps();
        });

        $this->schema()->create('subscriptions', function ($table) {
            $table->increments('id');
            $table->integer('user_id');
            $table->string('name');
            $table->string('braintree_id');
            $table->string('braintree_plan');
            $table->integer('quantity');
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamps();
        });

        Configuration::environment(getenv('BRAINTREE_ENV'));
        Configuration::merchantId(getenv('BRAINTREE_MERCHANT_ID'));
        Configuration::publicKey(getenv('BRAINTREE_PUBLIC_KEY'));
        Configuration::privateKey(getenv('BRAINTREE_PRIVATE_KEY'));
    }

    public function tearDown()
    {
        $this->schema()->drop('users');
        $this->schema()->drop('subscriptions');
    }

    protected function getVisaToken()
    {
        return 'fake-valid-country-of-issuance-usa-nonce';
    }

    protected function getMasterCardToken()
    {
        return 'fake-valid-mastercard-nonce';
    }

    protected function getPaypalToken()
    {
        return 'fake-paypal-future-nonce';
    }

    /**
     * Schema Helpers.
     */
    protected function schema()
    {
        return $this->connection()->getSchemaBuilder();
    }

    protected function connection()
    {
        return Eloquent::getConnectionResolver()->connection();
    }
}

class User extends Eloquent
{
    use LimeDeck\CashierBraintree\Billable;
}
