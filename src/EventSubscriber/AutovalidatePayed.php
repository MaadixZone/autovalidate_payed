<?php
namespace Drupal\autovalidate_payed\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Drupal\state_machine\Event\WorkflowTransitionEvent;
use Drupal\Core\Entity\Query\QueryFactory;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\hook_event_dispatcher\Event\Entity\EntityUpdateEvent;

/**
 * Moves an order from validate to complete when is payed.
 */
class AutovalidatePayed implements EventSubscriberInterface {

  /**
   * QueryFactory definition.
   *
   * @var Drupal\Core\Entity\Query\QueryFactory
   */
  protected $entityQuery;

  /**
   * Constructor.
   *
   * @param \Drupal\Core\Entity\Query\QueryFactory $entity_query
   *   The query factory.
   */
  public function __construct(QueryFactory $entity_query) {
    $this->entityQuery = $entity_query;
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    // The format for adding a state machine event to subscribe to is:
    // {group}.{transition key}.pre_transition or {group}.{transition key}.post_transition
    // depending on when you want to react.
    $events = [
      'commerce_payment.authorize.post_transition' => ['setOrderStateViaPayment', -101],
      'commerce_payment.authorize_capture.post_transition' => ['setOrderStateViaPayment', -101],
      'commerce_payment.receive.post_transition' => ['setOrderStateViaPayment', -101],
//      'commerce_order.place.post_transition' => ['setOrderState',-101],
      'hook_event_dispatcher.entity.update' => ['setOrderState', -101]
    ];
    return $events;
  }

 /**
  * Set Order State depending on total amount of payments. React on Order.
  *
  * This is normally when a user follows a checkout process, the payments are
  * done before transition of Draft to Validation. So we need to subscribe to
  * order placed transition (Draft => Validation) when validation is mandatory.
  *
  *  @param
  *  \Drupal\hook_event_dispatcher\Event\Entity\EntityUpdateEvent $event
  *    The event we subscribe to.
  *
  * @todo when paid_total property in order is there we need to use this
  *  instead of doing the query to get payments. We cannot subscribe to
  *  transition as no entity is saved before/after, just field, and so on we
  *  cannot apply a transition to validate->completed to a draft order entity.
  *  Using a proof-of-concept hook_event_dispatcher, but we can move everything
  *  to hook_entity_update.
  */
  public function setOrderState(EntityUpdateEvent $event){
    if ( $event->getEntity()->getEntityTypeId() != 'commerce_order'){
      return;
    }
    if ($event->getEntity()->getState()->value != 'validation' ){
      return;
    }
    /** @var \Drupal\commerce_order\Entity\OrderInterface $order */
    $order = $event->getEntity();
    $payments_done = $this->checkOrderPayments($order);
    if ($payments_done < 1){
      $transition = $order->getState()->getWorkflow()->getTransition('validate');
      $order->state->first()->applyTransition($transition);
      $order->save();
    }
  }

 /**
  *  Set Order State depending on total amount of payments. React on payment.
  *
  *  When a regular checkout process is done the payment is before the change of
  *  commerce_order state from draft to validation. So we cannot do the
  *  transition from draft to complete. But this doesn't happen when a manual
  *  payment is done, an order then is in Validation state, so we can transit to
  *  completed state depending on payments.
  *
  *  @param \Drupal\state_machine\Event\WorkflowTransitionEvent $event
  *    The event we subscribe to.
  *
  *  @todo when paid_total property in order is there we need to use this
  *  instead of doing the query to get payments.
  */
  public function setOrderStateViaPayment(WorkflowTransitionEvent $event) {
    /** @var \Drupal\commerce_payment\Entity\PaymentInterface $payment */
    $payment = $event->getEntity();
    /** @var \Drupal\commerce_order\Entity\OrderInterface $order */
    $order = $payment->getOrder();
    if($order->getState()->value != 'validation'){
      // if it's not in validate state means that we are in checkout process
      // so do nothing.
      return;
    }
    $payments_done = $this->checkOrderPayments($order);
    if ($payments_done < 1){
      // If get here set the order to Complete and save it.
      $transition = $order->getState()->getWorkflow()->getTransition('validate');
      $order->getState()->applyTransition($transition);
      //$order->set('state', 'completed');
      $order->save();
    }
  }

 /**
  * Check the payments done in the Order.
  *
  * @param \Drupal\commerce_order\Entity\OrderInterface $order
  *   The order to check payments.
  *
  * @return int
  *   The priceInterface comparision @see \Drupal\commerce_price\Price 
  *   compareTo method. 0 if both prices are equal, 1 if the first one is
  *   greater, -1 otherwise, 2 if no payments are done.
  *
  * @todo move to service checkOrderPayments. Also in
  * payment_receipt/src/EventSubscriber/PaymentReceiptSubscriber.php.
  */
  protected function checkOrderPayments(OrderInterface $order){
    $query = $this->entityQuery->get('commerce_payment');
    $query->condition('order_id', $order->id());
    $query->condition('state.value', ['completed', 'authorization'], 'IN');
    $payment_ids = $query->execute();
    if (!empty($payment_ids)){
      $payments = entity_load_multiple('commerce_payment', $payment_ids);
      // Check the amount of payments adding to popped last element of array.
      // And after all comparing to order. Using Price data type.
      $base_payment = array_pop($payments);
      $total_paid = $base_payment->getBalance();
      foreach ($payments as $id => $payment){
        $total_paid = $total_paid->add($payment->getBalance());
      }

      return $order->getTotalPrice()->compareTo($total_paid);
    }
    return 2;
  }
}

