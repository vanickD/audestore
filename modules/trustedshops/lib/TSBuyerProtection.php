<?php
/*
* 2007-2013 PrestaShop
*
* NOTICE OF LICENSE
*
* This source file is subject to the Academic Free License (AFL 3.0)
* that is bundled with this package in the file LICENSE.txt.
* It is also available through the world-wide-web at this URL:
* http://opensource.org/licenses/afl-3.0.php
* If you did not receive a copy of the license and are unable to
* obtain it through the world-wide-web, please send an email
* to license@prestashop.com so we can send you a copy immediately.
*
* DISCLAIMER
*
* Do not edit or add to this file if you wish to upgrade PrestaShop to newer
* versions in the future. If you wish to customize PrestaShop for your
* needs please refer to http://www.prestashop.com for more information.
*
*  @author PrestaShop SA <contact@prestashop.com>
*  @copyright  2007-2013 PrestaShop SA
*  @license	http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
*  International Registered Trademark & Property of PrestaShop SA
*/
include(_PS_MODULE_DIR_.'trustedshops/lib/TSBPException.php');

/**
 * @see the technical doc for entire description.
 * 		too long to set it here.
 * @author Prestashop - Nans Pellicari
 * @since prestashop 1.4
 * @version 0.1
 */
class TSBuyerProtection extends AbsTrustedShops
{
	const PREFIX_TABLE = 'TS_TAB1_';
	const ENV_MOD = 'production';// 'test' or 'production'
	const DB_ITEMS = 'ts_buyerprotection_items';
	const DB_APPLI = 'ts_application_id';
	const WEBSERVICE_BO = 'administration';
	const WEBSERVICE_FO = 'front-end';

	/**
	 * List of registration link, need to add parameters
	 * @see TSBuyerProtection::_getRegistrationLink()
	 * @var array
	 */
	private $registration_link = array(
		'DE'	=> 'http://www.trustedshops.de/shopbetreiber/mitgliedschaft.html',
		'EN'	=> 'http://www.trustedshops.com/merchants/membership.html',
		'FR'	=> 'http://www.trustedshops.com/marchands/affiliation.html',
		'PL'	=> 'http://www.trustedshops.pl/handlowcy/cennik.html',
		'ES'	=> ''
	);

	/**
	 * Link to obtain the certificate about the shop.
	 * Use by seal of approval.
	 * @see TSBuyerProtection::hookRightColumn()
	 * @var array
	 */
	private static $certificate_link = array(
		'DE'	=> 'http://www.trustedshops.de/profil/#shop_name#_#shop_id#.html',
		'EN'	=> 'http://www.trustedshops.com/profile/#shop_name#_#shop_id#.html',
		'FR'	=> 'http://www.trustedshops.fr/boutique_en_ligne/profil/#shop_name#_#shop_id#.html',
		'PL'	=> 'http://www.trustedshops.de/profil/#shop_name#_#shop_id#.html',
		'ES'	=> 'http://www.trustedshops.es/perfil/#shop_name#_#shop_id#.html'
	);

	/**
	 * Available language for used TrustedShops Buyer Protection
	 * @see TSBuyerProtection::__construct()
	 * @var array
	 */
	private $available_languages = array('EN' => '', 'FR' => '', 'DE' =>'', 'PL'=>'', 'ES' => '');

	/**
	 * @todo : be sure : see TrustedShopsRating::__construct()
	 * @var array
	 */
	public $limited_countries = array('PL', 'GB', 'US', 'FR', 'DE', 'ES');

	/**
	 * Differents urls to call for Trusted Shops API
	 * @var array
	 */
	private static $webservice_urls = array(
		'administration'	=> array(
			'test'				=> 'https://qa.trustedshops.de/ts/services/TsProtection?wsdl',
			'production'		=> 'https://www.trustedshops.de/ts/services/TsProtection?wsdl',
		),
		'front-end'			=> array(
			'test'				=> 'https://protection-qa.trustedshops.com/ts/protectionservices/ApplicationRequestService?wsdl',
			'production'		=> 'https://protection.trustedshops.com/ts/protectionservices/ApplicationRequestService?wsdl',
		),
	);

	// Configuration vars
	private static $SHOPSW;
	private static $ET_CID;
	private static $ET_LID;

	/**
	 * Its must look like :
	 * array(
	 * 		'lang_iso(ex: FR)' => array('stateEnum'=>'', 'typeEnum'=>'', 'url'=>'', 'tsID'=>'', 'user'=>'', 'password'=>''),
	 * 		...
	 * )
	 * @var array
	 */
	public static $CERTIFICATES;
	private static $DEFAULT_LANG;
	private static $CAT_ID;
	private static $ENV_API;

	/**
	 * save shop url
	 * @var string
	 */
	private $site_url;

	/**
	 * Payment type used by Trusted Shops.
	 * @var array
	 */
	private static $payments_type;

	public function __construct()
	{
		// need to set this in constructor to allow translation
		TSBuyerProtection::$payments_type = array(
			'DIRECT_DEBIT'		=> $this->l('Direct debit'),
			'CREDIT_CARD'		=> $this->l('Credit Card'),
			'INVOICE'			=> $this->l('Invoice'),
			'CASH_ON_DELIVERY'	=> $this->l('Cash on delivery'),
			'PREPAYMENT'		=> $this->l('Prepayment'),
			'CHEQUE'			=> $this->l('Cheque'),
			'PAYBOX'			=> $this->l('Paybox'),
			'PAYPAL'			=> $this->l('PayPal'),
			'CASH_ON_PICKUP'	=> $this->l('Cash on pickup'),
			'FINANCING'			=> $this->l('Financing'),
			'LEASING'			=> $this->l('Leasing'),
			'T_PAY'				=> $this->l('T-Pay'),
			'CLICKANDBUY'		=> $this->l('Click&Buy'),
			'GIROPAY'			=> $this->l('Giropay'),
			'GOOGLE_CHECKOUT'	=> $this->l('Google Checkout'),
			'SHOP_CARD'			=> $this->l('Online shop payment card'),
			'DIRECT_E_BANKING'	=> $this->l('DIRECTebanking.com'),
			'MONEYBOOKERS'		=> $this->l('moneybookers.com'),
			'OTHER'				=> $this->l('Other method of payment'),
		);

		$this->tab_name = $this->l('Trusted Shops quality seal and buyer protection');
		$this->site_url = Tools::htmlentitiesutf8('http://'.$_SERVER['HTTP_HOST'].__PS_BASE_URI__);

		TSBPException::setTranslationObject($this);

		if (!method_exists('Tools', 'jsonDecode') || !method_exists('Tools', 'jsonEncode'))
			$this->warnings[] = $this->l('Json functions must be implemented in your php version');
		else
		{
			foreach ($this->available_languages as $iso => $lang)
			{
				if ($lang === '')
					$this->available_languages[$iso] = Language::getLanguage(Language::getIdByIso($iso));

				$certificate = Configuration::get(TSBuyerProtection::PREFIX_TABLE.'CERTIFICATE_'.strtoupper($iso));
				TSBuyerProtection::$CERTIFICATES[strtoupper($iso)] = (array)Tools::jsonDecode(Tools::htmlentitiesDecodeUTF8($certificate));
			}

			if (TSBuyerProtection::$SHOPSW === NULL)
			{
				TSBuyerProtection::$SHOPSW = Configuration::get(TSBuyerProtection::PREFIX_TABLE.'SHOPSW');
				TSBuyerProtection::$ET_CID = Configuration::get(TSBuyerProtection::PREFIX_TABLE.'ET_CID');
				TSBuyerProtection::$ET_LID = Configuration::get(TSBuyerProtection::PREFIX_TABLE.'ET_LID');
				TSBuyerProtection::$DEFAULT_LANG = (int)Configuration::get('PS_LANG_DEFAULT');
				TSBuyerProtection::$CAT_ID = (int)Configuration::get(TSBuyerProtection::PREFIX_TABLE.'CAT_ID');
				TSBuyerProtection::$ENV_API = Configuration::get(TSBuyerProtection::PREFIX_TABLE.'ENV_API');
			}
		}
	}

