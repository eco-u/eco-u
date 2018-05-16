<?php
// catalog/controller/ajax/index.php
class ControllerAjaxIndex extends Controller {
  // Customers
    // Registration
    public function ajaxRegisterCustomer() {
          $arRequest = $this->request->post;
          $arUser['firstname'] = '';
          $arUser['lastname'] = '';
          $arUser['fax'] = '';
          $arUser['company'] = '';
          $arUser['address_1'] = '';
          $arUser['address_2'] = '';
          $arUser['city'] = '';
          $arUser['postcode'] = '';
          $arUser['country_id'] = 0;
          $arUser['zone_id'] = 0;
          $arUser['telephone'] = str_replace(Array('(', ')', '+', '-', ' '), '', $arRequest['telephone']);
          $arUser['password'] = $arRequest['pass'];
          $arUser['email'] = $arUser['telephone'].'@eco-u.ru';
          
          $this->load->model('account/customer');
          $customer_id = $this->model_account_customer->addCustomer($arUser);
          return $customer_id;
    }
    
    // SMS Confirm
    public function ajaxSendConfirmationSms() {
          $this->load->model('sms/confirmation');
          $arRequest = $this->request->post;
          $phone = $arRequest['telephone'];
          $phoneFormat = str_replace(Array('(', ')', '+', '-', ' '), '', $phone);
          if(!empty($phoneFormat))
          {
              $code = substr(str_replace('.', '', hexdec(md5(time()+$phone))), 0, 6);
              $message = str_replace('[REPLACE]', $code, $this->config->get('config_sms_confirmation_text'));
              $this->model_sms_confirmation->addCode($code, time()+300);
              $this->model_sms_confirmation->clearOldCodes();
              $result = json_decode($this->model_sms_confirmation->sendSMS($phoneFormat, $message));
              if($result->status == 'success')
              {
                  echo json_encode(Array('status' => 'success'));
              }
              else
              {
                  echo json_encode(Array('status' => 'error'));
              }
          }
    }
    
    // Check registration
    public function ajaxValidateRegistration() {
        
          $this->load->model('sms/confirmation');
          $arRequest = $this->request->post;
          $phone = $arRequest['telephone'];
          $password = $arRequest['pass'];
          $code = $arRequest['smscode'];
          
          //$this->model_sms_confirmation->clearOldCodes();
          $valid = $this->model_sms_confirmation->validateCode($code);
          if($valid === 0)
          {
              echo json_encode(Array('status' => 'error'));
          }
          else
          {
              $customer_id = $this->ajaxRegisterCustomer();
              if($customer_id) {
                  $this->customer->loginByPhone($phone, $password);
                  echo json_encode(Array('status' => 'success', 'customer_id' => $customer_id));
              } else {
                  echo json_encode(Array('status' => 'error'));
              }
          }
    }
    
    // Check new password
    public function ajaxValidateNewPassword() {
        
          $this->load->model('sms/confirmation');
          $arRequest = $this->request->get;
          $phoneFormat = str_replace(Array('(', ')', '+', '-', ' '), '', $arRequest['telephone']);
          $password = $arRequest['password'];
          
          $valid = $this->model_sms_confirmation->validateCode($password);
          if($valid === 0)
          {
              echo json_encode(Array('status' => 'error'));
          }
          else
          {
              $result = $this->customer->getByPhone($phoneFormat);
              if(isset($result['customer_id'])) {
                  $this->customer->setPassword($password, $result['customer_id']);
                  $this->customer->loginByPhone($phoneFormat, $password);
                  echo json_encode(Array('status' => 'success'));
              } else {
                  echo json_encode(Array('status' => 'error'));
              }
          }
    }
  
    // Set new password    
    public function ajaxSetPassword() {
        $arRequest = $this->request->get;
        $password = $arRequest['password'];
        $cid = 17;
        $this->customer->setPassword($password, $cid);
    }
    
    // Login
    public function ajaxLoginByPhone() {
        $arUser = $this->request->post;
        $phone = str_replace(Array('(', ')', '+', '-', ' '), '', $arUser['telephone']);
        $password = $arUser['password'];
        if(!empty($password)) {
            $response = Array('status' => 'success');
            $locked = '';
            if($this->customer->loginByPhone($phone, $password, false, $locked)) {
                echo json_encode($response);
            }
            else
            {
                if($locked) {
                    if($locked == 1) {
                        $m = 'минуту';
                    } elseif($locked < 5) {
                        $m = 'минуты';
                    } else {
                        $m = 'минут';
                    }
                    $locked = 'Ваш аккаунт заблокирован на ' . $locked . ' ' . $m;// . date('H:i:s d.m.Y', strtotime($locked));
                } else {
                    $locked = '';
                }
                echo json_encode(Array('status' => 'error', 'locked' => $locked));
            }
        }
    }
    
    // Logout
    public function ajaxLogout() {
        $this->customer->logout();
        $this->response->setOutput(json_encode(Array('status' => 'success')));
    }

    // Get customer
    public function getCustomerByTelephone() {
      // ---
        // Init
          $telephone = preg_replace("/[^0-9,.]/", "", $this->request->post['telephone']);
          
          $response = new stdClass();
        // ---

        // Get
          $this->load->model('account/customer');
          
          $customer = $this->model_account_customer->getCustomerByTelephone($telephone);

          if( empty($customer) ){
            $response->result = false;
            $response->message = 'Пользователь не найден';
          }
          else{
            $response->result = true;
            $response->message = 'Пользователь найден';

            $response->customer = $customer;
            $response->addresses = $this->model_account_customer->getAddresses($customer['customer_id']);
          }
        // ---

        // Response
        $response->status = 'success';
        
        echo json_encode($response);
        exit;
      // ---
    }  
  // ---


