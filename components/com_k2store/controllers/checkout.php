<?php
/*------------------------------------------------------------------------
 # com_k2store - K2 Store
# ------------------------------------------------------------------------
# author    Ramesh Elamathi - Weblogicx India http://www.weblogicxindia.com
# copyright Copyright (C) 2012 Weblogicxindia.com. All Rights Reserved.
# @license - http://www.gnu.org/licenses/gpl-2.0.html GNU/GPL
# Websites: http://k2store.org
# Technical Support:  Forum - http://k2store.org/forum/index.html
-------------------------------------------------------------------------*/


// no direct access
defined('_JEXEC') or die('Restricted access');

jimport('joomla.application.component.controller');
JLoader::register( 'K2StoreHelperCart', JPATH_SITE.'/components/com_k2store/helpers/cart.php');
JTable::addIncludePath( JPATH_ADMINISTRATOR.'/components/com_k2store/tables' );
require_once (JPATH_ADMINISTRATOR.'/components/com_k2store/library/tax.php');
require_once (JPATH_ADMINISTRATOR.'/components/com_k2store/library/selectable/base.php');
require_once (JPATH_ADMINISTRATOR.'/components/com_k2store/library/base.php');
class K2StoreControllerCheckout extends K2StoreController
{

	var $_order        = null;
//	var $defaultShippingMethod = null; // set in constructor
	var $initial_order_state   = 4;
	var $_cartitems = null;
	var $tax = null;
	var $session = null;
	var $option = 'com_k2store';
	var $params = null;

	function __construct()
	{
		parent::__construct();
		header('Content-Type: text/html; charset=utf-8');
		$this->params = JComponentHelper::getParams($this->option);
	//	$this->defaultShippingMethod = $this->params->get('defaultShippingMethod', '1');
		// create the order object
		$this->_order = JTable::getInstance('Orders', 'Table');
		//initialise tax class
		$this->tax = new K2StoreTax();
		//initialise the session object
		$this->session = JFactory::getSession();
		//language
		$language = JFactory::getLanguage();
		/* Set the base directory for the language */
		$base_dir = JPATH_SITE;
		/* Load the language. IMPORTANT Becase we use ajax to load cart */
		$language->load($this->option, $base_dir, $language->getTag(), true);


	}

	function display($cachable = false, $urlparams = array()) {
		$app = JFactory::getApplication();

		$values =  $app->input->getArray($_POST);
		$view = $this->getView( 'checkout', 'html' );
		$task = JRequest::getVar('task');
		$model		= $this->getModel('checkout');
		$cart_helper = new K2StoreHelperCart();
		$cart_model = $this->getModel('mycart');
		$link = JRoute::_('index.php?option=com_k2store&view=mycart');

		if (!$cart_helper->hasProducts() && $task != 'confirmPayment' )
		{
			$msg = JText::_('K2STORE_NO_ITEMS_IN_CART');
			$app->redirect($link, $msg);
		}

		//minimum order value check
		//prepare order
		$order= $this->_order;
		$order = $this->populateOrder(false);
		if(!$this->checkMinimumOrderValue($order)) {
			$msg = JText::_('K2STORE_ERROR_MINIMUM_ORDER_VALUE').K2StorePrices::number($this->params->get('global_minordervalue'));
			$link = JRoute::_('index.php?option=com_k2store&view=mycart');
			$app->redirect($link, $msg);
		}

	// Validate minimum quantity requirments.
	// Validate minimum quantity requirments.
		$products = $cart_model->getDataNew();
		try {
			K2StoreInventory::validateQuantityRestrictions($products);
		} catch (Exception $e) {
			$app->redirect($link, $e->getMessage());
		}

		$user 		=	JFactory::getUser();

		$isLogged = 0;
		if($user->id) {
			$isLogged = 1;
		}
		$view->assign('logged',$isLogged);

		//prepare shipping
		// Checking whether shipping is required
		$showShipping = false;

		if($this->params->get('show_shipping_address', 0)) {
			$showShipping = true;
		}

		if ($isShippingEnabled = $cart_model->getShippingIsEnabled())
		{
			$showShipping = true;
		}
		$view->assign( 'showShipping', $showShipping );
		$view->assign('params', $this->params);
		$view->setLayout( 'checkout');

		$view->display();
		return;
	}


	function login() {
		$app = JFactory::getApplication();

		$view = $this->getView( 'checkout', 'html' );
		$model		= $this->getModel('checkout');
		//check session
		$account = $this->session->get('account', 'register', 'k2store');
		if (isset($account)) {
			$view->assign('account', $account);
		} else {
			$view->assign('account', 'register');
		}

		$view->assign('params', $this->params);
		$view->setLayout( 'checkout_login');
		$html = '';
		ob_start();
		$view->display();
		$html = ob_get_contents();
		ob_end_clean();
		echo $html;
		$app->close();
	}

	function login_validate() {

		$app = JFactory::getApplication();
		$user = JFactory::getUser();

		$this->session->set('uaccount', 'login', 'k2store');

		$model = $this->getModel('checkout');
		$cart_helper = new K2StoreHelperCart();
		$redirect_url = JRoute::_('index.php?option=com_k2store&view=checkout');

		$json = array();

		if ($user->id) {
			$json['redirect'] = $redirect_url;
		}

		if ((!$cart_helper->hasProducts())) {
			$json['redirect'] = $redirect_url;
		}

		if (!$json) {

			require_once (JPATH_ADMINISTRATOR.'/components/com_k2store/library/user.php');
			$userHelper = new K2StoreHelperUser;
			//now login the user
			if ( !$userHelper->login(
					array('username' => $app->input->getString('email'), 'password' => $app->input->getString('password'))
			))
			{
				$json['error']['warning'] = JText::_('K2STORE_CHECKOUT_ERROR_LOGIN');
			}

		}

		if (!$json) {
			$this->session->clear('guest', 'k2store');

			// Default Addresses
			$address_info = $this->getModel('address')->getSingleAddressByUserID();

			if ($address_info) {
				if ($this->params->get('config_tax_default') == 'shipping') {
					$this->session->set('shipping_country_id', $address_info->country_id, 'k2store');
					$this->session->set('shipping_zone_id',$address_info->zone_id, 'k2store');
					$this->session->set('shipping_postcode',$address_info->zip, 'k2store');
				}

				if ($this->params->get('config_tax_default') == 'billing') {
					$this->session->set('billing_country_id', $address_info->country_id, 'k2store');
					$this->session->set('billing_zone_id',$address_info->zone_id, 'k2store');
				}
			} else {
				$this->session->clear('shipping_country_id', 'k2store');
				$this->session->clear('shipping_zone_id', 'k2store');
				$this->session->clear('shipping_postcode', 'k2store');
				$this->session->clear('billing_country_id', 'k2store');
				$this->session->clear('billing_zone_id', 'k2store');
			}

			$json['redirect'] = $redirect_url;
		}
		echo json_encode($json);
		$app->close();
	}

	function register() {
		$app = JFactory::getApplication();

		$view = $this->getView( 'checkout', 'html' );
		$model		= $this->getModel('checkout');
		$cart_model = $this->getModel('mycart');

		$this->session->set('uaccount', 'register', 'k2store');

		$products = $cart_model->getDataNew();
		try {
			K2StoreInventory::validateQuantityRestrictions($products);
		} catch (Exception $e) {
			$app->redirect($link, $e->getMessage());
		}

		$selectableBase = new K2StoreSelectableBase();
		$view->assign('fieldsClass', $selectableBase);
		$address = JTable::getInstance('address', 'Table');
		$fields = $selectableBase->getFields('register',$address,'address');
		$view->assign('fields', $fields);
		$view->assign('address', $address);

		//get layout settings
		$view->assign('storeProfile', K2StoreHelperCart::getStoreAddress());

		$showShipping = false;
		if($this->params->get('show_shipping_address', 0)) {
			$showShipping = true;
		}

		if ($isShippingEnabled = $cart_model->getShippingIsEnabled())
		{
			$showShipping = true;
		}
		$view->assign( 'showShipping', $showShipping );
		$view->assign('params', $this->params);
		$view->setLayout( 'checkout_register');

		ob_start();
		$view->display();
		$html = ob_get_contents();
		ob_end_clean();
		echo $html;
		$app->close();
	}