	public function install()
	{
		if (!method_exists('Tools', 'jsonDecode') || !method_exists('Tools', 'jsonEncode'))
			return false;

		foreach ($this->available_languages as $iso => $lang)
			Configuration::updateValue(TSBuyerProtection::PREFIX_TABLE.'CERTIFICATE_'.strtoupper($iso),
				Tools::htmlentitiesUTF8(Tools::jsonEncode(array('stateEnum'=>'', 'typeEnum'=>'', 'url'=>'', 'tsID'=>'', 'user'=>'', 'password'=>''))));

		Configuration::updateValue(TSBuyerProtection::PREFIX_TABLE.'SHOPSW', '');
		Configuration::updateValue(TSBuyerProtection::PREFIX_TABLE.'ET_CID', '');
		Configuration::updateValue(TSBuyerProtection::PREFIX_TABLE.'ET_LID', '');
		Configuration::updateValue(TSBuyerProtection::PREFIX_TABLE.'ENV_API', TSBuyerProtection::ENV_MOD);

		$query = 'CREATE TABLE IF NOT EXISTS `'._DB_PREFIX_.TSBuyerProtection::DB_ITEMS.'` ('.
			'`id_item` INT NOT NULL AUTO_INCREMENT PRIMARY KEY,'.
			'`id_product` INT NOT NULL,'.
			'`ts_id` VARCHAR( 33 ) NOT NULL,'.
			'`id` INT NOT NULL,'.
			'`currency` VARCHAR( 3 ) NOT NULL,'.
			'`gross_fee` DECIMAL( 20, 6 ) NOT NULL,'.
			'`net_fee` DECIMAL( 20, 6 ) NOT NULL,'.
			'`protected_amount_decimal` INT NOT NULL,'.
			'`protection_duration_int` INT NOT NULL,'.
			'`ts_product_id` TEXT NOT NULL,'.
			'`creation_date` VARCHAR( 25 ) NOT NULL);';

		Db::getInstance()->Execute($query);

		$query = 'CREATE TABLE IF NOT EXISTS `'._DB_PREFIX_.TSBuyerProtection::DB_APPLI.'` ('.
			'`id_application` INT NOT NULL PRIMARY KEY,'.
			'`ts_id` VARCHAR( 33 ) NOT NULL,'.
			'`id_order` INT NOT NULL,'.
			'`statut_number` INT NOT NULL DEFAULT \'0\','.
			'`creation_date` DATETIME NOT NULL,'.
			'`last_update` DATETIME NOT NULL);';

		Db::getInstance()->Execute($query);

		//add hidden category
		$category = new Category();

		foreach ($this->available_languages as $iso => $lang)
		{
			$language = Language::getIdByIso(strtolower($iso));

			$category->name[$language] = 'Trustedshops';
			$category->link_rewrite[$language] = 'trustedshops';
		}

		// If the default lang is different than available languages :
		// (Bug occurred otherwise)
		if (!array_key_exists(Language::getIsoById((int)Configuration::get('PS_LANG_DEFAULT')), $this->available_languages))
		{
			$language = (int)Configuration::get('PS_LANG_DEFAULT');

			$category->name[$language] = 'Trustedshops';
			$category->link_rewrite[$language] = 'trustedshops';
		}

		$category->id_parent = 0;
		$category->level_depth = 0;
		$category->active = 0;
		$category->add();

		Configuration::updateValue(TSBuyerProtection::PREFIX_TABLE.'CAT_ID', intval($category->id));
		Configuration::updateValue(TSBuyerProtection::PREFIX_TABLE.'SECURE_KEY', strtoupper(Tools::passwdGen(16)));

		return true;
	}

	public function uninstall()
	{
		foreach ($this->available_languages as $iso => $lang)
			Configuration::deleteByName(TSBuyerProtection::PREFIX_TABLE.'CERTIFICATE_'.strtoupper($iso));

		$category = new Category((int)TSBuyerProtection::$CAT_ID);
		$category->delete();

		Configuration::deleteByName(TSBuyerProtection::PREFIX_TABLE.'CAT_ID');
		Configuration::deleteByName(TSBuyerProtection::PREFIX_TABLE.'SHOPSW');
		Configuration::deleteByName(TSBuyerProtection::PREFIX_TABLE.'ET_CID');
		Configuration::deleteByName(TSBuyerProtection::PREFIX_TABLE.'ET_LID');
		Configuration::deleteByName(TSBuyerProtection::PREFIX_TABLE.'ENV_API');
		Configuration::deleteByName(TSBuyerProtection::PREFIX_TABLE.'SECURE_KEY');

		return true;
	}

	/**
	 * Just for return the file path
	 * @return string
	 */
	public function getCronFilePath()
	{
		return $this->site_url.'modules/'.self::$module_name.'/cron_garantee.php?secure_key='.Configuration::get(TSBuyerProtection::PREFIX_TABLE.'SECURE_KEY');
	}

	/**
	 * This method is used to access of TrustedShops API
	 * from a SoapClient object.
	 *
	 * @uses TSBuyerProtection::$webservice_urls with TSBuyerProtection::$ENV_API
	 * 		 To get the api url according to the environment (test or production)
	 * @param string $type
	 * @return SoapClient
	 */
	private function _getClient($type = TSBuyerProtection::WEBSERVICE_BO)
	{
		$url = TSBuyerProtection::$webservice_urls[$type][TSBuyerProtection::$ENV_API];
		$client = false;

		try
		{
			$client = new SoapClient($url);
		}
		catch(SoapFault $fault)
		{
			$this->errors[] = $this->l('Code #').$fault->faultcode.',<br />'.$this->l('message:').$fault->faultstring;
		}

		return $client;
	}

	/**
	 * Checks the Trusted Shops IDs entered in the shop administration
	 * and returns the characteristics of the corresponding certificate.
	 *
	 * @uses TSBuyerProtection::_getClient()
	 * @param string $certificate certificate code already send by Trusted Shops
	 */
	private function _checkCertificate($certificate)
	{
		$array_state = array(
			'PRODUCTION'	=> $this->l('The certificate is valid'),
			'CANCELLED'		=> $this->l('The certificate has expired'),
			'DISABLED'		=> $this->l('The certificate has been disabled'),
			'INTEGRATION'	=> $this->l('The shop is currently being certified'),
			'INVALID_TS_ID'	=> $this->l('No certificate has been allocated to the Trusted Shops ID'),
			'TEST'			=> $this->l('Test certificate'),
		);

		$client = $this->_getClient();
		$validation = false;

		try
		{
			$validation = $client->checkCertificate($certificate);
		}
		catch (SoapFault $fault)
		{
			$this->errors[] = $this->l('Code #').$fault->faultcode.',<br />'.$this->l('message:').$fault->faultstring;
			return false;
		}

		if (is_int($validation))
			throw new TSBPException($validation, TSBPException::ADMINISTRATION);

		if (!$validation OR array_key_exists($validation->stateEnum, $array_state))
		{
			if ($validation->stateEnum === 'TEST' ||
				$validation->stateEnum === 'PRODUCTION' ||
				$validation->stateEnum === 'INTEGRATION')
			{
				$this->confirmations[] = $array_state[$validation->stateEnum];
				return $validation;
			}
			else
			{
				$this->errors[] = $array_state[$validation->stateEnum];
				return false;
			}
		}
		else
			$this->errors[] = $this->l('Unknown error.');
	}

	/**
	 * Checks the shop's web service access credentials.
	 *
	 * @uses TSBuyerProtection::_getClient()
	 * @param string $ts_id
	 * @param string $user
	 * @param string $password
	 */
	private function _checkLogin($ts_id, $user, $password)
	{
		$client = $this->_getClient();
		$return = 0;

		try
		{
			$return = $client->checkLogin($ts_id, $user, $password);
		}
		catch (SoapClient $fault)
		{
			$this->errors[] = $this->l('Code #').$fault->faultcode.',<br />'.$this->l('message:').$fault->faultstring;
		}

		if ($return < 0)
			throw new TSBPException($return, TSBPException::ADMINISTRATION);

		return true;
	}