  // Orders
    // Add
    public function ajaxAddOrder($return = false) {
          $this->load->model('checkout/order');
          $this->cache->set('latest_category_sort', 0);
          
          // Основные данные заказа
          $data['products'] = $this->cart->getProducts();
          $total = 0;
          foreach($data['products'] as $i => $product) {
              if(empty($product['weight_variants'])) {
                  $data['products'][$i]['amount'] = round($product['quantity']);
                  $data['products'][$i]['variant'] = 1;
              } else {
                  $arWeightVariants = explode(',', $product['weight_variants']);
                  $data['products'][$i]['amount'] = round($product['quantity']/$arWeightVariants[$product['weight_variant']]);
                  $data['products'][$i]['variant'] = $arWeightVariants[$product['weight_variant']];
              }
              $total += ($this->tax->calculate($product['price'], $product['tax_class_id'], $this->config->get('config_tax')) * $product['quantity']);
          }
          $data['total'] = $total;
          
          $is_guest = false;
          
          $data['invoice_prefix'] = $this->config->get('config_invoice_prefix');
          $data['store_id'] = $this->config->get('config_store_id');
          $data['store_name'] = $this->config->get('config_name');
          $data['store_url'] = $this->config->get('config_url');

          if(isset($this->session->data['customer_id'])) {
            $data['customer_id'] = $this->session->data['customer_id'];
          } else {
            $data['customer_id'] = $this->customer->getId();
          }

          $data['customer_group_id'] = $this->customer->getGroupId();
          $data['firstname'] =  isset($this->request->post['firstname']) ? $this->request->post['firstname'] :$this->customer->getFirstName();
          $data['lastname'] = $this->customer->getLastName();
          $data['email'] = $this->customer->getEmail();
          $data['telephone'] = isset($this->request->post['telephone']) ? $this->clearTelephone($this->request->post['telephone']) : $this->customer->getTelephone();
          $data['fax'] = $this->customer->getFax();
          
          // Оплата
          $data['payment_firstname'] = isset($this->request->post['firstname']) ? $this->request->post['firstname'] : $this->customer->getFirstName();
          $data['payment_lastname'] = $this->customer->getLastName();
          $data['payment_company'] = '';
          $data['payment_address_1'] = '';
          $data['payment_address_2'] = '';
          $data['payment_city'] = '';
          $data['payment_postcode'] = '';
          $data['payment_country'] = '';
          $data['payment_country_id'] = '';
          $data['payment_zone'] = '';
          $data['payment_zone_id'] = '';
          $data['payment_address_format'] = ''; 
          $data['payment_method'] = '';
          $data['payment_code'] = '';
          
          // Доставка
          $data['shipping_firstname'] = isset($this->request->post['firstname']) ? $this->request->post['firstname'] : $this->customer->getFirstName();
          $data['shipping_lastname'] = $this->customer->getLastName();
          $data['shipping_company'] = '';
          $data['shipping_address_1'] = '';
          $data['shipping_address_2'] = '';
          $data['shipping_city'] = '';
          $data['shipping_postcode'] = '';
          $data['shipping_country'] = '';
          $data['shipping_country_id'] = '';
          $data['shipping_zone'] = '';
          $data['shipping_zone_id'] = '';
          $data['shipping_address_format'] = '';
          $data['shipping_method'] = '';
          $data['shipping_code'] = '';
          $data['custom_field'] = '';
          $data['payment_custom_field'] = '';
          $data['shipping_custom_field'] = '';
          
          // Прочее
          $data['comment'] = '';
          $data['affiliate_id'] = '';
          $data['commission'] = '';
          $data['marketing_id'] = '';
          $data['tracking'] = '';
          $data['currency_id'] = $this->currency->getId($this->session->data['currency']);
          $data['currency_code'] = $this->session->data['currency'];
          $data['currency_value'] = $this->currency->getValue($this->session->data['currency']);
          $data['ip'] = $this->request->server['REMOTE_ADDR'];
          $data['user_agent'] = '';
          $data['accept_language'] = '';
          $data['language_id'] = $this->config->get('config_language_id');

          if (!empty($this->request->server['HTTP_X_FORWARDED_FOR'])) {
                  $data['forwarded_ip'] = $this->request->server['HTTP_X_FORWARDED_FOR'];
          } elseif (!empty($this->request->server['HTTP_CLIENT_IP'])) {
                  $data['forwarded_ip'] = $this->request->server['HTTP_CLIENT_IP'];
          } else {
                  $data['forwarded_ip'] = '';
          }

          if (isset($this->request->server['HTTP_USER_AGENT'])) {
                  $data['user_agent'] = $this->request->server['HTTP_USER_AGENT'];
          } else {
                  $data['user_agent'] = '';
          }

          if (isset($this->request->server['HTTP_ACCEPT_LANGUAGE'])) {
                  $data['accept_language'] = $this->request->server['HTTP_ACCEPT_LANGUAGE'];
          } else {
                  $data['accept_language'] = '';
          }
          
          $order_id = $this->model_checkout_order->addOrder($data);
          
          $this->response->addHeader('Content-Type: application/json');
           
          if($order_id) {
            // Подтверждение купона
            if(isset($this->session->data['coupon'])) {
                $this->load->model('extension/total/coupon');

                $order_info = Array(
                    'order_id' => $order_id,
                    'customer_id' => $this->customer->getId()
                );
                $coupon = $this->model_extension_total_coupon->getCoupon($this->session->data['coupon']);

                // Totals
                  $this->load->model('extension/extension');

                  $totals = array();
                  $taxes = $this->cart->getTaxes();
                  $total = 0;

                  // Because __call can not keep var references so we put them into an array. 
                  $total_data = array(
                          'totals' => &$totals,
                          'taxes'  => &$taxes,
                          'total'  => &$total
                  );

                  $sort_order = array();

                  $results = $this->model_extension_extension->getExtensions('total');

                  foreach ($results as $key => $value) {
                          $sort_order[$key] = $this->config->get($value['code'] . '_sort_order');
                  }

                  array_multisort($sort_order, SORT_ASC, $results);

                  foreach ($results as $result) {
                          if ($this->config->get($result['code'] . '_status')) {
                                  $this->load->model('extension/total/' . $result['code']);
                                  $this->{'model_extension_total_' . $result['code']}->getTotal($total_data);
                          }
                  }

                  $sort_order = array();

                  foreach ($totals as $key => $value) {
                          $sort_order[$key] = $value['sort_order'];
                  }

                  array_multisort($sort_order, SORT_ASC, $totals);

                  foreach($totals as $total) {
                      if($total['code'] == 'total') {
                          $total_price = ceil($total["value"]);
                          break;
                      }
                  }

                $order_total = Array(
                    'value' => $total_price,
                    'title' => "#{$order_id} ({$coupon['code']})"
                );
                $data['total'] = $total_price;
                
                if(!$this->customer->getCouponDiscount()) {
                    $data['discount'] = $this->cart->getOrderDiscount();
                } else {
                    if(isset($this->session->data['personal_discount'])) $personalDiscount = floor($this->session->data['personal_discount']/100*$this->cart->getTotal());
                    else $personalDiscount = 0;
                    $coupon = $this->customer->getCouponDiscount();
                    $couponDiscount = floor($coupon['discount']/100*$this->cart->getTotal());
                    if($couponDiscount > $personalDiscount) {
                        $data['coupon_discount'] = $couponDiscount;
                    } else {
                        $data['discount'] = $personalDiscount;
                    }
                }
                
                $this->model_extension_total_coupon->confirm($order_info, $order_total);
                $this->model_checkout_order->editOrder($order_id, $data);
                $this->model_checkout_order->addOrderHistory($order_id, 1);
              }
            $json = Array('status' => 'success', 'orderId' => $order_id);
          } else {
            $json = Array('status' => 'error');
          }
          
          if($is_guest) {
            $this->customer->logout();
          }
          
          if($return) {
            return $order_id;
          }
          
          $this->response->setOutput(json_encode($json));
    }
    
