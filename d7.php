<?php 
/**
 * @file
 * Yandex.Market module Drupal 7 main file.
 */

/**
 * Implementation of hook_menu().
 */
function adept_yamarket_menu() 
{
  $items = array();

  $items['marketplace/api/order/accept'] = array(
    'type' => MENU_CALLBACK,
    'access arguments' => array('access content'),
    'page callback' => 'adept_yamarket_orderAccept',
  );

  $items['marketplace/api/order/status'] = array(
    'type' => MENU_CALLBACK,
    'access arguments' => array('access content'),
    'page callback' => 'adept_yamarket_orderStatus',
  );

  return $items;
}

/**
 * Принятие заказа
 */
function adept_yamarket_orderAccept()
{
  if (!adept_yamarket_httpauth()) {
    header("HTTP/1.1 403 Forbidden");
    exit;
  }

  $ymorder = file_get_contents('php://input');
  $ymorder = json_decode($ymorder);

  $response = new stdClass();
  $response->order = new stdClass();

  // Защита от дублей
  $double = adept_yamarket_orderCheck($ymorder->order->id);
  if ($double) {
    if (is_numeric($double)) {
      $response->order->accepted = TRUE;
      $response->order->id = $double;
      header('Content-Type: application/json');
      echo json_encode($response);
      exit;
    }
    header("HTTP/1.1 403 Forbidden");
    exit;
  }

  //Создание заказа
  $order = uc_order_new();

  //Необходимые комментарии
  uc_order_comment_save($order->order_id, 0, t('Order created programmatically for YaMarket integration.'), 'admin');
  uc_order_comment_save($order->order_id, 0, t('Номер заказа на YaMarket: ' . $ymorder->order->id), 'order');
  $shipmentDate = '';
  $shipmentId = '';
  foreach ($ymorder->order->delivery->shipments as $shipment) {
    uc_order_comment_save($order->order_id, 0, t('Дата отгрузки: ' . $shipment->shipmentDate), 'order');
    $shipmentDate = $shipment->shipmentDate;
    if (empty($shipmentId)) {
      $shipmentId = $shipment->id;
    } else {
      $shipmentId .= ' ' . $shipment->id;
    }
  }

  $cid = uc_cart_get_id();
  uc_cart_empty($cid);

  //Добавляем товары
  foreach ($ymorder->order->items as $item) {
    $nid = adept_yamarket_findNidByCode($item);
    !!strstr($item->offerId,'-') ? $count = trim(strstr($item->offerId,'-'), "-") : $count = 1;
    uc_cart_add_item($nid, $count*$item->count, $data = NULL, $cid, $msg = TRUE, $check_redirect = TRUE, $rebuild = TRUE);
    adept_yamarket_freezing($item->offerId, $item->count, $order->order_id);
  }

  // добавляем доставку
  uc_order_line_item_add(
    $order->order_id, 
    'shipping', 
    'Доставка по Екатеринбургу', 
    '0'
  );

  // добавляем e-mail
  $order->primary_email = variable_get('adept_ym_mail', '');
  // Создаем UCXF поля
  $address = UcAddressesAddress::newAddress();
  $address->setField('ucxf_status',variable_get( 'adept_ym_status', 'fiz' ));
  // Доставка
  $address->setField('ucxf_shipping',variable_get( 'adept_ym_dostavka', 4 )); 
  // Тип доставки
  $order->quote['method']       = variable_get( 'adept_ym_dostavka_method', 'method' ); 
  $order->quote['rate']         = variable_get( 'adept_ym_dostavka_rate', 0 );
  $order->quote['accessorials'] = variable_get( 'adept_ym_dostavka_accessorials', 0 );

  $address->setField('ucxf_customer_mail',variable_get( 'adept_ym_customer_mail', '' ));
  $address->setField('ucxf_company_contact',variable_get( 'adept_ym_dostavka', 'Яндекс.Маркет УСС' ));
  $order->billing_first_name  = variable_get( 'adept_ym_customer_first_name', 'Яндекс' );
  $order->billing_last_name   = variable_get( 'adept_ym_customer_last_name', 'Маркет' );
  $order->billing_phone       = variable_get( 'adept_ym_customer_phone', '000' );
  $order->billing_city        = variable_get( 'adept_ym_customer_city', 'Екатеринбург' );
  $order->billing_postal_code = variable_get( 'adept_ym_customer_postal_code', '620000' );

  $order->order_status            = uc_order_state_default('post_checkout');
  $order->uc_addresses['billing'] = $address;
  $order->products                = uc_cart_get_contents($cid);
  $order->line_items              = uc_order_load_line_items($order);
  $order->order_total             = uc_order_get_total($order, TRUE);

  uc_order_save($order);

  $order = uc_order_load($order->order_id);
  if (
    !$ymorder->order->fake 
    && $ymorder->order->paymentType == 'POSTPAID'
  ) {
    adept_yamarket_orderPay($order->order_id);
  } elseif (
    !$ymorder->order->fake 
    && !isset($ymorder->order->paymentType)
  ) {
    adept_yamarket_orderPay($order->order_id);
  } elseif (
    !$ymorder->order->fake 
    && empty($ymorder->order->paymentType)
  ) {
    adept_yamarket_orderPay($order->order_id);
  }

  uc_order_save($order);

  watchdog('Яндекс.Маркет order created', $order->order_id);

  $response->order->accepted = TRUE;
  $response->order->id = $order->order_id;
  header('Content-Type: application/json');
  echo json_encode($response);
}

/**
 * Статус заказа от ЯМ
 */
function adept_yamarket_orderStatus()
{
  if (!adept_yamarket_httpauth()) {
    header("HTTP/1.1 403 Forbidden");
    exit;
  }
  $ymorder = file_get_contents('php://input');
  $ymorder = json_decode($ymorder);

  if ($ymorder->order->status == 'CANCELLED') {
    adept_yamarket_orderCancel($ymorder->order->id);
  } else {
    adept_yamarket_orderStatusUpdate($ymorder->order);
  }

  header("HTTP/1.1 200 OK");
  exit;
}