	/**
	 * Returns the characteristics of the buyer protection products
	 * that are allocated individually to each certificate by Trusted Shops.
	 *
	 * @uses TSBuyerProtection::_getClient()
	 * @param string $ts_id
	 */
	private function _getProtectionItems($ts_id)
	{
		$client = $this->_getClient();

		try
		{
			$items = $client->getProtectionItems($ts_id);

			// Sometimes an object could be send for the item attribute if there is only one result
			if (isset($items) && !is_array($items->item))
				$items->item = array(0 => $items->item);
		}
		catch (SoapFault $fault)
		{
			$this->errors[] = $this->l('Code #').$fault->faultcode.',<br />'.$this->l('message:').$fault->faultstring;
		}

		return (isset($items->item)) ? $items->item : false;
	}

	/**
	 * Check validity for params required for TSBuyerProtection::_requestForProtectionV2()
	 *
	 * @param array $params
	 */
	private function _requestForProtectionV2ParamsValidator($params)
	{
		$bool_flag = true;

		$mandatory_keys = array(
			array('name'=>'tsID', 'validator'=>array('isCleanHtml')),
			array('name'=>'tsProductID', 'validator'=>array('isCleanHtml')),
			array('name'=>'amount', 'validator'=>array('isFloat')),
			array('name'=>'currency', 'length'=>3, 'validator'=>array('isString')),
			array('name'=>'paymentType', 'validator'=>array('isString')),
			array('name'=>'buyerEmail', 'validator'=>array('isEmail')),
			array('name'=>'shopCustomerID', 'validator'=>array('isInt')),
			array('name'=>'shopOrderID', 'validator'=>array('isInt')),
			array('name'=>'orderDate', 'ereg'=>'#[0-9]{4}\-[0-9]{2}\-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}#'),
			array('name'=>'shopSystemVersion','validator'=>array('isCleanHtml')),
			array('name'=>'wsUser','validator'=>array('isCleanHtml')),
			array('name'=>'wsPassword', 'validator'=>array('isCleanHtml'))
		);

		foreach ($mandatory_keys as $key)
		{
			$bool_flag = (array_key_exists($key['name'], $params)) ? $bool_flag : false;

			if ($bool_flag)
			{
				if (isset($key['length']))
					$bool_flag = strlen((string)$params[$key['name']]) === $key['length'];
				if (isset($key['length-min']))
					$bool_flag = strlen((string)$params[$key['name']]) > $key['length-min'];
				if (isset($key['length-max']))
					$bool_flag = strlen((string)$params[$key['name']]) < $key['length-max'];
				if (isset($key['validator']))
					foreach ($key['validator'] as $validator)
						if (method_exists('Validate', $validator))
							$bool_flag = !Validate::$validator((string)$params[$key['name']]) ? false : $bool_flag;
				if (isset($key['ereg']))
					$bool_flag = !preg_match($key['ereg'], $params[$key['name']]) ? false : $bool_flag ;
			}

			if (!$bool_flag)
			{
				$this->errors[] = sprintf($this->l('The field %s is wrong, please ensure it was correctly filled.'), $key['name']);
				break;
			}
		}
		return $bool_flag;
	}

	/**
	 * Create the Buyer Protection application by the web service.
	 * Applications are saved by Trusted Shops and are processed at regular intervals.
	 *
	 * @uses TSBuyerProtection::_getClient()
	 * @uses TSBuyerProtection::_requestForProtectionV2ParamsValidator()
	 * 		 to check required params
	 * @see TSBuyerProtection::cronTasks()
	 * @param array $params
	 */
	private function _requestForProtectionV2($params)
	{
		$code = 0;
		$client = $this->_getClient(TSBuyerProtection::WEBSERVICE_FO);
		$testing_params = $this->_requestForProtectionV2ParamsValidator($params);

		$query = '
			SELECT `id_order`'.
			'FROM `'._DB_PREFIX_.TSBuyerProtection::DB_APPLI.'`'.
			'WHERE `id_order` = "'.(int)$params['shopOrderID'].'"';

		// If an order was already added, no need to continue.
		// Otherwise a new application is created by TrustedShops.
		// this can occurred when order confirmation page is reload.
		if (Db::getInstance()->getValue($query))
			return false;

		if ($testing_params)
		{
			try
			{
				$code = $client->requestForProtectionV2(
					$params['tsID'], $params['tsProductID'], $params['amount'],
					$params['currency'], $params['paymentType'], $params['buyerEmail'],
					$params['shopCustomerID'], $params['shopOrderID'], $params['orderDate'],
					$params['shopSystemVersion'], $params['wsUser'], $params['wsPassword']);

				if ($code < 0)
					throw new TSBPException($code, TSBPException::FRONT_END);
			}
			catch (SoapFault $fault)
			{
				$this->errors[] = $this->l('Code #').$fault->faultcode.',<br />'.$this->l('message:').$fault->faultstring;
			}
			catch (TSBPException $e)
			{
				$this->errors[] = $e->getMessage();
			}

			if ($code > 0)
			{
				$date = date('Y-m-d H:i:s');

				$query = 'INSERT INTO `'._DB_PREFIX_.TSBuyerProtection::DB_APPLI.'` '.
					'(`id_application`, `ts_id`, `id_order`, `creation_date`, `last_update` ) '.
					'VALUES ("'.pSQL($code).'", "'.pSQL($params['tsID']).'", "'.pSQL($params['shopOrderID']).'", "'.pSQL($date).'", "'.pSQL($date).'")';

				Db::getInstance()->Execute($query);

				// To reset product quantity in database.
				$query = 'SELECT `id_product` '.
					'FROM `'._DB_PREFIX_.TSBuyerProtection::DB_ITEMS.'` '.
					'WHERE `ts_product_id` = "'.pSQL($params['tsProductID']).'"
					AND `ts_id` = "'.pSQL($params['tsID']).'"';

				if (($id_product = Db::getInstance()->getValue($query)))
				{
					$product = new Product($id_product);
					$product->quantity = 1000;
					$product->update();
					unset($product);
				}
			}
		}
		else
			$this->errors[] = $this->l('Some parameters sending to "requestForProtectionV2" method are wrong or missing.');
	}

	/**
	 * With the getRequestState() method,
	 * the status of a guarantee application is requested
	 * and in the event of a successful transaction,
	 * the guarantee number is returned.
	 *
	 * @uses TSBuyerProtection::_getClient()
	 * @param array $params
	 * @throws TSBPException
	 */
	private function _getRequestState($params)
	{
		$client = $this->_getClient(TSBuyerProtection::WEBSERVICE_FO);
		$code = 0;

		try
		{
			$code = $client->getRequestState($params['tsID'], $params['applicationID']);

			if ($code < 0)
				throw new TSBPException($code, TSBPException::FRONT_END);
		}
		catch (SoapFault $fault)
		{
			$this->errors[] = $this->l('Code #').$fault->faultcode.',<br />'.$this->l('message:').$fault->faultstring;
		}
		catch (TSBPException $e)
		{
			$this->errors[] = $e->getMessage();
		}

		return $code;
	}

	/**
	 * Check statut of last applications
	 * saved with TSBuyerProtection::_requestForProtectionV2()
	 *
	 * Negative value means an error occurred.
	 * Error code are managed in TSBPException.
	 * @see (exception) TSBPException::_getFrontEndMessage() method
	 *
	 * Trusted Shops recommends that the request
	 * should be automated by a cronjob with an interval of 10 minutes.
	 * @see /../cron_garantee.php
	 *
	 * A message is added to the sheet order in Back-office,
	 * @see Message class
	 *
	 * @uses TSBuyerProtection::_getRequestState()
	 * @uses Message class
	 * @return void
	 */
	public function cronTask()
	{
		// get the last 20min to get the api number (to be sure)
		$mktime = mktime(date('H'), date('i')-20, date('s'), date('m'), date('d'), date('Y'));
		$date = date('Y-m-d H:i:s', $mktime);
		$db_name = _DB_PREFIX_.TSBuyerProtection::DB_APPLI;

		$query = 'SELECT * '.
			'FROM `'.$db_name.'` '.
			'WHERE `last_update` >= "'.pSQL($date).'" '.
			'OR `statut_number` <= 0';

		$to_check = Db::getInstance()->ExecuteS($query);

		foreach ($to_check as $application)
		{
			$code = $this->_getRequestState(array('tsID'=>$application['ts_id'], 'applicationID' => $application['id_application']));

			if (!empty($this->errors))
			{
				$return_message = '<p style="color:red;">'.$this->l('Trusted Shops API returns an error concerning the application #').$application['id_application'].': <br />'.implode(', <br />', $this->errors).'</p>';
				$this->errors = array();
			}
			elseif ($code > 0)
				$return_message = sprintf($this->l('Trusted Shops application number %1$d was successfully processed. The guarantee number is: %2$d'), $application['id_application'], $code);

			$query = 'UPDATE `'.$db_name.'` '.
				'SET `statut_number` = "'.pSQL($code).'" '.
				'WHERE `id_application` >= "'.pSQL($application['id_application']).'"';

			Db::getInstance()->Execute($query);

			$msg = new Message();
			$msg->message = $return_message;
			$msg->id_order = (int)$application['id_order'];
			$msg->private = 1;
			$msg->add();
		}
	}