    // Create
    public function ajaxCreateOrder() {
          $this->load->model('checkout/order');
          
          $post = $this->request->post;
          
          $do_logout = false;
          $is_guest = false;
          if(!$this->customer->isLogged()) {
                  $is_guest = true;
                  $this->customer->loginByPhone($post['telephone'], false, true);
                  $do_logout = true;
          }
        
          // Основные данные заказа
          $data['products'] = $this->cart->getProducts();
          foreach($data['products'] as $i => $product) {
              if(empty($product['weight_variants'])) {
                  $data['products'][$i]['amount'] = round($product['quantity']);
                  $data['products'][$i]['variant'] = 1;
              } else {
                  $arWeightVariants = explode(',', $product['weight_variants']);
                  $data['products'][$i]['amount'] = round($product['quantity']/$arWeightVariants[$product['weight_variant']]);
                  $data['products'][$i]['variant'] = $arWeightVariants[$product['weight_variant']];
              }
          }
          $data['total'] = $this->cart->getTotal();
          $data['invoice_prefix'] = $this->config->get('config_invoice_prefix');
          $data['store_id'] = $this->config->get('config_store_id');
          $data['store_name'] = $this->config->get('config_name');
          $data['store_url'] = $this->config->get('config_url');
          if(isset($this->session->data['customer_id'])) {
              $data['customer_id'] = $this->session->data['customer_id'];
          } else {
              $data['customer_id'] = $this->customer->getId();
          }
          $data['customer_group_id'] = $this->customer->getGroupId();
          $data['firstname'] = $post['customer'];//$this->customer->getFirstName();
          $data['lastname'] = $this->customer->getLastName();
          $data['email'] = $this->customer->getEmail();
          $data['telephone'] = $post['telephone'];
          $data['fax'] = $this->customer->getFax();
          
          // Оплата
          $data['payment_firstname'] = $post['customer'];//$this->customer->getFirstName();
          $data['payment_lastname'] = $this->customer->getLastName();
          $data['payment_company'] = '';
          $data['payment_address_1'] = $post['address'];
          $data['payment_address_2'] = '';
          $data['payment_city'] = '';
          $data['payment_postcode'] = '';
          $data['payment_country'] = '';
          $data['payment_country_id'] = '';
          $data['payment_zone'] = '';
          $data['payment_zone_id'] = '';
          $data['payment_address_format'] = $post['address']; 
          $data['payment_method'] = $post['payment_method_title'];
          $data['payment_code'] = $post['payment_method_code'];
          
          // Доставка
          $data['shipping_firstname'] = $post['customer'];//$this->customer->getFirstName();
          $data['shipping_lastname'] = $this->customer->getLastName();
          $data['shipping_company'] = '';
          $data['shipping_address_1'] = $post['address'];
          $data['shipping_address_2'] = '';
          $data['shipping_city'] = '';
          $data['shipping_postcode'] = '';
          $data['shipping_country'] = '';
          $data['shipping_country_id'] = '';
          $data['shipping_zone'] = '';
          $data['shipping_zone_id'] = '';
          $data['shipping_address_format'] = $post['address'];
          $data['shipping_method'] = '';
          $data['shipping_code'] = '';
          $data['custom_field'] = '';
          $data['payment_custom_field'] = '';
          $data['shipping_custom_field'] = '';
          
          // Прочее
          $data['comment'] = $post['comment'];
          $data['affiliate_id'] = '';
          $data['commission'] = '';
          $data['marketing_id'] = '';
          $data['tracking'] = '';
          $data['currency_id'] = $this->currency->getId($this->session->data['currency']);
          $data['currency_code'] = $this->session->data['currency'];
          $data['currency_value'] = $this->currency->getValue($this->session->data['currency']);
          $data['ip'] = $this->request->server['REMOTE_ADDR'];
          $data['user_agent'] = '';
          $data['accept_language'] = '';
          $data['language_id'] = $this->config->get('config_language_id');

          if (!empty($this->request->server['HTTP_X_FORWARDED_FOR'])) {
                  $data['forwarded_ip'] = $this->request->server['HTTP_X_FORWARDED_FOR'];
          } elseif (!empty($this->request->server['HTTP_CLIENT_IP'])) {
                  $data['forwarded_ip'] = $this->request->server['HTTP_CLIENT_IP'];
          } else {
                  $data['forwarded_ip'] = '';
          }

          if (isset($this->request->server['HTTP_USER_AGENT'])) {
                  $data['user_agent'] = $this->request->server['HTTP_USER_AGENT'];
          } else {
                  $data['user_agent'] = '';
          }

          if (isset($this->request->server['HTTP_ACCEPT_LANGUAGE'])) {
                  $data['accept_language'] = $this->request->server['HTTP_ACCEPT_LANGUAGE'];
          } else {
                  $data['accept_language'] = '';
          }
          
          $order_id = $this->model_checkout_order->addOrder($data);
          
          
          if($order_id) {
              // Подтверждение купона
              if(isset($this->session->data['coupon'])) {
                  $this->load->model('extension/total/coupon');

                  $order_info = Array(
                      'order_id' => $order_id,
                      'customer_id' => $this->customer->getId()
                  );
                  $coupon = $this->model_extension_total_coupon->getCoupon($this->session->data['coupon']);

                  // Totals
                    $this->load->model('extension/extension');

                    $totals = array();
                    $taxes = $this->cart->getTaxes();
                    $total = 0;

                    // Because __call can not keep var references so we put them into an array. 
                    $total_data = array(
                            'totals' => &$totals,
                            'taxes'  => &$taxes,
                            'total'  => &$total
                    );

                    $sort_order = array();

                    $results = $this->model_extension_extension->getExtensions('total');

                    foreach ($results as $key => $value) {
                            $sort_order[$key] = $this->config->get($value['code'] . '_sort_order');
                    }

                    array_multisort($sort_order, SORT_ASC, $results);

                    foreach ($results as $result) {
                            if ($this->config->get($result['code'] . '_status')) {
                                    $this->load->model('extension/total/' . $result['code']);
                                    $this->{'model_extension_total_' . $result['code']}->getTotal($total_data);
                            }
                    }

                    $sort_order = array();

                    foreach ($totals as $key => $value) {
                            $sort_order[$key] = $value['sort_order'];
                    }

                    array_multisort($sort_order, SORT_ASC, $totals);

                    foreach($totals as $total) {
                        if($total['code'] == 'total') {
                            $total_price = ceil($total["value"]);
                            break;
                        }
                    }

                  $order_total = Array(
                      'value' => $total_price,
                      'title' => "#{$order_id} ({$coupon['code']})"
                  );
                  $data['total'] = $total_price;
                  
                  if(!$this->customer->getCouponDiscount()) {
                      $data['discount'] = $this->cart->getOrderDiscount();
                  } else {
                      if(isset($this->session->data['personal_discount'])) $personalDiscount = floor($this->session->data['personal_discount']/100*$this->cart->getTotal());
                      else $personalDiscount = 0;
                      $coupon = $this->customer->getCouponDiscount();
                      $couponDiscount = floor($coupon['discount']/100*$this->cart->getTotal());
                      if($couponDiscount > $personalDiscount) {
                          $data['coupon_discount'] = $couponDiscount;
                      } else {
                          $data['discount'] = $personalDiscount;
                      }
                  }
                  
                  $this->model_extension_total_coupon->confirm($order_info, $order_total);
                  $this->model_checkout_order->editOrder($order_id, $data);
                  $this->model_checkout_order->addOrderHistory($order_id, 1);
              }
          }
          
          $strDateTime = 'Дата и время доставки: ' . $post['date'] . ' ' . $post['time'] . PHP_EOL;
          $strDeliveryInterval = $this->request->post['date'].' '.$this->request->post['time'];
          $customer_id = (int)$this->customer->getId();
          $telephone = str_replace(Array('(', ')', '+', ' ', '-'), '', $post['telephone']);
        
          $customer_id = (int)$this->customer->getId();
        
          $this->load->model('dadata/index');
        
          $structure = array("ADDRESS");
          $record = array($post['address']);
          $result = $this->model_dadata_index->cleanRecord($structure, $record);
        
          if(isset($result['data'][0][0]['beltway_hit'])) {
              $bwhit = $result['data'][0][0]['beltway_hit'];
              if($result['data'][0][0]['beltway_hit'] == 'IN_MKAD') {
                  $delivery_price = 250;
              } else {
                  $delivery_price = 600;
              }
          } else {
              $bwhit = 'NOT_IN_MKAD';
              $delivery_price = 600;
          }
        
          $data2 = Array(
              'address' => $post['address'],
              'comment' => $post['comment'],
              'delivery_price' => $delivery_price,
              'delivery_time' => $strDateTime,
              'delivery_interval' => $strDeliveryInterval,
              'payment_method' => $post['payment_method_code'],
              'mkad' => $bwhit
          );
          if(!$this->customer->getCouponDiscount()) {
              $data2['discount'] = $this->cart->getOrderDiscount();
              if(isset($this->session->data['personal_discount'])) {
                      $personalPercentage = (int)$this->session->data['personal_discount'];
                      $data2['discount_percentage'] = $personalPercentage;
              }
          } else {
              if(isset($this->session->data['personal_discount'])) {
                  $personalDiscount = floor($this->session->data['personal_discount']/100*$this->cart->getTotal());
                  if($this->session->data['personal_discount'] <= 10) {
                      $personalPercentage = (int)$this->session->data['personal_discount'];
                  }
              } else {
                  $personalDiscount = 0;
              }
              $coupon = $this->customer->getCouponDiscount();
              $couponDiscount = floor($coupon['discount']/100*$this->cart->getTotal());
              if($coupon['discount'] <= 100) {
                  $couponPercentage = $coupon['discount'];
              }
              if($couponDiscount > $personalDiscount) {
                  $data2['coupon_discount'] = $couponDiscount;
                  $data2['discount_percentage'] = $couponPercentage;
              } else {
                  $data2['discount'] = $personalDiscount;
                  $data2['discount_percentage'] = $personalPercentage;
              }
          }
          $payment_method_online = $post['payment_method_code'] == 'cod' ? false : true;
          if($this->model_checkout_order->setDelivery($order_id, $customer_id, $data2, ($payment_method_online ? 16 : 1))) {
              // Добавление адреса доставки в список адресов клиента
              if($post['address_new'] && $this->customer->isLogged()) {
                  $this->customer->setAddress(0, $post['address']);
              }
              // Очистка корзины
              // $this->cart->clear();
              if($is_guest) {
                  $this->customer->logout();
              }
              // $this->cart->clear();
              if($payment_method_online) {
                  $results = $this->load->controller('extension/payment/rbs/payment', $order_id);
                  $json = $results;
              } else {
                  // Отправка sms        
                  $this->load->model('sms/confirmation');
                  $message = str_replace('[REPLACE]', $order_id, $this->config->get('config_sms_order_new_text'));
                  $this->model_sms_confirmation->sendSMS($telephone, $message);
                  $json = Array('status' => 'success', 'order_id' => $order_id);
              }
          } else {
              if($is_guest) {
                  $this->customer->logout();
              }
              $json = Array('status' => 'error');
          }
          
          
          $this->response->addHeader('Content-Type: application/json');
          $this->response->setOutput(json_encode($json));
    }
    
