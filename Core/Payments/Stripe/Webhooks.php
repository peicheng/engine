<?php
/**
 * Braintree webhooks
 */

namespace Minds\Core\Payments\Stripe;

use Minds\Core;
use Minds\Core\Guid;
use Minds\Core\Payments;
use Minds\Entities;


class Webhooks
{
    protected $stripe;
    protected $payload;
    protected $signature;
    protected $signingKey;
    protected $event;
    protected $aliases = [
      'invoice.payment_succeeded' => 'onInvoicePaymentSuccess',
      'customer.subscription.deleted' => 'onCancelled'
    ];
    protected $hooks;

    public function __construct($hooks = null, $stripe)
    {
        $this->hooks = $hooks ?: new Payments\Hooks();
    }

    /**
     * Set the request payload
     * @param string $payload
     * @return $this
     */
    public function setPayload($payload)
    {
        $this->payload = $payload;
        return $this;
    }

    /**
     * Set the request signature
     * @param string $signature
     * @return $this
     */
    public function setSignature($signature)
    {
        $this->signature = $signature;
        return $this;
    }

    /**
     * Set the request signature
     * @param string $signature
     * @return $this
     */
    public function setSigningKey($key)
    {
        $this->signingKey = $key;
        return $this;
    }

    public function buildEvent()
    {
        $this->event = \Stripe\Webhook::constructEvent($this->payload, $this->signature, $this->signingKey);
        return $this->event;
    }

    /**
      * Run the notification hook
      * @return $this
      */
    public function run()
    {
        $this->buildEvent();
        $this->routeAlias();
        return $this;
    }

    protected function routeAlias()
    {
        if (method_exists($this, $this->aliases[$this->event->type])) {
            $method = $this->aliases[$this->event->type];
            $this->$method();
        }
    }

    protected function onInvoicePaymentSuccess()
    {
        $invoiceObj = $this->event->data->object;
        $lines = $invoiceObj->lines->data;
        $chargeId = $invoiceObj->charge;
        $planId = "";

        $metadata = [];

        foreach ($lines as $line) {
            if($line->type == "subscription"){
                $metadata = $line->metadata->__toArray(false);
                $planId = $line->plan->id;
            }
        }

        $charge = \Stripe\Charge::retrieve($chargeId, [
          'stripe_account' => $this->event->account
        ]);
        $charge->metadata = (array) $metadata;
        $charge->save();

        //grab the customer
        $customerObj = \Stripe\Customer::retrieve($invoiceObj->customer, [
          'stripe_account' => $this->event->account
        ]);
        $customer = new Payments\Customer();
        $customer->setUser(new Entities\User($customerObj->metadata->__toArray()['user_guid']))
          ->setId($customerObj->id);

        //trigger the hooks
        $subscription = (new Payments\Subscriptions\Subscription())
            ->setCustomer($customer)
            ->setId($chargeId)
            ->setPlanId($planId)
            ->setPrice($charge->amount / 100);
        $this->hooks->onCharged($subscription);
    }

    protected function onCancelled()
    {

        $subscriptionObj = $this->event->data->object;

        //grab the customer
        $customerObj = \Stripe\Customer::retrieve($subscriptionObj->customer, [
          'stripe_account' => $this->event->account
        ]);
        $customer = new Payments\Customer();
        $customer->setUser(new Entities\User($customerObj->metadata->__toArray()['user_guid']))
          ->setId($customerObj->id);

        $subscription = (new Payments\Subscriptions\Subscription())
            ->setCustomer($customer)
            ->setId($subscripionObj->id)
            ->setPlanId($subscripionObj->plan->id)
            ->setPrice($charge->amount / 100);
        $this->hooks->onCanceled($subscription);
    }

    /**
     * @return void
     */
    protected function check()
    {
        error_log("[webook]:: check is OK!");
    }
}
