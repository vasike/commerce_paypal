<?php

namespace Drupal\commerce_paypal\Plugin\Commerce\PaymentGateway;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OffsitePaymentGatewayInterface;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\SupportsAuthorizationsInterface;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\SupportsRefundsInterface;

/**
 * Provides the interface for the Express Checkout payment gateway.
 */
interface ExpressCheckoutInterface extends OffsitePaymentGatewayInterface, SupportsAuthorizationsInterface, SupportsRefundsInterface {

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

  /**
   * DoCapture API Operation (NVP) request.
   * Builds the data for the request and make the request.
   *
   *  @see https://developer.paypal.com/docs/classic/api/merchant/DoCapture_API_Operation_NVP/
   *
   * @param \Drupal\commerce_payment\Entity\PaymentInterface $payment
   *   The payment.
   * @param number $amount_number
   *   The amount number to be captured.
   *
   * @return array[].
   *   PayPal response data array with apiRequest().
   */
  function doCapture(PaymentInterface $payment, $amount_number);

  /**
   * DoVoid API Operation (NVP) request.
   * Builds the data for the request and make the request.
   *
   *  @see https://developer.paypal.com/docs/classic/api/merchant/DoVoid_API_Operation_NVP/
   *
   * @param \Drupal\commerce_payment\Entity\PaymentInterface $payment
   *   The payment.
   *
   * @return array[].
   *   PayPal response data array with apiRequest().
   */
  function doVoid(PaymentInterface $payment);

  /**
   * RefundTransaction API Operation (NVP) request.
   * Builds the data for the request and make the request.
   *
   *  @see https://developer.paypal.com/docs/classic/api/merchant/RefundTransaction_API_Operation_NVP/
   *
   * @param \Drupal\commerce_payment\Entity\PaymentInterface $payment
   *   The payment.
   * @param array $extra
   *   Extra data needed for this request, ex.: refund amount, refund type, etc....
   *
   * @return array[].
   *   PayPal response data array with apiRequest().
   */
  function refundTransaction(PaymentInterface $payment, $extra);
}
