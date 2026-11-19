<?php
/**
 * Satelit API client shared by the Satelit store extensions.
 *
 * Plain PHP 7.1+, no framework and no dependencies, so the same file runs inside
 * OpenCart 3, OpenCart 4 and PrestaShop. The store code adds caching and storage;
 * this class only talks to https://api.satelit.md/v1 and turns answers into the
 * numbers a store needs.
 *
 * Copyright (c) 2026 Satelit (satelit.md). MIT licence.
 */

if (!class_exists('SatelitMoldovaClient', false)) {
    class SatelitMoldovaClient
    {
        const BASE = 'https://api.satelit.md/v1';
        const VERSION = '1.0.0';
        const LANGS = array('ro', 'ru', 'en', 'uk');

        private $key;
        private $lang;
        private $timeout;
        private $base;
        private $userAgent;
        /** @var callable|null fn(string $url, array $headers, int $timeout): array{status:int, headers:array, body:string} */
        private $transport;

        /** Response headers of the last call (lower-case names), e.g. x-credits-remaining. */
        public $lastHeaders = array();

        public function __construct($key, $lang = 'ro', $timeout = 8, $transport = null, $base = null, $userAgent = 'satelit-store')
        {
            $this->key = trim((string)$key);
            $this->lang = in_array($lang, self::LANGS, true) ? $lang : 'ro';
            // The brief for every Satelit integration: never wait more than 10 s.
            $this->timeout = max(1, min(10, (int)$timeout));
            $this->transport = $transport;
            $this->base = rtrim($base ? $base : self::BASE, '/');
            $this->userAgent = $userAgent . '/' . self::VERSION;
        }

        /** Maps a store language code (ro-ro, ru, en-gb, uk-ua) to an API language. */
        public static function langFromLocale($code, $fallback = 'ro')
        {
            $short = strtolower(substr((string)$code, 0, 2));
            return in_array($short, self::LANGS, true) ? $short : $fallback;
        }

        /**
         * GET a route. Never throws: the result says what happened.
         *
         * @return array{ok:bool, status:int, data:mixed, meta:array, error:array|null, headers:array}
         */
        public function get($path, array $params = array())
        {
            $params['lang'] = $this->lang;
            $url = $this->base . '/' . ltrim($path, '/') . '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
            $headers = array('Accept: application/json', 'User-Agent: ' . $this->userAgent);
            if ($this->key !== '') {
                $headers[] = 'Authorization: Bearer ' . $this->key;
            }

            // One bounded retry, only for 429 rate_limited and 503 with a short Retry-After
            // (never quota_exceeded): a page view must not hang on a busy API.
            for ($attempt = 0; ; $attempt++) {
                $res = $this->send($url, $headers);
                $out = self::parse($res);
                $this->lastHeaders = $out['headers'];
                $retryAfter = isset($out['headers']['retry-after']) ? (int)$out['headers']['retry-after'] : 0;
                $code = isset($out['error']['code']) ? $out['error']['code'] : '';
                $retryable = ($out['status'] === 429 && $code === 'rate_limited') || ($out['status'] === 503 && $retryAfter > 0);
                if ($attempt >= 1 || !$retryable || $retryAfter > 2) {
                    return $out;
                }
                sleep(max(1, $retryAfter));
            }
        }

        private function send($url, array $headers)
        {
            if ($this->transport) {
                return call_user_func($this->transport, $url, $headers, $this->timeout);
            }
            if (function_exists('curl_init')) {
                $respHeaders = array();
                $ch = curl_init($url);
                curl_setopt_array($ch, array(
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_HTTPHEADER => $headers,
                    CURLOPT_CONNECTTIMEOUT => min(5, $this->timeout),
                    CURLOPT_TIMEOUT => $this->timeout,
                    CURLOPT_SSL_VERIFYPEER => true,
                    CURLOPT_SSL_VERIFYHOST => 2,
                    CURLOPT_FOLLOWLOCATION => false,
                    CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$respHeaders) {
                        $p = strpos($line, ':');
                        if ($p !== false) {
                            $respHeaders[strtolower(trim(substr($line, 0, $p)))] = trim(substr($line, $p + 1));
                        }
                        return strlen($line);
                    },
                ));
                $body = curl_exec($ch);
                $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $err = curl_error($ch);
                curl_close($ch);
                return array('status' => $body === false ? 0 : $status, 'headers' => $respHeaders, 'body' => $body === false ? '' : (string)$body, 'transport_error' => $err);
            }
            $ctx = stream_context_create(array('http' => array(
                'method' => 'GET', 'header' => implode("\r\n", $headers), 'timeout' => $this->timeout, 'ignore_errors' => true,
            )));
            $body = @file_get_contents($url, false, $ctx);
            $status = 0;
            $respHeaders = array();
            if (isset($http_response_header) && is_array($http_response_header)) {
                foreach ($http_response_header as $line) {
                    if (preg_match('~^HTTP/\S+\s+(\d{3})~', $line, $m)) {
                        $status = (int)$m[1];
                    } elseif (($p = strpos($line, ':')) !== false) {
                        $respHeaders[strtolower(trim(substr($line, 0, $p)))] = trim(substr($line, $p + 1));
                    }
                }
            }
            return array('status' => $body === false ? 0 : $status, 'headers' => $respHeaders, 'body' => $body === false ? '' : (string)$body);
        }

        private static function parse(array $res)
        {
            $status = isset($res['status']) ? (int)$res['status'] : 0;
            $headers = isset($res['headers']) && is_array($res['headers']) ? array_change_key_case($res['headers'], CASE_LOWER) : array();
            $json = isset($res['body']) && $res['body'] !== '' ? json_decode($res['body'], true) : null;
            $out = array('ok' => false, 'status' => $status, 'data' => null, 'meta' => array(), 'error' => null, 'headers' => $headers);
            if ($status === 0) {
                $out['error'] = array('code' => 'network', 'message' => 'Satelit API did not answer' . (!empty($res['transport_error']) ? ' (' . $res['transport_error'] . ')' : ''));
                return $out;
            }
            if (!is_array($json)) {
                $out['error'] = array('code' => 'bad_response', 'message' => 'Satelit API answered HTTP ' . $status . ' without JSON');
                return $out;
            }
            if ($status >= 200 && $status < 300 && array_key_exists('data', $json)) {
                $out['ok'] = true;
                $out['data'] = $json['data'];
                $out['meta'] = isset($json['meta']) && is_array($json['meta']) ? $json['meta'] : array();
                return $out;
            }
            $err = isset($json['error']) && is_array($json['error']) ? $json['error'] : array();
            $out['error'] = array(
                'code' => isset($err['code']) ? (string)$err['code'] : 'http_' . $status,
                'message' => isset($err['message']) ? (string)$err['message'] : 'HTTP ' . $status,
            );
            return $out;
        }

        /** GET /me (0 credits): plan and credits, for the "Test connection" button. */
        public function me()
        {
            return $this->get('me');
        }

        /** GET /rates (1 credit): BNM official rates, MDL per `nominal` units. */
        public function rates()
        {
            return $this->get('rates');
        }

        /**
         * Store currency values from BNM rates.
         *
         * Stores keep one currency at 1.0 (the default) and every other one as
         * "units of this currency per 1 unit of the default". BNM gives MDL per
         * `nominal` units, so MDL per one unit of C is value / nominal, and
         * store value of C = (MDL per unit of default) / (MDL per unit of C).
         * With MDL as default: EUR = nominal / value (1 / 20.14 = 0.04965).
         * With EUR as default: MDL = 20.14, USD = 20.14 / 18.37.
         *
         * @param array  $rates   `data` of /v1/rates
         * @param array  $codes   ISO codes the store has
         * @param string $default the store's default currency
         * @return array{values:array<string,float>, skipped:string[], error:string|null, date:string|null}
         */
        public static function storeValues(array $rates, array $codes, $default)
        {
            $default = strtoupper((string)$default);
            $mdl = array('MDL' => 1.0);
            $date = null;
            foreach ($rates as $r) {
                if (!is_array($r) || empty($r['code']) || !isset($r['value']) || (float)$r['value'] <= 0) {
                    continue;
                }
                $nominal = isset($r['nominal']) && (float)$r['nominal'] > 0 ? (float)$r['nominal'] : 1.0;
                $mdl[strtoupper($r['code'])] = (float)$r['value'] / $nominal;
                if (!empty($r['date']) && ($date === null || $r['date'] > $date)) {
                    $date = (string)$r['date'];
                }
            }
            $out = array('values' => array(), 'skipped' => array(), 'error' => null, 'date' => $date);
            if (count($mdl) < 2) {
                $out['error'] = 'no_rates';
                return $out;
            }
            if (!isset($mdl[$default])) {
                $out['error'] = 'default_not_listed';
                return $out;
            }
            foreach ($codes as $code) {
                $code = strtoupper((string)$code);
                if ($code === $default) {
                    $out['values'][$code] = 1.0;
                } elseif (isset($mdl[$code])) {
                    $out['values'][$code] = $mdl[$default] / $mdl[$code];
                } else {
                    $out['skipped'][] = $code;
                }
            }
            return $out;
        }

        /**
         * Market prices for a product: search the term (3 credits), open the best
         * match (2 credits) and keep what a product page shows.
         *
         * @return array{ok:bool, found:bool, error:array|null, item:array|null}
         */
        public function market($term, $maxOffers = 5)
        {
            $term = self::cleanTerm($term);
            if ($term === '') {
                return array('ok' => true, 'found' => false, 'error' => null, 'item' => null);
            }
            $search = $this->get('prices/search', array('q' => $term));
            if (!$search['ok']) {
                return array('ok' => false, 'found' => false, 'error' => $search['error'], 'item' => null);
            }
            if (empty($search['data'][0]['id'])) {
                return array('ok' => true, 'found' => false, 'error' => null, 'item' => null);
            }
            $item = $this->get('prices/items/' . (int)$search['data'][0]['id']);
            if (!$item['ok'] || !is_array($item['data'])) {
                return array('ok' => false, 'found' => false, 'error' => $item['error'], 'item' => null);
            }
            $summary = self::summarize($item['data'], $maxOffers);
            return array('ok' => true, 'found' => $summary['offers'] ? true : false, 'error' => null, 'item' => $summary);
        }

        /** Search terms: 2..100 characters, one line, no markup. */
        public static function cleanTerm($term)
        {
            $term = trim(preg_replace('~\s+~u', ' ', strip_tags((string)$term)));
            if (function_exists('mb_substr')) {
                $term = mb_substr($term, 0, 100, 'UTF-8');
                return mb_strlen($term, 'UTF-8') >= 2 ? $term : '';
            }
            $term = substr($term, 0, 100);
            return strlen($term) >= 2 ? $term : '';
        }

        /**
         * Keeps the fields a product page shows: one offer per shop (its best one:
         * in stock first, then cheapest), shops in the same order. `in_stock` is
         * true, false or null (the shop does not say).
         */
        public static function summarize(array $item, $maxOffers = 5)
        {
            $rank = function ($o) {
                return $o['in_stock'] === false ? 1 : 0;
            };
            $better = function ($a, $b) use ($rank) {
                if ($rank($a) !== $rank($b)) {
                    return $rank($a) - $rank($b);
                }
                return $a['price'] == $b['price'] ? 0 : ($a['price'] < $b['price'] ? -1 : 1);
            };
            $byStore = array();
            foreach (isset($item['offers']) && is_array($item['offers']) ? $item['offers'] : array() as $o) {
                if (!isset($o['price']) || !is_numeric($o['price']) || empty($o['store']['name'])) {
                    continue;
                }
                $offer = array(
                    'store' => (string)$o['store']['name'],
                    'price' => (float)$o['price'],
                    'old_price' => isset($o['old_price']) && is_numeric($o['old_price']) ? (float)$o['old_price'] : null,
                    'in_stock' => isset($o['in_stock']) ? (bool)$o['in_stock'] : null,
                );
                $k = function_exists('mb_strtolower') ? mb_strtolower($offer['store'], 'UTF-8') : strtolower($offer['store']);
                if (!isset($byStore[$k]) || $better($offer, $byStore[$k]) < 0) {
                    $byStore[$k] = $offer;
                }
            }
            $offers = array_values($byStore);
            usort($offers, $better);
            $prices = array();
            foreach ($offers as $o) {
                $prices[] = $o['price'];
            }
            sort($prices);
            return array(
                'id' => isset($item['id']) ? (int)$item['id'] : 0,
                'name' => isset($item['name']) ? (string)$item['name'] : '',
                'min' => isset($item['min_price']) && is_numeric($item['min_price']) ? (float)$item['min_price'] : ($prices ? $prices[0] : null),
                'median' => isset($item['median_price']) && is_numeric($item['median_price']) ? (float)$item['median_price'] : self::median($prices),
                'stores' => isset($item['stores']) ? (int)$item['stores'] : count($offers),
                'updated_at' => isset($item['updated_at']) ? (string)$item['updated_at'] : null,
                'offers' => array_slice($offers, 0, max(1, (int)$maxOffers)),
            );
        }

        public static function median(array $prices)
        {
            $n = count($prices);
            if (!$n) {
                return null;
            }
            sort($prices);
            $mid = (int)floor($n / 2);
            return $n % 2 ? $prices[$mid] : ($prices[$mid - 1] + $prices[$mid]) / 2;
        }
    }
}
