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
*  @license    http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
*  International Registered Trademark & Property of PrestaShop SA
*/

if (!defined('_PS_VERSION_'))
	exit;

/*
* TODO:
*
* - Bad behaviour when an order is cancelled after an order return
* - We shouldn't use $cookie->id_currency in all situations
*/
	
class Loyalty extends Module
{
	public function __construct()
	{
		$this->name = 'loyalty';
		$this->tab = 'pricing_promotion';
		$this->version = '1.8';
		$this->author = 'PrestaShop';
		$this->need_instance = 0;

		parent::__construct();

		$this->displayName = $this->l('Customer loyalty and rewards');
		$this->description = $this->l('Provide a loyalty program to your customers.');
		$this->confirmUninstall = $this->l('Are you sure you want to delete all loyalty points and customer history?');
	}

	private function instanceDefaultStates()
	{
		include_once(dirname(__FILE__).'/LoyaltyStateModule.php');
		
		/* Recover default loyalty status save at module installation */
		$this->loyaltyStateDefault = new LoyaltyStateModule(LoyaltyStateModule::getDefaultId());
		$this->loyaltyStateValidation = new LoyaltyStateModule(LoyaltyStateModule::getValidationId());
		$this->loyaltyStateCancel = new LoyaltyStateModule(LoyaltyStateModule::getCancelId());
		$this->loyaltyStateConvert = new LoyaltyStateModule(LoyaltyStateModule::getConvertId());
		$this->loyaltyStateNoneAward = new LoyaltyStateModule(LoyaltyStateModule::getNoneAwardId());
	}

	public function install()
	{
		include_once(dirname(__FILE__).'/LoyaltyStateModule.php');
		
		if (!parent::install() OR !$this->installDB() OR !$this->registerHook('extraRight') OR !$this->registerHook('updateOrderStatus')
		OR !$this->registerHook('newOrder')	OR !$this->registerHook('adminCustomers') OR !$this->registerHook('shoppingCart')
		OR !$this->registerHook('orderReturn') OR !$this->registerHook('cancelProduct')	OR !$this->registerHook('customerAccount') 
		OR !Configuration::updateValue('PS_LOYALTY_POINT_VALUE', '0.02') OR !Configuration::updateValue('PS_LOYALTY_MINIMAL', 0)				
		OR !Configuration::updateValue('PS_LOYALTY_POINT_RATE', '1') OR !Configuration::updateValue('PS_LOYALTY_NONE_AWARD', '1'))
			return false;
		
		$defaultTranslations = array('en' => 'Loyalty reward', 'fr' => 'R??compense fid??lit??');
		$conf = array((int)Configuration::get('PS_LANG_DEFAULT') => $this->l('Loyalty reward'));
		foreach (Language::getLanguages() AS $language)
			if (isset($defaultTranslations[$language['iso_code']]))
				$conf[(int)$language['id_lang']] = $defaultTranslations[$language['iso_code']];
		Configuration::updateValue('PS_LOYALTY_VOUCHER_DETAILS', $conf);

		$category_config = '';
		$categories = Category::getSimpleCategories((int)(Configuration::get('PS_LANG_DEFAULT')));
		foreach ($categories AS $category)
			$category_config .= (int)$category['id_category'].',';
		$category_config = rtrim($category_config, ',');
		Configuration::updateValue('PS_LOYALTY_VOUCHER_CATEGORY', $category_config);

		/* This hook is optional */
		$this->registerHook('myAccountBlock');
		if (!LoyaltyStateModule::insertDefaultData())
			return false;
		return true;
	}

