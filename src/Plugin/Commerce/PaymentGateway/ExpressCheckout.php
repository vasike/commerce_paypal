<?php

namespace Drupal\commerce_paypal\Plugin\Commerce\PaymentGateway;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\commerce_payment\Exception\PaymentGatewayException;
use Drupal\commerce_payment\PaymentMethodTypeManager;
use Drupal\commerce_payment\PaymentTypeManager;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OffsitePaymentGatewayBase;
use Drupal\commerce_price\Price;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\State\StateInterface;
use GuzzleHttp\ClientInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides the Paypal Express Checkout payment gateway.
 *
 * @CommercePaymentGateway(
 *   id = "paypal_express_checkout",
 *   label = "PayPal (Express Checkout)",
 *   display_label = "PayPal",
 *    forms = {
 *     "offsite-payment" = "Drupal\commerce_paypal\PluginForm\ExpressCheckoutForm",
 *   },
 *   payment_method_types = {"credit_card"},
 *   credit_card_types = {
 *     "amex", "discover", "mastercard", "visa",
 *   },
 * )
 */
class ExpressCheckout extends OffsitePaymentGatewayBase implements ExpressCheckoutInterface {

  /**
   * The HTTP client.
   *
   * @var \GuzzleHttp\Client
   */
  protected $httpClient;

  /**
   * State service for retrieving database info.
   *
   * @var \Drupal\Core\State\StateInterface
   */
  protected $state;

  /**
   * {@inheritdoc}
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, EntityTypeManagerInterface $entity_type_manager, PaymentTypeManager $payment_type_manager, PaymentMethodTypeManager $payment_method_type_manager, ClientInterface $client, StateInterface $state) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $entity_type_manager, $payment_type_manager, $payment_method_type_manager);
    $this->httpClient = $client;
    $this->state = $state;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
      $container->get('plugin.manager.commerce_payment_type'),
      $container->get('plugin.manager.commerce_payment_method_type'),
      $container->get('http_client'),
      $container->get('state')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'api_username' => '',
      'api_password' => '',
      'signature' => '',
      'solution_type' => 'Mark',
    ] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);

    $form['api_username'] = [
      '#type' => 'textfield',
      '#title' => $this->t('API Username'),
      '#default_value' => $this->configuration['api_username'],
      '#required' => TRUE,
    ];
    $form['api_password'] = [
      '#type' => 'textfield',
      '#title' => $this->t('API Password'),
      '#default_value' => $this->configuration['api_password'],
      '#required' => TRUE,
    ];
    $form['signature'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Signature'),
      '#default_value' => $this->configuration['signature'],
      '#required' => TRUE,
    ];

    $form['solution_type'] = [
      '#type' => 'radios',
      '#title' => t('Type of checkout flow'),
      '#description' => t('Express Checkout Account Optional (ECAO) where PayPal accounts are not required for payment may not be available in all markets.'),
      '#options' => [
        'Mark' => t('Require a PayPal account (this is the standard configuration).'),
        'SoleLogin' => t('Allow PayPal AND credit card payments, defaulting to the PayPal form.'),
        'SoleBilling' => t('Allow PayPal AND credit card payments, defaulting to the credit card form.'),
      ],
      '#default_value' => $this->configuration['solution_type'],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::submitConfigurationForm($form, $form_state);
    if (!$form_state->getErrors()) {
      $values = $form_state->getValue($form['#parents']);
      $this->configuration['api_username'] = $values['api_username'];
      $this->configuration['api_password'] = $values['api_password'];
      $this->configuration['signature'] = $values['signature'];
      $this->configuration['solution_type'] = $values['solution_type'];
    }
  }

  /**
   * {@inheritdoc}
   */
  public function onReturn(OrderInterface $order, Request $request) {
    // GetExpressCheckoutDetails API Operation (NVP).
    // Shows information about an Express Checkout transaction.
    $paypal_response = $this->apiGetExpressCheckoutDetails($order);

    // If the request failed, exit now with a failure message.
    if ($paypal_response['ACK'] == 'Failure') {
      return FALSE;
    }

    // Set the Payer ID used to finalize payment.
    $order_express_checkout_data = $order->getData('paypal_express_checkout');
    $order_express_checkout_data['payerid'] = $paypal_response['PAYERID'];
    $order->setData('paypal_express_checkout', $order_express_checkout_data);

    // If the user is anonymous, add their PayPal e-mail to the order.
    if (empty($order->mail)) {
      $order->setEmail($paypal_response['EMAIL']);
    }
    $order->save();

    // DoExpressCheckoutPayment API Operation (NVP).
    // Completes an Express Checkout transaction.
    $paypal_response = $this->apiDoExpressCheckoutDetails($order);

    // Nothing to do for failures for now - no payment saved.
    // ToDo - more about the failures.
    if ($paypal_response['PAYMENTINFO_0_PAYMENTSTATUS'] == 'Failed') {
      return FALSE;
    }

    $payment_storage = $this->entityTypeManager->getStorage('commerce_payment');
    $payment = $payment_storage->create([
      'state' => 'authorization',
      'amount' => $order->getTotalPrice(),
      'payment_gateway' => $this->entityId,
      'order_id' => $order->id(),
      'test' => $this->getMode() == 'test',
      'remote_id' => $paypal_response['PAYMENTINFO_0_TRANSACTIONID'],
      'remote_state' => $paypal_response['PAYMENTINFO_0_PAYMENTSTATUS'],
      'authorized' => REQUEST_TIME,
    ]);

    // Process payment status received.
    // ToDo : payment updates if needed.
    // If we didn't get an approval response code...
    switch ($paypal_response['PAYMENTINFO_0_PAYMENTSTATUS']) {
      case 'Voided':
        $payment->state = 'authorization_voided';
        break;

      case 'Pending':
        $payment->state = 'authorization';
        break;

      case 'Completed':
      case 'Processed':
        $payment->state = 'capture_completed';
        break;

      case 'Refunded':
        $payment->state = 'capture_refunded';
        break;

      case 'Partially-Refunded':
        $payment->state = 'capture_partially_refunded';
        break;

      case 'Expired':
        $payment->state = 'authorization_expired';
        break;
    }

    $payment->save();
  }