	function register_validate() {

		$app = JFactory::getApplication();
		$user = JFactory::getUser();
		$model = $this->getModel('checkout');
		$data = $app->input->getArray($_POST);
		$address_model = $this->getModel('address');
		$store_address = K2StoreHelperCart::getStoreAddress();
		require_once (JPATH_ADMINISTRATOR.'/components/com_k2store/library/user.php');
		$userHelper = new K2StoreHelperUser;
		$json = array();

		// Validate if customer is already logged out.
		if ($user->id) {
			$json['redirect'] = $redirect_url;
		}

		// Validate cart has products and has stock.
		if (!K2StoreHelperCart::hasProducts()) {
			$json['redirect'] = $redirect_url;
		}

		// TODO Validate minimum quantity requirments.
		if (!$json) {

			$selectableBase = new K2StoreSelectableBase();
			$json = $selectableBase->validate($data, 'register', 'address');
			//validate the password fields
			if ((JString::strlen($app->input->post->get('password')) < 4)) {
				$json['error']['password'] = JText::_('K2STORE_PASSWORD_REQUIRED');
			}

			if ($app->input->post->get('confirm') != $app->input->post->get('password')) {
				$json['error']['confirm'] = JText::_('K2STORE_PASSWORDS_DOESTNOT_MATCH');
			}

			//check email
			if($userHelper->emailExists($app->input->post->getString('email') )){
				$json['error']['email'] = JText::_('K2STORE_EMAIL_EXISTS');
			}
		}

		if (!$json) {

				//now create the user
				// create the details array with new user info
				$details = array(
						'email' =>  $app->input->getString('email'),
						'name' => $app->input->getString('first_name').' '.$app->input->getString('last_name'),
						'username' =>  $app->input->getString('email'),
						'password' => $app->input->getString('password'),
						'password2'=> $app->input->getString('confirm')
				);
				$msg = '';
				$user = $userHelper->createNewUser($details, $msg);

				$this->session->set('account', 'register', 'k2store');

				//now login the user
				if ( $userHelper->login(
							array('username' => $user->username, 'password' => $details['password'])
					)
				) {

					//$billing_address_id = $userHelper->addCustomer();
					//store address to the table
					$billing_address_id = $address_model->addAddress('billing');

					//check if we have a country and zone id's. If not use the store address
					$country_id = $app->input->post->getInt('country_id', '');
					if(empty($country_id)) {
						$country_id = $store_address->country_id;
					}

					$zone_id = $app->input->post->getInt('zone_id', '');
					if(empty($zone_id)) {
						$zone_id = $store_address->zone_id;
					}

					$postcode = $app->input->post->getString('zip');
					if(empty($postcode)) {
						$postcode = $store_address->store_zip;
					}

					$this->session->set('billing_address_id', $billing_address_id , 'k2store');
					$this->session->set('billing_country_id', $country_id, 'k2store');
					$this->session->set('billing_zone_id', $zone_id, 'k2store');

					//check if ship to billing address is checked.
					$shipping_address = $app->input->post->get('shipping_address');

					if (!empty($shipping_address )) {
						$this->session->set('shipping_address_id', $billing_address_id, 'k2store');
						$this->session->set('shipping_country_id', $country_id, 'k2store');
						$this->session->set('shipping_zone_id', $zone_id, 'k2store');
						$this->session->set('shipping_postcode', $postcode, 'k2store');
					}
				} else {
					$json['redirect'] = $redirect_url;
				}

			$this->session->clear('guest', 'k2store');
			$this->session->clear('shipping_method', 'k2store');
			$this->session->clear('shipping_methods', 'k2store');
			$this->session->clear('payment_method', 'k2store');
			$this->session->clear('payment_methods', 'k2store');
		}
		echo json_encode($json);
		$app->close();
	}

	function guest() {
		$app = JFactory::getApplication();
		$cart_model = $this->getModel('mycart');
		$view = $this->getView( 'checkout', 'html' );
		$model = $this->getModel('checkout');

		$this->session->set('uaccount', 'guest', 'k2store');

		//check inventory
		$products = $cart_model->getDataNew();
		try {
			K2StoreInventory::validateQuantityRestrictions($products);
		} catch (Exception $e) {
			$app->redirect($link, $e->getMessage());
		}

		//set guest varibale to session as the array, if it does not exist
		if(!$this->session->has('guest', 'k2store')) {
			$this->session->set('guest', array(), 'k2store');
		}
		$guest = $this->session->get('guest', array(), 'k2store');

		$data = array();

		$selectableBase = new K2StoreSelectableBase();
		$view->assign('fieldsClass', $selectableBase);

		$address = JTable::getInstance('address', 'Table');

		if (empty($guest['billing']['zip']) && $this->session->has('billing_postcode', 'k2store') ) {
			$guest['billing']['zip'] = $this->session->get('billing_postcode', '', 'k2store');
		}

		if (empty($guest['billing']['country_id']) && $this->session->has('billing_country_id', 'k2store')) {
			$guest['billing']['country_id'] = $this->session->get('billing_country_id', '', 'k2store');
		}

		if (empty($guest['billing']['zone_id']) && $this->session->has('billing_zone_id', 'k2store')) {
			$guest['billing']['zone_id'] = $this->session->get('billing_zone_id', '', 'k2store');
		}

		//bind the guest data to address table if it exists in the session

		if(isset($guest['billing']) && count($guest['billing'])) {
			$address->bind($guest['billing']);
		}

		$fields = $selectableBase->getFields('guest',$address,'address');
		$view->assign('fields', $fields);
		$view->assign('address', $address);

		//get layout settings
		$storeProfile = K2StoreHelperCart::getStoreAddress();
		$view->assign('storeProfile', K2StoreHelperCart::getStoreAddress());


		$showShipping = false;
		if($this->params->get('show_shipping_address', 0)) {
			$showShipping = true;
		}

		if ($isShippingEnabled = $cart_model->getShippingIsEnabled())
		{
			$showShipping = true;
		}
		$view->assign( 'showShipping', $showShipping );

		$data['shipping_required'] = $showShipping;

		if (isset($guest['shipping_address'])) {
			$data['shipping_address'] = $guest['shipping_address'];
		} else {
			$data['shipping_address'] = true;
		}
		$view->assign( 'data', $data);

		$view->setLayout( 'checkout_guest');

		ob_start();
		$view->display();
		$html = ob_get_contents();
		ob_end_clean();
		echo $html;
		$app->close();

	}

	function guest_validate() {

		$app = JFactory::getApplication();
		$cart_helper = new K2StoreHelperCart();
		$address_model = $this->getModel('address');
		$model = $this->getModel('checkout');
		$redirect_url = JRoute::_('index.php?option=com_k2store&view=checkout');
		$data = $app->input->getArray($_POST);
		$store_address = K2StoreHelperCart::getStoreAddress();
		//initialise guest value from session
		$guest = $this->session->get('guest', array(), 'k2store');

		$json = array();

		// Validate if customer is logged in.
		if (JFactory::getUser()->id) {
			$json['redirect'] = $redirect_url;
		}

		// Validate cart has products and has stock.
		if ((!$cart_helper->hasProducts())) {
			$json['redirect'] = $redirect_url;
		}

		// Check if guest checkout is avaliable.
		//TODO prevent if products have downloads also
		if (!$this->params->get('allow_guest_checkout')) {
			$json['redirect'] = $redirect_url;
		}

		if (!$json) {

			$selectableBase = new K2StoreSelectableBase();
			$json = $selectableBase->validate($data, 'guest', 'address');
		}

		if (!$json) {

			//now assign the post data to the guest billing array.
			foreach($data as $key=>$value) {
				$guest['billing'][$key] = $value;
			}

			//check if we have a country and zone id's. If not use the store address
			$country_id = $app->input->post->getInt('country_id', '');
			if(empty($country_id)) {
				$country_id = $store_address->country_id;
			}

			$zone_id = $app->input->post->getInt('zone_id', '');
			if(empty($zone_id)) {
				$zone_id = $store_address->zone_id;
			}

			$postcode = $app->input->post->get('zip');
			if(empty($postcode)) {
				$postcode = $store_address->store_zip;
			}


			//returns an object
			$country_info = $model->getCountryById($country_id);

			if ($country_info) {
				$guest['billing']['country_name'] = $country_info->country_name;
				$guest['billing']['iso_code_2'] = $country_info->country_isocode_2;
				$guest['billing']['iso_code_3'] = $country_info->country_isocode_3;
			} else {
				$guest['billing']['country_name'] = '';
				$guest['billing']['iso_code_2'] = '';
				$guest['billing']['iso_code_3'] = '';
			}

			$zone_info = $model->getZonesById($zone_id);

			if ($zone_info) {
				$guest['billing']['zone_name'] = $zone_info->zone_name;
				$guest['billing']['zone_code'] = $zone_info->zone_code;
			} else {
				$guest['billing']['zone_name'] = '';
				$guest['billing']['zone_code'] = '';
			}

			if ($app->input->getInt('shipping_address')) {
				$guest['shipping_address'] = true;
			} else {
				$guest['shipping_address'] = false;
			}

			// Default billing address
			$this->session->set('billing_country_id', $country_id, 'k2store');
			$this->session->set('billing_zone_id', $zone_id, 'k2store');

			if ($guest['shipping_address']) {

				foreach($data as $key=>$value) {
					$guest['shipping'][$key] = $value;
				}

				if ($country_info) {
					$guest['shipping']['country_name'] = $country_info->country_name;
					$guest['shipping']['iso_code_2'] = $country_info->country_isocode_2;
					$guest['shipping']['iso_code_3'] = $country_info->country_isocode_3;
				} else {
					$guest['shipping']['country_name'] = '';
					$guest['shipping']['iso_code_2'] = '';
					$guest['shipping']['iso_code_3'] = '';
				}

				if ($zone_info) {
					$guest['shipping']['zone_name'] = $zone_info->zone_name;
					$guest['shipping']['zone_code'] = $zone_info->zone_code;
				} else {
					$guest['shipping']['zone_name'] = '';
					$guest['shipping']['zone_code'] = '';
				}
				// Default Shipping Address
				$this->session->set('shipping_country_id', $country_id, 'k2store');
				$this->session->set('shipping_zone_id', $zone_id, 'k2store');
				$this->session->set('shipping_postcode', $postcode, 'k2store');

			}

			//now set the guest values to the session
			$this->session->set('guest', $guest, 'k2store');
			$this->session->set('account', 'guest', 'k2store');

			$this->session->clear('shipping_method', 'k2store');
			$this->session->clear('shipping_methods', 'k2store');
			$this->session->clear('payment_method', 'k2store');
			$this->session->clear('payment_methods', 'k2store');
		}
		echo json_encode($json);
		$app->close();
	}

