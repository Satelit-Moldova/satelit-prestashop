<?php
/**
 * Cron URL: index.php?fc=module&module=satelitmoldova&controller=cron&token=…
 * Updates the BNM rates when the last ones are older than the TTL (add &force=1
 * to update now). Answers one JSON line.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class SatelitMoldovaCronModuleFrontController extends ModuleFrontController
{
    public $auth = false;
    public $ajax = true;

    public function postProcess()
    {
        $want = (string)Configuration::get('SATELIT_CRON_TOKEN');
        $got = (string)Tools::getValue('token');
        header('Content-Type: application/json');
        header('Cache-Control: no-store');
        if ($want === '' || !hash_equals($want, $got)) {
            http_response_code(403);
            echo json_encode(array('ok' => false, 'message' => 'bad token'));
            exit;
        }
        try {
            $r = SatelitMoldovaStore::refreshRates((bool)Tools::getValue('force'));
        } catch (Throwable $e) {
            PrestaShopLogger::addLog('Satelit rates: ' . $e->getMessage(), 2);
            $r = array('ok' => false, 'message' => 'error');
        }
        echo json_encode(array('ok' => $r['ok'], 'message' => $r['message']));
        exit;
    }
}