  public function onCancel(OrderInterface $order, Request $request) {
    parent::onCancel($order, $request);
  }

  /**
   * {@inheritdoc}
   */
  public function capturePayment(PaymentInterface $payment, Price $amount = NULL) {
    if ($payment->getState()->value != 'authorization') {
      throw new \InvalidArgumentException('Only payments in the "authorization" state can be captured.');
    }
    // If not specified, capture the entire amount.
    $amount = $amount ?: $payment->getAmount();
    $amount_number = $this->formatNumber($amount->getNumber());

    // GetExpressCheckoutDetails API Operation (NVP).
    // Shows information about an Express Checkout transaction.
    $paypal_response = $this->apiDoCapture($payment, $amount_number);

    if ($paypal_response['ACK'] == 'Failure') {
      $message = $paypal_response['L_LONGMESSAGE0'];
      throw new PaymentGatewayException($message, $paypal_response['L_ERRORCODE0']);
    }

    $payment->state = 'capture_completed';
    $payment->setAmount($amount);
    $payment->setCapturedTime(REQUEST_TIME);
    // Update the remote id for the captured transaction.
    $payment->setRemoteId($paypal_response['TRANSACTIONID']);
    $payment->save();
  }

  /**
   * {@inheritdoc}
   */
  public function voidPayment(PaymentInterface $payment) {
    if ($payment->getState()->value != 'authorization') {
      throw new \InvalidArgumentException('Only payments in the "authorization" state can be voided.');
    }

    // GetExpressCheckoutDetails API Operation (NVP).
    // Shows information about an Express Checkout transaction.
    $paypal_response = $this->apiDoVoid($payment);

    if ($paypal_response['ACK'] == 'Failure') {
      $message = $paypal_response['L_LONGMESSAGE0'];
      throw new PaymentGatewayException($message, $paypal_response['L_ERRORCODE0']);
    }

    $payment->state = 'authorization_voided';
    $payment->save();
  }