    // Delivery
    public function ajaxGetDeliveryPrice() {
      // ---

        // Check
          if( !isset($this->request->post['firstname']) && !isset($this->request->post['telephone']) && 
                !isset($this->request->post['address']) ){
            // ---
              $response->status = 'error';
              $response->message = 'Нет данных';
              

              echo json_encode($response);
              exit;
            // ---
          }
        // ---

        // Init
          $firstname = $this->request->post['firstname'];
          $telephone = preg_replace("/[^0-9,.]/", "", $this->request->post['telephone']);

          $price = (int)$this->request->post['price'];
          $address = $this->request->post['address'];
          $address_new = $this->request->post['address_new'];

          $order_id = isset($this->request->post['order_id']) ? (int)$this->request->post['order_id'] : false;
          
          $response = new stdClass();
        // ---

        // Create order
          if($order_id !== false && !$order_id) {
            $order_id = (int)$this->ajaxAddOrder(true);
          }
        // ---
        
        // Roistat
          $this->load->model('checkout/order');

          $order_roistat_visit_id = array_key_exists('roistat_visit', $_COOKIE) ? $_COOKIE['roistat_visit'] : "неизвестно";
          $this->model_checkout_order->addRoistatVisitId($order_id, $order_roistat_visit_id);
        // ---

        // Get sipping area
          $this->load->model('dadata/index');
          
          $structure = array("ADDRESS");
          $record = array($address);
          $result = $this->model_dadata_index->cleanRecord($structure, $record);

          $response->address = null;
          $response->mkad = null;
          $response->tobeltway = null;

          if( isset($result['data'][0][0]['source']) ) {
            $response->address = $result['data'][0][0]['source'];
          }

          if( isset($result['data'][0][0]['beltway_hit']) && $result['data'][0][0]['beltway_hit'] == 'IN_MKAD' ) {
              $response->mkad = 'IN_MKAD';
          } else {
              $response->mkad = 'OUT_MKAD';
          }

          if( isset($result['data'][0][0]['beltway_distance']) ) {
            $response->tobeltway = $result['data'][0][0]['beltway_distance'];
          }

          // Check
          if( $response->address == null || $response->mkad == null ) {
            // ---
              $response->status = 'error';
              $response->message = 'Не удалось поулчить адрес доставки';
              echo json_encode($response);
              exit;
            // ---
          }
        // ---

        // Get shipping methods
          $method_data = array();

          $this->load->model('extension/extension');

          $results = $this->model_extension_extension->getExtensions('shipping');

          $methods = [];

          foreach ($results as $key => $method) {
            // ---
              if( $method['code'] == 'free' ) { $cost = (int)$this->config->get('free_total'); }
              else { $cost = (int)$this->config->get($method['code'].'_cost'); }

              if( $method['code'] == 'flat' || $method['code'] == 'mkadout' ) { $netcost = (int)$this->config->get($method['code'].'_netcost'); }
              else { $netcost = 0; }

              if( $method['code'] == 'mkadout' ) { $milecost = (int)$this->config->get($method['code'].'_milecost'); }
              else { $milecost = 0; }

              $methods[$method['code']] = array(
                'extension_id' => $method['extension_id'],
                'cost' => $cost,
                'netcost' => $netcost,
                'milecost' => $milecost,
                'title' => $method['title']
              );
            // ---
          }

          $response->methods = $methods;

          // Check
          if( empty($response->methods) ) {
            // ---
              $response->status = 'error';
              $response->message = 'No shipping methods';
              echo json_encode($response);
              exit;
            // ---
          }
        // ---

        // Calculate delivery
          unset($this->session->data['shipping_price']);
          unset($this->session->data['shipping_code']);
          unset($this->session->data['shipping_address_1']);
          unset($this->session->data['shipping_method']);

          $response->deliveryprice = null;
          $response->method = null;

          if ( isset($response->methods['free']) ){
            // ---

              // Free
              if( $price >= $response->methods['free']['cost'] ){
                $response->deliveryprice = 0;
                $response->method = 'free';
              }
              // Mkad
              else {
                // ---
                  if ( isset($response->methods['flat']) && isset($response->methods['mkadout']) ){
                    // Inside
                    if( $response->mkad == 'IN_MKAD' ){
                      $response->deliveryprice = $response->methods['flat']['cost'];
                      $response->method = 'flat';
                    }
                    // Outside
                    else {
                      if( $response->tobeltway != null ){
                        $response->deliveryprice = (int)$response->methods['mkadout']['cost'] + (int)$response->methods['mkadout']['milecost'] * (int)$response->tobeltway;
                      }
                      else $response->deliveryprice = (int)$response->methods['mkadout']['cost'];

                      $response->method = 'mkadout';
                    }
                  }
                // ---
              }

            // ---
          }
          else {
            // ---

              if ( isset($response->methods['flat']) && isset($response->methods['mkadout']) ){
                // Inside
                if( $response->mkad == 'IN_MKAD' ){
                  $response->deliveryprice = $response->methods['flat']['cost'];
                  $response->method = 'flat';
                }
                // Outside
                else {
                  if( $response->tobeltway != null ){
                      $response->deliveryprice = (int)$response->methods['mkadout']['cost'] + (int)$response->methods['mkadout']['milecost'] * (int)$response->tobeltway;
                    }
                    else $response->deliveryprice = (int)$response->methods['mkadout']['cost'];

                  $response->method = 'mkadout';
                }
              }

            // ---
          }

          // Check
          if( $response->deliveryprice === null ) {
            // ---
              $response->status = 'error';
              $response->message = 'No delivery price';
              echo json_encode($response);
              exit;
            // ---
          }

          $this->load->model('extension/shipping/' . $response->method);
          $quote = $this->{'model_extension_shipping_' . $response->method}->getQuote(array('zone_id' => 0, 'country_id' => 0));

          $this->session->data['shipping_price'] = $response->deliveryprice;
          $this->session->data['shipping_code'] = $response->method;
          $this->session->data['shipping_address_1'] =  $response->address;
          $this->session->data['shipping_method'] = $quote['quote'][$response->method]['title'];
        // ---

        // Response
        $response->order_id = $order_id;
        $response->status = 'success';
        $response->message = 'Цена доставки получена';
        $response->session = $this->session;

        echo json_encode($response);
        exit;
      // ---
    }
    
