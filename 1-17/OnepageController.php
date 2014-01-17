<?php
/**
 * NOTICE OF LICENSE
 *
 * This source file is subject to the EULA
 * that is bundled with this package in the file LICENSE.txt.
 *
 /***************************************
 *         MAGENTO EDITION USAGE NOTICE *
 *****************************************/
 /* This package designed for Magento COMMUNITY edition
 * This extension is only for developers as a technology exchange
 * v1.0.0, remove payment step and set the payment method "Zero Subtotal Checkout" with code "free" as the default payment method.
 *****************************************************
 * @category   Cc
 * @package    Cc_Remove
 * @Author     Chimy
 */

require_once(Mage::getModuleDir('controllers', 'Mage_Checkout').'/OnepageController.php');
class Cc_Remove_OnepageController extends Mage_Checkout_OnepageController
{
    /**
     * Shipping method save action
     */
    public function saveShippingMethodAction()
    {
        if ($this->_expireAjax()) {
            return;
        }
        if ($this->getRequest()->isPost()) {
            $data = $this->getRequest()->getPost('shipping_method', '');
            $result = $this->getOnepage()->saveShippingMethod($data);
            
            //updated by Chimy
	        try{
				$data = array('method'=>'checkmo');
				$result = $this->getOnepage()->savePayment($data);
				$redirectUrl = $this->getOnepage()->getQuote()->getPayment()->getCheckoutRedirectUrl();
				if (empty($result['error']) && !$redirectUrl) {
					$this->loadLayout('checkout_onepage_review');
					$result['goto_section'] = 'review';
					$result['update_section'] = array(
						'name' => 'review',
						'html' => $this->_getReviewHtml()
					);
				}
				if ($redirectUrl) {
					$result['redirect'] = $redirectUrl;
				}
			} catch (Mage_Payment_Exception $e) {
				if ($e->getFields()) {
					$result['fields'] = $e->getFields();
				}
					$result['error'] = $e->getMessage();
			} catch (Mage_Core_Exception $e) {
				$result['error'] = $e->getMessage();
			} catch (Exception $e) {
				Mage::logException($e);
				$result['error'] = $this->__('Unable to set Payment Method.');
			}
            
            /*
            $result will have erro data if shipping method is empty
            */
            if(!$result) {
                Mage::dispatchEvent('checkout_controller_onepage_save_shipping_method',
                        array('request'=>$this->getRequest(),
                            'quote'=>$this->getOnepage()->getQuote()));
                $this->getOnepage()->getQuote()->collectTotals();
                $this->getResponse()->setBody(Mage::helper('core')->jsonEncode($result));

                $result['goto_section'] = 'payment';
                $result['update_section'] = array(
                    'name' => 'payment-method',
                    'html' => $this->_getPaymentMethodsHtml()
                );
            }
            $this->getOnepage()->getQuote()->collectTotals()->save();
            $this->getResponse()->setBody(Mage::helper('core')->jsonEncode($result));
        }
    }
    