	/**
	 * Registration link to Trusted Shops
	 *
	 * @param string $shopsw
	 * @param string $et_cid
	 * @param string $et_lid
	 * @param string $lang
	 * @return boolean|string boolean in case of $lang is not supported by Trusted Shops
	 * 		   string return is the url to access of form subscription
	 */
	private function _makeRegistrationLink($shopsw, $et_cid, $et_lid, $lang)
	{
		if (array_key_exists($lang, $this->registration_link))
			return $this->registration_link[$lang].sprintf('?shopsw=%s&et_cid=%s&et_lid=%s', urlencode($shopsw), urlencode($et_cid), urlencode($et_lid));
		return false;
	}

	/**
	 * Method to display or redirect the subscription link.
	 *
	 * @param string $link
	 */
	private function _getRegistrationLink($link)
	{
		return '<script type="text/javascript" >$().ready(function(){window.open("'.$link.'");});</script>
		<noscript><p><a href="'.$link.'" target="_blank" title="'.$this->l('Registration Link').'" class="link">'.$this->l('Click to get the Registration Link').'</a><p></noscript>';
	}

	/**
	 * saved paramter to acces of particular subscribtion link.
	 *
	 * @return string the registration link.
	 */
	private function _submitRegistrationLink()
	{
		// @todo : ask for more infos about values types
		TSBuyerProtection::$SHOPSW = (Validate::isCleanHtml(Tools::getValue('shopsw'))) ? Tools::getValue('shopsw') : '';
		TSBuyerProtection::$ET_CID = (Validate::isCleanHtml(Tools::getValue('et_cid'))) ? Tools::getValue('et_cid') : '';
		TSBuyerProtection::$ET_LID = (Validate::isCleanHtml(Tools::getValue('et_lid'))) ? Tools::getValue('et_lid') : '';

		Configuration::updateValue(TSBuyerProtection::PREFIX_TABLE.'SHOPSW', TSBuyerProtection::$SHOPSW);
		Configuration::updateValue(TSBuyerProtection::PREFIX_TABLE.'ET_CID', TSBuyerProtection::$ET_CID);
		Configuration::updateValue(TSBuyerProtection::PREFIX_TABLE.'ET_LID', TSBuyerProtection::$ET_LID);

		$link_registration = $this->_makeRegistrationLink(TSBuyerProtection::$SHOPSW, TSBuyerProtection::$ET_CID, TSBuyerProtection::$ET_LID, Tools::getValue('lang'));
		$this->confirmations[] = $this->l('Registration link has been created. Follow this link if you were not redirected earlier:').'&nbsp;<a href="'.$link_registration.'" class="link">&gt;'.$this->l('Link').'&lt;</a>';

		return $link_registration;
	}