    // Confirm and go to payment
    public function ajaxConfirmOrder() {
      // ---

        // Init
          $firstname = $this->request->post['firstname'];
          $telephone = preg_replace("/[^0-9,.]/", "", $this->request->post['telephone']);

          $price = (int)$this->request->post['price'];
          $address = $this->request->post['address'];
          $comment = $this->request->post['comment'];
          
          $order_id = isset($this->request->post['order_id']) ? (int)$this->request->post['order_id'] : 0;

          $payment_method = $this->request->post['payment_method_title'];
          $payment_code = $this->request->post['payment_method_code'];

          $strDateTime = 'Дата и время доставки: '.$this->request->post['date'].' '.$this->request->post['time'].PHP_EOL;
          $strDeliveryInterval = $this->request->post['date'].' '.$this->request->post['time'];

          $response = new stdClass();

          // Check
            if( $order_id == 0 ) {
              // ---
                $response->status = 'error';
                $response->message = 'Заказ не найден';
                echo json_encode($response);
                exit;
              // ---
            }
          // ---
        // ---

        // Old tmp
          //    TODO 2018-03-27 Отключил принудительную авторизацию по причине смены корзины
          //    Как результат $this->cart->getTotal() $this->cart->getProducts() и т.д.
          //    возвращали чужую, в основном пустую корзину. В результате итоги (totals)
          //    заказа были нулевые
          
          //$is_guest = false;
          //      if($customer_id == 0) {
          //          $is_guest = true;
          //          $this->customer->loginByPhone($telephone, false, true);
          //          $customer_id = (int)$this->customer->getId();
          //      }
        // ---
        
        // Check customer
          $this->load->model('account/customer');
          $customer = $this->model_account_customer->getCustomerByTelephone($telephone);

          if( empty($customer) ) {
            // Create new customer
              $password = $this->model_account_customer->generatePassword();

              $customer_data = array(
                'firstname' => $firstname,
                'lastname' => '',
                'email' => '',
                'telephone' => $telephone,
                'fax' => '',
                'password' => $password,
                'address_1' => $address
              );

              $customer_id = $this->model_account_customer->addCustomer($customer_data);
            // ---

            // SMS alert
              $this->load->model('sms/confirmation');
              $message = str_replace('[REPLACE]', $password, $this->config->get('config_sms_password_new_text'));
              $this->model_sms_confirmation->sendSMS($telephone, $message);
            // ---
          }
          else{
            // ---
              $customer_id = $customer['customer_id'];

              // Add new address
                $addresses = $this->model_account_customer->getAddresses($customer_id);

                if( $addresses == false ) {
                  $this->customer->setAddress(0,$address,$customer_id);
                }
              // ---

            // ---
          }
        // ---
            
        // Set data
          $data = Array(
              'price' => $price,
              'address' => $this->session->data['shipping_address_1'],
              'comment' => $comment,
              'delivery_price' => $this->session->data['shipping_price'],
              'delivery_time' => $strDateTime,
              'delivery_interval' => $strDeliveryInterval,
              'payment_method' => $payment_method,
              'mkad' => $response->mkad
          );
        // ---

        // Check coupon
          if(!$this->customer->getCouponDiscount()) {
            // ---
              $data['discount'] = $this->cart->getOrderDiscount();
              if(isset($this->session->data['personal_discount'])) {
                $personalPercentage = (int)$this->session->data['personal_discount'];
                $data['discount_percentage'] = $personalPercentage;
              }
            // ---
          } else {
            // ---
              if(isset($this->session->data['personal_discount'])) {
                  $personalDiscount = floor($this->session->data['personal_discount']/100*$this->cart->getTotal());
                  if($this->session->data['personal_discount'] <= 10) $personalPercentage = (int)$this->session->data['personal_discount'];
              } else $personalDiscount = 0;

              $coupon = $this->customer->getCouponDiscount();
              $couponDiscount = floor($coupon['discount']/100*$this->cart->getTotal());

              if($coupon['discount'] <= 100) $couponPercentage = $coupon['discount'];

              if($couponDiscount > $personalDiscount) {
                  $data['coupon_discount'] = $couponDiscount;
                  $data['discount_percentage'] = $couponPercentage;
              } else {
                  $data['discount'] = $personalDiscount;
                  $data['discount_percentage'] = $personalPercentage;
              }
            // ---
          }
        // ---

        // Checkout
          $this->load->model('checkout/order');
          
          $this->model_checkout_order->setPayment($order_id, $payment_code);

          $payment_method_online = $this->request->post['payment_method_code'] == 'cod' ? false : true;
          
          if($this->model_checkout_order->setDelivery($order_id, $customer_id, $data, ($payment_method_online ? 16 : 1))) {
            // ---

              // Set customer
              $this->model_checkout_order->setCustomer($order_id, $customer_id);

              if($payment_method_online) {
                // ---
                  // Add paymant detail
                  $this->model_checkout_order->addDetailPayment($order_id, $this->config->get('config_payment_status_id'), true);

                  $rbsid = $this->model_checkout_order->generateUniqRbsId($order_id);
                  $this->model_checkout_order->setPaymentCustomField($order_id, $rbsid);

                  $results = $this->load->controller('extension/payment/rbs/payment', $rbsid);
                  $response->payment = $results;
                  
                  if( isset($results['redirect']) ) {
                    $response->redirect = $results['redirect'];
                  }
                  else {
                    // ---
                      $response->status = 'error';
                      $response->message = 'Не удалось сформировать ссылку для оплаты';
                      echo json_encode($response);
                      exit;
                    // ---
                  }

                  // Clear cart
                  $this->cart->clear();

                  // Response
                  $response->order_id = $order_id;
                  $response->status = 'success';
                  $response->message = 'Оплатите заказ онлайн';
                  

                  echo json_encode($response);
                  exit;

                // ---
              } else {
                // ---
                  // Add paymant detail
                  $this->model_checkout_order->addDetailPayment($order_id, 1, true);

                  // Clear cart
                  $this->cart->clear();

                  // Send sms        
                  $this->load->model('sms/confirmation');
                  $message = str_replace('[REPLACE]', $order_id, $this->config->get('config_sms_order_new_text'));
                  $this->model_sms_confirmation->sendSMS($telephone, $message);

                  // Response
                  $response->order_id = $order_id;
                  $response->status = 'success';
                  $response->message = 'Заказ успешно создан';

                  echo json_encode($response);
                  exit;

                // ---
              }

            // ---
          } else {
              // ---

                // Clear cart
                $this->cart->clear();

                // Response
                $response->order_id = $order_id;
                $response->status = 'error';
                $response->message = 'Выбранный способ оплаты не доступен';

                echo json_encode($response);
                exit;

              // ---
          }
        // ---

      // ---
    }

