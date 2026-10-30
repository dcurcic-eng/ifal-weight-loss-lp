<?php
/**
 * decoda-check.php  —  deployment self-test for the OPPORTUNITY variant.
 * DELETE THIS FILE once the page is verified.
 *
 * Open:  https://YOURDOMAIN/lp/weight-loss/decoda-check.php?token=YOUR_CHECK_TOKEN
 * The token comes from DECODA_CHECK_TOKEN in decoda-config.php. Without it this page
 * refuses to run, so it cannot leak anything if you forget to delete it.
 *
 * It answers, in order: is the config found, does the key authenticate, does the key have
 * OPPORTUNITIES permission, does this page's campaign exist yet, and what would a real
 * submission send. It creates nothing.
 */

declare(strict_types=1);

$candidates = [
    __DIR__ . '/decoda-config.php',
    dirname(__DIR__) . '/decoda-config.php',
    dirname(__DIR__, 2) . '/decoda-config.php',
    dirname(__DIR__, 3) . '/decoda-config.php',
];
$configPath = null; $configLoaded = [];
foreach ($candidates as $c) {
    if (is_readable($c)) {
        if ($configPath === null) $configPath = $c;
        $configLoaded[] = $c;
        require_once $c;
    }
}

header('Content-Type: text/plain; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');

$token = (string) ($_GET['token'] ?? '');
if (!defined('DECODA_CHECK_TOKEN') || DECODA_CHECK_TOKEN === '' || !hash_equals(DECODA_CHECK_TOKEN, $token)) {
    http_response_code(403);
    echo "Forbidden.\n";
    if ($configPath === null) {
        echo "\nAlso: decoda-config.php was NOT found. Looked in:\n - " . implode("\n - ", $candidates) . "\n";
    }
    exit;
}

$pageKey = basename(__DIR__);
$row = [];
$pageMapSeen = defined('DECODA_PAGE_MAP');
$pageRowFound = false;
if ($pageMapSeen) {
    $map = is_array(DECODA_PAGE_MAP) ? DECODA_PAGE_MAP : json_decode((string) DECODA_PAGE_MAP, true);
    if (is_array($map) && isset($map[$pageKey]) && is_array($map[$pageKey])) {
        $row = $map[$pageKey];
        $pageRowFound = true;
    }
}
$campaignName = (string) ($row['campaign'] ?? '');
if (!defined('DECODA_FORM_NAME')   && !empty($row['form']))   define('DECODA_FORM_NAME',   $row['form']);
if (!defined('DECODA_LEAD_SOURCE') && !empty($row['source'])) define('DECODA_LEAD_SOURCE', $row['source']);

// Mirror lead.php's default so the writability check points at the real file.
if (!defined('DECODA_CAMPAIGN_CACHE_FILE')) {
    $__cdir = defined('LEAD_TAG_CACHE_FILE') ? dirname(LEAD_TAG_CACHE_FILE)
            : (defined('LEAD_LOG_FILE') ? dirname(LEAD_LOG_FILE) : sys_get_temp_dir());
    define('DECODA_CAMPAIGN_CACHE_FILE', $__cdir . '/decoda-campaigns.json');
}

$ok = true;
$line = function (bool $pass, string $label, string $detail = '') use (&$ok) {
    if (!$pass) $ok = false;
    echo ($pass ? '[ PASS ] ' : '[ FAIL ] ') . $label . ($detail !== '' ? ' — ' . $detail : '') . "\n";
};
$warn = function (string $label, string $detail = '') {
    echo '[ WARN ] ' . $label . ($detail !== '' ? ' — ' . $detail : '') . "\n";
};

echo "Decoda OPPORTUNITY endpoint check\n";
echo "=================================\n\n";

$line($configPath !== null, 'decoda-config.php found', $configLoaded ? implode(' + ', $configLoaded) : 'none');
$line(version_compare(PHP_VERSION, '7.4', '>='), 'PHP version', PHP_VERSION);
$line(function_exists('curl_init'), 'cURL available', function_exists('curl_init') ? '' : 'will fall back to allow_url_fopen');
$line(defined('DECODA_API_KEY') && DECODA_API_KEY !== '' && DECODA_API_KEY !== 'PASTE_THE_KEY_HERE',
    'API key set', defined('DECODA_API_KEY') ? 'length ' . strlen(DECODA_API_KEY) . ' (value not shown)' : 'missing');
