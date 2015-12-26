<?php

use Braintree\Configuration;
use Carbon\Carbon;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Database\Eloquent\Model as Eloquent;

class CashierTest extends PHPUnit_Framework_TestCase
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

        $db = new DB;
        $db->addConnection([
            'driver' => 'sqlite',
            'database' => ':memory:',
        ]);
        $db->bootEloquent();
        $db->setAsGlobal();

        $this->schema()->create('users', function ($table) {
            $table->increments('id');
            $table->string('email');
            $table->string('name');
            $table->string('braintree_id')->nullable();
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

    /**
     * Tests.
     */
    public function testSubscriptionsCanBeCreated()
    {
        $user = User::create([
            'email' => 'john@example.com',
            'name' => 'John Doe',
        ]);

        // Create Subscription
        $user->newSubscription('main', 'monthly-10-1')->create($this->getTestToken());

        $this->assertEquals(1, count($user->subscriptions));
        $this->assertNotNull($user->subscription('main')->braintree_id);

        $this->assertTrue($user->subscribed('main'));
        $this->assertTrue($user->subscribed('main', 'monthly-10-1'));
        $this->assertFalse($user->subscribed('main', 'monthly-10-2'));
        $this->assertTrue($user->subscription('main')->active());
        $this->assertFalse($user->subscription('main')->cancelled());
        $this->assertFalse($user->subscription('main')->onGracePeriod());

        // Cancel Subscription
        $subscription = $user->subscription('main');
        $subscription->cancel();

        $this->assertTrue($subscription->active());
        $this->assertTrue($subscription->cancelled());
        $this->assertTrue($subscription->onGracePeriod());

        // Modify Ends Date To Past
        $oldGracePeriod = $subscription->ends_at;
        $subscription->fill(['ends_at' => Carbon::now()->subDays(5)])->save();

        $this->assertFalse($subscription->active());
        $this->assertTrue($subscription->cancelled());
        $this->assertFalse($subscription->onGracePeriod());

        $subscription->fill(['ends_at' => $oldGracePeriod])->save();

        // Resume Subscription
//        $subscription->resume();
//        We can't resume canceled subscriptions, that's why we create a new one
        $user->newSubscription('main', 'monthly-10-1')->create();

        $subscription = $user->fresh()->subscription('main');

        $this->assertTrue($subscription->active());
        $this->assertFalse($subscription->cancelled());
        $this->assertFalse($subscription->onGracePeriod());

        // Swap Plan
        $subscription->swap('monthly-10-2');

        $this->assertEquals('monthly-10-2', $subscription->braintree_plan);
        $this->assertTrue($subscription->active());
        $this->assertFalse($subscription->cancelled());
        $this->assertFalse($subscription->onGracePeriod());
        $this->assertFalse($user->fresh()->subscribed('main', 'monthly-10-1'));

        // Invoice Tests
        $invoice = $user->invoices(true)[1];

        $this->assertEquals('$10.00', $invoice->total());
        $this->assertFalse($invoice->hasDiscount());
        $this->assertFalse($invoice->hasStartingBalance());
        $this->assertNull($invoice->coupon());
        $this->assertInstanceOf(Carbon::class, $invoice->date());
    }

    public function test_creating_subscription_with_coupons()
    {
        $user = User::create([
            'email' => 'john@example.com',
            'name' => 'John Doe',
        ]);

        // Create Subscription
        $user->newSubscription('main', 'monthly-10-1')
                ->withCoupon('coupon-1')->create($this->getTestToken());

        $subscription = $user->subscription('main');

        $this->assertTrue($user->subscribed('main'));
        $this->assertTrue($user->subscribed('main', 'monthly-10-1'));
        $this->assertFalse($user->subscribed('main', 'monthly-10-2'));
        $this->assertTrue($subscription->active());
        $this->assertFalse($subscription->cancelled());
        $this->assertFalse($subscription->onGracePeriod());

        // Invoice Tests
        $invoice = $user->invoices()[0];

        $this->assertTrue($invoice->hasDiscount());
        $this->assertEquals('$5.00', $invoice->total());
        $this->assertEquals('$5.00', $invoice->amountOff());
        $this->assertEquals('50%', $invoice->percentOff());
    }

    public function test_creating_subscription_with_percentage_coupons()
    {
        $user = User::create([
            'email' => 'john@example.com',
            'name' => 'John Doe',
        ]);

        // Create Subscription
        $user->newSubscription('main', 'monthly-10-1')
            ->withCoupon('coupon-1', true)->create($this->getTestToken());

        $subscription = $user->subscription('main');

        $this->assertTrue($user->subscribed('main'));
        $this->assertTrue($user->subscribed('main', 'monthly-10-1'));
        $this->assertFalse($user->subscribed('main', 'monthly-10-2'));
        $this->assertTrue($subscription->active());
        $this->assertFalse($subscription->cancelled());
        $this->assertFalse($subscription->onGracePeriod());

        // Invoice Tests
        $invoice = $user->invoices()[0];

        $this->assertTrue($invoice->hasDiscount());
        $this->assertEquals('$9.50', $invoice->total());
        $this->assertEquals('$0.50', $invoice->amountOff());
        $this->assertEquals('5%', $invoice->percentOff());
    }

    public function test_creating_subscription_with_trial()
    {
        $user = User::create([
            'email' => 'john@example.com',
            'name' => 'John Doe',
        ]);

        // Create Subscription
        $user->newSubscription('main', 'monthly-10-1')
                ->trialDays(7)->create($this->getTestToken());

        $subscription = $user->subscription('main');

        $this->assertTrue($subscription->active());
        $this->assertTrue($subscription->onTrial());
        $this->assertEquals(Carbon::today()->addDays(7)->day, $subscription->trial_ends_at->day);

        // Cancel Subscription
        $subscription->cancel();

        $this->assertTrue($subscription->active());
        $this->assertTrue($subscription->onGracePeriod());
    }

    public function test_applying_coupons_to_existing_customers()
    {
        $user = User::create([
            'email' => 'john@example.com',
            'name' => 'John Doe',
        ]);

        // Create Subscription
        $user->newSubscription('main', 'monthly-10-1')
                ->create($this->getTestToken());

        $subscription = $user->subscription('main');

        $subscription->applyCoupon('coupon-1');

        $this->assertEquals('coupon-1', $subscription->asBraintreeSubscription()->discounts[0]->id);
    }

    protected function getTestToken()
    {
        return 'fake-valid-country-of-issuance-usa-nonce';
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