	function guest_shipping() {

		$app = JFactory::getApplication();
		$cart_model = $this->getModel('mycart');
		$tax = new K2StoreTax();
		$view = $this->getView( 'checkout', 'html' );
		$model = $this->getModel('checkout');
		$guest = $this->session->get('guest', array(), 'k2store');

		$data = array();

		$selectableBase = new K2StoreSelectableBase();
		$view->assign('fieldsClass', $selectableBase);

		$address = JTable::getInstance('address', 'Table');

		if (empty($guest['shipping']['zip']) && $this->session->has('shipping_postcode', 'k2store') ) {
			$guest['shipping']['zip'] = $this->session->get('shipping_postcode', '', 'k2store');
		}

		if (empty($guest['shipping']['country_id']) && $this->session->has('shipping_country_id', 'k2store')) {
			$guest['shipping']['country_id'] = $this->session->get('shipping_country_id', '', 'k2store');
		}

		if (empty($guest['shipping']['zone_id']) && $this->session->has('shipping_zone_id', 'k2store')) {
			$guest['shipping']['zone_id'] = $this->session->get('shipping_zone_id', '', 'k2store');
		}

		//bind the guest data to address table if it exists in the session

		if(isset($guest['shipping']) && count($guest['shipping'])) {
			$address->bind($guest['shipping']);
		}

		$fields = $selectableBase->getFields('guest_shipping',$address,'address');
		$view->assign('fields', $fields);
		$view->assign('address', $address);

		//get layout settings
		$storeProfile = K2StoreHelperCart::getStoreAddress();
		$view->assign('storeProfile', K2StoreHelperCart::getStoreAddress());

		$view->assign( 'data', $data);

		$view->setLayout( 'checkout_guest_shipping');

		ob_start();
		$view->display();
		$html = ob_get_contents();
		ob_end_clean();
		echo $html;
		$app->close();

	}

	function guest_shipping_validate() {
		$app = JFactory::getApplication();
		$cart_helper = new K2StoreHelperCart();
		$address_model = $this->getModel('address');
		$model = $this->getModel('checkout');
		$redirect_url = JRoute::_('index.php?option=com_k2store&view=checkout');
		$data = $app->input->getArray($_POST);
		$store_address = K2StoreHelperCart::getStoreAddress();
		//initialise guest value from session
		$guest = $this->session->get('guest', array(), 'k2store');
		$json = array();

		// Validate if customer is logged in.
		if (JFactory::getUser()->id) {
			$json['redirect'] = $redirect_url;
		}

		// Validate cart has products and has stock.
		if ((!$cart_helper->hasProducts())) {
			$json['redirect'] = $redirect_url;
		}

		// Check if guest checkout is avaliable.
		//TODO prevent if products have downloads also
		if (!$this->params->get('allow_guest_checkout')) {
			$json['redirect'] = $redirect_url;
		}

		if (!$json) {
			$selectableBase = new K2StoreSelectableBase();
			$json = $selectableBase->validate($data, 'guest_shipping', 'address');
		}

		if(!$json) {

			//now assign the post data to the guest billing array.
			foreach($data as $key=>$value) {
				$guest['shipping'][$key] = $value;
			}

			//check if we have a country and zone id's. If not use the store address
			$country_id = $app->input->post->getInt('country_id', '');
			if(empty($country_id)) {
				$country_id = $store_address->country_id;
			}

			$zone_id = $app->input->post->getInt('zone_id', '');
			if(empty($zone_id)) {
				$zone_id = $store_address->zone_id;
			}

			$postcode = $app->input->post->get('zip');
			if(empty($postcode)) {
				$postcode = $store_address->store_zip;
			}

			//now get the country info
			//returns an object
			$country_info = $model->getCountryById($country_id);

			if ($country_info) {
				$guest['shipping']['country_name'] = $country_info->country_name;
				$guest['shipping']['iso_code_2'] = $country_info->country_isocode_2;
				$guest['shipping']['iso_code_3'] = $country_info->country_isocode_3;
			} else {
				$guest['shipping']['country_name'] = '';
				$guest['shipping']['iso_code_2'] = '';
				$guest['shipping']['iso_code_3'] = '';
			}

			$zone_info = $model->getZonesById($zone_id);

			if ($zone_info) {
				$guest['shipping']['zone_name'] = $zone_info->zone_name;
				$guest['shipping']['zone_code'] = $zone_info->zone_code;
			} else {
				$guest['shipping']['zone_name'] = '';
				$guest['shipping']['zone_code'] = '';
			}
			// Default Shipping Address
			$this->session->set('shipping_country_id', $country_id, 'k2store');
			$this->session->set('shipping_zone_id', $zone_id, 'k2store');
			$this->session->set('shipping_postcode', $postcode, 'k2store');

			//now set the guest values to the session
			$this->session->set('guest', $guest, 'k2store');

			$this->session->clear('shipping_method', 'k2store');
			$this->session->clear('shipping_methods', 'k2store');

		}
		echo json_encode($json);
		$app->close();
	}

	function billing_address() {

		$app = JFactory::getApplication();
		$address = $this->getModel('address')->getSingleAddressByUserID();
		$view = $this->getView( 'checkout', 'html' );
		$model = $this->getModel('checkout');

		//get the billing address id from the session
		if ($this->session->has('billing_address_id', 'k2store')) {
			$billing_address_id = $this->session->get('billing_address_id', '', 'k2store');
		} else {
			$billing_address_id = isset($address->id)?$address->id:'';
		}

		$view->assign('address_id', $billing_address_id);

		if ($this->session->has('billing_country_id', 'k2store')) {
			$billing_country_id = $this->session->get('billing_country_id', '', 'k2store');
		} else {
			$billing_country_id = isset($address->country_id)?$address->country_id:'';
		}

		if ($this->session->has('billing_zone_id', 'k2store')) {
			$billing_zone_id = $this->session->get('billing_zone_id', '', 'k2store');
		} else {
			$billing_zone_id = isset($address->zone_id)?$address->zone_id:'';
		}
		$view->assign('zone_id', $billing_zone_id);

		//get all address
		$addresses = $this->getModel('address')->getAddresses();
		$view->assign('addresses', $addresses);

	//	$bill_country = $model->getCountryList('country_id','country_id', $billing_country_id);
	//	$view->assign('bill_country', $bill_country);
		$selectableBase = new K2StoreSelectableBase();
		$view->assign('fieldsClass', $selectableBase);
		$address_table = JTable::getInstance('address', 'Table');
		$fields = $selectableBase->getFields('billing',$address,'address');
		$view->assign('fields', $fields);
		$view->assign('address', $address_table);

		//get layout settings
		$view->assign('storeProfile', K2StoreHelperCart::getStoreAddress());

		$view->setLayout( 'checkout_billing');

		ob_start();
		$view->display();
		$html = ob_get_contents();
		ob_end_clean();
		echo $html;
		$app->close();
	}

	//validate billing address

	function billing_address_validate() {

		$app = JFactory::getApplication();
		$user = JFactory::getUser();
		$address_model = $this->getModel('address');
		$redirect_url = JRoute::_('index.php?option=com_k2store&view=checkout');
		$cart_model = $this->getModel('mycart');
		$data = $app->input->getArray($_POST);
		$json = array();
		$store_address = K2StoreHelperCart::getStoreAddress();

		$selectableBase = new K2StoreSelectableBase();


		// Validate if customer is logged or not.
		if (!$user->id) {
			$json['redirect'] = $redirect_url;
		}

		// Validate cart has products and has stock.
		if (!K2StoreHelperCart::hasProducts()) {
			$json['redirect'] = $redirect_url;
		}

		// TODO Validate minimum quantity requirments.

		//Has the customer selected an existing address?
		$selected_billing_address =$app->input->getString('billing_address');
		if (isset($selected_billing_address ) && $app->input->getString('billing_address') == 'existing') {
			$selected_address_id =	$app->input->getInt('address_id');
			if (empty($selected_address_id)) {
				$json['error']['warning'] = JText::_('K2STORE_ADDRESS_SELECTION_ERROR');
			} elseif (!in_array($app->input->getInt('address_id'), array_keys($address_model->getAddresses('id')))) {
				$json['error']['warning'] = JText::_('K2STORE_ADDRESS_SELECTION_ERROR');
			} else {
				// Default Payment Address
				$address_info = $address_model->getAddress($app->input->getInt('address_id'));
			}

			if (!$json) {
				$this->session->set('billing_address_id', $app->input->getInt('address_id'), 'k2store');

				if ($address_info) {
					$this->session->set('billing_country_id',$address_info['country_id'], 'k2store');
					$this->session->set('billing_zone_id',$address_info['zone_id'], 'k2store');
				} else {
					$this->session->clear('billing_country_id', 'k2store');
					$this->session->clear('billing_zone_id', 'k2store');
				}
				$this->session->clear('payment_method', 'k2store');
				$this->session->clear('payment_methods', 'k2store');
			}
		} else {

			if (!$json) {
				$json = $selectableBase->validate($data, 'billing', 'address');

				if(!$json) {
					$address_id = $address_model->addAddress('billing');
					//now get the address and save to session
					$address_info = $address_model->getAddress($address_id);

					//check if we have a country and zone id's. If not use the store address
					$country_id = $app->input->post->getInt('country_id', '');
					if(empty($country_id)) {
						$country_id = $store_address->country_id;
					}

					$zone_id = $app->input->post->getInt('zone_id', '');
					if(empty($zone_id)) {
						$zone_id = $store_address->zone_id;
					}

					$this->session->set('billing_address_id', $address_info['id'], 'k2store');
					$this->session->set('billing_country_id', $country_id, 'k2store');
					$this->session->set('billing_zone_id',$zone_id, 'k2store');
					$this->session->clear('payment_method', 'k2store');
					$this->session->clear('payment_methods', 'k2store');
				}

			}

		}
		echo json_encode($json);
		$app->close();

	}