$line(defined('DECODA_TENANT') && DECODA_TENANT !== '', 'Tenant set', defined('DECODA_TENANT') ? DECODA_TENANT : 'missing');
$line($pageMapSeen, 'DECODA_PAGE_MAP present', $pageMapSeen ? 'yes' : 'NO — older config without the page map');
$line(!$pageMapSeen || $pageRowFound, 'this folder is in the page map',
    $pageRowFound ? 'folder "' . $pageKey . '" matched' : 'folder "' . $pageKey . '" NOT in the map');
$line(is_readable(__DIR__ . '/lead.php'), 'lead.php present in this folder', __DIR__ . '/lead.php');

foreach ([['LEAD_LOG_FILE', 'log file'], ['DECODA_CAMPAIGN_CACHE_FILE', 'campaign cache']] as [$const, $label]) {
    if (!defined($const)) { $warn($label . ' path not defined', 'lead.php will fall back to a default'); continue; }
    $path = constant($const);
    $line(is_writable(file_exists($path) ? $path : dirname($path)), $label . ' writable', $path);
}

/* ------------------------------------------------------------------ */
echo "\nDecoda API\n----------\n";

$base = defined('DECODA_BASE') ? DECODA_BASE : 'https://api.decodahealth.com';

function probe(string $base, string $path, array $query = []): array
{
    $url = rtrim($base, '/') . $path;
    if ($query) $url .= '?' . http_build_query($query);
    $headers = ['API-KEY: ' . DECODA_API_KEY, 'TENANT: ' . DECODA_TENANT, 'Accept: application/json'];

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 12, CURLOPT_CONNECTTIMEOUT => 5,
        ]);
        $body = curl_exec($ch);
        $err  = curl_error($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ['status' => $code, 'body' => $body === false ? '' : (string) $body, 'error' => $err];
    }
    $ctx  = stream_context_create(['http' => ['method' => 'GET', 'header' => implode("\r\n", $headers), 'timeout' => 12, 'ignore_errors' => true]]);
    $body = @file_get_contents($url, false, $ctx);
    $code = 0;
    foreach ($http_response_header ?? [] as $h) if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) $code = (int) $m[1];
    return ['status' => $code, 'body' => $body === false ? '' : (string) $body, 'error' => $body === false ? 'request failed' : ''];
}

