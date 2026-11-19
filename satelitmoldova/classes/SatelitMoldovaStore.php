<?php
/**
 * Store side of the Satelit module for PrestaShop 1.7.8 and 8.x: the rate update,
 * the cache table and the per-product search terms.
 *
 * Copyright (c) 2026 Satelit (satelit.md). MIT licence.
 */
if (!defined('_PS_VERSION_') && !defined('SATELIT_TEST')) {
    exit;
}

require_once __DIR__ . '/satelit_client.php';

class SatelitMoldovaStore
{
    const DOWN_SECONDS = 600; // after a failed call, wait 10 min before the next one

    /** @var callable|null HTTP transport for tests */
    public static $transport = null;

    public static function install()
    {
        $db = Db::getInstance();
        return $db->execute('CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'satelit_cache` (
                `cache_key` VARCHAR(100) NOT NULL,
                `data` MEDIUMTEXT NOT NULL,
                `expires` INT NOT NULL DEFAULT 0,
                `updated` INT NOT NULL DEFAULT 0,
                PRIMARY KEY (`cache_key`)
            ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4')
            && $db->execute('CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'satelit_product` (
                `id_product` INT UNSIGNED NOT NULL,
                `term` VARCHAR(255) NOT NULL DEFAULT \'\',
                PRIMARY KEY (`id_product`)
            ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4');
    }

    public static function uninstall()
    {
        $db = Db::getInstance();
        return $db->execute('DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'satelit_cache`')
            && $db->execute('DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'satelit_product`');
    }

    public static function hasKey()
    {
        return trim((string)Configuration::get('SATELIT_API_KEY')) !== '';
    }

    /** API language: the setting, or the shopper's language when set to auto. */
    public static function lang($isoCode)
    {
        $lang = (string)Configuration::get('SATELIT_LANG');
        return SatelitMoldovaClient::langFromLocale($lang === '' || $lang === 'auto' ? $isoCode : $lang);
    }

    public static function client($lang = 'ro', $key = null, $timeout = 8)
    {
        return new SatelitMoldovaClient($key === null ? (string)Configuration::get('SATELIT_API_KEY') : $key, $lang, $timeout, self::$transport, null, 'satelit-prestashop');
    }

    // ---- cache ----

    /** Fresh value, or null. A stale copy, when there is one, goes into $stale. */
    public static function cacheGet($key, &$stale = null)
    {
        $stale = null;
        $row = Db::getInstance()->getRow('SELECT `data`, `expires` FROM `' . _DB_PREFIX_ . 'satelit_cache` WHERE `cache_key` = \'' . pSQL($key) . '\'', false);
        if (!$row) {
            return null;
        }
        $data = json_decode($row['data'], true);
        if ((int)$row['expires'] > time()) {
            return $data;
        }
        $stale = $data;
        return null;
    }

    public static function cacheSet($key, $data, $ttl)
    {
        $json = pSQL(json_encode($data), true);
        $exp = time() + (int)$ttl;
        return Db::getInstance()->execute('INSERT INTO `' . _DB_PREFIX_ . 'satelit_cache` (`cache_key`, `data`, `expires`, `updated`) VALUES (\''
            . pSQL($key) . '\', \'' . $json . '\', ' . $exp . ', ' . time() . ') ON DUPLICATE KEY UPDATE `data` = VALUES(`data`), `expires` = VALUES(`expires`), `updated` = VALUES(`updated`)');
    }

    /** Market prices and the "API down" pause; the rates log and timer stay. */
    public static function clearCache()
    {
        return Db::getInstance()->execute('DELETE FROM `' . _DB_PREFIX_ . 'satelit_cache` WHERE `cache_key` LIKE \'m:%\' OR `cache_key` = \'down\'');
    }

    private static function log($line)
    {
        if (class_exists('PrestaShopLogger')) {
            PrestaShopLogger::addLog('Satelit: ' . $line, 2, null, 'Module', null, true);
        }
    }

    // ---- BNM rates ----

    public static function lastLog()
    {
        $stale = null;
        $v = self::cacheGet('rates_log', $stale);
        return (string)($v !== null ? $v : $stale);
    }

    private static function logRates($line)
    {
        $line = date('Y-m-d H:i') . ' · ' . $line;
        self::cacheSet('rates_log', $line, 10 * 365 * 86400);
        return $line;
    }

    /**
     * Update every currency's conversion rate from /v1/rates.
     *
     * @param bool $force true for "Update now"; false skips while the last fetch is younger than the TTL
     * @return array{ok:bool, message:string, values:array}
     */
    public static function refreshRates($force = false)
    {
        if (!self::hasKey()) {
            return array('ok' => false, 'message' => $force ? self::logRates('no API key') : 'no API key', 'values' => array());
        }
        if (!$force && self::cacheGet('rates_fetched') !== null) {
            return array('ok' => true, 'message' => 'fresh', 'values' => array());
        }
        $res = self::client('en')->rates();
        if (!$res['ok']) {
            $msg = self::logRates('error ' . $res['error']['code'] . ': ' . $res['error']['message']);
            self::log('rates ' . $msg);
            return array('ok' => false, 'message' => $msg, 'values' => array());
        }

        $default = Currency::getDefaultCurrency();
        $currencies = array();
        foreach (Currency::getCurrencies(false, false) as $row) {
            $currencies[strtoupper($row['iso_code'])] = (int)$row['id_currency'];
        }
        $calc = SatelitMoldovaClient::storeValues(is_array($res['data']) ? $res['data'] : array(), array_keys($currencies), $default ? $default->iso_code : '');
        if ($calc['error']) {
            $msg = self::logRates($calc['error'] === 'default_not_listed'
                ? 'default currency ' . ($default ? $default->iso_code : '?') . ' has no BNM rate: nothing changed'
                : 'the API sent no rates: nothing changed');
            return array('ok' => false, 'message' => $msg, 'values' => array());
        }

        $parts = array();
        foreach ($calc['values'] as $iso => $value) {
            $currency = new Currency($currencies[$iso]);
            if (!Validate::isLoadedObject($currency)) {
                continue;
            }
            // conversion_rate is DECIMAL(13,6) in PrestaShop.
            $currency->conversion_rate = round($value, 6);
            $currency->update();
            if ($iso !== strtoupper($default->iso_code)) {
                $parts[] = $iso . ' ' . rtrim(rtrim(number_format($value, 6, '.', ''), '0'), '.');
            }
        }
        $ttl = max(1, (int)Configuration::get('SATELIT_RATES_TTL')) * 3600;
        self::cacheSet('rates_fetched', $calc['date'], $ttl);
        $line = 'BNM ' . $calc['date'] . ', default ' . $default->iso_code . ': ' . ($parts ? implode(', ', $parts) : 'only the default');
        if ($calc['skipped']) {
            $line .= ' (no BNM rate: ' . implode(', ', $calc['skipped']) . ')';
        }
        return array('ok' => true, 'message' => self::logRates($line), 'values' => $calc['values']);
    }

    // ---- market prices ----

    public static function productTerm($idProduct)
    {
        return (string)Db::getInstance()->getValue('SELECT `term` FROM `' . _DB_PREFIX_ . 'satelit_product` WHERE `id_product` = ' . (int)$idProduct, false);
    }

    public static function saveProductTerm($idProduct, $term)
    {
        $idProduct = (int)$idProduct;
        if ($idProduct <= 0) {
            return false;
        }
        $term = SatelitMoldovaClient::cleanTerm($term);
        if ($term === '') {
            return Db::getInstance()->execute('DELETE FROM `' . _DB_PREFIX_ . 'satelit_product` WHERE `id_product` = ' . $idProduct);
        }
        return Db::getInstance()->execute('INSERT INTO `' . _DB_PREFIX_ . 'satelit_product` (`id_product`, `term`) VALUES (' . $idProduct . ', \''
            . pSQL($term) . '\') ON DUPLICATE KEY UPDATE `term` = VALUES(`term`)');
    }

    /**
     * Market prices for a product page, from the cache when it can.
     * Returns the summary (see SatelitMoldovaClient::summarize) or null.
     */
    public static function market($idProduct, $productName, $lang)
    {
        if (!self::hasKey()) {
            return null;
        }
        $term = self::productTerm($idProduct);
        $term = SatelitMoldovaClient::cleanTerm($term !== '' ? $term : (string)$productName);
        if ($term === '') {
            return null;
        }
        $key = 'm:' . $lang . ':' . md5(Tools::strtolower($term));
        $stale = null;
        $hit = self::cacheGet($key, $stale);
        if ($hit !== null) {
            return empty($hit['none']) ? $hit : null;
        }
        if (self::cacheGet('down') !== null) {
            return ($stale && empty($stale['none'])) ? $stale : null;
        }
        // Shoppers wait for this call: keep it short.
        $res = self::client($lang, null, 5)->market($term, max(1, min(10, (int)Configuration::get('SATELIT_OFFERS'))));
        if (!$res['ok']) {
            self::cacheSet('down', $res['error']['code'], self::DOWN_SECONDS);
            self::log('market prices: ' . $res['error']['code'] . ' (' . $res['error']['message'] . ')');
            return ($stale && empty($stale['none'])) ? $stale : null;
        }
        $ttl = max(1, (int)Configuration::get('SATELIT_PRICES_TTL')) * 3600;
        self::cacheSet($key, $res['found'] ? $res['item'] : array('none' => true), $ttl);
        return $res['found'] ? $res['item'] : null;
    }
}
