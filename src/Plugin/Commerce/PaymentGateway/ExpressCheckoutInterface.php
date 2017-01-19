<?php

namespace Drupal\commerce_paypal\Plugin\Commerce\PaymentGateway;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OffsitePaymentGatewayInterface;

/**
 * Provides the interface for the Express Checkout payment gateway.
 */
interface ExpressCheckoutInterface extends OffsitePaymentGatewayInterface {

  /**
   * Make a PayPal Express Checkout NVP API request.
   *
   * @param array $nvp_data
   *   The NVP API data array as documented.
   * @see https://developer.paypal.com/docs/classic/api/#express-checkout
   *
   * @return array[].
   *   PayPal response data array.
   */
  function apiRequest($nvp_data);

  /**
   * SetExpressCheckout API Operation (NVP) request.
   * Builds the data for the request and make the request.
   *
   *  @see https://developer.paypal.com/docs/classic/api/merchant/SetExpressCheckout_API_Operation_NVP/
   *
   * @param \Drupal\commerce_payment\Entity\PaymentInterface $payment
   *   The order.
   * @param array $extra
   *   Extra data needed for this request, ex.: cancel url, return url, transaction mode, etc....
   *
   * @return array[].
   *   PayPal response data array with apiRequest().
   */
  function setExpressCheckout(PaymentInterface $payment, $extra);

  /**
   * GetExpressCheckoutDetails API Operation (NVP) request.
   * Builds the data for the request and make the request.
   *
   *  @see https://developer.paypal.com/docs/classic/api/merchant/GetExpressCheckoutDetails_API_Operation_NVP/
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   *
   * @return array[].
   *   PayPal response data array with apiRequest().
   */
  function getExpressCheckoutDetails(OrderInterface $order);

  /**
   * GetExpressCheckoutDetails API Operation (NVP) request.
   * Builds the data for the request and make the request.
   *
   *  @see https://developer.paypal.com/docs/classic/api/merchant/GetExpressCheckoutDetails_API_Operation_NVP/
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   *
   * @return array[].
   *   PayPal response data array with apiRequest().
   */
  function doExpressCheckoutDetails(OrderInterface $order);

}
