<?php

use Carbon\Carbon;

class CashierTest extends \BaseTest
{
    /** @test */
    public function subscriptions_can_be_created_with_card()
    {
        $user = User::create([
            'email' => 'john@example.com',
            'name'  => 'John Doe',
        ]);

        // Create Subscription
        $user->newSubscription('main', 'monthly-10-1')->create($this->getVisaToken());

        $this->assertEquals(1, count($user->subscriptions));
        $this->assertNotNull($user->subscription('main')->braintree_id);

        $this->assertTrue($user->subscribed('main'));
        $this->assertTrue($user->subscribed('main', 'monthly-10-1'));
        $this->assertFalse($user->subscribed('main', 'monthly-10-2'));
        $this->assertTrue($user->subscription('main')->active());
        $this->assertFalse($user->subscription('main')->cancelled());
        $this->assertFalse($user->subscription('main')->onGracePeriod());
        $this->assertEquals(0, $user->subscription('main')->balance());
        $this->assertEquals('card', $user->payment_type);
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
    public function subscriptions_can_be_swapped_from_monthly_to_yearly()
    {
        $user = User::create([
            'email' => 'john@example.com',
            'name'  => 'John Doe',
        ]);

        // Create Subscription
        $user->newSubscription('main', 'monthly-10-1')->create($this->getVisaToken());

        $user->fresh()->subscription('main')->swap('yearly-100');

        $this->assertNotNull($user->subscription('main')->braintree_id);

        $this->assertTrue($user->subscribed('main'));
        $this->assertTrue($user->subscribed('main', 'yearly-100'));
        $this->assertFalse($user->subscribed('main', 'monthly-10-1'));
        $this->assertTrue($user->subscription('main')->active());
        $this->assertEquals(0.0, floatval($user->subscription('main')->balance()));


        $invoice = $user->invoices(true)[0];

        $this->assertCount(2, $user->invoices(true));
        $this->assertEquals('$100.00', $invoice->subtotal());
        // total paid amount should be less than full price of the yearly plan
        $this->assertLessThan(100, floatval(substr($invoice->total(), 1)));
        $this->assertTrue($invoice->hasDiscount());
        $this->assertFalse($invoice->hasStartingBalance());
        $this->assertNotNull($invoice->coupon());
    }

    /** @test */
    public function subscriptions_can_be_swapped_from_yearly_to_monthly()
    {
        $user = User::create([
            'email' => 'john@example.com',
            'name'  => 'John Doe',
        ]);

        // Create Subscription
        $user->newSubscription('main', 'yearly-100')->create($this->getVisaToken());

        $user->fresh()->subscription('main')->swap('monthly-10-1');

        $this->assertNotNull($user->subscription('main')->braintree_id);

        $this->assertTrue($user->subscribed('main'));
        $this->assertTrue($user->subscribed('main', 'monthly-10-1'));
        $this->assertFalse($user->subscribed('main', 'yearly-100'));
        $this->assertTrue($user->subscription('main')->active());
        $this->assertEquals(-90.0, floatval($user->subscription('main')->balance()));

        // user has only one invoice, since the swapping was paid from the credit balance
        $this->assertCount(1, $user->invoices(true));
    }

    /** @test */
    public function subscriptions_can_be_created_with_paypal_account()
    {
        $user = User::create([
            'email' => 'john@example.com',
            'name'  => 'John Doe',
        ]);

        $user->newSubscription('main', 'monthly-10-1')->create($this->getPaypalToken());

        $this->assertEquals(1, count($user->subscriptions));
        $this->assertNotNull($user->subscription('main')->braintree_id);

        $this->assertTrue($user->subscribed('main'));
        $this->assertTrue($user->subscribed('main', 'monthly-10-1'));
        $this->assertFalse($user->subscribed('main', 'monthly-10-2'));
        $this->assertTrue($user->subscription('main')->active());
        $this->assertFalse($user->subscription('main')->cancelled());
        $this->assertFalse($user->subscription('main')->onGracePeriod());
        $this->assertEquals('paypal', $user->payment_type);
        $this->assertNull($user->card_brand);
        $this->assertNull($user->card_last_four);
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
                ->withCoupon('coupon-1')->create($this->getVisaToken());

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
            ->withCoupon('coupon-1', true)->create($this->getVisaToken());

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
                ->trialDays(7)->create($this->getVisaToken());

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
    public function creating_subscription_with_tax()
    {
        User::$withTax = true;

        $user = User::create([
            'email' => 'john@example.com',
            'name'  => 'John Doe',
        ]);

        $user->newSubscription('main', 'monthly-10-1')->create($this->getVisaToken());

        $invoice = $user->invoices(true)[0];

        $this->assertEquals('$11.00', $invoice->total());
        $this->assertEquals('11.00', $user->subscription('main')->asBraintreeSubscription()->price);

        User::$withTax = false;
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
                ->create($this->getVisaToken());

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
        $user->newSubscription('main', 'monthly-10-1')->create($this->getVisaToken());
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
    public function users_can_update_their_card()
    {
        $user = User::create([
            'email' => 'john@example.com',
            'name'  => 'John Doe',
        ]);

        $user->newSubscription('main', 'monthly-10-1')->create($this->getVisaToken());

        $this->assertEquals('Visa', $user->card_brand);

        $user->updateCard($this->getMasterCardToken());

        $this->assertEquals('MasterCard', $user->card_brand);
    }
}
