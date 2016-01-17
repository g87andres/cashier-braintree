<?php

class InvoiceTest extends \BaseTest
{
    protected $user;

    protected $invoices;

    public function setUp()
    {
        parent::setUp();

        $this->user = User::create([
            'email' => 'john@example.com',
            'name'  => 'John Doe',
        ]);

        // Create Subscription
        $this->user->newSubscription('main', 'monthly-20')->create($this->getTestToken());
        $this->user->newSubscription('another', 'monthly-10-1')->withCoupon('coupon-1')->create();

        $this->invoices = $this->user->invoices(true);
    }

    /** @test */
    public function it_provides_date_of_the_invoice()
    {
        $invoice = $this->invoices[0];

        $this->assertInstanceOf(\Carbon\Carbon::class, $invoice->date());
    }

    /** @test */
    public function it_provides_total_amount_that_was_paid()
    {
        $invoice = $this->invoices[0];

        // $10.00 - $5.00 coupon
        $this->assertEquals('$5.00', $invoice->total());
    }

    /** @test */
    public function it_provides_subtotal_amount()
    {
        $invoice = $this->invoices[0];

        $this->assertEquals('$10.00', $invoice->subtotal());
    }

    /** @test */
    public function it_takes_starting_balance_into_consideration()
    {
        $this->user->subscription('main')->swap('monthly-10-2');

        $invoice = $this->user->invoices()[1];

        $this->assertTrue($invoice->hasStartingBalance());
        $this->assertNotNull($invoice->startingBalance());
    }

    /** @test */
    public function it_provides_discount_information()
    {
        $invoice = $this->user->invoices()[0];

        $this->assertTrue($invoice->hasDiscount());
        $this->assertEquals('5.0', $invoice->discount());
        $this->assertNotNull($invoice->coupon());
        $this->assertEquals('50%', $invoice->percentOff());
        $this->assertEquals('$5.00', $invoice->amountOff());
    }
}