    // Post paymnet
    public function rbsPostPayment() {
      // ---
        // Init
          $order_id = $this->request->post['order_id'];

          $response = new stdClass();
        // ---

        // Create payment link
          $this->load->model('checkout/order');

          $rbsid = $this->model_checkout_order->generateUniqRbsId($order_id);
          $this->model_checkout_order->setPaymentCustomField($order_id, $rbsid);

          $results = $this->load->controller('extension/payment/rbs/payment', $rbsid);
          $response->payment = $results;
          
          if( isset($results['redirect']) ) {
            $response->redirect = $results['redirect'];
          }
          else {
            // ---
              $response->status = 'error';
              $response->message = 'Не удалось сформировать ссылку для оплаты';
              echo json_encode($response);
              exit;
            // ---
          }
        // ---

        // Response
        $response->order_id = $order_id;
        $response->status = 'success';
        $response->message = 'Ссылка успешно сформирована';
        

        echo json_encode($response);
        exit;

      // ---
    }
  // ---


  public function ajaxGetProducts() {
    $this->load->language('ajax/index');
    $this->load->model('catalog/product');

    // загружаем все товары
    $products = $this->model_catalog_product->getProducts();
    $data['products'] = $products;

    $this->response->addHeader('Content-Type: application/json');
    $this->response->setOutput(json_encode($data['products']));
  }

  // Получить товар
  public function ajaxGetProduct() {
    if (isset($this->request->get['product_id'])) {
        $product_id = (int) $this->request->get['product_id'];

        if ($product_id > 0) {
            $this->load->model('catalog/product');
            $product = $this->model_catalog_product->getProduct($product_id);
            $data['product'] = $product;
            
            $this->response->addHeader('Content-Type: application/json');
            $this->response->setOutput(json_encode($data['product']));
        }
    }
  }
  
  public function ajaxShowMore() {
        $mode = $this->request->post['mode'];
        $target = $this->request->post['target'];
        $nInclude = $this->request->post['not_include'];
        $this->load->model('catalog/product');
        $this->load->model('account/user');
        $result = array();
        if($mode == 'asort') {
            $data['products'] = $this->model_catalog_product->getAsortProducts($target, $nInclude);
        } elseif($mode == 'catsort') {
            $data['products'] = $this->model_catalog_product->getCatsortProducts($target, $nInclude);
        }
        
        if (isset($this->session->data['user_id']) && $this->model_account_user->isAdmin($this->session->data['user_id'])) {
            $data['is_admin'] = true;
        }
        else {
            $data['is_admin'] = false;
        }
        $this->response->setOutput($this->load->view('product/dynamic', $data));
  }
  
  // Получить товары по тэгу
  public function ajaxGetProductsByTag() {
      $arRequest = $this->request->get;
      if(!empty($arRequest['tag'])) {
          $this->load->model('catalog/product');
          $data['products'] = $this->model_catalog_product->getProductsByTag($arRequest['tag']);
          if(!empty($data['products'])) {
                $this->response->setOutput($this->load->view('product/modal', $data));
          } else {
                $this->response->setOutput(json_encode(false));    
          }
      }
  }
  
  // Получить корзину
  public function ajaxGetCart() {
      
        $data['success'] = true;
        $data['html'] = $this->load->controller('common/cart');
      
        $data['products'] = Array();
        $total = 0;
        $totalCount = 0;
        if ($this->cart->hasProducts() || !empty($this->session->data['vouchers'])) {
            $products = $this->cart->getProducts();
            $totalCount = count($products);
            foreach($products as $product) {
              $total += round($product['total']);
            }
        }
        $data['total'] = floor($total);
        $data['count'] = $totalCount;
        
        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput(json_encode($data));
  }
  
  // Удалить товар из корзины
  public function ajaxRemoveCartProduct() {
        if($cart_id = $this->request->get['cart_id'])
        {
            $this->cart->remove(intval($cart_id));
            
            $this->response->addHeader('Content-Type: application/json');
            $this->response->setOutput(json_encode(Array('success' => true)));
        }
  }
  
  // Изменить количество товара в корзине
  public function ajaxChangeCartQuantity() {
        $cart_id = $this->request->post['cart_id'];
        $quantity = $this->request->post['quantity'];
        if(!empty($cart_id) && !empty($quantity)) {
            $this->cart->update($cart_id, $quantity);
            $this->response->addHeader('Content-Type: application/json');
            $this->response->setOutput(json_encode(Array('status' => 'success')));
        }
  }