  /**
   * {@inheritdoc}
   */
  public function refundPayment(PaymentInterface $payment, Price $amount = NULL) {
    if (!in_array($payment->getState()->value, [
      'capture_completed',
      'capture_partially_refunded'
    ])
    ) {
      throw new \InvalidArgumentException('Only payments in the "capture_completed" and "capture_partially_refunded" states can be refunded.');
    }
    // If not specified, refund the entire amount.
    $amount = $amount ?: $payment->getAmount();
    // Validate the requested amount.
    $balance = $payment->getBalance();
    if ($amount->greaterThan($balance)) {
      throw new InvalidRequestException(sprintf("Can't refund more than %s.", $balance->__toString()));
    }

    $extra['amount'] = $this->formatNumber($amount->getNumber());

    // Check if the Refund is partial or full.
    $old_refunded_amount = $payment->getRefundedAmount();
    $new_refunded_amount = $old_refunded_amount->add($amount);
    if ($new_refunded_amount->lessThan($payment->getAmount())) {
      $payment->state = 'capture_partially_refunded';
      $extra['refund_type'] = 'Partial';
    }
    else {
      $payment->state = 'capture_refunded';
      if ($amount->lessThan($payment->getAmount())) {
        $extra['refund_type'] = 'Partial';
      }
      else {
        $extra['refund_type'] = 'Full';
      }
    }

    // RefundTransaction API Operation (NVP).
    // Refund (full or partial) an Express Checkout transaction.
    $paypal_response = $this->apiRefundTransaction($payment, $extra);

    if ($paypal_response['ACK'] == 'Failure') {
      $message = $paypal_response['L_LONGMESSAGE0'];
      throw new PaymentGatewayException($message, $paypal_response['L_ERRORCODE0']);
    }

    $payment->setRefundedAmount($new_refunded_amount);
    $payment->save();
  }