	//shipping address

	function shipping_address() {

		$app = JFactory::getApplication();
		$address = $this->getModel('address')->getSingleAddressByUserID();
		$view = $this->getView( 'checkout', 'html' );
		$model = $this->getModel('checkout');

		//get the billing address id from the session
		if ($this->session->has('shipping_address_id', 'k2store')) {
			$shipping_address_id = $this->session->get('shipping_address_id', '', 'k2store');
		} else {
			$shipping_address_id = $address->id;
		}

		$view->assign('address_id', $shipping_address_id);

		if ($this->session->has('shipping_country_id', 'k2store')) {
			$shipping_country_id = $this->session->get('shipping_country_id', '', 'k2store');
		} else {
			$shipping_country_id = $address->country_id;
		}

		if ($this->session->has('shipping_zone_id', 'k2store')) {
			$shipping_zone_id = $this->session->get('shipping_zone_id', '', 'k2store');
		} else {
			$shipping_zone_id = $address->zone_id;
		}
		$view->assign('zone_id', $shipping_zone_id);

		//get all address
		$addresses = $this->getModel('address')->getAddresses();
		$view->assign('addresses', $addresses);

		$selectableBase = new K2StoreSelectableBase();
		$view->assign('fieldsClass', $selectableBase);
		$address_table = JTable::getInstance('address', 'Table');
		$fields = $selectableBase->getFields('shipping',$address,'address');
		$view->assign('fields', $fields);
		$view->assign('address', $address_table);

		//get layout settings
		$view->assign('storeProfile', K2StoreHelperCart::getStoreAddress());


		$view->setLayout( 'checkout_shipping');

		ob_start();
		$view->display();
		$html = ob_get_contents();
		ob_end_clean();
		echo $html;
		$app->close();
	}

function shipping_address_validate() {

	$app = JFactory::getApplication();
	$user = JFactory::getUser();
	$address_model = $this->getModel('address');
	$redirect_url = JRoute::_('index.php?option=com_k2store&view=checkout');
	$cart_model = $this->getModel('mycart');
	$data = $app->input->getArray($_POST);
	$json = array();
	$store_address = K2StoreHelperCart::getStoreAddress();

	$selectableBase = new K2StoreSelectableBase();


	// Validate if customer is logged or not.
	if (!$user->id) {
		$json['redirect'] = $redirect_url;
	}
	// Validate if shipping is required. If not the customer should not have reached this page.
	$showShipping = false;

	if($this->params->get('show_shipping_address', 0)) {
		$showShipping = true;
	}

	if ($isShippingEnabled = $cart_model->getShippingIsEnabled())
	{
		$showShipping = true;
	}


	if ($showShipping == false) {
		$json['redirect'] = $redirect_url;
	}

	// Validate cart has products and has stock.
	if (!K2StoreHelperCart::hasProducts()) {

		$json['redirect'] = $redirect_url;
	}
	// TODO Validate minimum quantity requirments.

	//Has the customer selected an existing address?
	$selected_shipping_address =$app->input->getString('shipping_address');
	if (isset($selected_shipping_address ) && $app->input->getString('shipping_address') == 'existing') {
		$selected_address_id =	$app->input->getInt('address_id');
		if (empty($selected_address_id)) {
			$json['error']['warning'] = JText::_('K2STORE_ADDRESS_SELECTION_ERROR');
		} elseif (!in_array($app->input->getInt('address_id'), array_keys($address_model->getAddresses('id')))) {
			$json['error']['warning'] = JText::_('K2STORE_ADDRESS_SELECTION_ERROR');
		} else {
			// Default shipping Address. returns associative list of single record
			$address_info = $address_model->getAddress($app->input->getInt('address_id'));
		}

		if (!$json) {
			$this->session->set('shipping_address_id', $app->input->getInt('address_id'), 'k2store');

			if ($address_info) {
				$this->session->set('shipping_country_id',$address_info['country_id'], 'k2store');
				$this->session->set('shipping_zone_id',$address_info['zone_id'], 'k2store');
				$this->session->set('shipping_postcode',$address_info['zip'], 'k2store');
			} else {
				$this->session->clear('shipping_country_id', 'k2store');
				$this->session->clear('shipping_zone_id', 'k2store');
				$this->session->clear('shipping_postcode', 'k2store');
			}
			$this->session->clear('shipping_method', 'k2store');
			$this->session->clear('shipping_methods', 'k2store');
		}
	} else {
		if (!$json) {

			$json = $selectableBase->validate($data, 'billing', 'address');

			if(!$json) {
				$address_id = $address_model->addAddress('shipping');
				//now get the address and save to session
				$address_info = $address_model->getAddress($address_id);

				//check if we have a country and zone id's. If not use the store address
				$country_id = $app->input->post->getInt('country_id', '');
				if(empty($country_id)) {
					$country_id = $store_address->country_id;
				}

				$zone_id = $app->input->post->getInt('zone_id', '');
				if(empty($zone_id)) {
					$zone_id = $store_address->zone_id;
				}

				$postcode= $app->input->post->get('zip');
				if(empty($postcode)) {
					$postcode = $store_address->zip;
				}

				$this->session->set('shipping_address_id', $address_info['id'], 'k2store');
				$this->session->set('shipping_country_id',$country_id, 'k2store');
				$this->session->set('shipping_zone_id',$zone_id, 'k2store');
				$this->session->set('shipping_postcode',$postcode, 'k2store');
				$this->session->clear('shipping_method', 'k2store');
				$this->session->clear('shipping_methods', 'k2store');
			}

		}

	}

	echo json_encode($json);
	$app->close();
}

//shipping and payment method
//TODO:: after developing shipping options, divide this function into two

	function shipping_payment_method() {
		$app = JFactory::getApplication();
		$view = $this->getView( 'checkout', 'html' );
		$task = JRequest::getVar('task');
		$model		= $this->getModel('checkout');
		$cart_helper = new K2StoreHelperCart();
		$cart_model = $this->getModel('mycart');

		if (!$cart_helper->hasProducts())
		{
			$msg = JText::_('K2STORE_NO_ITEMS_IN_CART');
			$link = JRoute::_('index.php?option=com_k2store&view=mycart');
			$app->redirect($link, $msg);
		}

		//prepare order
		$order= $this->_order;
		$order = $this->populateOrder(false);
		// get the order totals
		$order->calculateTotals();

		$dispatcher = JDispatcher::getInstance();
		JPluginHelper::importPlugin ('k2store');

		//custom fields
		$selectableBase = new K2StoreSelectableBase();
		$view->assign('fieldsClass', $selectableBase);
		$address_table = JTable::getInstance('address', 'Table');
		$fields = $selectableBase->getFields('payment',$address,'address');
		$view->assign('fields', $fields);
		$view->assign('address', $address_table);

		//get layout settings
		$view->assign('storeProfile', K2StoreHelperCart::getStoreAddress());

		$showShipping = false;

		if($this->params->get('show_shipping_address', 0)) {
			$showShipping = true;
		}

		if ($isShippingEnabled = $cart_model->getShippingIsEnabled())
		{
			$showShipping = true;
			//$this->setShippingMethod();
		}
		$view->assign( 'showShipping', $showShipping );

		if($showShipping)
		{
			$rates = $this->getShippingRates();

			$shipping_layout = "shipping_yes";
			//	if (!$this->session->has('shipping_address_id', 'k2store'))
			//	{
			//		$shipping_layout = "shipping_calculate";
			//	}

			$shipping_method_form = $this->getShippingHtml( $shipping_layout );
			$view->assign( 'showShipping', $showShipping );
			$view->assign( 'shipping_method_form', $shipping_method_form );

			$view->assign( 'rates', $rates );
		}



		//process payment plugins
		$showPayment = true;
		if ((float)$order->order_total == (float)'0.00')
		{
			$showPayment = false;
		}
		$view->assign( 'showPayment', $showPayment );

		require_once (JPATH_SITE.'/components/com_k2store/helpers/plugin.php');
		$payment_plugins = K2StoreHelperPlugin::getPluginsWithEvent( 'onK2StoreGetPaymentPlugins' );


		$plugins = array();
		if ($payment_plugins)
		{
			foreach ($payment_plugins as $plugin)
			{
				$results = $dispatcher->trigger( "onK2StoreGetPaymentOptions", array( $plugin->element, $order ) );
				if (in_array(true, $results, true))
				{
					$plugins[] = $plugin;
				}
			}
		}

		if (count($plugins) == 1)
		{
			$plugins[0]->checked = true;
		//	ob_start();
			$html = $this->getPaymentForm( $plugins[0]->element, true );
		//	$html = json_decode( ob_get_contents() );
		//	ob_end_clean();
			$view->assign( 'payment_form_div', $html);
		}

		$view->assign('plugins', $plugins);
		//also set the payment methods to session



		//terms and conditions
		if( $this->params->get('termsid') ){
			$tos_link = JRoute::_('index.php?option=com_k2&view=item&tmpl=component&id='.$this->params->get('termsid'));
		}else{
			$tos_link=null;
		}

		$view->assign( 'tos_link', $tos_link);

		//Get and Set Model
		$view->setModel( $model, true );
		$view->assign( 'order', $order );
		$view->assign('params', $this->params);
		$view->setLayout( 'checkout_shipping_payment');
		ob_start();
		$view->display();
		$html = ob_get_contents();
		ob_end_clean();
		echo $html;
		$app->close();

	}

