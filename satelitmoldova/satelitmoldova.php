<?php
/**
 * Satelit Moldova for PrestaShop 1.7.8 and 8.x.
 *
 * - BNM exchange rates into the shop's currencies (MDL handled, any default
 *   currency): "Update rates now" in the configuration and a cron URL with a token.
 * - "Market price" on product pages (hook displayProductAdditionalInfo): Satelit
 *   offers from Moldovan shops for the product, cached.
 *
 * Copyright (c) 2026 Satelit (satelit.md). MIT licence.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

require_once __DIR__ . '/classes/SatelitMoldovaStore.php';

class SatelitMoldova extends Module
{
    const LANGS = array('auto', 'ro', 'ru', 'en', 'uk');

    public function __construct()
    {
        $this->name = 'satelitmoldova';
        $this->tab = 'pricing_promotion';
        $this->version = '1.0.0';
        $this->author = 'Satelit';
        $this->need_instance = 0;
        $this->ps_versions_compliancy = array('min' => '1.7.8.0', 'max' => '8.99.99');
        $this->bootstrap = true;
        parent::__construct();

        $this->displayName = $this->l('Satelit Moldova');
        $this->description = $this->l('BNM exchange rates for your currencies and market prices from Moldovan shops on product pages, through the Satelit API.');
        $this->confirmUninstall = $this->l('Remove the Satelit settings, cache and search terms?');
    }

    public function install()
    {
        return parent::install()
            && SatelitMoldovaStore::install()
            && $this->registerHook('displayProductAdditionalInfo')
            && $this->registerHook('displayHeader')
            && $this->registerHook('displayAdminProductsExtra')
            && $this->registerHook('actionProductUpdate')
            && $this->registerHook('actionProductAdd')
            && Configuration::updateValue('SATELIT_API_KEY', '')
            && Configuration::updateValue('SATELIT_LANG', 'auto')
            && Configuration::updateValue('SATELIT_RATES_TTL', 3)
            && Configuration::updateValue('SATELIT_PRICES_TTL', 6)
            && Configuration::updateValue('SATELIT_OFFERS', 5)
            && Configuration::updateValue('SATELIT_MARKET_ON', 1)
            && Configuration::updateValue('SATELIT_CRON_TOKEN', bin2hex(random_bytes(16)));
    }

    public function uninstall()
    {
        foreach (array('SATELIT_API_KEY', 'SATELIT_LANG', 'SATELIT_RATES_TTL', 'SATELIT_PRICES_TTL', 'SATELIT_OFFERS', 'SATELIT_MARKET_ON', 'SATELIT_CRON_TOKEN') as $k) {
            Configuration::deleteByName($k);
        }
        return SatelitMoldovaStore::uninstall() && parent::uninstall();
    }

    public function cronUrl()
    {
        return $this->context->link->getModuleLink($this->name, 'cron', array('token' => Configuration::get('SATELIT_CRON_TOKEN')), true);
    }

    // ---- configuration page ----

    public function getContent()
    {
        $out = '';
        // PrestaShop checks the AdminModules token of this page; configure rights are checked here.
        if ((Tools::isSubmit('submitSatelit') || Tools::isSubmit('satelitTest') || Tools::isSubmit('satelitRates') || Tools::isSubmit('submitSatelitTerm'))
            && !$this->getPermission('configure')) {
            return $this->displayError($this->l('You do not have permission to configure this module.'));
        }
        // HelperForm always posts submitSatelit as a hidden field: the two extra buttons go first.
        if (Tools::isSubmit('submitSatelitTerm')) {
            $out .= $this->saveTermForm();
        } elseif (Tools::isSubmit('satelitTest')) {
            $out .= $this->testConnection();
        } elseif (Tools::isSubmit('satelitRates')) {
            $r = SatelitMoldovaStore::refreshRates(true);
            $out .= $r['ok'] ? $this->displayConfirmation(Tools::safeOutput($r['message'])) : $this->displayError(Tools::safeOutput($r['message']));
        } elseif (Tools::isSubmit('submitSatelit')) {
            $out .= $this->saveConfig();
        }
        return $out . $this->renderForm() . $this->renderInfo() . $this->renderTerms();
    }

    /**
     * Search terms by product ID. The product page form of PrestaShop 1.7 and 8 (legacy
     * page) has the field in its Modules tab; the newer product page of 8.1+ does not
     * show module fields, so terms can always be set here too.
     */
    private function saveTermForm()
    {
        $id = (int)Tools::getValue('satelit_term_product');
        if ($id <= 0 || !Validate::isLoadedObject(new Product($id))) {
            return $this->displayError($this->l('No product with this ID.'));
        }
        SatelitMoldovaStore::saveProductTerm($id, (string)Tools::getValue('satelit_term'));
        SatelitMoldovaStore::clearCache();
        return $this->displayConfirmation($this->l('Search term saved.'));
    }

    private function renderTerms()
    {
        $rows = Db::getInstance()->executeS('SELECT t.`id_product`, t.`term`, pl.`name` FROM `' . _DB_PREFIX_ . 'satelit_product` t
            LEFT JOIN `' . _DB_PREFIX_ . 'product_lang` pl ON pl.`id_product` = t.`id_product` AND pl.`id_lang` = ' . (int)$this->context->language->id
            . ' AND pl.`id_shop` = ' . (int)$this->context->shop->id . ' ORDER BY t.`id_product` LIMIT 200');
        $this->context->smarty->assign(array(
            'satelit_terms' => $rows ? $rows : array(),
            'satelit_action' => AdminController::$currentIndex . '&configure=' . $this->name . '&token=' . Tools::getAdminTokenLite('AdminModules'),
        ));
        return $this->display(__FILE__, 'views/templates/admin/terms.tpl');
    }

    private function saveConfig()
    {
        $errors = array();
        $key = trim((string)Tools::getValue('SATELIT_API_KEY'));
        if ($key !== '' && !preg_match('~^sat_[A-Za-z0-9_]{8,}$~', $key)) {
            $errors[] = $this->l('A Satelit key starts with sat_ (for example sat_live_...).');
        }
        $ranges = array('SATELIT_RATES_TTL' => array(1, 24), 'SATELIT_PRICES_TTL' => array(1, 48), 'SATELIT_OFFERS' => array(1, 10));
        foreach ($ranges as $name => $r) {
            $v = (string)Tools::getValue($name);
            if (!ctype_digit($v) || (int)$v < $r[0] || (int)$v > $r[1]) {
                $errors[] = sprintf($this->l('%s: a whole number from %d to %d.'), $this->fieldLabel($name), $r[0], $r[1]);
            }
        }
        $lang = (string)Tools::getValue('SATELIT_LANG');
        if (!in_array($lang, self::LANGS, true)) {
            $lang = 'auto';
        }
        if ($errors) {
            return $this->displayError(implode('<br>', array_map('Tools::safeOutput', $errors)));
        }
        // An empty key field keeps the saved key: the form never shows it.
        if ($key !== '') {
            Configuration::updateValue('SATELIT_API_KEY', $key);
        }
        Configuration::updateValue('SATELIT_LANG', $lang);
        foreach ($ranges as $name => $r) {
            Configuration::updateValue($name, (int)Tools::getValue($name));
        }
        Configuration::updateValue('SATELIT_MARKET_ON', (int)(bool)Tools::getValue('SATELIT_MARKET_ON'));
        SatelitMoldovaStore::clearCache();
        return $this->displayConfirmation($this->l('Settings saved.'));
    }

    private function testConnection()
    {
        $key = trim((string)Tools::getValue('SATELIT_API_KEY'));
        if ($key === '') {
            $key = (string)Configuration::get('SATELIT_API_KEY');
        }
        if ($key === '') {
            return $this->displayError($this->l('Enter an API key first.'));
        }
        $res = SatelitMoldovaStore::client('en', $key)->me();
        if (!$res['ok']) {
            return $this->displayError(Tools::safeOutput($res['error']['code'] . ': ' . $res['error']['message']));
        }
        if (empty($res['data']['plan'])) {
            return $this->displayError($this->l('The key works, but its account has no plan: choose one at developer.satelit.md/en/pricing/.'));
        }
        $c = $res['data']['credits'];
        return $this->displayConfirmation(Tools::safeOutput(sprintf($this->l('Connected: %s plan, %s of %s credits left, resets %s.'),
            $res['data']['plan'], number_format((int)$c['limit'] - (int)$c['used'], 0, '.', ' '), number_format((int)$c['limit'], 0, '.', ' '), Tools::substr((string)$c['reset'], 0, 10))));
    }

    private function fieldLabel($name)
    {
        $labels = array(
            'SATELIT_RATES_TTL' => $this->l('Rates: refresh after (hours)'),
            'SATELIT_PRICES_TTL' => $this->l('Market prices: cache for (hours)'),
            'SATELIT_OFFERS' => $this->l('Shops shown'),
        );
        return isset($labels[$name]) ? $labels[$name] : $name;
    }

    private function renderForm()
    {
        $saved = SatelitMoldovaStore::hasKey();
        $langs = array(
            array('id' => 'auto', 'name' => $this->l('Language of the shopper')),
            array('id' => 'ro', 'name' => 'Română'), array('id' => 'ru', 'name' => 'Русский'),
            array('id' => 'en', 'name' => 'English'), array('id' => 'uk', 'name' => 'Українська'),
        );
        $form = array('form' => array(
            'legend' => array('title' => $this->l('Settings'), 'icon' => 'icon-cogs'),
            'description' => $this->l('Keys: developer.satelit.md/en/console/ · plans: developer.satelit.md/en/pricing/. The key stays on the server: shop pages never see it.'),
            'input' => array(
                array('type' => 'password', 'name' => 'SATELIT_API_KEY', 'label' => $this->l('API key'),
                    'desc' => $saved ? $this->l('A key is saved. Leave this field empty to keep it.') : 'sat_live_...'),
                array('type' => 'select', 'name' => 'SATELIT_LANG', 'label' => $this->l('Data language'),
                    'options' => array('query' => $langs, 'id' => 'id', 'name' => 'name')),
                array('type' => 'text', 'name' => 'SATELIT_RATES_TTL', 'label' => $this->fieldLabel('SATELIT_RATES_TTL'), 'class' => 'fixed-width-sm',
                    'desc' => $this->l('The cron URL asks the API only when the last rates are older than this (1 credit per update).')),
                array('type' => 'switch', 'name' => 'SATELIT_MARKET_ON', 'label' => $this->l('Market prices on product pages'), 'is_bool' => true,
                    'values' => array(array('id' => 'on', 'value' => 1, 'label' => $this->l('Yes')), array('id' => 'off', 'value' => 0, 'label' => $this->l('No'))),
                    'desc' => $this->l('Shown under the add to cart button. Each product can have its own search term (below, or in the product page, Modules tab); otherwise its name is used. One lookup costs 5 credits and is cached.')),
                array('type' => 'text', 'name' => 'SATELIT_PRICES_TTL', 'label' => $this->fieldLabel('SATELIT_PRICES_TTL'), 'class' => 'fixed-width-sm'),
                array('type' => 'text', 'name' => 'SATELIT_OFFERS', 'label' => $this->fieldLabel('SATELIT_OFFERS'), 'class' => 'fixed-width-sm'),
            ),
            'submit' => array('title' => $this->l('Save'), 'name' => 'submitSatelit'),
            'buttons' => array(
                array('title' => $this->l('Test connection'), 'name' => 'satelitTest', 'type' => 'submit', 'icon' => 'process-icon-ok', 'class' => 'btn btn-default pull-right'),
                array('title' => $this->l('Update rates now'), 'name' => 'satelitRates', 'type' => 'submit', 'icon' => 'process-icon-refresh', 'class' => 'btn btn-default pull-right'),
            ),
        ));

        $helper = new HelperForm();
        $helper->module = $this;
        $helper->name_controller = $this->name;
        $helper->table = $this->name;
        $helper->identifier = $this->identifier;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = AdminController::$currentIndex . '&configure=' . $this->name;
        $helper->default_form_language = (int)Configuration::get('PS_LANG_DEFAULT');
        $helper->submit_action = 'submitSatelit';
        $helper->fields_value = array(
            'SATELIT_API_KEY' => '',
            'SATELIT_LANG' => Tools::getValue('SATELIT_LANG', Configuration::get('SATELIT_LANG')),
            'SATELIT_RATES_TTL' => Tools::getValue('SATELIT_RATES_TTL', Configuration::get('SATELIT_RATES_TTL')),
            'SATELIT_PRICES_TTL' => Tools::getValue('SATELIT_PRICES_TTL', Configuration::get('SATELIT_PRICES_TTL')),
            'SATELIT_OFFERS' => Tools::getValue('SATELIT_OFFERS', Configuration::get('SATELIT_OFFERS')),
            'SATELIT_MARKET_ON' => Tools::getValue('SATELIT_MARKET_ON', Configuration::get('SATELIT_MARKET_ON')),
        );
        return $helper->generateForm(array($form));
    }

    private function renderInfo()
    {
        $default = Currency::getDefaultCurrency();
        $this->context->smarty->assign(array(
            'satelit_last' => SatelitMoldovaStore::lastLog(),
            'satelit_cron' => $this->cronUrl(),
            'satelit_default' => $default ? $default->iso_code : '',
            'satelit_has_mdl' => (bool)Currency::getIdByIsoCode('MDL'),
        ));
        return $this->display(__FILE__, 'views/templates/admin/info.tpl');
    }

    // ---- product page ----

    public function hookDisplayHeader()
    {
        if (isset($this->context->controller->php_self) && $this->context->controller->php_self === 'product' && Configuration::get('SATELIT_MARKET_ON')) {
            $this->context->controller->registerStylesheet('satelitmoldova', 'modules/' . $this->name . '/views/css/market.css', array('media' => 'all', 'priority' => 200));
        }
    }

    public function hookDisplayProductAdditionalInfo($params)
    {
        if (!Configuration::get('SATELIT_MARKET_ON')) {
            return '';
        }
        $product = isset($params['product']) ? $params['product'] : null;
        $id = $product && isset($product['id_product']) ? (int)$product['id_product'] : (int)Tools::getValue('id_product');
        $name = $product && isset($product['name']) ? (string)$product['name'] : '';
        if ($id <= 0) {
            return '';
        }
        try {
            $item = SatelitMoldovaStore::market($id, $name, SatelitMoldovaStore::lang($this->context->language->iso_code));
        } catch (Throwable $e) {
            // A broken API or table never breaks a product page.
            PrestaShopLogger::addLog('Satelit market prices: ' . $e->getMessage(), 2);
            return '';
        }
        if (!$item) {
            return '';
        }
        $offers = array();
        foreach ($item['offers'] as $o) {
            $offers[] = array('store' => $o['store'], 'price' => $this->price($o['price']), 'out' => $o['in_stock'] === false);
        }
        $this->context->smarty->assign(array(
            'satelit_item' => array(
                'name' => $item['name'],
                'min' => $item['min'] !== null ? $this->price($item['min']) : '',
                'median' => $item['median'] !== null ? $this->price($item['median']) : '',
                'stores' => (int)$item['stores'],
            ),
            'satelit_offers' => $offers,
        ));
        return $this->display(__FILE__, 'views/templates/hook/market.tpl');
    }

    /** Prices come in MDL: shown in the shopper's currency when the shop has MDL. */
    private function price($mdl)
    {
        try {
            $idMdl = (int)Currency::getIdByIsoCode('MDL');
            $to = $this->context->currency;
            if ($idMdl && $to && Validate::isLoadedObject($to)) {
                $amount = Tools::convertPriceFull((float)$mdl, new Currency($idMdl), $to);
                return $this->context->getCurrentLocale()->formatPrice($amount, $to->iso_code);
            }
        } catch (Throwable $e) {
            // fall through to plain lei
        }
        return number_format((float)$mdl, 2, '.', ' ') . ' lei';
    }

    // ---- per-product search term ----

    public function hookDisplayAdminProductsExtra($params)
    {
        $id = isset($params['id_product']) ? (int)$params['id_product'] : (int)Tools::getValue('id_product');
        $this->context->smarty->assign(array('satelit_term' => $id ? SatelitMoldovaStore::productTerm($id) : ''));
        return $this->display(__FILE__, 'views/templates/admin/product_tab.tpl');
    }

    public function hookActionProductUpdate($params)
    {
        $this->saveTerm($params);
    }

    public function hookActionProductAdd($params)
    {
        $this->saveTerm($params);
    }

    private function saveTerm($params)
    {
        // Only when the product form sent the field (imports and API updates do not).
        if (Tools::getIsset('satelit_term')) {
            $id = isset($params['id_product']) ? (int)$params['id_product'] : (isset($params['product']->id) ? (int)$params['product']->id : 0);
            SatelitMoldovaStore::saveProductTerm($id, (string)Tools::getValue('satelit_term'));
        }
    }
}