  /**
   * {@inheritdoc}
   */
  public function onNotify(Request $request) {
    // Get IPN request data.
    parse_str(html_entity_decode($request->getContent()), $ipn_data);

    // Exit now if the $_POST was empty.
    if (empty($ipn_data)) {
      \Drupal::logger('commerce_paypal')->warning('IPN URL accessed with no POST data submitted.');
      return FALSE;
    }

    // Determine the proper PayPal server to POST the validation.
    if (!empty($ipn_data['test_ipn']) && $ipn_data['test_ipn'] == 1) {
      $url = 'https://www.sandbox.paypal.com/cgi-bin/webscr';
    }
    else {
      $url = 'https://www.paypal.com/cgi-bin/webscr';
    }
    $validate_ipn = 'cmd=_notify-validate&' . $request->getContent();

    // Make PayPal request for IPN validation.
    $request = $this->httpClient->post($url, [
      'body' => $validate_ipn,
    ])->getBody()
      ->getContents();

    parse_str(html_entity_decode($request), $paypal_response);

    // If the IPN was invalid, log a message and exit.
    if (isset($paypal_response['INVALID'])) {
      \Drupal::logger('commerce_paypal')->alert('Invalid IPN received and ignored.');
      return FALSE;
    }

    // Do not perform any processing on EC transactions here that do not have
    // transaction IDs, indicating they are non-payment IPNs such as those used
    // for subscription signup requests.
    if (empty($ipn_data['txn_id'])) {
      \Drupal::logger('commerce_paypal')->alert('The IPN request does not have a transaction id. Ignored.');
      return FALSE;
    }

    // Exit when we don't get a payment status we recognize.
    if (!in_array($ipn_data['payment_status'], ['Failed', 'Voided', 'Pending', 'Completed', 'Refunded'])) {
      return FALSE;
    }

    // If this is a prior authorization capture IPN...
    if (in_array($ipn_data['payment_status'], ['Voided', 'Pending', 'Completed']) && !empty($ipn_data['auth_id'])) {
      // Ensure we can load the existing corresponding transaction.
      $payment = $this->loadPaymentByRemoteId($ipn_data['auth_id']);

      // If not, bail now because authorization transactions should be created by
      // the Express Checkout API request itself.
      if (!$payment) {
        \Drupal::logger('commerce_paypal')->warning('IPN for Order @order_number ignored: authorization transaction already created.', ['@order_number' => $ipn_data['invoice']]);
        return FALSE;
      }

      $amount = new Price($ipn_data['mc_gross'], $ipn_data['mc_currency']);
      $payment->setAmount($amount);

      // Update the payment state.
      switch ($ipn_data['payment_status']) {
        case 'Voided':
          $payment->state = 'authorization_voided';
          break;

        case 'Pending':
          $payment->state = 'authorization';
          break;

        case 'Completed':
          $payment->state = 'capture_completed';
          $payment->setCapturedTime(REQUEST_TIME);
          break;
      }
      // Update the remote id.
      $payment->remote_id = $ipn_data['txn_id'];
    }
    elseif ($ipn_data['payment_status'] == 'Refunded') {
      // Get the corresponding parent transaction and refund it.
      /** @var \Drupal\commerce_payment\Entity\PaymentInterface $payment */
      $payment = $this->loadPaymentByRemoteId($ipn_data['parent_txn_id']);
      if (!$payment) {
        \Drupal::logger('commerce_paypal')->warning('IPN for Order @order_number ignored: the transaction to be refunded does not exist.', ['@order_number' => $ipn_data['invoice']]);
        return FALSE;
      }
      elseif ($payment->getState() != 'capture_refunded') {
        \Drupal::logger('commerce_paypal')->warning('IPN for Order @order_number ignored: the transaction is already refunded.', ['@order_number' => $ipn_data['invoice']]);
        return FALSE;
      }
      $amount_number = abs($ipn_data['mc_gross']);
      $amount = new Price((string) $amount_number, $ipn_data['mc_currency']);
      // Check if the Refund is partial or full.
      $old_refunded_amount = $payment->getRefundedAmount();
      $new_refunded_amount = $old_refunded_amount->add($amount);
      if ($new_refunded_amount->lessThan($payment->getAmount())) {
        $payment->state = 'capture_partially_refunded';
      }
      else {
        $payment->state = 'capture_refunded';
      }
      $payment->setRefundedAmount($new_refunded_amount);
    }
    elseif ($ipn_data['payment_status'] == 'Failed') {
      // ToDo - to check and report existing payments???
    }
    else {
      // In other circumstances, exit the processing, because we handle those
      // cases directly during API response processing.
      \Drupal::logger('commerce_paypal')->notice('IPN for Order @order_number ignored: this operation was accommodated in the direct API response.', ['@order_number' => $ipn_data['invoice']]);
      return FALSE;
    }

    $payment->currency_code = $ipn_data['mc_currency'];

    // Set the transaction's statuses based on the IPN's payment_status.
    $payment->remote_state = $ipn_data['payment_status'];

    // Save the transaction information.
    $payment->save();
  }

  /**
   * {@inheritdoc}
   */
  public function loadPaymentByRemoteId($remote_id) {
    $storage = $this->entityTypeManager->getStorage('commerce_payment');
    $payment_by_remote_id = $storage->loadByProperties(['remote_id' => $remote_id]);
    return reset($payment_by_remote_id);
  }

  /**
   * PayPal Express Checkout NVP API helper methods.
   */

