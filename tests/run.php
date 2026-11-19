<?php
/**
 * Offline tests: no network, no PrestaShop. Run with `php tests/run.php`.
 *
 * The few PrestaShop classes the module's store layer uses (Db, Configuration,
 * Currency, Tools...) are small in-memory fakes; HTTP is a fake transport fed
 * from tests/fixtures (real answers of the live API, trimmed).
 */
error_reporting(E_ALL);
ini_set('display_errors', '1');
define('SATELIT_TEST', 1);
define('_DB_PREFIX_', 'ps_');
define('_MYSQL_ENGINE_', 'InnoDB');

// ---- PrestaShop fakes ----
function pSQL($s, $html = false) { return addslashes((string)$s); }

class Configuration {
    public static $v = array();
    public static function get($k) { return isset(self::$v[$k]) ? self::$v[$k] : false; }
    public static function updateValue($k, $v) { self::$v[$k] = $v; return true; }
}
class Tools {
    public static function strtolower($s) { return mb_strtolower($s, 'UTF-8'); }
}
class Validate {
    public static function isLoadedObject($o) { return is_object($o) && !empty($o->id); }
}
class PrestaShopLogger {
    public static $lines = array();
    public static function addLog($m) { self::$lines[] = $m; }
}
class Currency {
    public static $rows = array();   // id => [iso, rate]
    public static $default = 1;
    public $id;
    public $iso_code;
    public $conversion_rate;
    public function __construct($id = null) {
        if ($id && isset(self::$rows[$id])) {
            $this->id = $id;
            $this->iso_code = self::$rows[$id][0];
            $this->conversion_rate = self::$rows[$id][1];
        }
    }
    public function update() { self::$rows[$this->id][1] = $this->conversion_rate; return true; }
    public static function getDefaultCurrency() { return new Currency(self::$default); }
    public static function getCurrencies($object = false, $active = true) {
        $out = array();
        foreach (self::$rows as $id => $r) { $out[] = array('id_currency' => $id, 'iso_code' => $r[0]); }
        return $out;
    }
}
class Db {
    public static $cache = array();
    public static $product = array();
    private static $i;
    public static function getInstance() { return self::$i ? self::$i : (self::$i = new Db()); }
    public function execute($sql) {
        if (preg_match("~INSERT INTO `ps_satelit_cache` .*VALUES \('(.*?)', '(.*?)', (\d+), \d+\)~s", $sql, $m)) {
            self::$cache[stripslashes($m[1])] = array(stripslashes($m[2]), (int)$m[3]);
        } elseif (strpos($sql, 'DELETE FROM `ps_satelit_cache`') === 0) {
            foreach (array_keys(self::$cache) as $k) {
                if (strpos($k, 'm:') === 0 || $k === 'down') { unset(self::$cache[$k]); }
            }
        } elseif (preg_match("~INSERT INTO `ps_satelit_product` .*VALUES \((\d+), '(.*?)'\)~", $sql, $m)) {
            self::$product[(int)$m[1]] = stripslashes($m[2]);
        } elseif (preg_match('~DELETE FROM `ps_satelit_product` WHERE `id_product` = (\d+)~', $sql, $m)) {
            unset(self::$product[(int)$m[1]]);
        }
        return true;
    }
    public function getRow($sql) {
        if (preg_match("~`cache_key` = '(.*)'~", $sql, $m) && isset(self::$cache[stripslashes($m[1])])) {
            $r = self::$cache[stripslashes($m[1])];
            return array('data' => $r[0], 'expires' => $r[1]);
        }
        return false;
    }
    public function getValue($sql) {
        return preg_match('~`id_product` = (\d+)~', $sql, $m) && isset(self::$product[(int)$m[1]]) ? self::$product[(int)$m[1]] : false;
    }
}

require __DIR__ . '/../satelitmoldova/classes/SatelitMoldovaStore.php';

// ---- helpers ----
$failures = 0;
$count = 0;
function ok($cond, $name) {
    global $failures, $count;
    $count++;
    echo ($cond ? 'ok   ' : 'FAIL ') . $name . "\n";
    if (!$cond) { $failures++; }
}
function near($a, $b, $eps = 1e-6) { return abs((float)$a - (float)$b) < $eps; }
function fixture($n) { return file_get_contents(__DIR__ . '/fixtures/' . $n); }

class FakeHttp {
    public $routes = array();
    public $calls = array();
    public function __invoke($url, $headers, $timeout) {
        $this->calls[] = $url;
        $path = substr(parse_url($url, PHP_URL_PATH), strlen('/v1/'));
        foreach ($this->routes as $prefix => $res) {
            if (strpos($path, $prefix) === 0) {
                return array('status' => $res[0], 'body' => $res[1], 'headers' => array());
            }
        }
        return array('status' => 0, 'body' => '', 'headers' => array());
    }
}
function reset_shop($currencies, $default, $key = 'sat_live_TESTKEY000') {
    Db::$cache = array();
    Db::$product = array();
    PrestaShopLogger::$lines = array();
    Currency::$rows = $currencies;
    Currency::$default = $default;
    Configuration::$v = array('SATELIT_API_KEY' => $key, 'SATELIT_LANG' => 'auto', 'SATELIT_RATES_TTL' => 3,
        'SATELIT_PRICES_TTL' => 6, 'SATELIT_OFFERS' => 5, 'SATELIT_MARKET_ON' => 1);
    $http = new FakeHttp();
    SatelitMoldovaStore::$transport = $http;
    return $http;
}

$live = array();
foreach (json_decode(fixture('rates.json'), true)['data'] as $r) { $live[$r['code']] = $r['value'] / $r['nominal']; }