if (!defined('DECODA_API_KEY') || !defined('DECODA_TENANT')) {
    echo "[ SKIP ] credentials missing, cannot test the API\n";
} else {
    // 1. Campaign list — the read half of the opportunities permission.
    $r = probe($base, '/opportunity/campaign/list');
    if ($r['status'] === 422) { // some endpoints demand page+per_page together
        $r = probe($base, '/opportunity/campaign/list', ['page' => 1, 'per_page' => 100]);
    }
    $line($r['status'] === 200, 'GET /opportunity/campaign/list', 'HTTP ' . $r['status']
        . (($r['status'] === 401 || $r['status'] === 403) ? '  <-- key is NOT provisioned for the Opportunities API' : ''));

    $campaigns = [];
    if ($r['status'] === 200) {
        $decoded = json_decode($r['body'], true);
        $campaigns = (is_array($decoded) && isset($decoded['data']) && is_array($decoded['data']))
            ? $decoded['data']
            : (is_array($decoded) ? $decoded : []);
        echo '         ' . count($campaigns) . " campaign(s) visible in the account\n";
        foreach ($campaigns as $c) {
            if (!is_array($c) || !isset($c['name'])) continue;
            echo '           - ' . $c['name']
                . '  [id ' . ($c['id'] ?? '?') . ']'
                . (array_key_exists('isActive', $c) ? ($c['isActive'] ? ' active' : ' INACTIVE') : '')
                . (!empty($c['pipelineId']) ? '  pipeline ' . $c['pipelineId'] : '')
                . "\n";
        }
        if (!$campaigns) {
            $warn('no campaigns exist yet', 'expected until Jayde finishes building them; leads will use the default pipeline');
        }
    } elseif ($r['status'] === 401 || $r['status'] === 403) {
        echo "         Ask Decoda for a key with Opportunities read+write and Campaigns read.\n";
    } elseif ($r['error'] !== '') {
        echo '         transport error: ' . $r['error'] . "\n";
    }

    // 2. Does this page's configured campaign exist?
    if ($campaignName === '') {
        $warn('no campaign name configured for this page', "add 'campaign' => '…' to its DECODA_PAGE_MAP row");
    } else {
        $found = null;
        foreach ($campaigns as $c) {
            if (is_array($c) && isset($c['name']) && strtolower(trim((string) $c['name'])) === strtolower(trim($campaignName))) {
                $found = $c; if (!empty($c['isActive'])) break;
            }
        }
        if ($found) {
            $line(true, 'configured campaign exists', $campaignName . ' -> ' . ($found['id'] ?? '?'));
        } else {
            $warn('configured campaign not found', '"' . $campaignName . '" — leads still land, just without a campaign');
        }
    }

    // 3. Opportunity list — confirms read access and shows whether anything has landed.
    $r2 = probe($base, '/opportunity/list', ['page' => 1, 'per_page' => 5]);
    $line($r2['status'] === 200, 'GET /opportunity/list', 'HTTP ' . $r2['status']);
    if ($r2['status'] === 200) {
        $d = json_decode($r2['body'], true);
        echo '         ' . (int) ($d['total'] ?? 0) . " opportunity(ies) in the account\n";
    }

    // 4. Patient search — only used to link a known patient. Optional.
    $r3 = probe($base, '/user/patient/search', ['search_term' => '0000000000', 'n' => 1]);
    if ($r3['status'] === 200) {
        $line(true, 'GET /user/patient/search (optional patient linking)', 'HTTP 200');
    } else {
        $warn('patient search unavailable', 'HTTP ' . $r3['status'] . ' — leads still work, they just will not be linked to an existing patient record');
    }
}

/* ------------------------------------------------------------------ */
echo "\nWhat a lead from this folder would send\n--------------------------------------\n";
printf("  folder            : %s\n", $pageKey);
printf("  campaign (by name): %s\n", $campaignName !== '' ? $campaignName : '(none configured)');
printf("  sourceDetail base : %s\n", defined('DECODA_LEAD_SOURCE') && DECODA_LEAD_SOURCE !== '' ? DECODA_LEAD_SOURCE : '(form name)');
printf("  notes header      : %s\n", defined('DECODA_FORM_NAME') ? DECODA_FORM_NAME : '(default)');
printf("  locationId        : %s\n", defined('DECODA_LOCATION_ID') && DECODA_LOCATION_ID !== '' ? DECODA_LOCATION_ID : '(none)');
printf("  pipeline override : %s\n", !empty($row['pipelineId']) ? $row['pipelineId'] : (defined('DECODA_OPP_PIPELINE_ID') ? DECODA_OPP_PIPELINE_ID : '(campaign or default)'));
printf("  stage override    : %s\n", !empty($row['stageId']) ? $row['stageId'] : (defined('DECODA_OPP_STAGE_ID') ? DECODA_OPP_STAGE_ID : '(pipeline default)'));
printf("  dedupe open opps  : %s\n", (!defined('DECODA_OPP_DEDUPE') || DECODA_OPP_DEDUPE) ? 'on (re-enquiry becomes a note)' : 'off');
printf("  link known patient: %s\n", (!defined('DECODA_LINK_PATIENT') || DECODA_LINK_PATIENT) ? 'on' : 'off');
printf("  LEAD_DEBUG        : %s\n", (defined('LEAD_DEBUG') && LEAD_DEBUG) ? 'ON — turn off before real traffic' : 'off');

echo "\n" . ($ok
    ? "ALL CHECKS PASSED — send a real test lead through the form, then DELETE this file.\n"
    : "SOME CHECKS FAILED — fix the [ FAIL ] lines above.\n");