	public function saveBillingAction()
	{
		if ($this->_expireAjax()) {
			return;
		}
		if ($this->getRequest()->isPost()) {
			//$postData = $this->getRequest()->getPost('billing', array());
			//$data = $this->_filterPostData($postData);
			
			$data = $this->getRequest()->getPost('billing', array());
			Mage::log($this->getRequest()->getPost('gcard'), null, 'riko.log');
			
			//For Use Gift Card
			$gcards_value = $this->getRequest()->getPost('gcard');
			if(isset($gcards_value) && count($gcards_value) > 0){
				$gcards = implode(',', $gcards_value);
				Mage::getSingleton('core/session')->setPayGcard($gcards);
			}else{
				Mage::getSingleton('core/session')->unsPayGcard();
			}
			
			Mage::log(Mage::getSingleton('core/session')->getPayGcard(), null,'riko.log' );
			
			$customerAddressId = $this->getRequest()->getPost('billing_address_id', false);
			if (isset($data['email'])) {
				$data['email'] = trim($data['email']);
			}
			$result = $this->getOnepage()->saveBilling($data, $customerAddressId);
			if (!isset($result['error'])) {
				//$method = array('method'=>'freeshipping_freeshipping');
				$method = 'freeshipping_freeshipping';
                $result = $this->getOnepage()->saveShippingMethod($method);
                $data = array('method'=>'checkmo');
				$result = $this->getOnepage()->savePayment($data);
                
				/* check quote for virtual */
				if ($this->getOnepage()->getQuote()->isVirtual()) {
					$result['goto_section'] = 'payment';
					$result['update_section'] = array(
						'name' => 'payment-method',
						'html' => $this->_getPaymentMethodsHtml()
					);
				}
				/*elseif (isset($data['use_for_shipping']) && $data['use_for_shipping'] == 1) {
				$result['goto_section'] = 'shipping_method';
				$result['update_section'] = array(
				'name' => 'shipping-method',
				'html' => $this->_getShippingMethodsHtml()
				);
				$result['allow_sections'] = array('shipping');
				$result['duplicateBillingInfo'] = 'true';
				}*/
				else {
					$this->loadLayout('checkout_onepage_review');
					$result['goto_section'] = 'review';
					$result['update_section'] = array(
						'name' => 'review',
						'html' => $this->_getReviewHtml()
					);

				}
			}
			$this->getResponse()->setBody(Mage::helper('core')->jsonEncode($result));
		}
	}
	
	
	public function saveOrderAction()
    {
	
		
        if ($this->_expireAjax()) {
            return;
        }

        $result = array();
        try {
            if ($requiredAgreements = Mage::helper('checkout')->getRequiredAgreementIds()) {
                $postedAgreements = array_keys($this->getRequest()->getPost('agreement', array()));
                if ($diff = array_diff($requiredAgreements, $postedAgreements)) {
                    $result['success'] = false;
                    $result['error'] = true;
                    $result['error_messages'] = $this->__('Please agree to all the terms and conditions before placing the order.');
                    $this->getResponse()->setBody(Mage::helper('core')->jsonEncode($result));
                    return;
                }
            }
            if ($data = $this->getRequest()->getPost('payment', false)) {
                $this->getOnepage()->getQuote()->getPayment()->importData($data);
            }
			
			
			//Add Check Flower Ctomer Qty 
			$check_flag = true;
			$customer_message = '';
			$quote = Mage::getSingleton('checkout/session')->getQuote();
			$cartItems = $quote->getAllVisibleItems();
			foreach($cartItems as $item){
				$item_sku = $item->getSku();
				$item_date = $item->getDevedate();
				$product = Mage::getModel('catalog/product')->load($item->getProductId()); 
				$custom_qty = $product->getCustomQty();
				if($custom_qty != '' || $custom_qty != null){					 
					$item_order_qty = Mage::helper('remove/catalog')->getQty($item_date, $item_sku);
					
					if($custom_qty - $item_order_qty <=0 || $item->getQty() + $item_order_qty > $custom_qty){
						$check_flag = false;
						$customer_message = $item->getName();
						break;
					}
				}
			}
			
			
			if($check_flag){
				 $this->getOnepage()->saveOrder();
				 	 
			}else{
				$result['success']  = false;
				$result['error']    = true;
				$result['error_messages'] = $this->__('There was an error processing your order. Please check %s qty.',$customer_message);
				$result['redirect'] = Mage::helper('core/url')->getHomeUrl().'checkout/cart/';
				Mage::getSingleton('checkout/session')->addError($this->__('There was an error processing your order. Please check %s qty.',$customer_message));
				$this->getResponse()->setBody(Mage::helper('core')->jsonEncode($result));
			}
			
			
			/* For Gift Card Model */
            $last_order_id = Mage::getSingleton('checkout/session')->getLastOrderId();
            $last_order = Mage::getModel('sales/order')->load($last_order_id);
            $quote_id = $last_order->getQuoteId();
            
            $resource = Mage::getSingleton('core/resource');
			$readConnection = $resource->getConnection('core_read');
			$writeConnection = $resource->getConnection('core_write');
			$table = Mage::getSingleton('core/resource')->getTableName('salesrule/coupon');
			$history_table = ' salesrule_coupon_history';
			
			$select = "SELECT balance,used_last_order,coupon_id FROM $table WHERE last_quote_id = $quote_id";
			$columns = $readConnection->fetchAll($select);
			$total_used = 0;
			foreach($columns as $key => $column){
					$coupon_id = $column['coupon_id'];
					$balance = $column['balance'];
					$used_last_order = $column['used_last_order'];
					$_balance = $balance - $used_last_order;
					$update = "UPDATE $table SET balance = $_balance, used_last_order = 0 WHERE coupon_id = $coupon_id";
					$writeConnection->query($update);
					
					$insert = "INSERT INTO $history_table (order_id, coupon_id, balance, type) VALUES ($last_order_id, $coupon_id, $used_last_order, 'reduce')";
					$writeConnection->query($insert);
					$total_used += $used_last_order;
					
			}
			$insert_gift_amount = "UPDATE `sales_flat_order_grid` SET `gift_amount` = '{$total_used}' WHERE `entity_id` = {$last_order_id}";
			$writeConnection->query($insert_gift_amount);
			/*  */
			$grand_total = $last_order->getGrandTotal();
			// $order_grand_total = $last_order->getGrandTotal();
			
			if($grand_total == '0.0000'){
		
				$mud_amount = $last_order->getMudAmount();
				$base_subtotal = $last_order->getBaseSubtotal();
				if(floatval($mud_amount) >= floatval($base_subtotal)){
					
					/*
					$orderState = Mage_Sales_Model_Order::STATE_NEW;
					$orderStatus = 'processing';
					$message = Mage::helper('salesrule')->__('已用礼品卡支付全部订单金额。');
					$isCustomerNotified = true;
					$last_order->setState($orderState, $orderStatus, $message, $isCustomerNotified)->save();
					*/
					
					$this->saveInvoice($last_order);
				    $last_order->addStatusToHistory(
						'processing',
						Mage::helper('salesrule')->__('已用礼品卡支付全部订单金额。'),
						true
				    );
				    try{
				        $last_order->save();
				    } catch(Exception $e){
				        ;
				    }
				}
			}

			
			
           

            $redirectUrl = $this->getOnepage()->getCheckout()->getRedirectUrl();
            $result['success'] = true;
            $result['error']   = false;
        } catch (Mage_Payment_Model_Info_Exception $e) {
            $message = $e->getMessage();
            if( !empty($message) ) {
                $result['error_messages'] = $message;
            }
            $result['goto_section'] = 'payment';
            $result['update_section'] = array(
                'name' => 'payment-method',
                'html' => $this->_getPaymentMethodsHtml()
            );
        } catch (Mage_Core_Exception $e) {
            Mage::logException($e);
            Mage::helper('checkout')->sendPaymentFailedEmail($this->getOnepage()->getQuote(), $e->getMessage());
            $result['success'] = false;
            $result['error'] = true;
            $result['error_messages'] = $e->getMessage();

            if ($gotoSection = $this->getOnepage()->getCheckout()->getGotoSection()) {
                $result['goto_section'] = $gotoSection;
                $this->getOnepage()->getCheckout()->setGotoSection(null);
            }

            if ($updateSection = $this->getOnepage()->getCheckout()->getUpdateSection()) {
                if (isset($this->_sectionUpdateFunctions[$updateSection])) {
                    $updateSectionFunction = $this->_sectionUpdateFunctions[$updateSection];
                    $result['update_section'] = array(
                        'name' => $updateSection,
                        'html' => $this->$updateSectionFunction()
                    );
                }
                $this->getOnepage()->getCheckout()->setUpdateSection(null);
            }
        } catch (Exception $e) {
            Mage::logException($e);
            Mage::helper('checkout')->sendPaymentFailedEmail($this->getOnepage()->getQuote(), $e->getMessage());
            $result['success']  = false;
            $result['error']    = true;
            $result['error_messages'] = $this->__('There was an error processing your order. Please contact us or try again later.');
        }
        $this->getOnepage()->getQuote()->save();
        /**
         * when there is redirect to third party, we don't want to save order yet.
         * we will save the order in return action.
         */
        if (isset($redirectUrl)) {
            $result['redirect'] = $redirectUrl;
        }

        $this->getResponse()->setBody(Mage::helper('core')->jsonEncode($result));
    }
	
	
	
