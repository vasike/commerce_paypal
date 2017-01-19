<?php

namespace Drupal\commerce_paypal\Plugin\Commerce\PaymentGateway;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\commerce_payment\PaymentMethodTypeManager;
use Drupal\commerce_payment\PaymentTypeManager;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OffsitePaymentGatewayBase;
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
class ExpressCheckout extends OffsitePaymentGatewayBase {

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
    $paypal_response = $this->getExpressCheckoutDetails($order);

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
    $paypal_response = $this->doExpressCheckoutDetails($order);

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

    $payment->save();
    drupal_set_message('Payment was processed');
  }

  public function onCancel(OrderInterface $order, Request $request) {
    parent::onCancel($order, $request);
  }

  /**
   * {@inheritdoc}
   */
  public function setExpressCheckout(PaymentInterface $payment, $urls) {
    $order = $payment->getOrder();

    $amount = $payment->getAmount()->getNumber();
    $configuration = $this->getConfiguration();

    $flow = 'ec';
    // Build a name-value pair array for this transaction.
    $nvp_data = array(
      'METHOD' => 'SetExpressCheckout',

      // Default the Express Checkout landing page to the Mark solution.
      'SOLUTIONTYPE' => 'Mark',
      'LANDINGPAGE' => 'Login',

      // Disable entering notes in PayPal, as we don't have any way to accommodate
      // them right now.
      'ALLOWNOTE' => '0',

      'PAYMENTREQUEST_0_PAYMENTACTION' => 'Sale',
      'PAYMENTREQUEST_0_AMT' => $this->formatNumber($amount),
      'PAYMENTREQUEST_0_CURRENCYCODE' => $payment->getAmount()->getCurrencyCode(),
      'PAYMENTREQUEST_0_INVNUM' => $order->id() . '-' . REQUEST_TIME,

      // Set the return and cancel URLs.
      'RETURNURL' => $urls['#return_url'],
      'CANCELURL' => $urls['#cancel_url'],
    );

    $order_express_checkout_data = $order->getData('paypal_express_checkout');
    if (!empty($order_express_checkout_data['token'])) {
      $nvp_data['TOKEN'] = $order_express_checkout_data['token'];
    }

    // Get the order line items
    $n = 0;
    foreach ($order->getItems() as $item) {
      $item_amount = $item->getUnitPrice()->getNumber();
      $nvp_data += array(
        'L_PAYMENTREQUEST_0_NAME' . $n => $item->getTitle(),
        'L_PAYMENTREQUEST_0_AMT' . $n => $this->formatNumber($item_amount),
        'L_PAYMENTREQUEST_0_QTY' . $n => $item->getQuantity(),
      );
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
  public function getExpressCheckoutDetails(OrderInterface $order) {
    // Get the Express Checkout order token.
    $order_express_checkout_data = $order->getData('paypal_express_checkout');
    if (empty($order_express_checkout_data['token'])) {
      return FALSE;
    }

    // Build a name-value pair array to obtain buyer information from PayPal.
    $nvp_data = array(
      'METHOD' => 'GetExpressCheckoutDetails',
      'TOKEN' => $order_express_checkout_data['token'],
    );

    // Make the PayPal NVP API request.
    return $this->apiRequest($nvp_data);

  }

  /**
   * {@inheritdoc}
   */
  public function doExpressCheckoutDetails(OrderInterface $order) {
    // Build NVP data for PayPal API request.
    $order_express_checkout_data = $order->getData('paypal_express_checkout');
    $amount = $order->getTotalPrice()->getNumber();
    $nvp_data = [
      'METHOD' => 'DoExpressCheckoutPayment',
      'TOKEN' => $order_express_checkout_data['token'],
      'PAYMENTREQUEST_0_AMT' => $this->formatNumber($amount),
      'PAYMENTREQUEST_0_CURRENCYCODE' => $order->getTotalPrice()->getCurrencyCode(),
      'PAYMENTREQUEST_0_INVNUM' => $order->getOrderNumber(),
      'PAYERID' => $order_express_checkout_data['payerid'],
      'PAYMENTREQUEST_0_PAYMENTACTION' => 'Sale',
      //'PAYMENTREQUEST_0_NOTIFYURL' => ToDo,
    ];

    // Make the PayPal NVP API request.
    return $this->apiRequest($nvp_data);

  }

  /**
   * {@inheritdoc}
   */
  public function apiRequest($nvp_data) {
    // Add the default name-value pairs to the array.
    $configuration = $this->getConfiguration();
    $nvp_data += array(
      // API credentials
      'USER' => $configuration['api_username'],
      'PWD' => $configuration['api_password'],
      'SIGNATURE' => $configuration['signature'],
      'VERSION' => '124.0',
    );

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