	function shipping_payment_method_validate() {

		$app = JFactory::getApplication();
		$user = JFactory::getUser();
		$model		= $this->getModel('checkout');
		$cart_helper = new K2StoreHelperCart();
		$cart_model = $this->getModel('mycart');
		$address_model = $this->getModel('address');
		$redirect_url = JRoute::_('index.php?option=com_k2store&view=checkout');
		//now get the values posted by the plugin, if any
		$values = $app->input->getArray($_POST);
		$json = array();

		//first validate custom fields
		$selectableBase = new K2StoreSelectableBase();
		$json = $selectableBase->validate($values, 'payment', 'address');

		if (!$json) {
			//validate weather the customer is logged in
			$billing_address = '';
			if ($user->id && $this->session->has('billing_address_id', 'k2store')) {
				$billing_address = $address_model->getAddress($this->session->get('billing_address_id', '', 'k2store'));
			} elseif ($this->session->has('guest', 'k2store')) {
				$guest = $this->session->get('guest', array(), 'k2store');
				$billing_address = $guest['billing'];
			}

			if (empty($billing_address)) {
				$json['redirect'] = $redirect_url;
			}

			//cart has products?
			if(!$cart_helper->hasProducts()) {
				$json['redirect'] = $redirect_url;
			}

			if (!$json) {

				$isShippingEnabled = $cart_model->getShippingIsEnabled();
				//validate selection of shipping methods and set the shipping rates
				if($this->params->get('show_shipping_address', 0) || $isShippingEnabled ) {
					//shipping is required.

					if ($user->id && $this->session->has('shipping_address_id', 'k2store')) {
						$shipping_address = $address_model->getAddress($this->session->get('shipping_address_id', '', 'k2store'));
					} elseif ($this->session->has('guest', 'k2store')) {
						$guest = $this->session->get('guest', array(), 'k2store');
						$shipping_address = $guest['shipping'];
					}

					//check if shipping address id is set in session. If not, redirect
					if(empty($shipping_address)) {
						$json['error']['shipping'] = JText::_('K2STORE_CHECKOUT_ERROR_SHIPPING_ADDRESS_NOT_FOUND');
						$json['redirect'] = $redirect_url;
					}

					try {
						$this->validateSelectShipping($values);
					} catch (Exception $e) {
						$json['error']['shipping_error_div'] = $e->getMessage();
					}

					if(!$json) {

						$shipping_values = array();
						$shipping_values['shipping_price']    = isset($values['shipping_price']) ? $values['shipping_price'] : 0;
						$shipping_values['shipping_extra']   = isset($values['shipping_extra']) ? $values['shipping_extra'] : 0;
						$shipping_values['shipping_code']     = isset($values['shipping_code']) ? $values['shipping_code'] : '';
						$shipping_values['shipping_name']     = isset($values['shipping_name']) ? $values['shipping_name'] : '';
						$shipping_values['shipping_tax']      = isset($values['shipping_tax']) ? $values['shipping_tax'] : 0;
						$shipping_values['shipping_plugin']     = isset($values['shipping_plugin']) ? $values['shipping_plugin'] : '';
						//set the shipping method to session
						$this->session->set('shipping_method',$shipping_values['shipping_plugin'], 'k2store');
						$this->session->set('shipping_values',$shipping_values, 'k2store');
					}

				}

			}


			//validate selection of payment methods
			if (!$json) {

				//payment validation had to be done only when the order value is greater than zero
				//prepare order
				$order= $this->_order;
				$order = $this->populateOrder(false);
				// get the order totals
				$order->calculateTotals();
				$showPayment = true;
				if ((float)$order->order_total == (float)'0.00')
				{
					$showPayment = false;
				}



				if($showPayment) {
					$payment_plugin = $app->input->getString('payment_plugin');
					if (!isset($payment_plugin)) {
						$json['error']['warning'] = JText::_('K2STORE_CHECKOUT_ERROR_PAYMENT_METHOD');
					}
					//validate the selected payment
					try {
						$this->validateSelectPayment($payment_plugin, $values);
					} catch (Exception $e) {
						$json['error']['payment_error_div'] = $e->getMessage();
					}

				}

				if($this->params->get('show_terms', 0) && $this->params->get('terms_display_type', 'link') =='checkbox' ) {
					$tos_check = $app->input->get('tos_check');
					if (!isset($tos_check)) {
						$json['error']['warning'] = JText::_('K2STORE_CHECKOUT_ERROR_AGREE_TERMS');
					}
				}

				if (!$json) {

					$payment_plugin = $app->input->getString('payment_plugin');
					//set the payment plugin form values in the session as well.
					$this->session->set('payment_values', $values, 'k2store');
					$this->session->set('payment_method', $payment_plugin, 'k2store');
					$this->session->set('customer_note', strip_tags($app->input->getString('customer_note')), 'k2store');
				}
			}
		}
		echo json_encode($json);
		$app->close();
	}

