<?php

use Carbon\Carbon;

class CashierTest extends \BaseTest
{
    /** @test */
    public function subscriptions_can_be_created()
    {
        $user = User::create([
            'email' => 'john@example.com',
            'name'  => 'John Doe',
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
        $this->assertNotNull($user->card_brand);
        $this->assertNotNull($user->card_last_four);

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

    /** @test */
    public function creating_subscription_with_coupons()
    {
        $user = User::create([
            'email' => 'john@example.com',
            'name'  => 'John Doe',
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

    /** @test */
    public function creating_subscription_with_percentage_coupons()
    {
        $user = User::create([
            'email' => 'john@example.com',
            'name'  => 'John Doe',
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

    /** @test */
    public function creating_subscription_with_trial()
    {
        $user = User::create([
            'email' => 'john@example.com',
            'name'  => 'John Doe',
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

    /** @test */
    public function applying_coupons_to_existing_customers()
    {
        $user = User::create([
            'email' => 'john@example.com',
            'name'  => 'John Doe',
        ]);

        // Create Subscription
        $user->newSubscription('main', 'monthly-10-1')
                ->create($this->getTestToken());

        $subscription = $user->subscription('main');

        $subscription->applyCoupon('coupon-1');

        $this->assertEquals('coupon-1', $subscription->asBraintreeSubscription()->discounts[0]->id);
        $this->assertEquals('5.00', $subscription->asBraintreeSubscription()->nextBillingPeriodAmount);
    }

    /** @test */
    public function obtaining_all_invoices_of_a_user()
    {
        $user = User::create([
            'email' => 'john@example.com',
            'name'  => 'John Doe',
        ]);

        // Create Subscription
        $user->newSubscription('main', 'monthly-10-1')->create($this->getTestToken());
        $user->newSubscription('another', 'monthly-10-2')->create();

        $this->assertEquals(2, count($user->subscriptions));

        // Invoice Tests
        $invoices = $user->invoices(true);

        $this->assertCount(2, $invoices);

        $invoice = $invoices[1];

        $this->assertEquals('$10.00', $invoice->total());
        $this->assertFalse($invoice->hasDiscount());
        $this->assertFalse($invoice->hasStartingBalance());
        $this->assertNull($invoice->coupon());
        $this->assertInstanceOf(Carbon::class, $invoice->date());
    }

    /** @test */
    public function user_can_update_their_payment_method()
    {
        $user = User::create([
            'email' => 'john@example.com',
            'name'  => 'John Doe',
        ]);

        $user->newSubscription('main', 'monthly-10-1')->create($this->getTestToken());

        $user->updateCard($this->getAnotherTestToken());
    }
}
