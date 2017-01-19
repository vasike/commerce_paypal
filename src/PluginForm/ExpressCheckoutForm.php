<?php

namespace Drupal\commerce_paypal\PluginForm;

use Drupal\commerce_payment\PluginForm\PaymentOffsiteForm as BasePaymentOffsiteForm;
use Drupal\Core\Form\FormStateInterface;
use GuzzleHttp\ClientInterface;
use Drupal\Core\Url;

class ExpressCheckoutForm extends BasePaymentOffsiteForm {

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);

    /** @var \Drupal\commerce_payment\Entity\PaymentInterface $payment */
    $payment = $this->entity;
    /** @var \Drupal\commerce_paypal\Plugin\Commerce\PaymentGateway\ExpressCheckoutInterface $payment_gateway_plugin */
    $payment_gateway_plugin = $payment->getPaymentGateway()->getPlugin();

    $extra = [
      'return_url' => $form['#return_url'],
      'cancel_url' => $form['#cancel_url'],
    ];

    $paypal_response = $payment_gateway_plugin->setExpressCheckout($payment, $extra);

    if (!empty($paypal_response['TOKEN'])) {
      $order = $payment->getOrder();
      $order->setData('paypal_express_checkout', [
        'flow' => 'ec',
        'token' => $paypal_response['TOKEN'],
        'payerid' => FALSE,
      ]);
      $order->save();

      // Redirect the buyer to PayPal URL.
      if ($payment_gateway_plugin->getMode() == 'test') {
        $redirect_url = 'https://www.sandbox.paypal.com/checkoutnow?token=' . $paypal_response['TOKEN'];
      }
      else {
        $redirect_url = 'https://www.paypal.com/checkoutnow?token=' . $paypal_response['TOKEN'];
      }
    }
    else {
      $redirect_url = '';
    }

    $data = [
      'return' => $form['#return_url'],
      'cancel' => $form['#cancel_url'],
      'total' => $payment->getAmount()->getNumber(),
    ];

    return $this->buildRedirectForm($form, $form_state, $redirect_url, $data, 'get');
  }

}
