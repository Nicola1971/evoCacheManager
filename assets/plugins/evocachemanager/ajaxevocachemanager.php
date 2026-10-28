<?php
/**
 * EvoCacheManager - endpoint AJAX
 * Compatibile Evolution CMS 1.4.x / PHP 7.4 – 8.4
 */
if (!isset($_POST['fun'])) {
    die('What are you doing? Get out of here!');
}

$fun  = (string)$_POST['fun'];
$part = isset($_POST['part']) ? max(1, (int)$_POST['part']) : 2;

// Qualsiasi output (warning, deprecation del core su PHP 8.x) finisce nel buffer
// e viene scartato prima di stampare il JSON.
ob_start();

define('MODX_API_MODE', true);
include_once dirname(__FILE__) . '/../../../index.php';

$modx->db->connect();
if (empty($modx->config)) {
    $modx->getSettings();
}

if (!isset($_SERVER['HTTP_X_REQUESTED_WITH']) || strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) !== 'xmlhttprequest') {
    $modx->sendRedirect($modx->config['site_url']);
}

// Solo utenti loggati al manager
if (empty($_SESSION['mgrInternalKey'])) {
    ecm_output(array('error' => 'not authorized'), 403);
}

@set_time_limit(600);

/**
 * Pulisce il buffer e invia la risposta JSON
 */
function ecm_output(array $data, $status = 200)
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode($data);
    exit;
}

if ($fun === 'get' || $fun === 'init') {
    $cache_folder = MODX_BASE_PATH . $modx->getCacheFolder();

    $res = $modx->db->select(
        'id',
        $modx->getFullTableName('site_content'),
        "cacheable = '1' AND type = 'document' AND published = '1' AND template <> '0' AND deleted = '0' AND privateweb = '0' AND privatemgr = '0'"
    );

    $all_docs = array();
    while ($line = $modx->db->getRow($res)) {
        $all_docs[] = (int)$line['id'];
    }
    $count_all_docs = count($all_docs);

    // ID dei documenti già in cache (docid_12_xxxx.pageCache.php -> 12)
    $cached = array();
    $files  = glob($cache_folder . 'docid_*');
    if (is_array($files)) {
        foreach ($files as $file) {
            if (preg_match('/^docid_(\d+)/', basename($file), $m)) {
                $cached[] = (int)$m[1];
            }
        }
    }

    foreach (array('error_page', 'unauthorized_page', 'site_unavailable_page') as $key) {
        if (!empty($modx->config[$key])) {
            $cached[] = (int)$modx->config[$key];
        }
    }

    $no_cached_docs    = array_values(array_diff($all_docs, $cached));
    $count_cached_docs = $count_all_docs - count($no_cached_docs);
    $has_curl          = function_exists('curl_init');

    if ($fun === 'get' && $has_curl) {
        while ($part > 0 && !empty($no_cached_docs)) {
            $id = array_pop($no_cached_docs);
            $part--;
            $count_cached_docs++;

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $modx->makeUrl($id, '', '', 'full'));
            curl_setopt($ch, CURLOPT_HEADER, true);
            curl_setopt($ch, CURLOPT_NOBODY, true);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            curl_exec($ch);
            if (PHP_VERSION_ID < 80000) {
                curl_close($ch); // da PHP 8 non serve (e in 8.5 è deprecata)
            }
            unset($ch);
        }
    }

    $perc = $count_all_docs > 0 ? (int)floor($count_cached_docs / $count_all_docs * 100) : 100;

    ecm_output(array(
        'count_cached_docs' => $count_cached_docs,
        'count_all_docs'    => $count_all_docs,
        'perc'              => $perc,
        'do'                => ($count_cached_docs < $count_all_docs) && $has_curl,
    ));
}

if ($fun === 'clear') {
    $modx->clearCache('full');
    ecm_output(array('result' => true));
}

ecm_output(array('error' => 'unknown action'), 400);