  // Оформление доставки
  public function ajaxSetDelivery() {
      $address = $this->request->post['address'];
      $address_new = $this->request->post['address_new'];
      $comment = $this->request->post['comment'];
      $order_id = $this->request->post['order_id'];
      $payment_method = $this->request->post['payment_method'];
      $payment_code = $this->request->post['payment_code'];
      $payment_method_online = $this->request->post['payment_method_online'];
      $strDateTime = 'Дата и время доставки: '.$this->request->post['date'].' '.$this->request->post['time'].PHP_EOL;
      $strDeliveryInterval = $this->request->post['date'].' '.$this->request->post['time'];
      $customer_id = (int)$this->customer->getId();
      $telephone = str_replace(Array('(', ')', '+', ' ', '-'), '', $this->request->post['telephone']);
      
      //    TODO 2018-03-27 Отключил принудительную авторизацию по причине смены корзины
      //    Как результат $this->cart->getTotal() $this->cart->getProducts() и т.д.
      //    возвращали чужую, в основном пустую корзину. В результате итоги (totals)
      //    заказа были нулевые
      $is_guest = false;
      //      if($customer_id == 0) {
      //          $is_guest = true;
      //          $this->customer->loginByPhone($telephone, false, true);
      //          $customer_id = (int)$this->customer->getId();
      //      }
      
      $this->load->model('dadata/index');
      
      $structure = array("ADDRESS");
      $record = array($address);
      $result = $this->model_dadata_index->cleanRecord($structure, $record);
      
      if(isset($result['data'][0][0]['beltway_hit'])) {
          $bwhit = $result['data'][0][0]['beltway_hit'];
          if($result['data'][0][0]['beltway_hit'] == 'IN_MKAD') {
              $delivery_price = 250;
          } else {
              $delivery_price = 600;
          }
      } else {
          $bwhit = 'NOT_IN_MKAD';
          $delivery_price = 600;
      }
      
      
      $data = Array(
          'address' => $address,
          'comment' => $comment,
          'delivery_price' => $delivery_price,
          'delivery_time' => $strDateTime,
          'delivery_interval' => $strDeliveryInterval,
          'payment_method' => $payment_method,
          'mkad' => $bwhit
      );
      if(!$this->customer->getCouponDiscount()) {
            $data['discount'] = $this->cart->getOrderDiscount();
			if(isset($this->session->data['personal_discount'])) {
				$personalPercentage = (int)$this->session->data['personal_discount'];
				$data['discount_percentage'] = $personalPercentage;
			}
        } else {
            if(isset($this->session->data['personal_discount'])) {
                $personalDiscount = floor($this->session->data['personal_discount']/100*$this->cart->getTotal());
                if($this->session->data['personal_discount'] <= 10) $personalPercentage = (int)$this->session->data['personal_discount'];
            } else $personalDiscount = 0;
            $coupon = $this->customer->getCouponDiscount();
            $couponDiscount = floor($coupon['discount']/100*$this->cart->getTotal());
            if($coupon['discount'] <= 100) $couponPercentage = $coupon['discount'];
            if($couponDiscount > $personalDiscount) {
                $data['coupon_discount'] = $couponDiscount;
                $data['discount_percentage'] = $couponPercentage;
            } else {
                $data['discount'] = $personalDiscount;
                $data['discount_percentage'] = $personalPercentage;
            }
        }
      
      $this->load->model('checkout/order');
      
      $this->model_checkout_order->setPayment($order_id, $payment_code);
      
      if($this->model_checkout_order->setDelivery($order_id, $customer_id, $data, ($payment_method_online ? 16 : 1))) {
          // Добавление адреса доставки в список адресов клиента
          if($address_new == 'true') $this->customer->setAddress(0, $address);
          // Очистка корзины
          // $this->cart->clear();
          if($is_guest) $this->customer->logout();
          // $this->cart->clear();
          if($payment_method_online) {
              $results = $this->load->controller('extension/payment/rbs/payment', $order_id);
              echo json_encode($results);
          } else {
          // Отправка sms        
            $this->load->model('sms/confirmation');
            $message = str_replace('[REPLACE]', $order_id, $this->config->get('config_sms_order_new_text'));
            $this->model_sms_confirmation->sendSMS($telephone, $message);
            echo json_encode(Array('status' => 'success'));
          }
      } else {
          if($is_guest) $this->customer->logout();
          echo json_encode(Array('status' => 'error'));
      }
  }
  
  // Изменить имя покупателя
  public function ajaxChangeCustomerInfo() {
      $arRequest = $this->request->post;
      if($this->customer->isLogged()) {
          if($this->customer->setInfo($arRequest)) {
              $this->response->setOutput(json_encode(Array('status' => 'success')));
          } else {
              $this->response->setOutput(json_encode(Array('status' => 'error')));
          }
      } elseif(!empty($arRequest['firstname']) && !empty($arRequest['telephone'])) {
            $arUser['firstname'] = $arRequest['firstname'];
            $arUser['lastname'] = '';
            $arUser['fax'] = '';
            $arUser['company'] = '';
            $arUser['address_1'] = '';
            $arUser['address_2'] = '';
            $arUser['city'] = '';
            $arUser['postcode'] = '';
            $arUser['country_id'] = 0;
            $arUser['zone_id'] = 0;
            $arUser['telephone'] = str_replace(Array('(', ')', '+', '-', ' '), '', $arRequest['telephone']);
            $arUser['password'] = md5($arUser['telephone'].time());
            $arUser['email'] = $arUser['telephone'].'@eco-u.ru';

            $this->load->model('account/customer');
            $customer_id = $this->model_account_customer->addCustomer($arUser);
            
            if($customer_id) { 
                $this->customer->loginByPhone($arUser['telephone'], $arUser['password']);
                $this->response->setOutput(json_encode(Array('status' => 'success')));
            } else {
                $this->response->setOutput(json_encode(Array('status' => 'error')));
            }
      } else {
          $this->response->setOutput(json_encode(Array('status' => 'error')));
      }
  }
  
  // Заказ без авторизации
  public function ajaxAddNoAuthOrder() {
      $arRequest = $this->request->post;
      $this->customer->loginByPhone($arRequest['telephone'], false, true);
      $this->ajaxAddOrder();
      $this->customer->logout();
  }
  
  // Получить новый пароль по sms
  public function ajaxForgotPassword() {
      $this->load->model('sms/confirmation');
      
      $arRequest = $this->request->get;
      $phoneFormat = str_replace(Array('(', ')', '+', '-', ' '), '', $arRequest['telephone']);
      
      $result = $this->customer->getByPhone($phoneFormat);
      
      if(isset($result['customer_id'])) {
          $code = substr(str_replace('.', '', hexdec(md5(time()+$phoneFormat))), 0, 6);
          $message = str_replace('[REPLACE]', $code, $this->config->get('config_sms_password_new_text'));
          $this->model_sms_confirmation->addCode($code, time()+300);
          $this->model_sms_confirmation->clearOldCodes();
          $this->model_sms_confirmation->sendSMS($phoneFormat, $message);
          $this->response->setOutput(json_encode(Array('status' => 'success')));
      } else {
          $this->response->setOutput(json_encode(Array('status' => 'error')));
      }
  }
  