	public function installDB()
	{
		Db::getInstance()->Execute('
		CREATE TABLE `'._DB_PREFIX_.'loyalty` (
			`id_loyalty` INT UNSIGNED NOT NULL AUTO_INCREMENT,
			`id_loyalty_state` INT UNSIGNED NOT NULL DEFAULT 1,
			`id_customer` INT UNSIGNED NOT NULL,
			`id_order` INT UNSIGNED DEFAULT NULL,
			`id_discount` INT UNSIGNED DEFAULT NULL,
			`points` INT NOT NULL DEFAULT 0,
			`date_add` DATETIME NOT NULL,
			`date_upd` DATETIME NOT NULL,
			PRIMARY KEY (`id_loyalty`),
			INDEX index_loyalty_loyalty_state (`id_loyalty_state`),
			INDEX index_loyalty_order (`id_order`),
			INDEX index_loyalty_discount (`id_discount`),
			INDEX index_loyalty_customer (`id_customer`)
		) DEFAULT CHARSET=utf8 ;');

		Db::getInstance()->Execute('
		CREATE TABLE `'._DB_PREFIX_.'loyalty_history` (
			`id_loyalty_history` INT UNSIGNED NOT NULL AUTO_INCREMENT,
			`id_loyalty` INT UNSIGNED DEFAULT NULL,
			`id_loyalty_state` INT UNSIGNED NOT NULL DEFAULT 1,
			`points` INT NOT NULL DEFAULT 0,
			`date_add` DATETIME NOT NULL,
			PRIMARY KEY (`id_loyalty_history`),
			INDEX `index_loyalty_history_loyalty` (`id_loyalty`),
			INDEX `index_loyalty_history_loyalty_state` (`id_loyalty_state`)
		) DEFAULT CHARSET=utf8 ;');

		Db::getInstance()->Execute('
		CREATE TABLE `'._DB_PREFIX_.'loyalty_state` (
			`id_loyalty_state` INT UNSIGNED NOT NULL AUTO_INCREMENT,
			`id_order_state` INT UNSIGNED DEFAULT NULL,
			PRIMARY KEY (`id_loyalty_state`),
			INDEX index_loyalty_state_order_state (`id_order_state`)
		) DEFAULT CHARSET=utf8 ;');

		Db::getInstance()->Execute('
		CREATE TABLE `'._DB_PREFIX_.'loyalty_state_lang` (
			`id_loyalty_state` INT UNSIGNED NOT NULL AUTO_INCREMENT,
			`id_lang` INT UNSIGNED NOT NULL,
			`name` varchar(64) NOT NULL,
			UNIQUE KEY `index_unique_loyalty_state_lang` (`id_loyalty_state`,`id_lang`)
		) DEFAULT CHARSET=utf8 ;');

		return true;
	}
	
	public function uninstall()
	{
		if (!parent::uninstall() OR !$this->uninstallDB() OR !Configuration::deleteByName('PS_LOYALTY_POINT_VALUE')	OR !Configuration::deleteByName('PS_LOYALTY_POINT_RATE')
		OR !Configuration::deleteByName('PS_LOYALTY_NONE_AWARD') OR !Configuration::deleteByName('PS_LOYALTY_MINIMAL') OR !Configuration::deleteByName('PS_LOYALTY_VOUCHER_CATEGORY')
		OR !Configuration::deleteByName('PS_LOYALTY_VOUCHER_DETAILS'))
			return false;
		return true;
	}

	public function uninstallDB()
	{
		Db::getInstance()->Execute('DROP TABLE `'._DB_PREFIX_.'loyalty`;');
		Db::getInstance()->Execute('DROP TABLE `'._DB_PREFIX_.'loyalty_state`;');
		Db::getInstance()->Execute('DROP TABLE `'._DB_PREFIX_.'loyalty_state_lang`;');
		Db::getInstance()->Execute('DROP TABLE `'._DB_PREFIX_.'loyalty_history`;');

		return true;
	}

	private function _postProcess()
	{
		if (Tools::isSubmit('submitLoyalty'))
		{
			$defaultLanguage = (int)(Configuration::get('PS_LANG_DEFAULT'));
			$languages = Language::getLanguages();
			
			$this->_errors = array();
			if (!is_array(Tools::getValue('categoryBox')) OR !sizeof(Tools::getValue('categoryBox')))
				$this->_errors[] = $this->l('You must choose at least one category in order to generate discount vouchers');
			if (!sizeof($this->_errors))
			{
				Configuration::updateValue('PS_LOYALTY_VOUCHER_CATEGORY', $this->voucherCategories(Tools::getValue('categoryBox')));
				Configuration::updateValue('PS_LOYALTY_POINT_VALUE', (float)(Tools::getValue('point_value')));
				Configuration::updateValue('PS_LOYALTY_POINT_RATE', (float)(Tools::getValue('point_rate')));
				Configuration::updateValue('PS_LOYALTY_NONE_AWARD', (int)(Tools::getValue('PS_LOYALTY_NONE_AWARD')));
				Configuration::updateValue('PS_LOYALTY_MINIMAL', (float)(Tools::getValue('minimal')));
				
				$this->loyaltyStateValidation->id_order_state = (int)(Tools::getValue('id_order_state_validation'));
				$this->loyaltyStateCancel->id_order_state = (int)(Tools::getValue('id_order_state_cancel'));
			
				$arrayVoucherDetails = array();
				foreach ($languages AS $language)
				{
					$arrayVoucherDetails[(int)($language['id_lang'])] = Tools::getValue('voucher_details_'.(int)($language['id_lang']));
					$this->loyaltyStateDefault->name[(int)($language['id_lang'])] = Tools::getValue('default_loyalty_state_'.(int)($language['id_lang']));
					$this->loyaltyStateValidation->name[(int)($language['id_lang'])] = Tools::getValue('validation_loyalty_state_'.(int)($language['id_lang']));
					$this->loyaltyStateCancel->name[(int)($language['id_lang'])] = Tools::getValue('cancel_loyalty_state_'.(int)($language['id_lang']));
					$this->loyaltyStateConvert->name[(int)($language['id_lang'])] = Tools::getValue('convert_loyalty_state_'.(int)($language['id_lang']));
					$this->loyaltyStateNoneAward->name[(int)($language['id_lang'])] = Tools::getValue('none_award_loyalty_state_'.(int)($language['id_lang']));
				}
				if (empty($arrayVoucherDetails[$defaultLanguage]))
					$arrayVoucherDetails[$defaultLanguage] = ' ';
				Configuration::updateValue('PS_LOYALTY_VOUCHER_DETAILS', $arrayVoucherDetails);
			
				if (empty($this->loyaltyStateDefault->name[$defaultLanguage]))
					$this->loyaltyStateDefault->name[$defaultLanguage] = ' ';
				$this->loyaltyStateDefault->save();
			
				if (empty($this->loyaltyStateValidation->name[$defaultLanguage]))
					$this->loyaltyStateValidation->name[$defaultLanguage] = ' ';
				$this->loyaltyStateValidation->save();
			
				if (empty($this->loyaltyStateCancel->name[$defaultLanguage]))
					$this->loyaltyStateCancel->name[$defaultLanguage] = ' ';
				$this->loyaltyStateCancel->save();
			
				if (empty($this->loyaltyStateConvert->name[$defaultLanguage]))
					$this->loyaltyStateConvert->name[$defaultLanguage] = ' ';
				$this->loyaltyStateConvert->save();
			
				if (empty($this->loyaltyStateNoneAward->name[$defaultLanguage]))
					$this->loyaltyStateNoneAward->name[$defaultLanguage] = ' ';
				$this->loyaltyStateNoneAward->save();

				echo $this->displayConfirmation($this->l('Settings updated.'));
			}
			else
			{
				$errors = '';
				foreach ($this->_errors AS $error)
					$errors .= $error.'<br />';
				echo $this->displayError($errors);
			}
		}
	}

	private function voucherCategories($categories)
	{
		$cat = '';
		if ($categories)
			foreach ($categories AS $category)
				$cat .= $category.',';
		return rtrim($cat, ',');
	}

	public function getContent()
	{
		global $cookie;

		$this->instanceDefaultStates();
		$this->_postProcess();

		$categories = Category::getCategories((int)($cookie->id_lang));
		$order_states = OrderState::getOrderStates((int)$cookie->id_lang);
		$currency = new Currency((int)(Configuration::get('PS_CURRENCY_DEFAULT')));
		$defaultLanguage = (int)(Configuration::get('PS_LANG_DEFAULT'));
		$languages = Language::getLanguages(false);
		$languageIds = 'voucher_details??default_loyalty_state??none_award_loyalty_state??convert_loyalty_state??validation_loyalty_state??cancel_loyalty_state';

		$html = '
		<script type="text/javascript">
			id_language = Number('.$defaultLanguage.');
		</script>
		<h2>'.$this->l('Loyalty Program').'</h2>
		<form action="'.Tools::safeOutput($_SERVER['REQUEST_URI']).'" method="post">
			<fieldset>
				<legend>'.$this->l('Settings').'</legend>
				
				<label>'.$this->l('Ratio').'</label>
				<div class="margin-form">
					<input type="text" size="2" id="point_rate" name="point_rate" value="'.(float)(Configuration::get('PS_LOYALTY_POINT_RATE')).'" /> '.$currency->sign.'
					<label for="point_rate" class="t"> = '.$this->l('1 reward point').'.</label>
					<br />
					<label for="point_value" class="t">'.$this->l('1 point = ').'</label>
					<input type="text" size="2" name="point_value" id="point_value" value="'.(float)(Configuration::get('PS_LOYALTY_POINT_VALUE')).'" /> '.$currency->sign.'
					<label for="point_value" class="t">'.$this->l('for the discount').'.</label>
				</div>
				<div class="clear"></div>
				<label>'.$this->l('Voucher details').'</label>
				<div class="margin-form">';
		foreach ($languages as $language)
			$html .= '
					<div id="voucher_details_'.$language['id_lang'].'" style="display: '.($language['id_lang'] == $defaultLanguage ? 'block' : 'none').'; float: left;">
						<input size="33" type="text" name="voucher_details_'.$language['id_lang'].'" value="'.Configuration::get('PS_LOYALTY_VOUCHER_DETAILS', (int)($language['id_lang'])).'" />
					</div>';
		$html .= $this->displayFlags($languages, $defaultLanguage, $languageIds, 'voucher_details', true);
		$html .= '	</div>
				<div class="clear" style="margin-top: 20px"></div>
				<label>'.$this->l('Minimum amount in which the voucher can be used').'</label>
				<div class="margin-form">
					<input type="text" size="2" name="minimal" value="'.(float)(Configuration::get('PS_LOYALTY_MINIMAL')).'" /> '.$currency->sign.'
				</div>
				<div class="clear" style="margin-top: 20px"></div>
				<label>'.$this->l('Give points on discounted products').' </label>
				<div class="margin-form">
					<input type="radio" name="PS_LOYALTY_NONE_AWARD" id="PS_LOYALTY_NONE_AWARD_on" value="1" '.(Configuration::get('PS_LOYALTY_NONE_AWARD') ? 'checked="checked" ' : '').'/>
					<label class="t" for="PS_LOYALTY_NONE_AWARD_on"><img src="../img/admin/enabled.gif" alt="'.$this->l('Enabled').'" title="'.$this->l('Yes').'" /></label>
					<input type="radio" name="PS_LOYALTY_NONE_AWARD" id="PS_LOYALTY_NONE_AWARD_off" value="0" '.(!Configuration::get('PS_LOYALTY_NONE_AWARD') ? 'checked="checked" ' : '').'/>
					<label class="t" for="PS_LOYALTY_NONE_AWARD_off"><img src="../img/admin/disabled.gif" alt="'.$this->l('Disabled').'" title="'.$this->l('No').'" /></label>
					</div>
				<div class="clear"></div>
				<label>'.$this->l('Points are awarded when the order is').'</label>
				<div class="margin-form" style="margin-top:10px">
					<select id="id_order_state_validation" name="id_order_state_validation">';
		foreach ($order_states AS $order_state)
		{
			$html .= '<option value="' . $order_state['id_order_state'] . '" style="background-color:' . $order_state['color'] . ';"';
			if ((int)($this->loyaltyStateValidation->id_order_state) == $order_state['id_order_state'] )
				$html .= ' selected="selected"';
			$html .= '>' . $order_state['name'] . '</option>';
		}
		$html .= '</select>
				</div>
				<div class="clear"></div>
				<label>'.$this->l('Points are cancelled when the order is').'</label>
				<div class="margin-form" style="margin-top:10px">
					<select id="id_order_state_cancel" name="id_order_state_cancel">';
		foreach ($order_states AS $order_state)
		{
			$html .= '<option value="' . $order_state['id_order_state'] . '" style="background-color:' . $order_state['color'] . ';"';
			if ((int)($this->loyaltyStateCancel->id_order_state) == $order_state['id_order_state'] )
				$html .= ' selected="selected"';
			$html .= '>' . $order_state['name'] . '</option>';
		}
		$html .= '</select>
				</div>
				<div class="clear"></div>
				<label>'.$this->l('Vouchers created by the loyalty system can be used in the following categories :').'</label>';
		$index = explode(',', Configuration::get('PS_LOYALTY_VOUCHER_CATEGORY'));
		$indexedCategories =  isset($_POST['categoryBox']) ? $_POST['categoryBox'] : $index;
		// Translations are not automatic for the moment ;)
		$trads = array(
			 'Home' => $this->l('Home'), 
			 'selected' => $this->l('selected'), 
			 'Collapse All' => $this->l('Collapse All'), 
			 'Expand All' => $this->l('Expand All'), 
			 'Check All' => $this->l('Check All'), 
			 'Uncheck All'  => $this->l('Uncheck All')
		);
		$html .= '<div class="margin-form">'.Helper::renderAdminCategorieTree($trads, $indexedCategories).'</div>';
		 $html .= '
				<p style="padding-left:200px;">'.$this->l('Mark the box(es) of categories in which loyalty vouchers are usable.').'</p>
				<div class="clear"></div>
				<h3 style="margin-top:20px">'.$this->l('Loyalty points progression').'</h3>
				<label>'.$this->l('Initial').'</label>
				<div class="margin-form">';
		foreach ($languages as $language)
			$html .= '
					<div id="default_loyalty_state_'.$language['id_lang'].'" style="display: '.($language['id_lang'] == $defaultLanguage ? 'block' : 'none').'; float: left;">
						<input size="33" type="text" name="default_loyalty_state_'.$language['id_lang'].'" value="'.(isset($this->loyaltyStateDefault->name[(int)($language['id_lang'])]) ? $this->loyaltyStateDefault->name[(int)($language['id_lang'])] : $this->loyaltyStateDefault->name[(int)$defaultLanguage]).'" />
					</div>';
		$html .= $this->displayFlags($languages, $defaultLanguage, $languageIds, 'default_loyalty_state', true);
		$html .= '	</div>
				<div class="clear"></div>
				<label>'.$this->l('Unavailable').'</label>
				<div class="margin-form">';
		foreach ($languages as $language)
			$html .= '
					<div id="none_award_loyalty_state_'.$language['id_lang'].'" style="display: '.($language['id_lang'] == $defaultLanguage ? 'block' : 'none').'; float: left;">
						<input size="33" type="text" name="none_award_loyalty_state_'.$language['id_lang'].'" value="'.(isset($this->loyaltyStateNoneAward->name[(int)($language['id_lang'])]) ? $this->loyaltyStateNoneAward->name[(int)($language['id_lang'])] : $this->loyaltyStateNoneAward->name[(int)$defaultLanguage]).'" />
					</div>';
		$html .= $this->displayFlags($languages, $defaultLanguage, $languageIds, 'none_award_loyalty_state', true);
		$html .= '	</div>
				<div class="clear"></div>
				<label>'.$this->l('Converted').'</label>
				<div class="margin-form">';
		foreach ($languages as $language)
			$html .= '
					<div id="convert_loyalty_state_'.$language['id_lang'].'" style="display: '.($language['id_lang'] == $defaultLanguage ? 'block' : 'none').'; float: left;">
						<input size="33" type="text" name="convert_loyalty_state_'.$language['id_lang'].'" value="'.(isset($this->loyaltyStateConvert->name[(int)($language['id_lang'])]) ? $this->loyaltyStateConvert->name[(int)($language['id_lang'])] : $this->loyaltyStateConvert->name[(int)$defaultLanguage]).'" />
					</div>';
		$html .= $this->displayFlags($languages, $defaultLanguage, $languageIds, 'convert_loyalty_state', true);
		$html .= '	</div>
				<div class="clear"></div>
				<label>'.$this->l('Validation').'</label>
				<div class="margin-form">';
		foreach ($languages as $language)
			$html .= '
					<div id="validation_loyalty_state_'.$language['id_lang'].'" style="display: '.($language['id_lang'] == $defaultLanguage ? 'block' : 'none').'; float: left;">
						<input size="33" type="text" name="validation_loyalty_state_'.$language['id_lang'].'" value="'.(isset($this->loyaltyStateValidation->name[(int)($language['id_lang'])]) ? $this->loyaltyStateValidation->name[(int)($language['id_lang'])] : $this->loyaltyStateValidation->name[(int)$defaultLanguage]).'" />
					</div>';
		$html .= $this->displayFlags($languages, $defaultLanguage, $languageIds, 'validation_loyalty_state', true);
		$html .= '	</div>
				<div class="clear"></div>
				<label>'.$this->l('Cancelled').'</label>
				<div class="margin-form">';
		foreach ($languages as $language)
			$html .= '
					<div id="cancel_loyalty_state_'.$language['id_lang'].'" style="display: '.($language['id_lang'] == $defaultLanguage ? 'block' : 'none').'; float: left;">
						<input size="33" type="text" name="cancel_loyalty_state_'.$language['id_lang'].'" value="'.(isset($this->loyaltyStateCancel->name[(int)($language['id_lang'])]) ? $this->loyaltyStateCancel->name[(int)($language['id_lang'])] : $this->loyaltyStateCancel->name[(int)$defaultLanguage]).'" />
					</div>';
		$html .= $this->displayFlags($languages, $defaultLanguage, $languageIds, 'cancel_loyalty_state', true);
		$html .= '	</div>
				<div class="clear center">
					<input type="submit" style="margin-top:20px" name="submitLoyalty" id="submitLoyalty" value="'.$this->l('   Save   ').'" class="button" />
				</div>
			</fieldset>
		</form>';

		return $html;
	}

	public static function recurseCategoryForInclude($id_obj, $indexedCategories, $categories, $current, $id_category = 1, $id_category_default = NULL, $has_suite = array())
	{
		global $done;
		static $irow;
		$html = '';

		if (!isset($done[$current['infos']['id_parent']]))
			$done[$current['infos']['id_parent']] = 0;
		$done[$current['infos']['id_parent']] += 1;

		$todo = sizeof($categories[$current['infos']['id_parent']]);
		$doneC = $done[$current['infos']['id_parent']];

		$level = $current['infos']['level_depth'] + 1;

		$html .= '
		<tr class="'.($irow++ % 2 ? 'alt_row' : '').'">
			<td>
				<input type="checkbox" name="categoryBox[]" class="categoryBox'.($id_category_default == $id_category ? ' id_category_default' : '').'" id="categoryBox_'.$id_category.'" value="'.$id_category.'"'.((in_array($id_category, $indexedCategories) OR ((int)(Tools::getValue('id_category')) == $id_category AND !(int)($id_obj))) ? ' checked="checked"' : '').' />
			</td>
			<td>
				'.$id_category.'
			</td>
			<td>';
			for ($i = 2; $i < $level; $i++)
				$html .= '<img src="../img/admin/lvl_'.$has_suite[$i - 2].'.gif" alt="" style="vertical-align: middle;"/>';
			$html .= '<img src="../img/admin/'.($level == 1 ? 'lv1.gif' : 'lv2_'.($todo == $doneC ? 'f' : 'b').'.gif').'" alt="" style="vertical-align: middle;"/> &nbsp;
			<label for="categoryBox_'.$id_category.'" class="t">'.stripslashes($current['infos']['name']).'</label></td>
		</tr>';

		if ($level > 1)
			$has_suite[] = ($todo == $doneC ? 0 : 1);
		if (isset($categories[$id_category]))
			foreach ($categories[$id_category] AS $key => $row)
				if ($key != 'infos')
					$html .= self::recurseCategoryForInclude($id_obj, $indexedCategories, $categories, $categories[$id_category][$key], $key, $id_category_default, $has_suite);
		return $html;
	}

	/* Hook display on product detail */
	public function hookExtraRight($params)
	{
		include_once(dirname(__FILE__).'/LoyaltyModule.php');
		
		global $smarty;

		$product = new Product((int)Tools::getValue('id_product'));
		if (Validate::isLoadedObject($product))
		{
			if (Validate::isLoadedObject($params['cart']))
			{
				$pointsBefore = (int)LoyaltyModule::getCartNbPoints($params['cart']);
				$pointsAfter = (int)LoyaltyModule::getCartNbPoints($params['cart'], $product);
				$points = (int)($pointsAfter - $pointsBefore);
			}
			else
			{
				if (!(int)Configuration::get('PS_LOYALTY_NONE_AWARD') && Product::isDiscounted((int)$product->id))
				{
					$points = 0;
					$smarty->assign('no_pts_discounted', 1);
				}
				else
				{
					global $cookie;
					if (isset($cookie->id_currency))
						$points = (int)LoyaltyModule::getNbPointsByPrice($product->getPrice(Product::getTaxCalculationMethod() == PS_TAX_EXC ? false : true, (int)$product->getIdProductAttributeMostExpensive()), (int)$cookie->id_currency);
					else
						$points = 0;
				}

				$pointsAfter = $points;
				$pointsBefore = 0;
			}
			$smarty->assign(array(
				'points' => (int)$points,
				'total_points' => (int)$pointsAfter,
				'point_rate' => Configuration::get('PS_LOYALTY_POINT_RATE'),
				'point_value' => Configuration::get('PS_LOYALTY_POINT_VALUE'),
				'points_in_cart' => (int)$pointsBefore,
				'voucher' => LoyaltyModule::getVoucherValue((int)$pointsAfter)));

			return $this->display(__FILE__, 'product.tpl');
		}

		return false;
	}

	/* Hook display on customer account page */
	public function hookCustomerAccount($params)
	{
		return $this->display(__FILE__, 'my-account.tpl');
	}
	
	public function hookMyAccountBlock($params)
	{
		return $this->hookCustomerAccount($params);
	}
	
	/* Catch product returns and substract loyalty points */
	public function hookOrderReturn($params)
	{
		if (!Validate::isLoadedObject($params['orderReturn']))
			return false;

		include_once(dirname(__FILE__).'/LoyaltyStateModule.php');
		include_once(dirname(__FILE__).'/LoyaltyModule.php');
		
		$totalPrice = 0;
		$details = OrderReturn::getOrdersReturnDetail((int)$params['orderReturn']->id);
		foreach ($details as $detail)
		{
			$price_wt = Db::getInstance()->getValue('
			SELECT product_price * (1 + (tax_rate / 100)) price
			FROM '._DB_PREFIX_.'order_detail od
			WHERE id_order_detail = '.(int)$detail['id_order_detail']);

			$totalPrice += number_format($price_wt, 2, '.', '') * $detail['product_quantity'];
		}
		
		if (!isset($params['order']) || !Validate::isLoadedObject($params['order']))
		{
			$params['order'] = new Order((int)$params['orderReturn']->id_order);
			if (!Validate::isLoadedObject($params['order']))
				return false;
		}
		
		$loyaltyNew = new LoyaltyModule();
		$loyaltyNew->points = (int)(-1 * LoyaltyModule::getNbPointsByPrice($totalPrice, (int)$params['order']->id_currency));
		$loyaltyNew->id_loyalty_state = (int)LoyaltyStateModule::getCancelId();
		$loyaltyNew->id_order = (int)$params['orderReturn']->id_order;
		$loyaltyNew->id_customer = (int)$params['orderReturn']->id_customer;
		$loyaltyNew->save();
	}

	/* Hook display on shopping cart summary */
	public function hookShoppingCart($params)
	{
		include_once(dirname(__FILE__).'/LoyaltyModule.php');
		
		global $smarty;

		if (Validate::isLoadedObject($params['cart']))
		{
			$points = LoyaltyModule::getCartNbPoints($params['cart']);
			$smarty->assign(array(
				 'points' => (int)$points, 
				 'voucher' => LoyaltyModule::getVoucherValue((int)$points),
				 'guest_checkout' => (int)Configuration::get('PS_GUEST_CHECKOUT_ENABLED')
			));
		} else {
			$smarty->assign(array('points' => 0));
		}
		
		return $this->display(__FILE__, 'shopping-cart.tpl');
	}

	/* Hook called when a new order is created */
	public function hookNewOrder($params)
	{
		include_once(dirname(__FILE__).'/LoyaltyStateModule.php');
		include_once(dirname(__FILE__).'/LoyaltyModule.php');
		
		if (!Validate::isLoadedObject($params['customer']) OR !Validate::isLoadedObject($params['order']))
			die(Tools::displayError('Missing parameters'));
		$loyalty = new LoyaltyModule();
		$loyalty->id_customer = (int)$params['customer']->id;
		$loyalty->id_order = (int)$params['order']->id;
		$loyalty->points = LoyaltyModule::getOrderNbPoints($params['order']);
		if (!Configuration::get('PS_LOYALTY_NONE_AWARD') AND (int)$loyalty->points == 0)
			$loyalty->id_loyalty_state = LoyaltyStateModule::getNoneAwardId();
		else
			$loyalty->id_loyalty_state = LoyaltyStateModule::getDefaultId();
		return $loyalty->save();
	}

	/* Hook called when an order change its status */
	public function hookUpdateOrderStatus($params)
	{
		include_once(dirname(__FILE__).'/LoyaltyStateModule.php');
		include_once(dirname(__FILE__).'/LoyaltyModule.php');
		
		if (!Validate::isLoadedObject($params['newOrderStatus']))
			die(Tools::displayError('Missing parameters'));
		$newOrder = $params['newOrderStatus'];
		$order = new Order((int)($params['id_order']));
		if ($order AND !Validate::isLoadedObject($order))
			die(Tools::displayError('Incorrect object Order.'));
		$this->instanceDefaultStates();
		
		$return = false;
		
		if ($newOrder->id == $this->loyaltyStateValidation->id_order_state OR $newOrder->id == $this->loyaltyStateCancel->id_order_state)
		{
			if (!Validate::isLoadedObject($loyalty = new LoyaltyModule(LoyaltyModule::getByOrderId($order->id))))
				return false;
			if ((int)Configuration::get('PS_LOYALTY_NONE_AWARD') AND $loyalty->id_loyalty_state == LoyaltyStateModule::getNoneAwardId())
				return true;

			if ($newOrder->id == $this->loyaltyStateValidation->id_order_state)
			{
				$loyalty->id_loyalty_state = LoyaltyStateModule::getValidationId();
				if ((int)($loyalty->points) < 0)
					$loyalty->points = abs((int)($loyalty->points));
				$return &= $loyalty->save();					
			}
			elseif ($newOrder->id == $this->loyaltyStateCancel->id_order_state)
			{
				$loyalties = LoyaltyModule::getAllByOrderId($order->id);
				if(is_array($loyalties))
					foreach($loyalties as $entity)
					{		
						$loyalty = new LoyaltyModule($entity['id_loyalty']);			
						$loyalty->id_loyalty_state = LoyaltyStateModule::getCancelId();
						$loyalty->points = 0;
						$return &= $loyalty->save();
					}
			}
			return $loyalty->save();
		}
		return $return;
	}

	/* Hook display in tab AdminCustomers on BO */
	public function hookAdminCustomers($params)
	{
		include_once(dirname(__FILE__).'/LoyaltyModule.php');
		include_once(dirname(__FILE__).'/LoyaltyStateModule.php');
		
		$customer = new Customer((int)$params['id_customer']);
		if ($customer AND !Validate::isLoadedObject($customer))
			die(Tools::displayError('Incorrect object Customer.'));

		$details = LoyaltyModule::getAllByIdCustomer((int)$params['id_customer'], (int)$params['cookie']->id_lang);
		$points = (int)LoyaltyModule::getPointsByCustomer((int)$params['id_customer']);

		$html = '
		<br /><h2>'.$this->l('Loyalty points').' ('.(int)$points.' '.$this->l('points').')</h2>';
		
		if (!isset($points) || count($details) == 0)
			return $html.' '.$this->l('This customer has no points');
		
		$html .= '
		<table cellspacing="0" cellpadding="0" class="table">
			<tr style="background-color:#F5E9CF; padding: 0.3em 0.1em;">
				<th>'.$this->l('Order').'</th>
				<th>'.$this->l('Date').'</th>
				<th>'.$this->l('Total (without shipping)').'</th>
				<th>'.$this->l('Points').'</th>
				<th>'.$this->l('Points Status').'</th>
			</tr>';
		foreach ($details AS $key => $loyalty)
		{
			$html.= '
			<tr style="background-color: '.($key % 2 != 0 ? '#FFF6CF' : '#FFFFFF').';">
				<td>'.((int)$loyalty['id'] > 0 ? '<a style="color: #268CCD; font-weight: bold; text-decoration: underline;" href="index.php?tab=AdminOrders&id_order='.$loyalty['id'].'&vieworder&token='.Tools::getAdminToken('AdminOrders'.(int)(Tab::getIdFromClassName('AdminOrders')).(int)($params['cookie']->id_employee)).'">'.$this->l('#').sprintf('%06d', $loyalty['id']).'</a>' : '--').'</td>
				<td>'.Tools::displayDate($loyalty['date'], (int)($params['cookie']->id_lang)).'</td>
				<td>'.((int)$loyalty['id'] > 0 ? $loyalty['total_without_shipping'] : '--').'</td>
				<td>'.(int)$loyalty['points'].'</td>
				<td>'.$loyalty['state'].'</td>
			</tr>';
		}
		$html.= '
			<tr>
				<td>&nbsp;</td>
				<td colspan="2" class="bold" style="text-align: right;">'.$this->l('Total points available:').'</td>
				<td>'.$points.'</td>
				<td>'.$this->l('Voucher value:').' '.Tools::displayPrice(LoyaltyModule::getVoucherValue((int)$points, (int)Configuration::get('PS_CURRENCY_DEFAULT')), new Currency((int)Configuration::get('PS_CURRENCY_DEFAULT'))).'</td>
			</tr>
		</table>';

		return $html;
	}
	
	public function hookCancelProduct($params)
	{
		global $cookie;
		include_once(dirname(__FILE__).'/LoyaltyStateModule.php');
		include_once(dirname(__FILE__).'/LoyaltyModule.php');
		
		$orderDetail = '';
		if (!isset($params['order_detail']) || !Validate::isLoadedObject($params['order_detail']))
		{	
			if (Validate::isLoadedObject($orderDetail = new OrderDetail((int)($params['id_order_detail']))))
				$orderDetail = get_object_vars($orderDetail);
		}
		else
			$orderDetail =  get_object_vars($params['order_detail']);	
			
		if (!Validate::isLoadedObject($order = $params['order']) || !Validate::isLoadedObject($loyalty = new LoyaltyModule((int)LoyaltyModule::getByOrderId((int)($params['order']->id)))))
			return false;
			
		if (is_array($orderDetail) && count($orderDetail))
				$order->setProductPrices($orderDetail);
		else
			return false;

		$qtyList = Tools::getValue('cancelQuantity');
		if (isset($qtyList[$orderDetail['id_order_detail']]))
			$qtyList = abs((int)$qtyList[$orderDetail['id_order_detail']]);
		else
			$qtyList =	(int)$orderDetail['product_quantity'];

		$product_price = $order->getTaxCalculationMethod() == PS_TAX_EXC ? $orderDetail['product_price'] + $orderDetail['ecotax'] : $orderDetail['product_price_wt'];
		$points = (int)LoyaltyModule::getNbPointsByPrice(number_format($product_price, 2, '.', ''), (int)$params['order']->id_currency) * $qtyList;

		if (!$order->hasBeenDelivered())
		{
			if ($points > $loyalty->points)
				$points = (int)$loyalty->points;		
			$loyalty->points = $loyalty->points - $points;
			return $loyalty->save();
		}
		else
		{
			$loyaltyNew = new LoyaltyModule();	
			$loyaltyNew->points = -1 * $points;
			$loyaltyNew->id_loyalty_state = (int)LoyaltyStateModule::getCancelId();
			$loyaltyNew->id_order = (int)$params['order']->id;
			$loyaltyNew->id_customer = (int)$loyalty->id_customer;
			return $loyaltyNew->add();
		}
		return false;
	}			
	
	public function getL($key)
	{
		$translations = array(
		'Awaiting validation' => $this->l('Awaiting validation'),
		'Available' => $this->l('Available'),
		'Cancelled' => $this->l('Cancelled'),
		'Already converted' => $this->l('Already converted'),
		'Unavailable on discounts' => $this->l('Unavailable on discounts'),
		'Not available on discounts.' => $this->l('Not available on discounts.'));

		return (array_key_exists($key, $translations)) ? $translations[$key] : $key;
	}
}
