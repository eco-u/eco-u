<?php
// Init
	error_reporting(E_ALL);
	ini_set('display_errors', '1');
	header('Content-Type: text/html; charset=utf-8');

	include("opencart_inc.php");

	$time=time();

	$alertsList = ["mihnenko@gmail.com"];

	$log = "";

	define('MS_AUTH', 'admin@mail195:b41fd841edc5');
	define('RCRM_KEY', 'AuNf4IgJFHTmZQu7PwTKuPNQch5v03to');
// ---


$res_orders=mysql_query("
	SELECT 
		payment_method, customer_id, order_id, firstname, lastname, email, telephone, comment, total, 
		order_status_id, date_added, shipping_code, shipping_postcode, shipping_city, shipping_country, 
		shipping_address_1, shipping_address_2, delivery_time 
	FROM oc_order WHERE order_id = 23517 AND customer_id>0 AND order_status_id>0 ORDER BY date_modified DESC LIMIT 0,30");

$i=1;


// Get managers
	$managers = [];

	$url = 'https://eco-u.retailcrm.ru/api/v5/users';
	$qdata = array('apiKey' => RCRM_KEY,'limit' => 100);

	$response = connectGetAPI($url,$qdata);

	foreach ($response->users as $key => $user) {
		if( $user->isManager == 1 ){
			$managers[] = $user->id;
		}
	}
// ---

// Get free shipping
	$freeShippingTotalValue = 1000000;

	// Get free
		if ( $qFreeTotal = mysql_query("SELECT * FROM `oc_setting` WHERE `code`='free';") ) $nFreeTotal = mysql_num_rows($qFreeTotal);
		else $nFreeTotal = 0;


		if( $nFreeTotal>0 ){
			// ---
				while ($freeTotalRow = mysql_fetch_assoc($qFreeTotal)) {
					if( $freeTotalRow['key'] == 'free_total' ) { $freeShippingTotalValue = $freeTotalRow['value']; }
				}
			// ---
		}
	// ---
// ---

while(list($payment_method,$customer_id,$order_id,$fname,$lname,$email,$phone,$comm,$total,$order_status_id,$date_added,$shipping_code,$shipping_postcode,$shipping_city,
	$shipping_country,$shipping_address_1,$shipping_address_2,$delivery_time)=mysql_fetch_row($res_orders)){
	
// ---
	$orderCreatedAt = $date_added;
	
	// Check email
	if($email == 'empty@localhost' || $email=='') {
		if ($phone!=''){
			$email = $phone.'@eco-u.ru';
		}else{
			$email = "customer_".$customer_id."@eco-u.ru";
		}
	}
	
	$res_roistat_id = "SELECT `order_roistat_visit_id` FROM `oc_order_roistat` WHERE `order_id`=".$order_id;
	$res=mysql_query($res_roistat_id);
	list($roistat_id)=mysql_fetch_row($res);
	if(!isset($roistat_id)) $roistat_id="";
	
	
	// Get delivery cost
		$res=mysql_query("select value from oc_order_total where code='shipping' and order_id='$order_id'");
		list($deliveryCost)=mysql_fetch_row($res);
		if(!isset($deliveryCost)) $deliveryCost=0;
	// ---

	// Customer
		$ms_last_tmp=DateTime::createFromFormat("Y-m-d H:i:s",$date_added);
		$ms_last= $ms_last_tmp->getTimestamp();

		$ms_last=$ms_last+3600;
		$date_added=date("Y-m-d H:i:s",$ms_last);
		$ms_data=array();


		$data['externalId']=$email;
		$data['email']=$email;
		$data['lastName']=$lname;
		$data['firstName']=$fname;
		$data['phone']=$phone;

		if ( $qCustomers = mysql_query("SELECT * FROM `rcrm_customers` WHERE `email`='".$email."';") ) $nCustomers = mysql_num_rows($qCustomers);
		else $nCustomers = 0;

		if( $nCustomers==0 ){
			// ---
				$link='https://eco-u.retailcrm.ru/api/v5/customers/create?apiKey='.$retail_key;
				$senddata['customer']=json_encode($data);
				$res=crm_query_send($link,$senddata);

				$qInsert = mysql_query("
					INSERT INTO `rcrm_customers` SET 
					`id_internal`='".(int)$res['id']."',
					`id_external`='".$email."',
					`firstname`='".$fname."',
					`email`='".$email."',
					`dublicates`=0,
					`created`=NOW()
				");

				$cust_id=$res['id'];
			// ---
		}
		else{
			$rowCustomer = mysql_fetch_assoc($qCustomers);
			$cust_id=$rowCustomer['id_internal'];
		}
	// ---
	
	$items_=$order=$items=$items_new=$data=null;
	$weight_all=0;
	$weight_ignore=0;

	// Get order options
		$resx=mysql_query("select MSP.ms_id,MSP.product_id,OOP.order_product_id,OOP.quantity,OOP.variant,OOP.amount,OOP.price from oc_order_product as OOP, ms_products as MSP where  MSP.product_id=OOP.product_id and OOP.order_id='$order_id'");
	
		while(list( $ms_pr_id,$msp_product_id,$opid,$quantity,$fasovka,$amount,$price)=mysql_fetch_row($resx)){

			$resx2=mysql_query("select MSV.ms_id,MSV.product_option_value_id  from oc_order_option as OOO, ms_variants as MSV where MSV.product_option_value_id=OOO.product_option_value_id and OOO.order_id='$order_id' and OOO.order_product_id='$opid'");
			list( $ms_var_id, $msv_povid)=mysql_fetch_row($resx2);

			/*Подсчитываем общий вес всех товаров*/

			//1.Получем единицу измерения товара
			$resx3=mysql_query("select weight_class_id,weight,weight_package from oc_product where product_id='".(int)$msp_product_id."'");
			list( $weight_class_id,$weight,$weight_package)=mysql_fetch_row($resx3);
			
			//2.Формируем вес
			//Если в МС не установлен вес для весовых товаров, то берём по умолчанию 1 кг

			if($weight=="0.00000000" && $weight_class_id==9){
				$weight="1";
			}
			
			//Если милилитры или граммы, то это и есть граммы или штуки
			if($weight=="0.00000000"){
				$weight_ignore=1;
			}
			else {
				
				if($weight_class_id==8 || $weight_class_id==2 || $weight_class_id==1 || $weight_class_id==7) {
					$weight_all=$weight_all+round(($quantity*$weight));
					
				}

				//Если килограммы, то тоже самое но умножаем на 1000
				if($weight_class_id==9) {
					$weight_all=$weight_all+(round(($quantity*$weight)*1000));
					
				}

				// Add package weight
					if( $weight_package != '' ) {
						$wpArr = (array) json_decode(html_entity_decode($weight_package));

						if( isset($wpArr[$fasovka]) ) {
							if($weight_class_id==2 || $weight_class_id==9) {
								$weight_all=$weight_all + floatval($wpArr[$fasovka]);
							}
						}
					}
				// ---
			}
			
			

			$extid=$msp_product_id;
			if($msv_povid) $extid.="#$msv_povid";

			if($quantity) $items_[$extid][]=array('quantity'=>(float)$quantity, 'amount'=>(float)$amount, 'initialPrice'=>(double)$price, 'fasovka'=>$fasovka);	
		} // while
	// ...

	// Array of products
		$total_new=0;	

		foreach($items_ as $ki=>$vi){		
			// ---
		 	
				$quantity=0;
				$sum=0;		
				$gnum=1;
				$allfasovka=null;
				
				foreach($vi as $ki2=>$vi2){
					// ---
						$quantity=$quantity+$vi2['quantity'];
						$sum+=$vi2['quantity']*$vi2['initialPrice'];
						$allfasovka[]=array('name'=>'Фасовка '.$gnum,'value'=>$vi2['fasovka']."кг X ".$vi2['amount']);
						$gnum++;
					// ---
				}
				
				$newprice=round($sum/$quantity,4);		
				$items_new[]=array('offer'=>array('externalId'=>$ki),'quantity'=>(float)$quantity, 'initialPrice'=>(double)$newprice, 'properties'=>$allfasovka);
				
				// Sum total
				$total_new+=$quantity*round($newprice,4);

			// ---
		}	
	// ...

	// Discount
		$managerCommentCouponDiscount = '';
	    $discval=$discvalproc=0;
	    $resxxx=mysql_query("SELECT value from oc_order_total where order_id='".$order_id."' and code='coupon'");
	    $couponFlag = false;

	    list($discval)=mysql_fetch_row($resxxx);
		
		if(!$discval){
		    $resxxx=mysql_query("SELECT value from oc_order_total where order_id='".$order_id."' and code='discount'");
		    list($discval)=mysql_fetch_row($resxxx);
		}
		else{
			$couponFlag = true;
		}

	    $resxxx=mysql_query("SELECT value from oc_order_total where order_id='".$order_id."' and code='discount_percentage'");
	    list($discvalproc)=mysql_fetch_row($resxxx);
		
		if($discval&&$discvalproc){
			$tmpdiscval=$total_new*$discvalproc/100;
			if($discval<$tmpdiscval) {
				unset( $discval);
				//$disval=$tmpdiscval;
				//unset($discvalproc);
			}else unset( $discvalproc);
		}


        if( isset($discval) && $discval!=0) $order['discountManualAmount']=(double)$discval;

        if( isset($discvalproc) &&  $discvalproc!=0 ) {
        	$order['discountManualPercent']=(double)$discvalproc;
        	
        	if( isset($couponFlag) && $couponFlag == true ) {
        		$managerCommentCouponDiscount = 'Скидка '.$order['discountManualPercent'].'% по купону';
        	}
        }
    // ---
		
    // Shipping
		$add_text = "";
		if($shipping_postcode!=''){$add_text.=$shipping_postcode.", ";}
		if($shipping_country!=''){$add_text.=$shipping_country.", ";}
		if($shipping_city!=''){$add_text.=$shipping_city.", ";}
		if($shipping_address_1!=''){$add_text.=$shipping_address_1;}
		if($shipping_address_2!=''){$add_text.=", ".$shipping_address_2;}

			
		$tmp=explode(".",$shipping_code);
		$delivery_code = $tmp[0];
		$order['shipmentStore']='eco-u';
		$vr=$delivery_code;

		if($delivery_code=="mkadout") {
			$vr="mkad";
		}
		if($delivery_code=="free") {
			$vr="flat";
		}
		if($delivery_code=="flat") {
			$vr="flat-pay";
		}


		// Get delivery net cost
			$deliveryNetCost = 0;
			
			// Get current
				if ( $qShippingNetCost = mysql_query("SELECT * FROM `oc_setting` WHERE `code`='".$delivery_code."';") ) $nShippingNetCost = mysql_num_rows($qShippingNetCost);
				else $nShippingNetCost = 0;


				if( $nShippingNetCost>0 ){
					// ---
						$mainCost = 0;
						$netCost = 0;

						while ($shippingNetCostRow = mysql_fetch_assoc($qShippingNetCost)) {
							
							if( $shippingNetCostRow['key'] == $delivery_code.'_cost' ) { $mainCost = $shippingNetCostRow['value']; }
							if( $shippingNetCostRow['key'] == $delivery_code.'_netcost' ) { $netCost = $shippingNetCostRow['value']; }
						
						}

						// Apply netcost config
							if( $netCost != 0 ) {
								$netCostValue = 0;
								$weightValue = $weight_all / 1000;

								$netcost_config_list = json_decode( html_entity_decode($netCost, ENT_QUOTES, 'UTF-8') );


								foreach($netcost_config_list as $key => $item) {
									if( $weightValue > intval($item->from) && $weightValue <= intval($item->to) ) {
										$netCostValue = intval($item->cost);
										break;
									}
								}

								$netCost = $netCostValue;
							}
						// ---

						if($delivery_code=="flat") {
							$deliveryNetCost = $netCost;
						}
						else{
							// ---
								if( $total_new >= $freeShippingTotalValue ){
									// Free shipping
										$deliveryNetCost = $netCost + $deliveryCost;
									// ---
								}
								else{
									// Paided shipping
										$deliveryNetCost = $netCost + ($deliveryCost - $mainCost);
									// ---
								}
							// ---
						}

					// ---
				}
			// ---
		// ---


        $order['delivery'] = array(
			'code' => !empty($vr) ? $vr:0,
			'cost' => (double)$deliveryCost,
			'netCost' => (double)$deliveryNetCost,
			'address' => array('text' => $add_text)
		);
    // ---

    // Init
		$link='https://eco-u.retailcrm.ru/api/v5/orders/create?apiKey='.$retail_key;
		$order['createdAt']=$orderCreatedAt;
	   	$order['items']=$items_new;
		$order['number']='IM'.$order_id;
		if($weight_ignore==0) { $order['weight']=$weight_all; }
		
		$order['externalId']=$order_id;
		$order['lastName']=$lname;
	// ---
				
				
	if($cust_id) $order['customer']['id']=$cust_id;
	$order['customerComment']=$comm;
	$order['managerComment']=$managerCommentCouponDiscount;

	$tmpd=explode(" ",$delivery_time);

	$tmpd3=explode(".",$tmpd[0]);
	$tmpd2=explode("-",$tmpd[1]);

	if(count($tmpd)>0){
		if($tmpd3[0]) $order['delivery']['date']=$tmpd3[2]."-".$tmpd3[1]."-".$tmpd3[0];
		if($tmpd2[0]) $order['delivery']['time']['from']=$tmpd2[0];
		if($tmpd2[1]) $order['delivery']['time']['to']=$tmpd2[1];
	}

	if($payment_method=='Банковской картой на сайте') $pmethod='e-money';
	else $pmethod='cash';

	
	$deliveryCost=round((double)$deliveryCost,0);


	//Новая сумма исключает глюки при пересчёте сложных цен
	$discount_real = 0;
		
	if( isset($discvalproc) && $discvalproc) { $discount_real=(double)$total_new*$discvalproc/100; }
	if( isset($discval) && $discval) { $discount_real=$discval; }

	$discount_real = $discount_real;

	$total_pay_new=round($total_new, 4) +$deliveryCost - $discount_real;

	
	if($pmethod=='e-money' && $order_status_id==20) {
		$order['payments'][]=array('externalId'=>$order_id, 'type'=>$pmethod,'amount'=>(double)$total_pay_new, 'paidAt' => $date_added, 'status'=>'paid');
	}
	else {
		$order['payments'][]=array('externalId'=>$order_id, 'type'=>$pmethod,'amount'=>(double)$total_pay_new, 'paidAt' => $date_added, 'status'=>'not-paid');
	}
	
	$order['status']="new";

	$order['firstName']=$fname;
	$order['phone']=$phone;
	$order['email']=$email;

	$order['orderMethod']="shopping-cart";		
	
	if($order_status_id==0){
		$order['orderMethod']="missed-order";
		$order['status']="lost-order";
	}
	
	//Передаём ROISTAT_ID
	if($roistat_id!=""){
		$order['customFields']['roistat']=$roistat_id;
	}
	
		
   	$senddata['order']=json_encode($order);
	//header('Content-Type: application/json');
	echo $senddata['order'];


	if($order['status']!="lost-order"){

		$json=crm_query_send($link,$senddata);

		echo "retailCRM response: "; print_r($json);
		echo "<br><br>";

		if (!$json['success']) {
			echo "<br><span style='color:#ff0000'>ERROR: ".$json['errorMsg']."</span><br><br>";
			
			if ($json['errorMsg']!='Order already exists.'){
				// Add log
					if ( $qLogs = mysql_query("SELECT * FROM `rcrm_errors` WHERE `id_order`=".$order_id.";") ) $nLogs = mysql_num_rows($qLogs);
					else $nLogs = 0;

					if( $nLogs==0 ){
						// ---
							$qInsert = mysql_query("
								INSERT INTO `rcrm_errors` SET 
								`id_order`='".$order_id."',
								`id_externalid`='IM".$order_id."',
								`message`='".$json['errorMsg']."'
							");

							$log .= "ID заказа: ".$order_id." <span style='color:#ff0000'>ERROR: ".$json['errorMsg']."</span><br>";
							
							// Set task
								$url = 'https://eco-u.retailcrm.ru/api/v5/tasks/create?apiKey='.RCRM_KEY;

								foreach ($managers as $key => $manager_id) {
									// Set data
										$task['text'] = 'Заказ №'.$order_id.' не выгружен в CRM';
										$task['datetime'] = date('Y-m-d H:i', (time()+3600) );
										$task['performerId'] = $manager_id;
										$data['task'] = json_encode($task);
									// ---
									
									$response=connectPostAPI($url,$data);
								}
							// ---

							foreach($order["items"] as $item){
								echo "quantity = ".$item["quantity"].", price = ".$item["initialPrice"]."<br>";
							}
						// ---
					}
				// ---
			}
		}
		else {
			echo "<br><span style='color:#00ff00'>Order upload successfuly</span><br><br>";
		}
	}
	else {
		echo "lost-order. not load<br><br>";
	}

//---
}

// Send log
	if( $log != ""){
		// Send emails
			$subject = "Ошибка отправки заказа(ов) в RetailCRM";
		    $message = "<b>Лог ошибок:</b><br><br>".$log;

		    $headers = "From: noreoly@eco-u.ru\r\n";
		    $headers .= "Reply-To: noreoly@eco-u.ru\r\n";
		    $headers .= "MIME-Version: 1.0\r\n";
		    $headers .= "Content-Type: text/html; charset=utf-8\r\n";

			foreach ($alertsList as $key => $to) {
		        // Semd email
		        if (mail($to, $subject, $message, $headers)) {
		            $mess .= 'Send to client '.$to;
		        } else {
		            $mess .= 'Do not send to client '.$to;
		        }
			}
		// ---
	}
// ---


$link='https://eco-u.retailcrm.ru/api/v5/customers/?apiKey='.$retail_key;
$res=crm_query($link);


function connectPostAPI($url, $qdata, $auth='', $cookie='') {
	// ---
		$data = http_build_query($qdata);

		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL,$url);
		curl_setopt($ch, CURLOPT_POST, 1);
		curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");  
		curl_setopt($ch, CURLOPT_POSTFIELDS,$data);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		if( !empty($auth) ){
			curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_ANY);
			curl_setopt($ch, CURLOPT_USERPWD, $auth);
		}
		curl_setopt($ch, CURLOPT_COOKIE, $cookie);
		$headers = ['Content-Type: application/x-www-form-urlencoded'];
		curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
		curl_setopt($ch, CURLOPT_HEADER, false);

		// Output
		$output = curl_exec($ch);
		$result = json_decode($output);

		// Result
		if( $result != null ){
			curl_close ($ch);
			return $result;
		}
		else {
			curl_close ($ch);
			return false;
		}
	// ---
}

function connectGetAPI($url, $qdata, $auth='') {
	// ---
		$data = http_build_query($qdata);

		$ch = curl_init();
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, TRUE);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
		if( !empty($auth) ){
			curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_ANY);
			curl_setopt($ch, CURLOPT_USERPWD, $auth);
		}
		curl_setopt($ch, CURLOPT_URL,$url.'?'.$data);
		curl_setopt($ch, CURLOPT_TIMEOUT, 80);

		// Output
		$output = curl_exec($ch);
		$result = json_decode($output);

		// Result
		if( $result != null ){
			curl_close ($ch);
			return $result;
		}
		else {
			curl_close ($ch);
			return false;
		}
	// ---
}