  /**
   * {@inheritdoc}
   */
  public function apiSetExpressCheckout(PaymentInterface $payment, $extra) {
    $order = $payment->getOrder();

    $amount = $payment->getAmount()->getNumber();
    $configuration = $this->getConfiguration();

    if ($extra['capture']) {
      $payment_action = 'Sale';
    }
    else {
      $payment_action = 'Authorization';
    }

    $flow = 'ec';
    // Build a name-value pair array for this transaction.
    $nvp_data = [
      'METHOD' => 'SetExpressCheckout',

      // Default the Express Checkout landing page to the Mark solution.
      'SOLUTIONTYPE' => 'Mark',
      'LANDINGPAGE' => 'Login',

      // Disable entering notes in PayPal, as we don't have any way to accommodate
      // them right now.
      'ALLOWNOTE' => '0',

      'PAYMENTREQUEST_0_PAYMENTACTION' => $payment_action,
      'PAYMENTREQUEST_0_AMT' => $this->formatNumber($amount),
      'PAYMENTREQUEST_0_CURRENCYCODE' => $payment->getAmount()->getCurrencyCode(),
      'PAYMENTREQUEST_0_INVNUM' => $order->id() . '-' . REQUEST_TIME,

      // Set the return and cancel URLs.
      'RETURNURL' => $extra['return_url'],
      'CANCELURL' => $extra['cancel_url'],
    ];

    $order_express_checkout_data = $order->getData('paypal_express_checkout');
    if (!empty($order_express_checkout_data['token'])) {
      $nvp_data['TOKEN'] = $order_express_checkout_data['token'];
    }

    // Get the order line items
    $n = 0;
    foreach ($order->getItems() as $item) {
      $item_amount = $item->getUnitPrice()->getNumber();
      $nvp_data += [
        'L_PAYMENTREQUEST_0_NAME' . $n => $item->getTitle(),
        'L_PAYMENTREQUEST_0_AMT' . $n => $this->formatNumber($item_amount),
        'L_PAYMENTREQUEST_0_QTY' . $n => $item->getQuantity(),
      ];
      $n++;
    }

    // If reference transactions are enabled and a billing agreement is supplied...
    if (!empty($configuration['reference_transactions']) &&
      !empty($configuration['ba_desc'])) {
      $nvp_data['BILLINGTYPE'] = 'MerchantInitiatedBillingSingleAgreement';
      $nvp_data['L_BILLINGTYPE0'] = 'MerchantInitiatedBillingSingleAgreement';
      $nvp_data['L_BILLINGAGREEMENTDESCRIPTION0'] = $configuration['ba_desc'];
    }

    // If Express Checkout Account Optional is enabled...
    if ($configuration['solution_type'] != 'Mark') {
      // Update the solution type and landing page parameters accordingly.
      $nvp_data['SOLUTIONTYPE'] = 'Sole';

      if ($configuration['solution_type'] == 'SoleBilling') {
        $nvp_data['LANDINGPAGE'] = 'Billing';
      }
    }

    // ToDo Shipping data.
    $nvp_data['NOSHIPPING'] = '1';

    // Overrides specific values for the BML payment method.
    if ($flow == 'bml') {
      $nvp['USERSELECTEDFUNDINGSOURCE'] = 'BML';
      $nvp['SOLUTIONTYPE'] = 'SOLE';
      $nvp['LANDINGPAGE'] = 'BILLING';
    }

    // Make the PayPal NVP API request.s
    return $this->apiRequest($nvp_data);

  }

  /**
   * {@inheritdoc}
   */
  public function apiGetExpressCheckoutDetails(OrderInterface $order) {
    // Get the Express Checkout order token.
    $order_express_checkout_data = $order->getData('paypal_express_checkout');
    if (empty($order_express_checkout_data['token'])) {
      return FALSE;
    }

    // Build a name-value pair array to obtain buyer information from PayPal.
    $nvp_data = [
      'METHOD' => 'GetExpressCheckoutDetails',
      'TOKEN' => $order_express_checkout_data['token'],
    ];

    // Make the PayPal NVP API request.
    return $this->apiRequest($nvp_data);

  }