  public function ajaxApplyCoupon() {
      $this->load->model('extension/total/coupon');
      $arRequest = $this->request->post;
      if(isset($arRequest['code'])) {
          $coupon = $this->model_extension_total_coupon->getCoupon($arRequest['code']);
          if($coupon) {
              $this->session->data['coupon'] = $coupon['code'];
              $this->session->data['coupon_id'] = $coupon['coupon_id'];
          } else {
              unset($this->session->data['coupon']);
              unset($this->session->data['coupon_id']);
          }
          
          // Totals
        $this->load->model('extension/extension');

        $totals = array();
        $taxes = $this->cart->getTaxes();
        $total = 0;

        // Because __call can not keep var references so we put them into an array. 
        $total_data = array(
                'totals' => &$totals,
                'taxes'  => &$taxes,
                'total'  => &$total
        );

        $sort_order = array();

        $results = $this->model_extension_extension->getExtensions('total');

        foreach ($results as $key => $value) {
                $sort_order[$key] = $this->config->get($value['code'] . '_sort_order');
        }

        array_multisort($sort_order, SORT_ASC, $results);

        foreach ($results as $result) {
                if ($this->config->get($result['code'] . '_status')) {
                        $this->load->model('extension/total/' . $result['code']);
                        $this->{'model_extension_total_' . $result['code']}->getTotal($total_data);
                }
        }

        $sort_order = array();

        foreach ($totals as $key => $value) {
                $sort_order[$key] = $value['sort_order'];
        }

        array_multisort($sort_order, SORT_ASC, $totals);  
        
        
        foreach($totals as $total) {
            if($total['code'] == 'total') {
                $total_price = ceil($total["value"]);
                
                if(isset($this->session->data['coupon_id'])) {
                    $customer_coupon = $this->customer->getCouponDiscount();
                }
                $html = '<div>'; // root
                if($customer_id = $this->customer->isLogged()) {
                    $this->load->model('checkout/order');
                    $orders = $this->model_checkout_order->getPersonalOrders($customer_id);
                    $customer_discount = $this->customer->getPersonalDiscount($customer_id, $orders);
                    $html .= '<div class="personal-discount" style="position:relative;color:#666;font-size:18px;font-weight:700;height:50px;line-height:50px; margin-top: -32px;display: none;">';
                    $html .= 'Текущая скидка <span class="p-o_discount sticker_discount" style="position:relative;top:0;left:10px;display:inline-block;width:40px;height:40px;line-height:40px;font-size:16px;">' . -1 * (int)$customer_discount . '%</span>';
                    $html .= '<input type="hidden" id="customer_discount" data-type="P" value="' . (int)$customer_discount . '">';
                    $html .= '</div>';
                }

                $html .= '<div class="personal-coupon" style="height:50px;  margin-top: -32px; display: none;">';
                if(isset($customer_coupon)) {
                    if($customer_coupon['type'] == 'P') {
                        $cDcnt = (int)$totals[0]['value']*((int)$customer_coupon['discount']/100);
                        $html .= 'Текущая скидка <span class="p-o_discount sticker_discount b-d_coupon_circle">' . -1*(int)$customer_coupon['discount'] . '%</span>';
                    } elseif($customer_coupon['type'] == 'F') {
                        $cDcnt = (int)$customer_coupon['discount'];
                        $html .= 'Ваша скидка <span class="c-d_amount">' . (int)$customer_coupon['discount'] . '</span> руб';
                    }
                    $html .= '<input type="hidden" id="customer_coupon" data-type="' . $customer_coupon['type'] . '" value="' . (int)$customer_coupon['discount'] . '">';
                }
                $html .= '</div>';
                if(!isset($customer_coupon) && !isset($customer_discount)) {
                        $html .= '<div class="b-d_coupon" style="display: none;">';
                        $html .= 'Есть купон на скидку?';
                        $html .= '</div>';
                } else {
                        $html .= '<div class="b-d_coupon_discount" style="display: none;">';
                        $html .= 'Увеличить скидку';
                        $html .= '</div>';
                }
                $html .= '</div>'; // root

                $response = Array(
                    'status' => 'success',
                    'total' => (int)$this->cart->getOrderPrice(),
                    'html' => $html
                );
                $response['discountValue'] = (int)$this->cart->getTotal() - $response['total'];

                
                $this->response->setOutput(json_encode($response));
                break;
            }
        }
      } else {
          $this->response->setOutput(json_encode(Array('status' => 'error')));
      }
  }
  
  public function ajaxGetTotals() {
      echo $this->cart->getTotal();
  }
  
  public function ajaxGetPersonalOrders() {
      $customer_id = $this->session->data['customer_id'];
      
      $this->load->model('checkout/order');
      $orders = $this->model_checkout_order->getPersonalOrders($customer_id);
      $arOrders = Array();
      foreach($orders as $order) {
          $arOrders[] = Array(
              'order_id' => $order['order_id'],
              'date_added' => $order['date_added'],
              'order_status' => $order['status_text'],
              'order_status_id' => $order['order_status_id'],
              'order_total' => $order['total']
          );
      }
      
      $this->response->setOutput(json_encode(Array('status' => 'success', 'orders' => $arOrders)));
  }
  
  public function ajaxSetCustomerData() {
      $arRequest = $this->request->post;
      $arRequest['telephone'] = str_replace(Array('(', ')', '+', '-', ' '), '', $arRequest['telephone']);
      
      $this->load->model('dadata/index');
      $structure = Array();
      $record = Array();
      foreach($arRequest['addresses'] as $address) {
          $structure[] = "ADDRESS";
          $record[] = $address['value'];
      }
      $result = $this->model_dadata_index->cleanRecord($structure, $record);
      $toReplace = Array();
      foreach($arRequest['addresses'] as $i => $address) {
            if(in_array($result['data'][0][$i]['qc'], Array(0,3))) {
                $this->customer->setAddress($address['address_id'], $result['data'][0][$i]['result']);
                $toReplace[] = Array(
                    'value' => $result['data'][0][$i]['result'],
                    'id' => $address['address_id']
                );
            } else {
                $this->customer->setAddress($address['address_id'], $address['value']);
            }
      }
      
      $this->customer->setFirstName($arRequest['firstname']);
      $this->customer->setTelephone($arRequest['telephone']);
      $this->customer->setEmail($arRequest['email']);
      if(isset($arRequest['newsletter'])) $this->customer->setNewsletter($arRequest['newsletter']);
      $this->response->setOutput(json_encode(Array('status' => 'success', 'dadata' => $toReplace)));
  }
  
  private function clearTelephone($telephone) {
      return str_replace(Array('(', ')', '+', '-', ' '), '', $telephone);
  }
  
  public function ajaxSearchProducts() {
      $search = $this->request->get['search'];
      
      $this->load->model('catalog/product');
      $this->load->model('tool/image');
      
      $data['products'] = $this->model_catalog_product->searchProducts($search);
      foreach($data['products'] as $i => $result) {
            if(isset($result['composite_price'])) {
                $data['products'][$i]['composite_price'] = json_encode($result['composite_price']);
            }
            if ($result['image_preview']) {
                    $image = '/image/'.$result['image_preview'];
                    //$image = $this->model_tool_image->resize($result['image'], $this->config->get($this->config->get('config_theme') . '_image_product_width'), $this->config->get($this->config->get('config_theme') . '_image_product_height'));
            } else {
                    $image = $this->model_tool_image->resize('eco_logo.png', $this->config->get($this->config->get('config_theme') . '_image_product_width'), $this->config->get($this->config->get('config_theme') . '_image_product_height'));
            }
            if(!empty($result['sticker']['class'])) {
                $data['products'][$i]['sticker_class'] = $result['sticker']['class'];
                $data['products'][$i]['sticker_name'] = $result['sticker']['name'];
            }
            $data['products'][$i]['thumb'] = $image;
      }
      $this->response->setOutput($this->load->view('product/search', $data));
  }
  
  public function ajaxGetOrderPrice() {
      $response = Array(
            'status' => 'success',
            'price' => (int)$this->cart->getOrderPrice()
      );
      $response['discount'] = (int)$this->cart->getTotal() - $response['price'];
      $this->response->setOutput(json_encode($response));
  }
  
  public function ajaxRemoveAddress() {
      $address_id = $this->request->get['address_id'];
      $this->load->model('account/customer');
      $this->model_account_customer->deleteAddress($address_id);
      $response = Array(
            'status' => 'success'
      );
      $this->response->setOutput(json_encode($response));
  }
}
