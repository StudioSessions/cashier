<?php

namespace Laravel\Cashier;

use Carbon\Carbon;

class SubscriptionBuilder
{
    /**
     * The model that is subscribing.
     *
     * @var \Illuminate\Database\Eloquent\Model
     */
    protected $owner;

    /**
     * The name of the subscription.
     *
     * @var string
     */
    protected $name;

    /**
     * The name of the plan being subscribed to.
     *
     * @var string
     */
    protected $plan;

    /**
     * The quantity of the subscription.
     *
     * @var int
     */
    protected $quantity = 1;

    /**
     * The number of trial days to apply to the subscription.
     *
     * @var int|null
     */
    protected $trialDays;

    /**
     * The application fee in percent for the parent stripe account.
     *
     * @var int|null
     */
    protected $application_fee;

    /**
     * Indicates that the trial should end immediately.
     *
     * @var bool
     */
    protected $skipTrial = false;

    /**
     * The coupon code being applied to the customer.
     *
     * @var string|null
     */
    protected $coupon;

    /**
     * The metadata to apply to the subscription.
     *
     * @var array|null
     */
    protected $metadata;

    /**
     * The stripe connect account for the subscription.
     *
     * @var string|null
     */
    protected $connect_account;

    /**
     * Create a new subscription builder instance.
     *
     * @param  mixed  $owner
     * @param  string  $name
     * @param  string  $plan
     * @return void
     */
    public function __construct($owner, $name, $plan, $connect_account = NULL)
    {
        $this->name = $name;
        $this->plan = $plan;
        $this->owner = $owner;
        $this->connect_account = $connect_account;
    }

    /**
     * Specify the quantity of the subscription.
     *
     * @param  int  $quantity
     * @return $this
     */
    public function quantity($quantity)
    {
        $this->quantity = $quantity;

        return $this;
    }

    /**
     * Specify the ending date of the trial.
     *
     * @param  int  $trialDays
     * @return $this
     */
    public function trialDays($trialDays)
    {
        $this->trialDays = $trialDays;

        return $this;
    }

    /**
     * Specify the application fee percentage.
     *
     * @param  int  $trialDays
     * @return $this
     */
    public function applicationFee($percentage)
    {
        $this->application_fee = $percentage;

        return $this;
    }

    /**
     * Specify a connected account on which to create the subscription.
     *
     * @param  string  $id
     * @return $this
     */
    public function connectAccount($id)
    {
        $this->connect_account = $id;

        return $this;
    }

    /**
     * Force the trial to end immediately.
     *
     * @return $this
     */
    public function skipTrial()
    {
        $this->skipTrial = true;

        return $this;
    }

    /**
     * The coupon to apply to a new subscription.
     *
     * @param  string  $coupon
     * @return $this
     */
    public function withCoupon($coupon)
    {
        $this->coupon = $coupon;

        return $this;
    }

    /**
     * The metadata to apply to a new subscription.
     *
     * @param  array  $metadata
     * @return $this
     */
    public function withMetadata($metadata)
    {
        $this->metadata = $metadata;

        return $this;
    }

    /**
     * Add a new Stripe subscription to the Stripe model.
     *
     * @param  array  $options
     * @return \Laravel\Cashier\Subscription
     */
    public function add(array $options = [])
    {
        return $this->create(null, $options);
    }

    /**
     * Create a new Stripe subscription.
     *
     * @param  string|null  $token
     * @param  array  $options
     * @return \Laravel\Cashier\Subscription
     */
    public function create($token = null, array $options = [])
    {
        $customer = $this->getStripeCustomer($token, $options);

        $subscription = $customer->subscriptions->create(
            $this->buildPayload(),
            $this->buildOptions()
        );

        if ($this->skipTrial) {
            $trialEndsAt = null;
        } else {
            $trialEndsAt = $this->trialDays ? Carbon::now()->addDays($this->trialDays) : null;
        }

        return $this->owner->subscriptions()->create([
            'name' => $this->name,
            'stripe_id' => $subscription->id,
            'stripe_plan' => $this->plan,
            'quantity' => $this->quantity,
            'trial_ends_at' => $trialEndsAt,
            'ends_at' => null,
        ]);
    }

    /**
     * Get the Stripe customer instance for the current user and token.
     *
     * @param  string|null  $token
     * @param  array  $options
     * @return \Stripe\Customer
     */
    protected function getStripeCustomer($token = null, array $options = [])
    {
        if (! $this->owner->stripe_id) {
            $customer = $this->owner->createAsStripeCustomer($token, $options);
        } else {
            $customer = $this->owner->asStripeCustomer();

            if ($token) {
                $this->owner->updateCard($token);
            }
        }

        return $customer;
    }

    /**
     * Build the payload for subscription creation.
     *
     * @return array
     */
    protected function buildPayload()
    {
        return array_filter([
            'plan' => $this->plan,
            'quantity' => $this->quantity,
            'coupon' => $this->coupon,
            'trial_end' => $this->getTrialEndForPayload(),
            'tax_percent' => $this->getTaxPercentageForPayload(),
            'metadata' => $this->metadata,
            'application_fee_percent' => $this->application_fee,
        ]);
    }

    /**
     * Build the options payload for subscription creation.
     *
     * @return array
     */
    protected function buildOptions()
    {
        return array_filter([
            'stripe_account' => $this->connect_account,
        ]);
    }

    /**
     * Get the trial ending date for the Stripe payload.
     *
     * @return int|null
     */
    protected function getTrialEndForPayload()
    {
        if ($this->skipTrial) {
            return 'now';
        }

        if ($this->trialDays) {
            return Carbon::now()->addDays($this->trialDays)->getTimestamp();
        }
    }

    /**
     * Get the tax percentage for the Stripe payload.
     *
     * @return int|null
     */
    protected function getTaxPercentageForPayload()
    {
        if ($taxPercentage = $this->owner->taxPercentage()) {
            return $taxPercentage;
        }
    }
}