	function confirm() {

		$app = JFactory::getApplication();
		$user = JFactory::getUser();
		$lang = JFactory::getLanguage();
		$db = JFactory::getDbo();
		$dispatcher    = JDispatcher::getInstance();
		JPluginHelper::importPlugin ('k2store');

		$view = $this->getView( 'checkout', 'html' );
		$model		= $this->getModel('checkout');
		$cart_helper = new K2StoreHelperCart();
		$cart_model = $this->getModel('mycart');
		$address_model = $this->getModel('address');
		$redirect_url = JRoute::_('index.php?option=com_k2store&view=checkout');
		$redirect = '';
		//get the payment plugin form values set in the session.
		if($this->session->has('payment_values', 'k2store')) {
			$values = $this->session->get('payment_values', array(), 'k2store');
			//backward compatibility. TODO: change the way the plugin gets its data
			foreach($values as $name=>$value) {
				$app->input->set($name, $value);
			}
		}
		//prepare order
		$order= $this->_order;
		$order = $this->populateOrder(false);
		// get the order totals
		$order->calculateTotals();

		//set shiping address
		if($user->id && $this->session->has('shipping_address_id', 'k2store')) {
			$shipping_address = $address_model->getAddress($this->session->get('shipping_address_id', '', 'k2store'));
		} elseif($this->session->has('guest', 'k2store')) {
			$guest = $this->session->get('guest', array(), 'k2store');
			if($guest['shipping']) {
				$shipping_address = $guest['shipping'];
			}

		}else{
			$shipping_address = array();
		}

		//validate shipping
		$showShipping = false;
		if ($isShippingEnabled = $cart_model->getShippingIsEnabled())
		{
			if (empty($shipping_address)) {
				$redirect = $redirect_url;
			}
			$showShipping = true;
			if($this->session->has('shipping_values', 'k2store')) {

				//set the shipping methods
				$shipping_values = $this->session->get('shipping_values', array(), 'k2store');
				$this->setShippingMethod($shipping_values);

			}

		}else {
			$this->session->clear('shipping_method', 'k2store');
			$this->session->clear('shipping_methods', 'k2store');
			$this->session->clear('shipping_values', 'k2store');
		}
		$view->assign( 'showShipping', $showShipping );

		//process payment plugins
		$showPayment = true;
		if ((float)$order->order_total == (float)'0.00')
		{
			$showPayment = false;
		}
		$view->assign( 'showPayment', $showPayment );

		// Validate if billing address has been set.

		if ($user->id && $this->session->has('billing_address_id', 'k2store')) {
			$billing_address = $address_model->getAddress($this->session->get('billing_address_id', '', 'k2store'));
		} elseif ($this->session->has('guest', 'k2store')) {
			$guest = $this->session->get('guest', array(), 'k2store');
			$billing_address = $guest['billing'];
		}

		if (empty($billing_address)) {
			$redirect = $redirect_url;
		}

		// Validate if payment method has been set.
		if ($showPayment == true && !$this->session->has('payment_method', 'k2store')) {
			$redirect = $redirect_url;

			If(!$this->validateSelectPayment($this->session->get('payment_method', '', 'k2store'), $values)) {
				$redirect = $redirect_url;
			}

		}

		// Validate cart has products and has stock.
		if (!$cart_helper->hasProducts()) {
			$redirect = $redirect_url;
		}
		//minimum order value check
		if(!$this->checkMinimumOrderValue($order)) {
			$error_msg[] = JText::_('K2STORE_ERROR_MINIMUM_ORDER_VALUE').K2StorePrices::number($this->params->get('global_minordervalue'));
			$redirect = $redirect_url;
		}

		if(!$redirect) {

			$order_id = time();
			$values['order_id'] = $order_id;

			//all is well so far. If this is a guest checkout, store the billing and shipping values of the guest in the address table.
			if ($this->session->has('guest', 'k2store')) {
				$guest = $this->session->get('guest', array(), 'k2store');
				if(isset($guest['billing']) && count($guest['billing'])) {
					$address_model->addAddress('billing', $guest['billing']);
				}

				if(isset($guest['shipping']) && count($guest['shipping'])) {
					$address_model->addAddress('shipping', $guest['shipping']);
				}

			}

			// Save the orderitems with  status
			if (!$this->saveOrderItems($values))
			{	// Output error message and halt
				$error_msg[] = $this->getError();
			}
			$orderpayment_type = $this->session->get('payment_method', '', 'k2store');

			//trigger onK2StoreBeforePayment event
			if ($showPayment == true && !empty($orderpayment_type)) {
				$results = $dispatcher->trigger( "onK2StoreBeforePayment", array($orderpayment_type, $order) );
			}
			//set a default transaction status.
			$transaction_status = JText::_( "K2STORE_TRANSACTION_INCOMPLETE" );

			// in the case of orders with a value of 0.00, use custom values
			if ( (float) $order->order_total == (float)'0.00' )
			{
				$orderpayment_type = 'free';
				$transaction_status = JText::_( "K2STORE_TRANSACTION_COMPLETE" );
			}

			//set order values
			$order->user_id = $user->id;
			$order->ip_address = $_SERVER['REMOTE_ADDR'];

			//generate a unique hash
			$order->token = JApplication::getHash($order_id);
			//user email
			$user_email = ($user->id)?$user->email:$billing_address['email'];
			$order->user_email = $user_email;

			//get the customer note
			$customer_note = $this->session->get('customer_note', '', 'k2store');
			$order->customer_note = $customer_note;
			$order->customer_language = $lang->getTag();

			// Save an order with an Incomplete status
			$order->order_id = $order_id;
			$order->orderpayment_type = $orderpayment_type; // this is the payment plugin selected
			$order->transaction_status = $transaction_status; // payment plugin updates this field onPostPayment
			$order->order_state_id = 5; // default incomplete order state
			$order->orderpayment_amount = $order->order_total; // this is the expected payment amount.  payment plugin should verify actual payment amount against expected payment amount

			//get currency id, value and code and store it
			$currency = K2StoreFactory::getCurrencyObject();
			$order->currency_id = $currency->getId();
			$order->currency_code = $currency->getCode();
			$order->currency_value = $currency->getValue($currency->getCode());

			if ($order->save())
			{
				//set values for orderinfo table

				// send the order_id and orderpayment_id to the payment plugin so it knows which DB record to update upon successful payment
				$values["order_id"]             = $order->order_id;
				//$values["orderinfo"]            = $order->orderinfo;
				$values["orderpayment_id"]      = $order->id;
				$values["orderpayment_amount"]  = $order->orderpayment_amount;

				if($billing_address) {

					//dump all billing fields as json as it may contain custom field values as well

					if ($this->session->has('uaccount', 'k2store')) {
						$uset_account_type = $this->session->get('uaccount', 'billing', 'k2store');
					}
					if($uset_account_type == 'register' ) {
						$type= 'register';
					}elseif($uset_account_type == 'guest' ) {
						$type= 'guest';
					}elseif($uset_account_type == 'login' ) {
						$type= 'billing';
					}else {
						$type= 'billing';
					}
					$values['orderinfo']['all_billing']= $db->escape($this->processCustomFields($type, $billing_address));

					foreach ($billing_address as $key=>$value) {
						$values['orderinfo']['billing_'.$key] = $value;
						//legacy compatability for payment plugins
						$values['orderinfo'][$key] = $value;
					}
					$values['orderinfo']['country'] = $billing_address['country_name'];
					$values['orderinfo']['state'] = $billing_address['zone_name'];
				}

				if(isset($shipping_address) && is_array($shipping_address)) {
					//dump all shipping fields as json as it may contain custom field values as well
					if($uset_account_type == 'guest' ) {
						$type= 'guest_shipping';
					}else {
						$type= 'shipping';
					}

					$values['orderinfo']['all_shipping']= $db->escape($this->processCustomFields($type, $shipping_address));

					foreach ($shipping_address as $key=>$value) {
						$values['orderinfo']['shipping_'.$key] = $value;
					}
				}

				//now dump all payment_values as well. Because we may have custom fields there to
				if($this->session->has('payment_values', 'k2store')) {
					$pay_values = $this->session->get('payment_values', array(), 'k2store');
					$values['orderinfo']['all_payment']= $db->escape($this->processCustomFields('payment', $pay_values));
				}



				$values['orderinfo']['user_email'] = $user_email;
				$values['orderinfo']['user_id'] = $user->id;
				$values['orderinfo']['order_id'] = $order->order_id;
				$values['orderinfo']['orderpayment_id'] = $order->id;

				try {

					$this->saveOrderInfo($values['orderinfo']);
				} catch (Exception $e) {
					$redirect = $redirect_url;
					echo $e->getMessage()."\n";
				}

				//save shipping info
				if ( isset( $order->shipping ) && !$this->saveOrderShippings( $shipping_values ))
				{
					// TODO What to do if saving order shippings fails?
					$error = true;
				}

			} else {
				// Output error message and halt
				JError::raiseNotice( 'K2STORE_ERROR_SAVING_ORDER', $order->getError() );
				$redirect = $redirect_url;
			}

			// IMPORTANT: Store the order_id in the user's session for the postPayment "View Invoice" link

			$app->setUserState( 'k2store.order_id', $order->order_id );
			$app->setUserState( 'k2store.orderpayment_id', $order->id );
			$app->setUserState( 'k2store.order_token', $order->token);
			// in the case of orders with a value of 0.00, we redirect to the confirmPayment page
			if ( (float) $order->order_total == (float)'0.00' )
			{
				$free_redirect = JRoute::_( 'index.php?option=com_k2store&view=checkout&task=confirmPayment' );
				$view->assign('free_redirect', $free_redirect);
			}

			$payment_plugin = $this->session->get('payment_method', '', 'k2store');
			$values['payment_plugin'] =$payment_plugin;
			$results = $dispatcher->trigger( "onK2StorePrePayment", array( $payment_plugin, $values ) );

			// Display whatever comes back from Payment Plugin for the onPrePayment
			$html = "";
			for ($i=0; $i<count($results); $i++)
			{
			$html .= $results[$i];
			}
			//check if plugins set a redirect
			if($this->session->has('plugin_redirect', 'k2store') ) {
				$redirect = $this->session->get('plugin_redirect', '', 'k2store');
			}

			$view->assign('plugin_html', $html);

			$summary = $this->getOrderSummary();
			$view->assign('orderSummary', $summary);

		}
			// Set display
			$view->setLayout('checkout_confirm');
			$view->set( '_doTask', true);
			$view->assign('order', $order);
			$view->assign('redirect', $redirect);
			$view->setModel( $model, true );
			ob_start();
			$view->display();
			$html = ob_get_contents();
			ob_end_clean();
			echo $html;
			$app->close();
	}

	public function processCustomFields($type, $data) {
		$selectableBase = new K2StoreSelectableBase();
		$address = JTable::getInstance('address', 'Table');
		$orderinfo = JTable::getInstance('Orderinfo', 'Table');
		$fields = $selectableBase->getFields($type,$address,'address');
		$values = array();
		foreach ($fields as $fieldName => $oneExtraField) {
			if($data[$fieldName]) {
				if(!property_exists($orderinfo, $type.'_'.$fieldName) && !property_exists($orderinfo, 'user_'.$fieldName ) && $fieldName !='country_id' && $fieldName != 'zone_id' && $fieldName != 'option' && $fieldName !='task' && $fieldName != 'view' ) {
					$values[$fieldName]['label'] =$oneExtraField->field_name;
					$values[$fieldName]['value'] = $data[$fieldName];
				}
			}
		}
		$registry = new JRegistry();
		$registry->loadArray($values);
		$json = $registry->toString('JSON');
		return $json;

	}


	public function ajaxGetZoneList() {

		$app = JFactory::getApplication();
		$model = $this->getModel('checkout');
		$post = JRequest::get('post');
		$country_id = $post['country_id'];
		$zone_id = $post['zone_id'];
		$name=$post['field_name'];;
		$id=$post['field_id'];
		if($country_id) {
			$zones = $model->getZoneList($name,$id,$country_id,$zone_id);
			echo $zones;
		}
		$app->close();
	}