	/**
	 * Save in special database each buyer protection product for a certificate,
	 * Each Trusted Shops particular characteristics is saved.
	 * Create a product in Prestashop database to allow added each of them in cart.
	 *
	 * @param array|stdClass $protection_items
	 * @param string $ts_id
	 */
	private function _saveProtectionItems($protection_items, $ts_id)
	{
		$query = 'DELETE ts, p, pl '.
			'FROM `'._DB_PREFIX_.TSBuyerProtection::DB_ITEMS.'` AS ts '.
			'LEFT JOIN `'._DB_PREFIX_.'product` AS p ON ts.`id_product` = p.`id_product` '.
			'LEFT JOIN `'._DB_PREFIX_.'product_lang` AS pl ON ts.`id_product` = pl.`id_product` '.
			'WHERE ts.`ts_id`="'.pSQL($ts_id).'"';

		Db::getInstance()->Execute($query);

		foreach ($protection_items as $item)
		{
			//add hidden product
			$product = new Product();

			foreach ($this->available_languages as $iso=>$lang)
			{
				$language = Language::getIdByIso(strtolower($iso));

				if ((int)$language !== 0)
				{
					$product->name[$language] = 'TrustedShops guarantee';
					$product->link_rewrite[$language] = 'trustedshops_guarantee';
				}
			}

			// If the default lang is different than available languages :
			// (Bug occurred otherwise)
			if (!array_key_exists(Language::getIsoById((int)Configuration::get('PS_LANG_DEFAULT')), $this->available_languages))
			{
				$product->name[(int)Configuration::get('PS_LANG_DEFAULT')] = 'Trustedshops';
				$product->link_rewrite[(int)Configuration::get('PS_LANG_DEFAULT')] = 'trustedshops';
			}

			// Add specifics translations
			$id_lang = Language::getIdByIso('de');
			if ((int)$id_lang > 0) $product->name[$id_lang] = 'Trusted Shops K??uferschutz';
			$id_lang = Language::getIdByIso('en');
			if ((int)$id_lang > 0) $product->name[$id_lang] = 'Trusted Shops buyer protection';
			$id_lang = Language::getIdByIso('fr');
			if ((int)$id_lang > 0) $product->name[$id_lang] = 'Trusted Shops protection acheteur';

			$product->quantity = 1000;
			$product->price = Tools::convertPrice($item->grossFee, Currency::getIdByIsoCode($item->currency));
			$product->id_category_default = TSBuyerProtection::$CAT_ID;
			$product->active = true;
			$product->visibility = 'none';
			$product->id_tax = 0;
			$product->add();

			if ($product->id)
			{
				$query = 'INSERT INTO `'._DB_PREFIX_.TSBuyerProtection::DB_ITEMS.'` '.
					'(`creation_date`, `id_product`, `ts_id`, `id`, `currency`, `gross_fee`, `net_fee`, '.
					'`protected_amount_decimal`, `protection_duration_int`, `ts_product_id`) '.
					'VALUES ("'.pSQL($item->creationDate).'", "'.pSQL($product->id).'", "'.pSQL($ts_id).'", '.
					'"'.(int)$item->id.'", "'.pSQL($item->currency).'", "'.pSQL($item->grossFee).'", '.
					'"'.pSQL($item->netFee).'", "'.pSQL($item->protectedAmountDecimal).'", '.
					'"'.pSQL($item->protectionDurationInt).'", "'.pSQL($item->tsProductID).'")';

				Db::getInstance()->Execute($query);

				if (class_exists('StockAvailable'))
				{
					$id_stock_available = Db::getInstance()->getValue('
						SELECT s.`id_stock_available` FROM `'._DB_PREFIX_.'stock_available` s
						WHERE s.`id_product` = '.(int)$product->id);

					$stock = new StockAvailable($id_stock_available);
					$stock->id_product = $product->id;
					$stock->out_of_stock = 1;
					$stock->id_product_attribute = 0;
					$stock->quantity = 1000000;
					$stock->id_shop = Context::getContext()->shop->id;
					if ($stock->id)
						$stock->update();
					else
						$stock->add();
				}
			}
			else
				$this->errors['products'] = $this->l('Product wasn\'t saved.');
		}
	}

	/**
	 * Check and add a Trusted Shops certificate in shop.
	 *
	 * @uses TSBuyerProtection::_getProtectionItems()
	 * 		 to get all buyer protection products from Trusted Shops
	 * @uses TSBuyerProtection::_saveProtectionItems()
	 * 		 to save buyer protection products in shop
	 * @return boolean true if certificate is added successfully, false otherwise
	 */
	private function _submitAddCertificate()
	{
		$checked_certificate = false;

		try
		{
			$checked_certificate = $this->_checkCertificate(ToolsCore::getValue('new_certificate'));
		}
		catch (TSBPException $e)
		{
			$this->errors[] = $e->getMessage();
		}

		if ($checked_certificate)
		{
			TSBuyerProtection::$CERTIFICATES[strtoupper($checked_certificate->certificationLanguage)] = array(
				'stateEnum' => $checked_certificate->stateEnum,
				'typeEnum'  => $checked_certificate->typeEnum,
				'tsID'      => $checked_certificate->tsID,
				'url'       => $checked_certificate->url,
				'user'      => '',
				'password'  => '');

			// update the configuration var
			Configuration::updateValue(TSBuyerProtection::PREFIX_TABLE.'CERTIFICATE_'.strtoupper($checked_certificate->certificationLanguage), Tools::htmlentitiesUTF8(Tools::jsonEncode(TSBuyerProtection::$CERTIFICATES[strtoupper($checked_certificate->certificationLanguage)])));
			$this->confirmations[] = $this->l('Certificate has been added successfully.');

			if ($checked_certificate->typeEnum === 'EXCELLENCE')
			{
				try
				{
					$protection_items = $this->_getProtectionItems($checked_certificate->tsID);

					if ($protection_items)
						$this->_saveProtectionItems($protection_items, $checked_certificate->tsID);
				}
				catch (TSBPException $e)
				{
					$this->errors[] = $e->getMessage();
				}
			}
		}
		return (bool)$checked_certificate;
	}

	/**
	 * Apply delete or edit action to a certificate
	 *
	 * @return boolean|array
	 * 		   - false if action concerned multiple certificate
	 * 		   (in normal way, this never occurred )
	 * 		   - return required $certificate to edit.
	 * 		   - true in other case.
	 */
	private function _submitEditCertificate()
	{
		$edit = Tools::getValue('certificate_edit');
		$delete = Tools::getValue('certificate_delete');

		if ((is_array($edit) AND count($edit) > 1) OR (is_array($delete) AND count($delete) > 1))
		{
			$this->errors[] = $this->l('You must edit or delete a Certificate one at a time');
			return false;
		}

		// delete action :
		if (is_array($delete) AND isset(TSBuyerProtection::$CERTIFICATES[$delete[0]]['tsID']))
		{
			$certificate_to_delete = TSBuyerProtection::$CERTIFICATES[$delete[0]]['tsID'];
			Configuration::deleteByName(TSBuyerProtection::PREFIX_TABLE.'CERTIFICATE_'.strtoupper($delete[0]));
			unset(TSBuyerProtection::$CERTIFICATES[$delete[0]]);
			$this->confirmations[] = $this->l('The certificate')
				.' "'.$certificate_to_delete.'" ('.$this->l('language').' : '.$delete[0].') '
				.$this->l('has been deleted successfully');
		}

		// edit action :
		if (is_array($edit))
		{
			$return = TSBuyerProtection::$CERTIFICATES[$edit[0]];
			$return['language'] = $edit[0];
			return $return;
		}
		return true;
	}

	/**
	 * Change the certificate values.
	 * concerns only excellence certificate
	 * for payment type, login and password values.
	 *
	 * @uses TSBuyerProtection::_checkLogin()
	 * @return true;
	 */
	private function _submitChangeCertificate()
	{
		$all_payment_type = Tools::getValue('choosen_payment_type');
		$iso_lang = Tools::getValue('iso_lang');
		$password = Tools::getValue('password');
		$user = Tools::getValue('user');

		if ($user != '' AND $password != '')
		{
			TSBuyerProtection::$CERTIFICATES[$iso_lang]['payment_type'] = array();
			$check_login = false;

			if ($all_payment_type)
				if (is_array($all_payment_type))
					foreach ($all_payment_type as $key=>$module_id)
						TSBuyerProtection::$CERTIFICATES[$iso_lang]['payment_type'][(string)$key] = $module_id;

			try
			{
				$check_login = $this->_checkLogin(TSBuyerProtection::$CERTIFICATES[$iso_lang]['tsID'], $user, $password);
			}
			catch (TSBPException $e)
			{
				$this->errors[] = $e->getMessage();
			}

			if ($check_login)
			{
				TSBuyerProtection::$CERTIFICATES[$iso_lang]['user'] = $user;
				TSBuyerProtection::$CERTIFICATES[$iso_lang]['password'] = $password;

				Configuration::updateValue(TSBuyerProtection::PREFIX_TABLE.'CERTIFICATE_'.$iso_lang, Tools::htmlentitiesUTF8(Tools::jsonEncode(TSBuyerProtection::$CERTIFICATES[$iso_lang])));
				$this->confirmations[] = $this->l('Certificate login has been successful.');

			}
			else
				$this->errors[] = $this->l('Certificate login failed');
		}
		else
			$this->errors[] = $this->l('You have to set a username and a password before any changes can be made.');

		return true;
	}

	/**
	 * Change the environment for working.
	 * Not use anymore but keeped
	 * @return true
	 */
	private function _submitEnvironment()
	{
		TSBuyerProtection::$ENV_API = Tools::getValue('env_api');
		Configuration::updateValue(TSBuyerProtection::PREFIX_TABLE.'ENV_API', TSBuyerProtection::$ENV_API);

		return true;
	}

	/*
	 ** Update the env_api
	 */
	public function _setEnvApi($env_api)
	{
		if (Configuration::get(TSBuyerProtection::PREFIX_TABLE.'ENV_API') != $env_api)
			Configuration::updateValue(TSBuyerProtection::PREFIX_TABLE.'ENV_API', $env_api);
		TSBuyerProtection::$ENV_API = $env_api;
	}

	/**
	 * Dispatch post process depends on each formular
	 *
	 * @return array depend on the needs about each formular.
	 */
	private function _preProcess()
	{
		$posts_return = array();

		/*if (Tools::isSubmit('submit_registration_link'))
			$posts_return['registration_link'] = $this->_submitRegistrationLink();*/
		if (Tools::isSubmit('submit_add_certificate'))
			$posts_return['add_certificate'] = $this->_submitAddCertificate();
		if (Tools::isSubmit('submit_edit_certificate'))
			$posts_return['edit_certificate'] = $this->_submitEditCertificate();
		if (Tools::isSubmit('submit_change_certificate'))
			$posts_return['change_certificate'] = $this->_submitChangeCertificate();

		return $posts_return;
	}

	/**
	 * Display each formaular in back-office
	 *
	 * @see Module::getContent()
	 * @return string for displaying form.
	 */
	public function getContent()
	{
		$bool_display_certificats = false;
		$posts_return = $this->_preProcess();

		$out = $this->_displayPresentation().'<br />';
		$out .= $this->_displayFormAddCertificate().'<br />';

		if (is_array(self::$CERTIFICATES))
			foreach (self::$CERTIFICATES as $certif)
				$bool_display_certificats = (isset($certif['tsID']) && $certif['tsID'] != '') ? true : $bool_display_certificats;

		if ($bool_display_certificats)
			$out .= $this->_displayFormCertificatesList();

		if (isset($posts_return['edit_certificate']) && is_array($posts_return['edit_certificate']))
			$out .= $this->_displayFormEditCertificate($posts_return['edit_certificate']).'<br />';

		$out .= $this->_displayInfoCronTask().'<br />';

		return $out;
	}

	private function _displayPresentation()
	{
		global $cookie;

		$iso = Language::getIsoById((int)$cookie->id_lang);

		if (strtolower($iso) == 'de')
			$link = '<p><b><a style="text-decoration: underline; font-weight: bold; color: #0000CC;" target="_blank" href="http://www.trustedshops.de/shopbetreiber/mitgliedschaft.html?et_cid=14&et_lid=29069" target="_blank">Jetzt bei Trusted Shops anmelden!</a></b></p><br />';
		else if (strtolower($iso) == 'en')
			$link = '<p><b><a style="text-decoration: underline; font-weight: bold; color: #0000CC;" target="_blank" href="http://www.trustedshops.com/merchants/membership.html?shopsw=PRESTA&et_cid=53&et_lid=3361" target="_blank">Appy now!</a></b></p><br />';
		else if (strtolower($iso) == 'fr')
			$link = '<p><b><a style="text-decoration: underline; font-weight: bold; color: #0000CC;" target="_blank" href="http://www.trustedshops.fr/marchands/tarifs.html?shopsw=PRESTA&et_cid=53&et_lid=3362" target="_blank">Enregistrement Trusted Shops</a></b></p><br />';
		else
			$link = '';

		return '
			<div style="text-align:right; margin:10px 20px 10px 0">
				<img src="'.__PS_BASE_URI__.'modules/'.self::$module_name.'/img/siegel.gif" alt="logo"/>
			</diV>
		<h3>'.$this->l('Seal of Approval and Buyer Protection').'</h3>
		<p>'.$this->l('Trusted Shops is the well-known internet Seal of Approval for online shops which also offers customers a Buyer Protection. During the audit, your online shop is subjected to extensive and thorough tests. This audit, consisting of over 100 individual criteria, is based on the requirements of consumer protection, national and European laws.').'</p>
		<h3>'.$this->l('More trust leads to more sales!').'</h3>
		<p>'.$this->l('The Trusted Shops seal of approval is the optimal way to increase the trust of your online customers. Trust increases customers\' willingness to buy from you.').'</p>
		<h3>'.$this->l('Less abandoned purchases').'</h3>
		<p>'.$this->l('Give your online customers a strong reason to buy with the Trusted Shops Buyer Protection. This additional security leads to less shopping basket abandonment').'</p>
		<h3>'.$this->l('Profitable and long-term customer relationship').'</h3>
		<p>'.$this->l('For many online shoppers, the Trusted Shops Seal of Approval with Buyer Protection is an effective sign of quality for safe shopping on the internet. One-time buyers become regular customers.').'</p><br />
		<h3>'.$this->l('Environment type').'</h3>
		<p>'.$this->l('You are currently using the mode :').' <b>'.TSBuyerProtection::$ENV_API.'</b></p><br />'.$link;
	}