	 /*  */
    protected function saveInvoice(Mage_Sales_Model_Order $order)
	{
		if(!$order->canInvoice()) {
			Mage::throwException($this->__('Invalid order. Cannot generate invoice.'));
		}
		$invoice = Mage::getModel('sales/service_order', $order)->prepareInvoice();
		$invoice->setRequestedCaptureCase(Mage_Sales_Model_Order_Invoice::CAPTURE_OFFLINE);
		$invoice->register();
		$invoice->getOrder()->setCustomerNoteNotify(false);
		$invoice->getOrder()->setIsInProcess(true);
		// $order->addStatusHistoryComment('Automatically INVOICED by Inchoo_Invoicer.', false);
		$transactionSave = Mage::getModel('core/resource_transaction')
			->addObject($invoice)
			->addObject($invoice->getOrder());
		$transactionSave->save();
		$invoice->save();
		$invoice->sendEmail();
		$invoice->setEmailSent(true);

		return false;
	}
    /*  */
	
	  public function indexAction()
    {
		$this->checkQuoteAllItems();
		
        if (!Mage::helper('checkout')->canOnepageCheckout()) {
            Mage::getSingleton('checkout/session')->addError($this->__('The onepage checkout is disabled.'));
            $this->_redirect('checkout/cart');
            return;
        }
        $quote = $this->getOnepage()->getQuote();
        if (!$quote->hasItems() || $quote->getHasError()) {
            $this->_redirect('checkout/cart');
            return;
        }
        if (!$quote->validateMinimumAmount()) {
            $error = Mage::getStoreConfig('sales/minimum_order/error_message') ?
                Mage::getStoreConfig('sales/minimum_order/error_message') :
                Mage::helper('checkout')->__('Subtotal must exceed minimum order amount');

            Mage::getSingleton('checkout/session')->addError($error);
            $this->_redirect('checkout/cart');
            return;
        }
        Mage::getSingleton('checkout/session')->setCartWasUpdated(false);
        Mage::getSingleton('customer/session')->setBeforeAuthUrl(Mage::getUrl('*/*/*', array('_secure'=>true)));
        $this->getOnepage()->initCheckout();
        $this->loadLayout();
        $this->_initLayoutMessages('customer/session');
        $this->getLayout()->getBlock('head')->setTitle($this->__('Checkout'));
		
        $this->renderLayout();
    }
	
	
	
	
	public function checkQuoteAllItems()
	{	
	
		$quote = $this->getOnepage()->getQuote();
		// $region = $quote->getBillingAddress()->getRegion();
		
		$shipping_address_id = Mage::getSingleton('customer/session')->getCustomer()->getDefaultShipping();
		
		$address_data = Mage::getModel('customer/address')->load($shipping_address_id)->getData();
		$region = $address_data['region'];
		// Mage::log($address_data);
		
		
		if ($quote->hasItems()){
			foreach($quote->getAllVisibleItems() as $_item){
				$_product = Mage::getModel('catalog/product')->load($_item->getProductId());
				if((boolean)$_product->getData('shippin_area')){
					if($region != '上海市'){
						 Mage::helper('checkout/cart')->getCart()->removeItem($_item->getId())->save();
					}	
				}						
			}
		}
		
		return $this;
		
	}
	
	
}