	function getOrderSummary()
	{
		// get the order object
		$order= $this->_order;
		$model = $this->getModel('mycart');
		$view = $this->getView( 'checkout', 'html' );
		$view->set( '_controller', 'checkout' );
		$view->set( '_view', 'checkout' );
		$view->set( '_doTask', true);
		$view->set( 'hidemenu', true);
		$view->setModel( $model, true );
		$view->assign( 'state', $model->getState() );

		$show_tax = $this->params->get('show_tax_total');
		$view->assign( 'show_tax', $this->params->get('show_tax_total'));
		$view->assign( 'params', $this->params);
		$view->assign( 'order', $order );

		$orderitems = $order->getItems();
		foreach ($orderitems as &$item)
        {
      		$item->orderitem_price = $item->orderitem_price + floatval( $item->orderitem_attributes_price );
        	$taxtotal = 0;
            if($show_tax)
            {
            	$taxtotal = ($item->orderitem_tax / $item->orderitem_quantity);
            }
            $item->orderitem_price = $item->orderitem_price + $taxtotal;
            $item->orderitem_final_price = $item->orderitem_price * $item->orderitem_quantity;
            $order->order_subtotal += ($taxtotal * $item->orderitem_quantity);
        }


		// Checking whether shipping is required
		$showShipping = false;

		if ($isShippingEnabled = $model->getShippingIsEnabled())
		{
			$showShipping = true;
			$view->assign( 'shipping_total', $order->getShippingTotal() );
		}
		$view->assign( 'showShipping', $showShipping );

		$view->assign( 'orderitems', $orderitems );
		$view->setLayout( 'cartsummary' );

		ob_start();
		$view->display();
		$html = ob_get_contents();
		ob_end_clean();

		return $html;
	}

	function populateOrder($guest = false)
	{
		$order= $this->_order;
		//$order->shipping_method_id = $this->defaultShippingMethod;
		$items = K2StoreHelperCart::getProducts();
		foreach ($items as $item)
		{
			$order->addItem( $item );
		}
		// get the order totals
		$order->calculateTotals();

		return $order;
	}


	function checkMinimumOrderValue($order) {



		$min_value = $this->params->get('global_minordervalue');
		if(!empty($min_value)) {
			if($order->order_subtotal >= $min_value) {
			 return true;
			} else {
			 return false;
			}
		} else {
			return true;
		}
	}


	/**
	 * Sets the selected shipping method
	 *
	 * @return unknown_type
	 */
	function setShippingMethod($values)
	{

		$app = JFactory::getApplication();
		// get the order object so we can populate it
		$order = $this->_order; // a TableOrders object (see constructor)

		// set the shipping method
		$order->shipping = new JObject();
		$order->shipping->shipping_price      = $values['shipping_price'];
		$order->shipping->shipping_extra      = $values['shipping_extra'];
		$order->shipping->shipping_code      = $values['shipping_code'];
		$order->shipping->shipping_name       = $values['shipping_name'];
		$order->shipping->shipping_tax        = $values['shipping_tax'];
		$order->shipping->shipping_type		  = $values['shipping_plugin'];

		// get the order totals
		$order->calculateTotals();

		return;
	}




	function getShippingHtml( $layout='shipping_yes' )
	{
		$order= $this->_order;

		$html = '';
		$model = $this->getModel( 'Checkout', 'K2StoreModel' );
		$view   = $this->getView( 'checkout', 'html' );
		$view->set( '_controller', 'checkout' );
		$view->set( '_view', 'checkout' );
		$view->set( '_doTask', true);
		$view->set( 'hidemenu', true);
		$view->setModel( $model, true );
		$view->setLayout( $layout );
		$rates = array();

	 switch (strtolower($layout))
        {
            case "shipping_calculate":
                break;
            case "shipping_no":
                break;
            case "shipping_yes":
            default:
                $rates = $this->getShippingRates();
                $default_rate = array();

                if (count($rates) == 1)
                {
                    $default_rate = $rates[0];
                }
                $view->assign( 'rates', $rates );
                $view->assign( 'default_rate', $default_rate );
                break;
        }

		ob_start();
		$view->display();
		$html = ob_get_contents();
		ob_end_clean();
		return $html;
	}

	/**
	 * Gets the applicable rates
	 *
	 * @return array
	 */
	public function getShippingRates()
	{
		static $rates;

		if (empty($rates) || !is_array($rates))
		{
			$rates = array();
		}

		if (!empty($rates))
		{
			return $rates;
		}
		require_once (JPATH_SITE.'/components/com_k2store/helpers/plugin.php');
		JModelLegacy::addIncludePath(JPATH_ADMINISTRATOR.'/components/com_k2store/models');
		$model = JModelLegacy::getInstance('Shipping', 'K2StoreModel');
		$model->setState('filter_enabled', '1');
		$plugins = $model->getList();

		$dispatcher = JDispatcher::getInstance();

		$rates = array();

		// add taxes, even thought they aren't displayed
		$order_tax = 0;
		$orderitems = $this->_order->getItems();
		foreach( $orderitems as $item )
		{
			$this->_order->order_subtotal += $item->orderitem_tax;
			$order_tax += $item->orderitem_tax;
		}

		if ($plugins)
		{
			foreach ($plugins as $plugin)
			{

				$shippingOptions = $dispatcher->trigger( "onK2StoreGetShippingOptions", array( $plugin->element, $this->_order ) );

				if (in_array(true, $shippingOptions, true))
				{
					$results = $dispatcher->trigger( "onK2StoreGetShippingRates", array( $plugin->element, $this->_order ) );

					foreach ($results as $result)
					{
						if(is_array($result))
						{
							foreach( $result as $r )
							{
								$extra = 0;
								// here is where a global handling rate would be added
							//	if ($global_handling = $this->defines->get( 'global_handling' ))
							//	{
							//		$extra = $global_handling;
							//	}
								$r['extra'] += $extra;
								$r['total'] += $extra;
								$rates[] = $r;
							}
						}
					}
				}
			}
		}

		$this->_order->order_subtotal -= $order_tax;

		return $rates;
	}

	function getPaymentForm($element='', $plain_format=false)
	{
		$app = JFactory::getApplication();
		$values = JRequest::get('post');
		$html = '';
		$text = "";
		$user = JFactory::getUser();
		if (empty($element)) {
			$element = JRequest::getVar( 'payment_element' );
		}
		$results = array();
		$dispatcher    = JDispatcher::getInstance();
		JPluginHelper::importPlugin ('k2store');

		$results = $dispatcher->trigger( "onK2StoreGetPaymentForm", array( $element, $values ) );
		for ($i=0; $i<count($results); $i++)
		{
			$result = $results[$i];
			$text .= $result;
		}

		$html = $text;
		if($plain_format) {
			return $html;
		} else {
		// set response array
		$response = array();
		$response['msg'] = $html;

		// encode and echo (need to echo to send back to browser)
		echo json_encode($response);
		$app->close();
		}

	}

	/**
	 * Saves each individual item in the order to the DB
	 *
	 * @return unknown_type
	 */
	function saveOrderItems($values)
	{
		$order= $this->_order;
		$order_id = $values['order_id'];
		//review things once again
		$cart_helper = new K2StoreHelperCart();
		$cart_model = $this->getModel('mycart');
//		$reviewitems = $cart_helper->getProductsInfo();

	//	foreach ($reviewitems as $reviewitem)
	//	{
	//		$order->addItem( $reviewitem );
	//	}

		$order->order_state_id = $this->initial_order_state;
		$order->calculateTotals();


		$items = $order->getItems();

		if (empty($items) || !is_array($items))
		{
			$this->setError( "saveOrderItems:: ".JText::_( "K2STORE_ORDER_SAVE_INVALID_ITEMS" ) );
			return false;
		}

		$error = false;
		$errorMsg = "";
		foreach ($items as $item)
		{
			$item->order_id = $order_id;

			if (!$item->save())
			{
				// track error
				$error = true;
				$errorMsg .= $item->getError();
			}
			else
			{
				// Save the attributes also
				if (!empty($item->orderitem_attributes))
				{
					//$attributes = explode(',', $item->orderitem_attributes);
					//first we got to convert the JSON-structured attribute options into an object
					$registry = new JRegistry;
					$registry->loadString($item->orderitem_attributes, 'JSON');
					$product_options = $registry->toObject();

					foreach ($product_options as $attribute)
					{
						unset($productattribute);
						unset($orderitemattribute);
						//we first have to load the product options table to get the data. Just for a cross check
						//TODO do we need this? the mycart model already has the data and we mapped it to orderitem_attributes in JSON format.
						$productattribute = $cart_model->getCartProductOptions($attribute->product_option_id, $item->product_id);
						$orderitemattribute = JTable::getInstance('OrderItemAttributes', 'Table');
						$orderitemattribute->orderitem_id = $item->orderitem_id;
						//this is the product option id
						$orderitemattribute->productattributeoption_id = $productattribute->product_option_id;
						$orderitemattribute->productattributeoptionvalue_id = $attribute->product_optionvalue_id;
						//product option name. Dont confuse this with the option value name
						$orderitemattribute->orderitemattribute_name = $productattribute->option_name;
						$orderitemattribute->orderitemattribute_value = $attribute->option_value;
						//option price
						$orderitemattribute->orderitemattribute_price = $attribute->price;
						//$orderitemattribute->orderitemattribute_code = $productattribute->productattributeoption_code;
						$orderitemattribute->orderitemattribute_prefix = $attribute->price_prefix;
						$orderitemattribute->orderitemattribute_type = $attribute->type;
						if (!$orderitemattribute->save())
						{
							// track error
							$error = true;
							$errorMsg .= $orderitemattribute->getError();
						}

					}
				}
			}
		}

		if ($error)
		{

			$this->setError( $errorMsg );
			return false;
		}
		return true;
	}