	private function _displayFormRegistrationLink($link = false)
	{
		$out = '
		<form action="'.$this->_makeFormAction(strip_tags($_SERVER['REQUEST_URI']), $this->id_tab).'" method="post" >
			<fieldset>
				<legend><img src="../img/admin/cog.gif" alt="" />'.$this->l('Get the Registration Link').'</legend>
				<p>'.$this->l('This variable was sent to you via e-mail by TrustedShops').'</p>
				<label>'.$this->l('Internal identification of shop software at Trusted Shops').'</label>
				<div class="margin-form">
					<input type="text" name="shopsw" value="'.TSBuyerProtection::$SHOPSW.'"/>
				</div>
				<br />
				<br class="clear" />
				<label>'.$this->l('Etracker channel').'</label>
				<div class="margin-form">
					<input type="text" name="et_cid" value="'.TSBuyerProtection::$ET_CID.'"/>
				</div>
				<br class="clear" />
				<label>'.$this->l('Etracker campaign').'</label>
				<div class="margin-form">
					<input type="text" name="et_lid" value="'.TSBuyerProtection::$ET_LID.'"/>
				</div>
				<label>'.$this->l('Language').'</label>
				<div class="margin-form">
					<select name="lang" >';

		foreach ($this->available_languages as $iso => $lang)
			if (is_array($lang))
				$out .= '<option value="'.$iso.'" '.((int)$lang['id_lang'] === TSBuyerProtection::$DEFAULT_LANG ? 'selected' : '' ).'>'.$lang['name'].'</option>';

		$out .= '</select>
				</div>
				<div style="text-align:center;">';
		// If Javascript is deactivated
		if ($link !== false)
			$out .= $this->_getRegistrationLink($link);
		$out .='<input type="submit" name="submit_registration_link" class="button" value="'.$this->l('send').'"/>
				</div>
			</fieldset>
		</form>';

		return $out;
	}

	private function _displayFormAddCertificate()
	{
		$out = '
		<form action="'.$this->_makeFormAction(strip_tags($_SERVER['REQUEST_URI']), $this->id_tab).'" method="post" >
			<fieldset>
				<legend><img src="../img/admin/cog.gif" alt="" />'.$this->l('Add Trusted Shops certificate').'</legend>
				<label>'.$this->l('New certificate').'</label>
				<div class="margin-form">
					<input type="text" name="new_certificate" value="" style="width:300px;"/>&nbsp;
					<input type="submit" name="submit_add_certificate" class="button" value="'.$this->l('Add it').'"/>
				</div>
			</fieldset>
		</form>';

		return $out;
	}

