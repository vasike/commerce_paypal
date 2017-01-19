<?php

namespace Drupal\commerce_paypal\Plugin\Commerce\PaymentGateway;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OffsitePaymentGatewayInterface;

/**
 * Provides the interface for the Express Checkout payment gateway.
 */
interface ExpressCheckoutInterface extends OffsitePaymentGatewayInterface {

  function setExpressCheckout(PaymentInterface $payment, $urls);

  function apiRequest($nvp_data);

  function getExpressCheckoutDetails(OrderInterface $order);

}