	function saveOrderInfo($values){

		$row = JTable::getInstance('orderinfo','Table');

		if (!$row->bind($values)) {
			throw new Exception($row->getError());
			return false;
		}

		if (!$row->check()) {
			throw new Exception($row->getError());
			return false;
		}

		if (!$row->store()) {
			throw new Exception($row->getError());
			return false;
		}

		return true;
	}

	function saveOrderShippings( $values )
	{
		$order = $this->_order;

		$shipping_type = isset($values['shipping_plugin']) ? $values['shipping_plugin'] : '';
		if(!empty($shipping_type)) {
			$row = JTable::getInstance('OrderShippings', 'Table');
			$row->order_id = $order->id;
			$row->ordershipping_type = $values['shipping_plugin'];
			$row->ordershipping_price = $values['shipping_price'];
			$row->ordershipping_name = $values['shipping_name'];
			$row->ordershipping_code = $values['shipping_code'];
			$row->ordershipping_tax = $values['shipping_tax'];
			$row->ordershipping_extra = $values['shipping_extra'];

			if (!$row->save($row))
			{
				$this->setError( $row->getError() );
				return false;
			}

			// Let the plugin store the information about the shipping
			if (isset($values['shipping_plugin']))
			{
				$dispatcher = JDispatcher::getInstance();
				$dispatcher->trigger( "onK2StorePostSaveShipping", array( $values['shipping_plugin'], $row ) );
			}
		}

		return true;
	}


	function validateSelectPayment($payment_plugin, $values) {

		$response = array();
		$response['msg'] = '';
		$response['error'] = '';

		$dispatcher    = JDispatcher::getInstance();
		JPluginHelper::importPlugin ('k2store');

		//verify the form data
		$results = array();
		$results = $dispatcher->trigger( "onK2StoreGetPaymentFormVerify", array( $payment_plugin, $values) );

		for ($i=0; $i<count($results); $i++)
		{
			$result = $results[$i];
			if (!empty($result->error))
			{
				$response['msg'] =  $result->message;
				$response['error'] = '1';
			}

		}
		if($response['error']) {
			throw new Exception($response['msg']);
			return false;
		} else {
			return true;
		}
		return false;
	}


	function validateSelectShipping($values) {

		$error = 0;

		if (isset($values['shippingrequired']))
		{
			if ($values['shippingrequired'] == 1 && empty($values['shipping_plugin']))
			{
				throw new Exception(JText::_('K2STORE_CHECKOUT_SELECT_A_SHIPPING_METHOD'));
				return false;
			}
		}

		//if order value is zero, then return true
		$order = $this->_order;

		// get the items and add them to the order
		$items = K2StoreHelperCart::getProducts();
		foreach ($items as $item)
		{
			$order->addItem( $item );
		}
		$order->calculateTotals();
		if ( (float) $order->order_total == (float) '0.00' )
		{
			return true;
		}

		//trigger the plugin's validation function
		// no matter what, fire this validation plugin event for plugins that extend the checkout workflow
		$results = array();
		$dispatcher = JDispatcher::getInstance();
		$results = $dispatcher->trigger( "onValidateSelectShipping", array( $values ) );

		for ($i=0; $i<count($results); $i++)
		{
		$result = $results[$i];
		if (!empty($result->error))
		{
		throw new Exception($result->message);
		return false;
		}

		}

		if($error == '1')
		{
		return false;
	}

	return true;
}



	/**
	 * This method occurs after payment is attempted,
	 * and fires the onPostPayment plugin event
	 *
	 * @return unknown_type
	 */
	function confirmPayment()
	{
		$app =JFactory::getApplication();
		$orderpayment_type = $app->input->getString('orderpayment_type');

		// Get post values
		$values = $app->input->getArray($_POST);
		//backward compatibility for payment plugins
		foreach($values as $name=>$value) {
			$app->input->set($name, $value);
		}

		//set the guest mail to null if it is present
		//check if it was a guest checkout
		$account = $this->session->get('account', 'register', 'k2store');

		// get the order_id from the session set by the prePayment
		$orderpayment_id = (int) $app->getUserState( 'k2store.orderpayment_id' );
		if($account != 'guest') {
			$order_link = 'index.php?option=com_k2store&view=orders&task=view&id='.$orderpayment_id;
		} else {
			$guest_token  = $app->getUserState( 'k2store.order_token' );
			$order_link = 'index.php?option=com_k2store&view=orders&task=view';

			//assign to another session variable, for security reasons
			if($this->session->has('guest', 'k2store')) {
				$guest = $this->session->get('guest', array(), 'k2store');
				$this->session->set('guest_order_email', $guest['billing']['email']);
				$this->session->set('guest_order_token', $guest_token);
			}
		}

		$dispatcher = JDispatcher::getInstance();
		JPluginHelper::importPlugin ('k2store');

		$html = "";
		$order= $this->_order;
		$order->load( array('id'=>$orderpayment_id));

		// free product? set the state to confirmed and save the order.
		if ( (!empty($orderpayment_id)) && (float) $order->order_total == (float)'0.00' )
		{
			$order->order_state = trim(JText::_('CONFIRMED'));
			$order->order_state_id = '1'; // PAYMENT RECEIVED.
			if($order->save()) {
				// remove items from cart
				K2StoreHelperCart::removeOrderItems( $order->id );
			}
			//send email
			require_once (JPATH_SITE.'/components/com_k2store/helpers/orders.php');
			K2StoreOrdersHelper::sendUserEmail($order->user_id, $order->order_id, $order->transaction_status, $order->order_state, $order->order_state_id);

		}
		else
		{
			// get the payment results from the payment plugin
			$results = $dispatcher->trigger( "onK2StorePostPayment", array( $orderpayment_type, $values ) );

			// Display whatever comes back from Payment Plugin for the onPrePayment
			for ($i=0; $i<count($results); $i++)
			{
				$html .= $results[$i];
			}

			// re-load the order in case the payment plugin updated it
			$order->load( array('id'=>$orderpayment_id) );
		}

		// $order_id would be empty on posts back from Paypal, for example
		if (isset($orderpayment_id))
		{

			//unset a few things from the session.
			$this->session->clear('shipping_method', 'k2store');
			$this->session->clear('shipping_methods', 'k2store');
			$this->session->clear('shipping_values', 'k2store');
			$this->session->clear('payment_method', 'k2store');
			$this->session->clear('payment_methods', 'k2store');
			$this->session->clear('payment_values', 'k2store');
			$this->session->clear('guest', 'k2store');
			$this->session->clear('customer_note', 'k2store');

			//save the coupon to the order_coupons table for tracking and unset session.
			if($this->session->has('coupon', 'k2store')) {
					$coupon_info = K2StoreHelperCart::getCoupon($this->session->get('coupon', '', 'k2store'));
					if($coupon_info) {
						$order_coupons = JTable::getInstance('OrderCoupons', 'Table');
						$order_coupons->set('coupon_id', $coupon_info->coupon_id);
						$order_coupons->set('orderpayment_id', $orderpayment_id);
						$order_coupons->set('customer_id', JFactory::getUser()->id);
						$order_coupons->set('amount', $order->order_discount);
						$order_coupons->set('created_date', JFactory::getDate()->toSql());
						$order_coupons->store();
					}
			}

			//clear the session
			$this->session->clear('coupon', 'k2store');


			// Set display
			$view = $this->getView( 'checkout', 'html' );
			$view->setLayout('postpayment');
			$view->set( '_doTask', true);
			$view->assign('order_link', JRoute::_($order_link) );
			$view->assign('plugin_html', $html);

			// Get and Set Model
			$model = $this->getModel('checkout');
			$view->setModel( $model, true );

			$view->display();
		}
		return;
	}

	public function getCountry() {
		$app = JFactory::getApplication();
		$model = $this->getModel('checkout');
		$country_id =$app->input->get('country_id');
		$json = array();
		$country_info = $model->getCountryById($country_id);
		if ($country_info) {
		$zones = $this->getModel('checkout')->getZonesByCountryId($app->input->get('country_id'));

			$json = array(
					'country_id'        => $country_info->country_id,
					'name'              => $country_info->country_name,
					'iso_code_2'        => $country_info->country_isocode_2,
					'iso_code_3'        => $country_info->country_isocode_3,
					'zone'              => $zones
			);
		}

		echo json_encode($json);
		$app->close();
	}

	public function getTerms() {

		$app = JFactory::getApplication();
		$id = $app->input->getInt('k2item_id');
		require_once (JPATH_COMPONENT_ADMINISTRATOR.'/library/k2item.php' );
		$k2item = new K2StoreItem();
		$data = $k2item->display($id);
		$view = $this->getView( 'checkout', 'html' );
		$view->set( '_controller', 'checkout' );
		$view->set( '_view', 'checkout' );
		$view->set( '_doTask', true);
		$view->set( 'hidemenu', true);
		$view->assign( 'html', $data);
		$view->setLayout( 'checkout_terms' );
		ob_start();
		$view->display();
		$html = ob_get_contents();
		ob_end_clean();
		echo $html;
		$app->close();
	}

}