  /**
   * {@inheritdoc}
   */
  public function apiDoExpressCheckoutDetails(OrderInterface $order) {
    // Build NVP data for PayPal API request.
    $order_express_checkout_data = $order->getData('paypal_express_checkout');
    $amount = $order->getTotalPrice()->getNumber();
    if ($order_express_checkout_data['capture']) {
      $payment_action = 'Sale';
    }
    else {
      $payment_action = 'Authorization';
    }
    $nvp_data = [
      'METHOD' => 'DoExpressCheckoutPayment',
      'TOKEN' => $order_express_checkout_data['token'],
      'PAYMENTREQUEST_0_AMT' => $this->formatNumber($amount),
      'PAYMENTREQUEST_0_CURRENCYCODE' => $order->getTotalPrice()->getCurrencyCode(),
      'PAYMENTREQUEST_0_INVNUM' => $order->getOrderNumber(),
      'PAYERID' => $order_express_checkout_data['payerid'],
      'PAYMENTREQUEST_0_PAYMENTACTION' => $payment_action,
      //'PAYMENTREQUEST_0_NOTIFYURL' => ToDo,
    ];

    // Make the PayPal NVP API request.
    return $this->apiRequest($nvp_data);

  }

  /**
   * {@inheritdoc}
   */
  public function apiDoCapture(PaymentInterface $payment, $amount) {
    $order = $payment->getOrder();

    // Build a name-value pair array for this transaction.
    $nvp_data = [
      'METHOD' => 'DoCapture',
      'AUTHORIZATIONID' => $payment->getRemoteId(),
      'AMT' => $amount,
      'CURRENCYCODE' => $payment->getAmount()->getCurrencyCode(),
      'INVNUM' => $order->getOrderNumber(),
      'COMPLETETYPE' => 'Complete',
    ];

    // Make the PayPal NVP API request.s
    return $this->apiRequest($nvp_data);

  }

  /**
   * {@inheritdoc}
   */
  public function apiDoVoid(PaymentInterface $payment) {
    // Build a name-value pair array for this transaction.
    $nvp_data = [
      'METHOD' => 'DoVoid',
      'AUTHORIZATIONID' => $payment->getRemoteId(),
    ];

    // Make the PayPal NVP API request.s
    return $this->apiRequest($nvp_data);

  }

  /**
   * {@inheritdoc}
   */
  public function apiRefundTransaction(PaymentInterface $payment, $extra) {

    // Build a name-value pair array for this transaction.
    $nvp_data = [
      'METHOD' => 'RefundTransaction',
      'TRANSACTIONID' => $payment->getRemoteId(),
      'REFUNDTYPE' => $extra['refund_type'],
      'AMT' => $extra['amount'],
      'CURRENCYCODE' => $payment->getAmount()->getCurrencyCode(),
    ];

    // Make the PayPal NVP API request.s
    return $this->apiRequest($nvp_data);

  }

  /**
   * {@inheritdoc}
   */
  public function apiRequest($nvp_data) {
    // Add the default name-value pairs to the array.
    $configuration = $this->getConfiguration();
    $nvp_data += [
      // API credentials
      'USER' => $configuration['api_username'],
      'PWD' => $configuration['api_password'],
      'SIGNATURE' => $configuration['signature'],
      'VERSION' => '124.0',
    ];

    $mode = $this->getMode();
    if ($mode == 'test') {
      $url = 'https://api-3t.sandbox.paypal.com/nvp';
    }
    else {
      $url = 'https://api-3t.paypal.com/nvp';
    }

    // Make PayPal request.
    $request = $this->httpClient->post($url, [
      'form_params' => $nvp_data,
    ])->getBody()
      ->getContents();

    parse_str(html_entity_decode($request), $paypal_response);

    return $paypal_response;
  }

  /**
   * Formats the charge amount for PayPal.
   *
   * @param integer $amount
   *   The amount being charged.
   *
   * @return integer
   *   The PayPal formatted amount.
   */
  protected function formatNumber($amount) {
    $amount = number_format($amount, 2, '.', '');
    return $amount;
  }

}
