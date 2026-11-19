<?php
/**
 * Opt-in live smoke test against https://api.satelit.md (about 6 credits).
 *
 *   SATELIT_API_KEY=sat_live_… php tests/live.php
 *
 * Skips (exit 0) when the variable is not set. Never prints the key.
 */
require __DIR__ . '/../satelitmoldova/classes/satelit_client.php';

$key = getenv('SATELIT_API_KEY');
if (!$key) {
    echo "skip: SATELIT_API_KEY is not set\n";
    exit(0);
}
$c = new SatelitMoldovaClient($key, 'en', 10);
$fail = 0;

$me = $c->me();
echo $me['ok'] ? "ok   /me: plan {$me['data']['plan']}\n" : "FAIL /me: {$me['error']['code']}\n";
$fail += $me['ok'] ? 0 : 1;

$rates = $c->rates();
if ($rates['ok']) {
    $v = SatelitMoldovaClient::storeValues($rates['data'], array('MDL', 'EUR', 'USD'), 'MDL');
    printf("ok   /rates: EUR %.4f MDL -> store value %.8f (%s)\n", 1 / $v['values']['EUR'], $v['values']['EUR'], $rates['meta']['attribution']);
    echo 'ok   credits left: ' . $c->lastHeaders['x-credits-remaining'] . "\n";
} else {
    echo "FAIL /rates: {$rates['error']['code']}\n";
    $fail++;
}

$m = $c->market('iPhone 15 128GB', 3);
if ($m['ok'] && $m['found']) {
    echo "ok   market: {$m['item']['name']}, from {$m['item']['min']} MDL, " . $m['item']['offers'][0]['store'] . "\n";
} else {
    echo 'FAIL market: ' . ($m['error'] ? $m['error']['code'] : 'no match') . "\n";
    $fail++;
}
exit($fail ? 1 : 0);