// ---- rates ----
$http = reset_shop(array(1 => array('MDL', 1), 2 => array('EUR', 0.05), 3 => array('USD', 0.05), 4 => array('GBP', 0.04)), 1);
$http->routes['rates'] = array(200, fixture('rates.json'));
$r = SatelitMoldovaStore::refreshRates(true);
ok($r['ok'] && near(Currency::$rows[2][1], round(1 / $live['EUR'], 6)) && Currency::$rows[1][1] == 1, 'rates: MDL default, EUR = 1 / value (6 decimals)');
ok(Currency::$rows[4][1] == 0.04, 'rates: GBP (no BNM rate) untouched');
ok(strpos(SatelitMoldovaStore::lastLog(), 'no BNM rate: GBP') !== false, 'rates: log line');
ok(SatelitMoldovaStore::refreshRates(false)['message'] === 'fresh' && count($http->calls) === 1, 'rates: cron respects the TTL');

$http = reset_shop(array(1 => array('EUR', 1), 2 => array('MDL', 20), 3 => array('USD', 1.1)), 1);
$http->routes['rates'] = array(200, fixture('rates.json'));
SatelitMoldovaStore::refreshRates(true);
ok(near(Currency::$rows[2][1], $live['EUR']) && near(Currency::$rows[3][1], round($live['EUR'] / $live['USD'], 6)), 'rates: EUR default, MDL = value, USD = EUR/USD');

$http = reset_shop(array(1 => array('MDL', 1), 2 => array('EUR', 0.05)), 1);
$http->routes['rates'] = array(401, '{"error":{"code":"key_invalid","message":"The API key is invalid or revoked."}}');
$r = SatelitMoldovaStore::refreshRates(true);
ok(!$r['ok'] && Currency::$rows[2][1] == 0.05 && strpos($r['message'], 'key_invalid') !== false && PrestaShopLogger::$lines, 'rates: bad key keeps the rates and logs');

$http = reset_shop(array(1 => array('GBP', 1), 2 => array('EUR', 1.1)), 1);
$http->routes['rates'] = array(200, fixture('rates.json'));
$r = SatelitMoldovaStore::refreshRates(true);
ok(!$r['ok'] && Currency::$rows[2][1] == 1.1 && strpos($r['message'], 'GBP has no BNM rate') !== false, 'rates: default without a BNM rate changes nothing');

$http = reset_shop(array(1 => array('MDL', 1)), 1, '');
ok(SatelitMoldovaStore::refreshRates(false)['message'] === 'no API key' && !$http->calls, 'rates: no key, no call');

// ---- market ----
$http = reset_shop(array(1 => array('MDL', 1)), 1);
$http->routes['prices/search'] = array(200, fixture('search.json'));
$http->routes['prices/items/'] = array(200, fixture('item.json'));
$m = SatelitMoldovaStore::market(1, 'iPhone', 'ro');
ok($m && $m['name'] === 'Apple iPhone 15 128 GB' && count($m['offers']) === 5, 'market: name used, 5 shops');
ok(SatelitMoldovaStore::market(1, 'iPhone', 'ro') == $m && count($http->calls) === 2, 'market: cached');
SatelitMoldovaStore::saveProductTerm(1, 'iPhone 15 128GB');
ok(SatelitMoldovaStore::productTerm(1) === 'iPhone 15 128GB', 'terms: saved');
SatelitMoldovaStore::market(1, 'iPhone', 'ro');
ok(strpos($http->calls[2], 'q=iPhone%2015%20128GB') !== false, 'market: product term wins');
SatelitMoldovaStore::saveProductTerm(1, ' ');
ok(SatelitMoldovaStore::productTerm(1) === '', 'terms: blank removes');

$http = reset_shop(array(1 => array('MDL', 1)), 1);
ok(SatelitMoldovaStore::market(2, 'iPhone', 'ro') === null && SatelitMoldovaStore::market(3, 'Samsung', 'ro') === null && count($http->calls) === 1, 'market: dead API, one call then a pause');

$http = reset_shop(array(1 => array('MDL', 1)), 1);
$http->routes['prices/search'] = array(200, fixture('search.json'));
$http->routes['prices/items/'] = array(200, fixture('item.json'));
SatelitMoldovaStore::market(4, 'iPhone', 'ro');
foreach (Db::$cache as $k => $row) { Db::$cache[$k][1] = time() - 1; }
$http->routes = array();
$stale = SatelitMoldovaStore::market(4, 'iPhone', 'ro');
ok($stale && $stale['name'] === 'Apple iPhone 15 128 GB', 'market: dead API shows the stale copy');

reset_shop(array(1 => array('MDL', 1)), 1, '');
ok(SatelitMoldovaStore::market(5, 'iPhone', 'ro') === null, 'market: no key, nothing');
ok(SatelitMoldovaStore::lang('ru') === 'ru', 'lang: auto follows the shopper');

// ---- module files ----
$tpl = file_get_contents(__DIR__ . '/../satelitmoldova/views/templates/hook/market.tpl');
preg_match_all('~\{\$[^}]+\}~', $tpl, $m);
$unescaped = array_filter($m[0], function ($v) { return strpos($v, '|escape:') === false; });
ok(!$unescaped, 'templates: every value escaped');
foreach (array('ro', 'ru') as $iso) {
    $_MODULE = array();
    include __DIR__ . '/../satelitmoldova/translations/' . $iso . '.php';
    ok(count($_MODULE) > 40, "translations: $iso loads");
}
$xml = simplexml_load_file(__DIR__ . '/../satelitmoldova/config.xml');
ok((string)$xml->name === 'satelitmoldova' && (string)$xml->version === '1.0.0', 'config.xml: name and version');

echo "\n$count tests, $failures failed\n";
exit($failures ? 1 : 0);