	private function _displayFormCertificatesList()
	{
		$out = '
		<script type="text/javascript">
			$().ready(function()
			{
				$(\'#certificate_list\').find(\'input[type=checkbox]\').click(function()
				{
					$(\'#certificate_list\').find(\'input[type=checkbox]\').not($(this)).removeAttr(\'checked\');
				});
			});
		</script>
		<form action="'.$this->_makeFormAction(strip_tags($_SERVER['REQUEST_URI']), $this->id_tab).'" method="post" >
		<fieldset>
			<legend><img src="../img/admin/cog.gif" alt="" />'.$this->l('Manage Trusted Shops certificates').'</legend>
				<table width="100%">
					<thead>
						<tr
					 
					public $	style="text-align:center;">
							<th>'.$this->l('Certificate').'</th>
							<th>'.$this->l('Language').'</th>
							<th>'.$this->l('State').'</th>
							<th>'.$this->l('Type').'</th>
							<th>'.$this->l('Shop url').'</th>
							<th>'.$this->l('Edit').'</th>
							<th>'.$this->l('Delete').'</th>
						</tr>
					</thead>
					<tbody id="certificate_list">';

		foreach (TSBuyerProtection::$CERTIFICATES as $lang => $certificate)
		{
			$certificate = (array)$certificate;

			if (isset($certificate['tsID']) AND $certificate['tsID'] !== '')
			{
				$out .= '
						<tr style="text-align:center;">
							<td>'.$certificate['tsID'].'</td>
							<td>'.$lang.'</td>
							<td>'.$certificate['stateEnum'].'</td>
							<td>'.$certificate['typeEnum'].'</td>
							<td>'.$certificate['url'].'</td>
							<td>';

				if ($certificate['typeEnum'] === 'EXCELLENCE')
				{
					$out .= '<input type="checkbox" name="certificate_edit[]" value="'.$lang.'" />';
					$out .= $certificate['user'] == '' ? '<br /><b style="color:red;font-size:0.7em;">'.$this->l('Login or password missing').'</b>' : '';
				}
				else
					$out .= $this->l('No need');

				$out .= '
							</td>
							<td>';

				if ($certificate['typeEnum'] === 'EXCELLENCE' || $certificate['typeEnum'] === 'CLASSIC')
					$out .= '<input type="checkbox" name="certificate_delete[]" value="'.$lang.'" />';
				else
					$out .= $this->l('No need');

				$out .= '
							</td>
						</tr>';
			}
		}

		$out .='
					</tbody>
				</table>
				<div style="text-align:center;"><input type="submit" name="submit_edit_certificate" class="button" value="'.$this->l('Edit certificate').'"/></div>
			</fieldset>
		</form>
		';

		return $out;
	}

	/**
	 * Check if a module is payment module.
	 *
	 * Method instanciate a $module by its name,
	 * Module::getInstanceByName() rather than Module::getInstanceById()
	 * is used for cache improvement and avoid an sql request.
	 *
	 * Method test if PaymentMethod::getCurrency() is a method from the module.
	 *
	 * @see Module::getInstanceByName() in classes/Module.php
	 * @param string $module name of the module
	 */
	private static function _isPaymentModule($module)
	{
		$return = false;
		$module = Module::getInstanceByName($module);

		if (method_exists($module, 'getCurrency'))
			$return = clone $module;

		unset($module);

		return $return;
	}

	private function _displayFormEditCertificate($certificate)
	{
		$payment_module_collection = array();
		$installed_modules = Module::getModulesInstalled();

		foreach ($installed_modules as $value)
			if ($return = TSBuyerProtection::_isPaymentModule($value['name']))
				$payment_module_collection[$value['id_module']] = $value;

		$out = '
		<script type="text/javascript" src="'.$this->site_url.'modules/trustedshops/lib/js/payment.js" ></script>
		<script type="text/javascript">
			$().ready(function()
			{
				TSPayment.payment_type = $.parseJSON(\''.Tools::jsonEncode(TSBuyerProtection::$payments_type).'\');
				TSPayment.payment_module = $.parseJSON(\''.Tools::jsonEncode($payment_module_collection).'\');
				$(\'.payment-module-label\').css(TSPayment.module_box.css).fadeIn();
				$(\'.choosen_payment_type\').each(function()
				{
					TSPayment.deleteModuleFromList($(this).val());
					TSPayment.setLabelModuleName($(this).val());
				});
				TSPayment.init();
			});

		</script>
		<form action="'.$this->_makeFormAction(strip_tags($_SERVER['REQUEST_URI']), $this->id_tab).'" method="post" >
			<fieldset>
				<legend><img src="../img/admin/tab-tools.gif" alt="" />'.$this->l('Edit certificate').'</legend>
				<input type="hidden" name="iso_lang" value="'.$certificate['language'].'" />
				<label>'.$this->l('Language').'</label>
				<div class="margin-form">'.$certificate['language'].'</div>
				<label>'.$this->l('Shop url').'</label>
				<div class="margin-form">'.$certificate['url'].'</div>
				<label>'.$this->l('Certificate id').'</label>
				<div class="margin-form">'.$certificate['tsID'].'</div>
				<label>'.$this->l('User Name').' <sup>*</sup></label>
				<div class="margin-form"><input type="text" name="user" value="'.$certificate['user'].'" style="width:300px;"/></div>
				<label>'.$this->l('Password').' <sup>*</sup></label>
				<div class="margin-form"><input type="text" name="password" value="'.$certificate['password'].'" style="width:300px;"/></div>
				<div id="payment-type">
					<label>'.$this->l('Payment type to edit').' <sup>*</sup></label>
					<div class="margin-form">
						<select name="payment_type">';

		foreach (TSBuyerProtection::$payments_type as $type => $translation)
			$out .= '	<option value="'.$type.'" >'.$translation.'</option>';

		$out .= '		</select>&nbsp;'
			.$this->l('with')
			.'&nbsp;
						<select name="payment_module">';

		foreach ($payment_module_collection as $module_info)
			$out .= '		<option value="'.$module_info['id_module'].'" >'.$module_info['name'].'</option>';

		$out .= '		</select>&nbsp;'
			.$this->l('payment module')
			.'&nbsp;<input type="button" value="'.$this->l('Add it').'" class="button" name="add_payment_module" />
					</div><!-- .margin-form -->
					<div id="payment_type_list">';
		$input_output = '';

		if (isset($certificate['payment_type']) AND !empty($certificate['payment_type']))
		{
			foreach ($certificate['payment_type'] as $payment_type=>$modules)
			{
				$out .= '	<label style="clear:both;" class="payment-type-label" >'.TSBuyerProtection::$payments_type[$payment_type].'</label>';
				$out .= '	<div class="margin-form" id="block-payment-'.$payment_type.'">';
				foreach ($modules as $module_id)
				{
					$out .= '<b class="payment-module-label" id="label-module-'.$module_id.'"></b>';
					$input_output .= '<input type="hidden" value="'.$module_id.'" class="choosen_payment_type" name="choosen_payment_type['.$payment_type.'][]">';
				}
				$out .= '	</div><!-- .margin-form -->';
			}
		}

		$out .= '</div><!-- #payment_type_list -->
			</div><!-- #payment-type -->
			<p id="input-hidden-val" style="display:none;">'.$input_output.'</p>
			<p style="text-align:center;">
				<input type="submit" name="submit_change_certificate" class="button" value="'.$this->l('Update it').'"/>
			</p>
			</fieldset>
		</form>';

		return $out;
	}

	private function _displayInfoCronTask()
	{
		$out = '<fieldset>
				<legend><img src="../img/admin/warning.gif" alt="" />'.$this->l('Cronjob configuration').'</legend>';
		$out .= '<p>'
			.$this->l('If you are using a Trusted Shops EXCELLENCE cetificate in your shop, set up a cron job on your web server.').'<br />'
			.$this->l('Run the script file ').' <b style="color:red;">'.$this->getCronFilePath().'</b> '.$this->l('with an interval of 10 minutes.').'<br /><br />'
			.$this->l('The corresponding line in your cron file may look like this:').' <br /><b style="color:red;">*/10 * * * * '.$this->getCronFilePath().'>/dev/null 2>&1</b><br />'
			.'</p>';
		$out .= '</fieldset>';

		return $out;
	}

	public function hookRightColumn($params)
	{
		$iso_code = strtoupper(Language::getIsoById($params['cookie']->id_lang));

		if (array_key_exists($iso_code, $this->available_languages) AND isset(TSBuyerProtection::$CERTIFICATES[$iso_code]['tsID']))
		{
			TSBuyerProtection::$smarty->assign('trusted_shops_id', TSBuyerProtection::$CERTIFICATES[$iso_code]['tsID']);
			TSBuyerProtection::$smarty->assign('onlineshop_name', ConfigurationCore::get('PS_SHOP_NAME'));

			$url = str_replace(array('#shop_id#', '#shop_name#'), array(
					TSBuyerProtection::$CERTIFICATES[$iso_code]['tsID'],
					urlencode(str_replace('_', '-', ConfigurationCore::get('PS_SHOP_NAME')))
				),
				TSBuyerProtection::$certificate_link[$iso_code]);
			TSBuyerProtection::$smarty->assign('trusted_shops_url', $url);

			if (isset(TSBuyerProtection::$CERTIFICATES[$iso_code]))
			{
				$certificate = TSBuyerProtection::$CERTIFICATES[$iso_code];

				if (isset($certificate['tsID']) && ($certificate['typeEnum'] == 'CLASSIC' || 
					($certificate['typeEnum'] == 'EXCELLENCE' && $certificate['user'] != '' && $certificate['password'] != '')))
					return TrustedShops::display_seal();
			}
		}

		return '';
	}

	/**
	 * For Excellence certificate display Buyer protection products.
	 * An error message if the certificate is not totally filled
	 *
	 * @param array $params
	 * @return string tpl content
	 */
	public function hookPaymentTop($params)
	{
		$lang = strtoupper(Language::getIsoById($params['cookie']->id_lang));

		if (!isset(TSBuyerProtection::$CERTIFICATES[$lang]) ||
			!isset(TSBuyerProtection::$CERTIFICATES[$lang]['typeEnum']))
			return '';

		// This hook is available only with EXCELLENCE certificate.
		if (TSBuyerProtection::$CERTIFICATES[$lang]['typeEnum'] == 'CLASSIC' ||
			 (TSBuyerProtection::$CERTIFICATES[$lang]['stateEnum'] !== 'INTEGRATION' &&
				TSBuyerProtection::$CERTIFICATES[$lang]['stateEnum'] !== 'PRODUCTION' &&
				TSBuyerProtection::$CERTIFICATES[$lang]['stateEnum'] !== 'TEST'))
			return '';

		// If login parameters missing for the certificate an error occurred
		if ((TSBuyerProtection::$CERTIFICATES[$lang]['user'] == '' ||
			 TSBuyerProtection::$CERTIFICATES[$lang]['password'] == '') &&
			 TSBuyerProtection::$CERTIFICATES[$lang]['typeEnum'] == 'EXCELLENCE')
			return '';

		// Set default value for an unexisting item
		TSBuyerProtection::$smarty->assign('item_exist', false);
		if (array_key_exists($lang, $this->available_languages))
		{
			$currency = new Currency((int)$params['cookie']->id_currency);

			$query = '
				SELECT * '.
				'FROM `'._DB_PREFIX_.TSBuyerProtection::DB_ITEMS.'` '.
				'WHERE ts_id ="'.pSQL(TSBuyerProtection::$CERTIFICATES[$lang]['tsID']).'" '.
				'AND `protected_amount_decimal` >= "'.(int)$params['cart']->getOrderTotal(true, Cart::BOTH).'" '.
				'AND `currency` = "'.pSQL($currency->iso_code).'" '.
				'ORDER BY `protected_amount_decimal` ASC';

			// If amout is bigger, get the max one requested by TS
			if (!$item = Db::getInstance()->getRow($query))
			{
				$query = '
					SELECT *, MAX(protected_amount_decimal) '.
					'FROM `'._DB_PREFIX_.TSBuyerProtection::DB_ITEMS.'` '.
					'WHERE ts_id ="'.pSQL(TSBuyerProtection::$CERTIFICATES[$lang]['tsID']).'" '.
					'AND `currency` = "'.pSQL($currency->iso_code).'"';

				$item = Db::getInstance()->getRow($query);
			}

			if ($item && count($item))
				TSBuyerProtection::$smarty->assign(array(
						'item_exist' => true,
						'shop_id' => TSBuyerProtection::$CERTIFICATES[$lang]['tsID'],
						'buyer_protection_item' => $item,
						'currency', $currency)
				);
		}

		/**
		 * We need to clean the cart of other TSBuyerProtection product, in case the customer wants to change the currency
		 * The price of a TSBuyerProtection product is different for each currency, the conversion_rate won't change anything
		 */

		$query = 'SELECT id_product '.
			'FROM `'._DB_PREFIX_.TSBuyerProtection::DB_ITEMS.'`';

		$product = Db::getInstance()->ExecuteS($query);

		$product_protection = array();

		foreach ($product as $item)
			$product_protection[] = $item['id_product'];

		// TODO : REWRITE this part because it's completely not a good way (Control  + R, add Product dynamically)
		foreach ($params['cart']->getProducts() as $item)
			if (in_array($item['id_product'], $product_protection))
				$params['cart']->deleteProduct($item['id_product']);

		return $this->display(TSBuyerProtection::$module_name, 'display_products.tpl');
	}

	/**
	 * This prepare values to create the Trusted Shops web service
	 * for Excellence certificate.
	 *
	 * @see TSBuyerProtection::_requestForProtectionV2() method
	 * @param array $params
	 * @param string $lang
	 * @return string empty if no error occurred or no item was set.
	 */
	private function _orderConfirmationExcellence($params, $lang)
	{
		$currency = new Currency((int)$params['objOrder']->id_currency);
		$order_products = $params['objOrder']->getProducts();
		$order_item_ids = array();

		foreach ($order_products as $product)
			$order_item_ids[] = (int)$product['product_id'];

		$query = 'SELECT * '.
			'FROM `'._DB_PREFIX_.TSBuyerProtection::DB_ITEMS.'` '.
			'WHERE `id_product` IN ('.implode(',', $order_item_ids).') '.
			'AND `ts_id` ="'.pSQL(TSBuyerProtection::$CERTIFICATES[$lang]['tsID']).'" '.
			'AND `currency` = "'.pSQL($currency->iso_code).'"';

		if (!($item = Db::getInstance()->getRow($query)))
			return '';

		$customer = new Customer($params['objOrder']->id_customer);
		$payment_module = Module::getInstanceByName($params['objOrder']->module);
		$arr_params = array();

		$arr_params['paymentType'] = '';
		foreach (TSBuyerProtection::$CERTIFICATES[$lang]['payment_type'] as $payment_type => $id_modules)
			if (in_array($payment_module->id, $id_modules))
			{
				$arr_params['paymentType'] = (string)$payment_type;
				break;
			}

		if ($arr_params['paymentType'] == '')
			$arr_params['paymentType'] = 'OTHER';

		$arr_params['tsID'] = TSBuyerProtection::$CERTIFICATES[$lang]['tsID'];
		$arr_params['tsProductID'] = $item['ts_product_id'];
		$arr_params['amount'] = $params['total_to_pay'];
		$arr_params['currency'] = $currency->iso_code;
		$arr_params['buyerEmail'] = $customer->email;
		$arr_params['shopCustomerID'] = $customer->id;
		$arr_params['shopOrderID'] = $params['objOrder']->id;
		$arr_params['orderDate'] = date('Y-m-d\TH:i:s', strtotime($params['objOrder']->date_add));
		$arr_params['shopSystemVersion'] = 'Prestashop '._PS_VERSION_;
		$arr_params['wsUser'] = TSBuyerProtection::$CERTIFICATES[$lang]['user'];
		$arr_params['wsPassword'] = TSBuyerProtection::$CERTIFICATES[$lang]['password'];

		$this->_requestForProtectionV2($arr_params);

		if (!empty($this->errors))
			return '<p style="color:red">'.implode('<br />', $this->errors).'</p>';

		return '';
	}

	/**
	 * Trusted Shops Buyer Protection is integrated at the end of the checkout
	 * as a form on the order confirmation page.
	 * At the moment the customer clicks the registration button,
	 * the order data is processed to Trusted Shops.
	 * The customer confirms the Buyer Protection on the Trusted Shops site.
	 * The guarantee is then booked and the customer receives an email by Trusted Shops.
	 *
	 * @param array $params
	 * @param string $lang
	 * @return string tpl content
	 */
	private function _orderConfirmationClassic($params, $lang)
	{
		$payment_type = 'OTHER';

		// Payment type for native module list
		$payment_type_list = array(
			'bankwire' => 'PREPAYMENT',
			'authorizeaim' => 'CREDIT_CARD',
			'buyster' => 'CREDIT_CARD',
			'cashondelivery' => 'CASH_ON_DELIVERY',
			'dibs' => 'CREDIT_CARD' ,
			'cheque' => 'CHEQUE',
			'gcheckout' => 'GOOGLE_CHECKOUT',
			'hipay' => 'CREDIT_CARD',
			'moneybookers' => 'MONEYBOOKERS',
			'kwixo' => 'CREDIT_CARD',
			'paypal' => 'CREDIT_CARD',
			'paysafecard' => 'CREDIT_CARD',
			'wexpay' => 'CREDIT_CARD',
			'banktransfert' => 'DIRECT DEBIT'
		);

		if (array_key_exists($params['objOrder']->module, $payment_type_list))
			$payment_type = $payment_type_list[$params['objOrder']->module];

		$customer = new Customer($params['objOrder']->id_customer);
		$currency = new Currency((int)$params['objOrder']->id_currency);

		$arr_params = array(
			'amount'        => $params['total_to_pay'],
			'buyer_email'   => $customer->email,
			'charset'       => 'UTF-8',
			'currency'      => $currency->iso_code,
			'customer_id'   => $customer->id,
			'order_id'      => $params['objOrder']->id,
			'payment_type'  => $payment_type,
			'shop_id'       => TSBuyerProtection::$CERTIFICATES[$lang]['tsID']
		);

		TSBuyerProtection::$smarty->assign(
			array(
				'tax_label' => 'TTC',
				'buyer_protection' => $arr_params
			)
		);

		return $this->display(TSBuyerProtection::$module_name, 'order-confirmation-tsbp-classic.tpl');
	}

	/**
	 * Order confirmation displaying and actions depend on the certificate type.
	 *
	 * @uses TSBuyerProtection::_orderConfirmationClassic() for Classic certificate
	 * @uses TSBuyerProtection::_orderConfirmationExcellence for Excellence certificate.
	 * @param array $params
	 * @return string depend on which certificate is used.
	 */
	public function hookOrderConfirmation($params)
	{
		$lang = strtoupper(Language::getIsoById($params['objOrder']->id_lang));

		// Security check to avoid any useless warning, a certficate tab will always exist for a configured language
		if (!isset(TSBuyerProtection::$CERTIFICATES[$lang]) || !count(TSBuyerProtection::$CERTIFICATES[$lang]))
			return '';

		if (((TSBuyerProtection::$CERTIFICATES[$lang]['user'] != '' &&
				TSBuyerProtection::$CERTIFICATES[$lang]['password'] != '') &&
				TSBuyerProtection::$CERTIFICATES[$lang]['typeEnum'] == 'EXCELLENCE'))
			return $this->_orderConfirmationExcellence($params, $lang);

		else if ((TSBuyerProtection::$CERTIFICATES[$lang]['stateEnum'] == 'INTEGRATION' ||
				TSBuyerProtection::$CERTIFICATES[$lang]['stateEnum'] == 'PRODUCTION' ||
				TSBuyerProtection::$CERTIFICATES[$lang]['stateEnum'] == 'TEST') &&
				TSBuyerProtection::$CERTIFICATES[$lang]['typeEnum'] == 'CLASSIC')
			return $this->_orderConfirmationClassic($params, $lang);

		return '';
	}
}
