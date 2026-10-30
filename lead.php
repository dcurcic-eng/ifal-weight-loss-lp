<?php
/**
 * lead.php  —  Ageless ad LP form -> Decoda OPPORTUNITIES API
 * Medical Weight Loss LP, /lp/weight-loss/
 *
 * This is the opportunity-based variant of the lead endpoint. Instead of creating a
 * patient and tagging it, it creates an Opportunity so the clinic can work the lead
 * through pipeline stages and automations.
 *
 * Decoda routes used, and no others:
 *   GET  /opportunity/campaign/list      resolve the page's campaign NAME -> id (cached)
 *   GET  /opportunity/list               dedupe: find an existing open opportunity
 *   POST /opportunity                    create the lead
 *   POST /opportunity/{id}/note          log a re-enquiry on an existing open opportunity
 *   GET  /user/patient/search            optional: link the lead to a known patient
 *
 * Requires decoda-config.php (above public_html). See CHANGES.md for the config keys.
 */

declare(strict_types=1);

/* ------------------------------------------------------------------ *
 * Config: nearest first, then up to three levels above. Every readable
 * config is loaded and a PHP constant keeps its FIRST definition, so the
 * closest file wins.
 * ------------------------------------------------------------------ */
$__cfg_candidates = [
    __DIR__ . '/decoda-config.php',
    dirname(__DIR__) . '/decoda-config.php',
    dirname(__DIR__, 2) . '/decoda-config.php',
    dirname(__DIR__, 3) . '/decoda-config.php',
];
$__cfg_loaded = false;
foreach ($__cfg_candidates as $__cfg) {
    if (is_readable($__cfg)) {
        require_once $__cfg;
        $__cfg_loaded = true;
    }
}

// Per-page values from DECODA_PAGE_MAP, keyed by this landing page's folder name.
$__page_key      = basename(__DIR__);
$__page_unmapped = false;
$__page_row      = [];
if (defined('DECODA_PAGE_MAP')) {
    $__map = is_array(DECODA_PAGE_MAP) ? DECODA_PAGE_MAP : json_decode((string) DECODA_PAGE_MAP, true);
    $__row = (is_array($__map) && isset($__map[$__page_key]) && is_array($__map[$__page_key]))
        ? $__map[$__page_key]
        : null;
    if ($__row) {
        $__page_row = $__row;
        if (!defined('DECODA_FORM_NAME')   && !empty($__row['form']))   define('DECODA_FORM_NAME',   $__row['form']);
        if (!defined('DECODA_LEAD_SOURCE') && !empty($__row['source'])) define('DECODA_LEAD_SOURCE', $__row['source']);
    } else {
        $__page_unmapped = true;
    }
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');

if (!defined('DECODA_BASE'))            define('DECODA_BASE', 'https://api.decodahealth.com');
if (!defined('DECODA_FORM_NAME'))       define('DECODA_FORM_NAME', 'IFAL Ad Form');
if (!defined('DECODA_LEAD_SOURCE'))     define('DECODA_LEAD_SOURCE', '');
if (!defined('DECODA_LOCATION_ID'))     define('DECODA_LOCATION_ID', '');
if (!defined('DECODA_OPP_DEDUPE'))      define('DECODA_OPP_DEDUPE', true);   // reuse an open opportunity
if (!defined('DECODA_LINK_PATIENT'))    define('DECODA_LINK_PATIENT', true); // link known patients
if (!defined('LEAD_DEBUG'))             define('LEAD_DEBUG', false);
if (!defined('LEAD_ALERT_WEBHOOK_URL')) define('LEAD_ALERT_WEBHOOK_URL', '');
if (!defined('LEAD_LOG_FILE'))          define('LEAD_LOG_FILE', sys_get_temp_dir() . '/decoda-leads.log');
if (!defined('LEAD_RATE_LIMIT'))        define('LEAD_RATE_LIMIT', 8);
if (!defined('LEAD_RATE_WINDOW'))       define('LEAD_RATE_WINDOW', 600);

// Campaign id cache. Defaults next to the existing tag cache / log, i.e. outside the web root.
if (!defined('DECODA_CAMPAIGN_CACHE_FILE')) {
    $__cache_dir = defined('LEAD_TAG_CACHE_FILE') ? dirname(LEAD_TAG_CACHE_FILE) : dirname(LEAD_LOG_FILE);
    define('DECODA_CAMPAIGN_CACHE_FILE', $__cache_dir . '/decoda-campaigns.json');
}

const HONEYPOT_FIELD    = 'company_website';
const REQUEST_TIMEOUT   = 8;     // seconds per Decoda call
const CAMPAIGN_CACHE_TTL = 1800; // 30 minutes

/* ------------------------------------------------------------------ *
 * Helpers
 * ------------------------------------------------------------------ */
function lead_log(string $line): void
{
    $ok = @file_put_contents(
        LEAD_LOG_FILE,
        '[' . gmdate('c') . '] [OPP] ' . $line . PHP_EOL,
        FILE_APPEND | LOCK_EX
    );
    // Never mirrored to error_log(): on shared hosting that lands in a php_errorlog
    // inside the web root, and failure lines carry the full lead payload.
    if ($ok === false) {
        error_log('[DECODA_LEAD] cannot write ' . LEAD_LOG_FILE . ' — check the path and permissions');
    }
}

function respond(int $status, array $body): void
{
    http_response_code($status);
    echo json_encode($body);
    exit;
}

function digits_only($value): string
{
    return preg_replace('/\D/', '', (string) $value) ?? '';
}

function client_ip(): string
{
    foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $key) {
        if (!empty($_SERVER[$key])) return trim(explode(',', (string) $_SERVER[$key])[0]);
    }
    return 'unknown';
}

function unwrap_list($payload): array
{
    if (is_array($payload)) {
        if (isset($payload['data']) && is_array($payload['data'])) return $payload['data'];
        if (isset($payload['items']) && is_array($payload['items'])) return $payload['items'];
        if ($payload === [] || array_keys($payload) === range(0, count($payload) - 1)) return $payload;
    }
    return [];
}

/* ------------------------------------------------------------------ *
 * HTTP layer: cURL when available, streams as a fallback.
 * One retry with backoff on 429 / 5xx / network error.
 * ------------------------------------------------------------------ */
class DecodaError extends RuntimeException
{
    public int $httpStatus = 0;
}

function http_send(string $method, string $url, array $headers, ?string $body, int $timeout): array
{
    $headerLines = [];
    foreach ($headers as $k => $v) $headerLines[] = $k . ': ' . $v;

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headerLines,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_HEADER         => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);

        $raw = curl_exec($ch);
        if ($raw === false) {
            $err = curl_error($ch);
            curl_close($ch);
            return ['status' => 0, 'body' => '', 'headers' => [], 'error' => $err];
        }
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $status     = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $parsed = [];
        foreach (preg_split('/\r?\n/', substr((string) $raw, 0, $headerSize)) as $line) {
            if (strpos($line, ':') !== false) {
                [$hk, $hv] = explode(':', $line, 2);
                $parsed[strtolower(trim($hk))] = trim($hv);
            }
        }
        return ['status' => $status, 'body' => substr((string) $raw, $headerSize), 'headers' => $parsed, 'error' => null];
    }

    $ctx = stream_context_create(['http' => [
        'method' => $method, 'header' => implode("\r\n", $headerLines),
        'content' => $body ?? '', 'timeout' => $timeout, 'ignore_errors' => true,
    ]]);
    $respBody = @file_get_contents($url, false, $ctx);
    if ($respBody === false) return ['status' => 0, 'body' => '', 'headers' => [], 'error' => 'stream request failed'];

    $status = 0; $parsed = [];
    foreach ($http_response_header ?? [] as $line) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) $status = (int) $m[1];
        elseif (strpos($line, ':') !== false) {
            [$hk, $hv] = explode(':', $line, 2);
            $parsed[strtolower(trim($hk))] = trim($hv);
        }
    }
    return ['status' => $status, 'body' => $respBody, 'headers' => $parsed, 'error' => null];
}

/**
 * @return mixed decoded JSON
 * @throws DecodaError
 */
function decoda(string $path, string $method = 'GET', ?array $body = null, array $query = [])
{
    if (!defined('DECODA_API_KEY') || !defined('DECODA_TENANT') || DECODA_API_KEY === '' || DECODA_TENANT === '') {
        throw new DecodaError('decoda-config.php missing or DECODA_API_KEY / DECODA_TENANT not set');
    }

    $url   = rtrim(DECODA_BASE, '/') . $path;
    $clean = [];
    foreach ($query as $k => $v) {
        if ($v !== null && $v !== '') $clean[$k] = (string) $v;
    }
    if ($clean) $url .= '?' . http_build_query($clean);

    $headers = ['API-KEY' => DECODA_API_KEY, 'TENANT' => DECODA_TENANT, 'Accept' => 'application/json'];
    $payload = $body === null ? null : json_encode($body, JSON_UNESCAPED_SLASHES);
    if ($payload !== null) $headers['Content-Type'] = 'application/json';

    for ($attempt = 0; $attempt < 2; $attempt++) {
        $res = http_send($method, $url, $headers, $payload, REQUEST_TIMEOUT);

        $retryable = $res['status'] === 0 || $res['status'] === 429 || $res['status'] >= 500;
        if ($retryable && $attempt === 0) {
            $retryAfter = isset($res['headers']['retry-after']) ? (int) $res['headers']['retry-after'] : 0;
            usleep($retryAfter > 0 ? min($retryAfter, 3) * 1000000 : 900000);
            continue;
        }

        if ($res['status'] === 0) {
            throw new DecodaError("Decoda $method $path network error: " . (string) $res['error']);
        }

        $decoded = $res['body'] === '' ? null : json_decode($res['body'], true);

        if ($res['status'] < 200 || $res['status'] >= 300) {
            $hint = '';
            if ($res['status'] === 401 || $res['status'] === 403) {
                $hint = ' [the API key may not be provisioned for the Opportunities API — ask Decoda to widen its scope]';
            }
            $e = new DecodaError("Decoda $method $path -> {$res['status']} " . substr($res['body'], 0, 500) . $hint);
            $e->httpStatus = $res['status'];
            throw $e;
        }
        return $decoded;
    }

    throw new DecodaError("Decoda $method $path failed after retry");
}

/* ------------------------------------------------------------------ *
 * Campaign name -> id, cached to a JSON file with a TTL.
 * Campaigns are built by the clinic, so we resolve by NAME: the ids do
 * not exist until Jayde creates them and would otherwise be unknowable.
 * ------------------------------------------------------------------ */
function campaign_cache_read(): array
{
    $raw = @file_get_contents(DECODA_CAMPAIGN_CACHE_FILE);
    if ($raw === false || $raw === '') return [];
    $data = json_decode($raw, true);
    if (!is_array($data)) return [];
    $now = time(); $out = [];
    foreach ($data as $name => $entry) {
        if (is_array($entry) && isset($entry['id'], $entry['at']) && ($now - (int) $entry['at']) < CAMPAIGN_CACHE_TTL) {
            $out[$name] = $entry;
        }
    }
    return $out;
}

function campaign_cache_write(string $key, string $id): void
{
    $data = campaign_cache_read();
    $data[$key] = ['id' => $id, 'at' => time()];
    @file_put_contents(DECODA_CAMPAIGN_CACHE_FILE, json_encode($data), LOCK_EX);
}

/** @return array{0:?string,1:?string} [campaignId, pipelineId] */
function resolve_campaign(string $name): array
{
    $name = trim($name);
    if ($name === '') return [null, null];
    $key = strtolower($name);

    $cache = campaign_cache_read();
    if (isset($cache[$key]['id'])) {
        return [(string) $cache[$key]['id'], $cache[$key]['pipelineId'] ?? null];
    }

    // Documented as taking no query params. Some Decoda endpoints reject per_page
    // without page, so only fall back to paging if the bare call is refused.
    try {
        $payload = decoda('/opportunity/campaign/list');
    } catch (DecodaError $e) {
        if ($e->httpStatus === 422) {
            $payload = decoda('/opportunity/campaign/list', 'GET', null, ['page' => 1, 'per_page' => 100]);
        } else {
            throw $e;
        }
    }

    $match = null;
    foreach (unwrap_list($payload) as $c) {
        if (!is_array($c) || empty($c['id']) || !isset($c['name'])) continue;
        if (strtolower(trim((string) $c['name'])) !== $key) continue;
        // Prefer an active campaign if the clinic has duplicates of the same name.
        if ($match === null || !empty($c['isActive'])) $match = $c;
        if (!empty($c['isActive'])) break;
    }

    if ($match === null) return [null, null];

    $data = ['id' => (string) $match['id'], 'at' => time()];
    if (!empty($match['pipelineId'])) $data['pipelineId'] = (string) $match['pipelineId'];
    $all = campaign_cache_read();
    $all[$key] = $data;
    @file_put_contents(DECODA_CAMPAIGN_CACHE_FILE, json_encode($all), LOCK_EX);

    return [(string) $match['id'], $match['pipelineId'] ?? null];
}

/* ------------------------------------------------------------------ *
 * Dedupe: find an existing opportunity for this person.
 * `search` covers name, email and phone, but we only accept a strict
 * field match so a fuzzy hit can never attach a lead to the wrong person.
 * ------------------------------------------------------------------ */
function find_existing_opportunity(string $phoneE164, string $phone10, string $email): ?array
{
    $closed = ['WON', 'LOST', 'DISQUALIFIED'];
    $fallback = null;

    foreach (array_filter([$phone10, $phoneE164, $email]) as $term) {
        try {
            $payload = decoda('/opportunity/list', 'GET', null, [
                'search' => $term, 'page' => 1, 'per_page' => 20,
            ]);
        } catch (DecodaError $e) {
            lead_log('opportunity search failed for "' . $term . '": ' . $e->getMessage());
            continue; // never block a lead on a failed search
        }

        foreach (unwrap_list($payload) as $o) {
            if (!is_array($o) || empty($o['id'])) continue;
            $oPhone   = digits_only($o['phoneNumber'] ?? '');
            $phoneHit = strlen($phone10) === 10 && strlen($oPhone) >= 10 && substr($oPhone, -10) === $phone10;
            $emailHit = $email !== '' && strtolower(trim((string) ($o['email'] ?? ''))) === $email;
            if (!$phoneHit && !$emailHit) continue;

            $status = strtoupper((string) ($o['status'] ?? 'OPEN'));
            if (!in_array($status, $closed, true)) return $o;   // an open one wins outright
            if ($fallback === null) $fallback = $o;             // remember a closed one
        }
    }

    // Only closed opportunities matched. Treat this as a fresh enquiry, but tell the log.
    if ($fallback !== null) {
        lead_log('previous opportunity ' . $fallback['id'] . ' is '
            . strtoupper((string) ($fallback['status'] ?? '?')) . ' — creating a new one');
    }
    return null;
}

/** Optional: link the opportunity to an existing patient record. Non-fatal. */
function find_existing_patient(string $phoneE164, string $phone10, string $email): ?string
{
    foreach (array_filter([$phoneE164, $phone10, $email]) as $term) {
        try {
            $payload = decoda('/user/patient/search', 'GET', null, ['search_term' => $term, 'n' => 10]);
        } catch (DecodaError $e) {
            lead_log('patient search skipped for "' . $term . '": ' . $e->getMessage());
            return null; // no patient permission, or the endpoint is unavailable
        }
        foreach (unwrap_list($payload) as $p) {
            if (!is_array($p) || empty($p['id'])) continue;
            $pPhone   = digits_only($p['phoneNumber'] ?? '');
            $phoneHit = strlen($phone10) === 10 && strlen($pPhone) >= 10 && substr($pPhone, -10) === $phone10;
            $emailHit = $email !== '' && strtolower(trim((string) ($p['email'] ?? ''))) === $email;
            if ($phoneHit || $emailHit) return (string) $p['id'];
        }
    }
    return null;
}

/* ------------------------------------------------------------------ *
 * Alerting so a paid lead is never silently lost
 * ------------------------------------------------------------------ */
function alert_failure(string $summary, array $payload): void
{
    lead_log('LEAD_FAILED ' . $summary . ' payload=' . json_encode($payload));

    if (LEAD_ALERT_WEBHOOK_URL === '') return;
    $text = ":rotating_light: Decoda OPPORTUNITY push FAILED (" . basename(__DIR__) . ")\n"
        . $summary . "\n```" . json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . '```';
    @http_send('POST', LEAD_ALERT_WEBHOOK_URL, ['Content-Type' => 'application/json'],
        json_encode(['text' => $text]), 4);
}

/* ------------------------------------------------------------------ *
 * Light per-IP throttle (fails open)
 * ------------------------------------------------------------------ */
function rate_limited(string $ip): bool
{
    if (LEAD_RATE_LIMIT <= 0) return false;
    $file = sys_get_temp_dir() . '/decoda-rl-' . md5($ip) . '.json';
    $now  = time(); $hits = [];
    $raw  = @file_get_contents($file);
    if ($raw !== false && $raw !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            foreach ($decoded as $ts) if (($now - (int) $ts) < LEAD_RATE_WINDOW) $hits[] = (int) $ts;
        }
    }
    if (count($hits) >= LEAD_RATE_LIMIT) return true;
    $hits[] = $now;
    @file_put_contents($file, json_encode($hits), LOCK_EX);
    return false;
}

/* ------------------------------------------------------------------ *
 * Request handling
 * ------------------------------------------------------------------ */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(204); exit; }
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Allow: POST');
    respond(405, ['ok' => false, 'error' => 'Method not allowed']);
}

$raw  = file_get_contents('php://input');
$data = json_decode((string) $raw, true);
if (!is_array($data)) $data = $_POST;

// Honeypot -> pretend success, create nothing.
if (trim((string) ($data[HONEYPOT_FIELD] ?? '')) !== '') {
    respond(200, ['ok' => true]);
}

if (!$__cfg_loaded) {
    $msg = 'FATAL decoda-config.php not found. Looked in: ' . implode(', ', $__cfg_candidates);
    lead_log($msg);
    if (LEAD_DEBUG) respond(200, ['ok' => true, 'queued' => true, 'debug' => $msg]);
}
if ($__page_unmapped) {
    lead_log('WARNING folder "' . $__page_key . '" is not in DECODA_PAGE_MAP — no campaign will be attached');
}

// Validate
$firstName = trim((string) ($data['firstName'] ?? ''));
$lastName  = trim((string) ($data['lastName'] ?? ''));
$email     = strtolower(trim((string) ($data['email'] ?? '')));
$message   = trim((string) ($data['message'] ?? ''));

$phone10 = digits_only($data['phone'] ?? '');
if (strlen($phone10) === 11 && $phone10[0] === '1') $phone10 = substr($phone10, 1);

if ($firstName === '' || $lastName === '') {
    respond(400, ['ok' => false, 'error' => 'Please enter your first and last name.']);
}
if (!preg_match('/^[^\s@]+@[^\s@]+\.[A-Za-z]{2,}$/', $email)) {
    respond(400, ['ok' => false, 'error' => 'Please enter a valid email address.']);
}
if (!preg_match('/^\d{10}$/', $phone10)) {
    respond(400, ['ok' => false, 'error' => 'Please enter a 10-digit US phone number.']);
}
if (rate_limited(client_ip())) {
    lead_log('rate limited ip=' . client_ip() . ' email=' . $email);
    respond(429, ['ok' => false, 'error' => 'Too many submissions. Please try again in a few minutes.']);
}

$phoneE164 = '+1' . $phone10;
$pageUrl   = substr((string) ($data['pageUrl'] ?? ''), 0, 2000);

// Attribution: client-collected values win, anything still on the URL is a fallback.
$attribution = (isset($data['attribution']) && is_array($data['attribution'])) ? $data['attribution'] : [];
$urlParams = [];
$qs = parse_url($pageUrl, PHP_URL_QUERY);
if (is_string($qs) && $qs !== '') parse_str($qs, $urlParams);

$tracked = [
    'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'utm_id',
    'gclid', 'gbraid', 'wbraid', 'fbclid', 'ttclid', 'msclkid', 'li_fat_id',
];
$params = [];
foreach ($tracked as $key) {
    $val = $attribution[$key] ?? ($urlParams[$key] ?? '');
    if (is_string($val) && $val !== '') $params[$key] = substr($val, 0, 512);
}

if (!empty($params['gclid']) || !empty($params['gbraid']) || !empty($params['wbraid'])) $adSource = 'google';
elseif (!empty($params['fbclid']))  $adSource = 'meta';
elseif (!empty($params['ttclid']))  $adSource = 'tiktok';
elseif (!empty($params['msclkid'])) $adSource = 'microsoft';
elseif (!empty($params['utm_source'])) $adSource = (string) $params['utm_source'];
else $adSource = 'other';

$smsConsent   = ($data['smsConsent'] ?? false) === true || ($data['smsConsent'] ?? '') === 'on';
$campaignName = (string) ($__page_row['campaign'] ?? '');
$submittedAt  = gmdate('c');

/**
 * Opportunities have no `meta` object and no custom fields, so all the structured
 * attribution the patient API used to hold has to live in free text. It goes in
 * `notes` (full detail) and `sourceDetail` (a short label the list view can show).
 */
function attribution_block(array $p, array $ctx): string
{
    $lines = [];
    $lines[] = 'Message: ' . ($ctx['message'] !== '' ? $ctx['message'] : '(none given)');
    $lines[] = 'Eligibility quiz: ' . ($ctx['eligibility'] !== '' ? $ctx['eligibility'] : '(not taken)');
    $lines[] = '';
    $lines[] = '--- Ad attribution (from the landing page) ---';
    $lines[] = 'Form: ' . $ctx['form'];
    $lines[] = 'Landing page: ' . ($ctx['pageUrl'] !== '' ? $ctx['pageUrl'] : '(not sent)');
    $lines[] = 'Ad source: ' . $ctx['adSource'];
    foreach ($p as $k => $v) $lines[] = $k . ': ' . $v;
    if ($ctx['referrer'] !== '') $lines[] = 'Referrer: ' . $ctx['referrer'];
    $lines[] = 'SMS marketing consent: ' . ($ctx['smsConsent'] ? 'yes (checkbox ticked)' : 'no');
    $lines[] = 'Submitted: ' . $ctx['submittedAt'];
    return implode("\n", $lines);
}

$ctx = [
    'message'     => $message,
    // Weight-loss LP only: the quiz summary ("Age: … | BMI: … | Result: …").
    'eligibility' => substr(trim((string) ($data['eligibility'] ?? '')), 0, 1000),
    'form'        => DECODA_FORM_NAME,
    'pageUrl'     => $pageUrl,
    'adSource'    => $adSource,
    'referrer'    => substr((string) ($data['referrer'] ?? ''), 0, 500),
    'smsConsent'  => $smsConsent,
    'submittedAt' => $submittedAt,
];
$notes = attribution_block($params, $ctx);

// Short label for the opportunity list view.
$sourceBits = array_filter([
    DECODA_LEAD_SOURCE !== '' ? DECODA_LEAD_SOURCE : DECODA_FORM_NAME,
    $adSource,
    $params['utm_campaign'] ?? null,
]);
$sourceDetail = substr(implode(' · ', $sourceBits), 0, 200);

$oppPayload = [
    'firstName'               => $firstName,
    'lastName'                => $lastName,
    'email'                   => $email,
    'phoneNumber'             => $phoneE164,
    'sourceDetail'            => $sourceDetail,
    'notes'                   => $notes,
    // The consent checkbox covers marketing SMS only, so email opt-in stays false.
    'optedInToSmsMarketing'   => $smsConsent,
    'optedInToEmailMarketing' => false,
];
if (DECODA_LOCATION_ID !== '') $oppPayload['locationId'] = DECODA_LOCATION_ID;

try {
    // 1. Campaign: resolve by name. A missing campaign is not fatal — the
    //    opportunity then lands in the default pipeline.
    $campaignId = $campaignPipelineId = null;

    // A campaign id pinned in the page-map row wins and skips the lookup entirely.
    $pinnedCampaignId = trim((string) ($__page_row['campaignId'] ?? ''));
    if ($pinnedCampaignId !== '') {
        $campaignId = $pinnedCampaignId;
    } elseif ($campaignName !== '') {
        try {
            [$campaignId, $campaignPipelineId] = resolve_campaign($campaignName);
            if ($campaignId === null) {
                lead_log('campaign "' . $campaignName . '" not found in Decoda yet — lead goes to the default pipeline');
            }
        } catch (Throwable $ce) {
            lead_log('campaign lookup failed (' . $ce->getMessage() . ') — continuing without a campaign');
        }
    }
    if ($campaignId !== null) $oppPayload['campaignId'] = $campaignId;

    // Explicit pipeline/stage overrides win over whatever the campaign implies.
    $pipelineId = (string) ($__page_row['pipelineId'] ?? (defined('DECODA_OPP_PIPELINE_ID') ? DECODA_OPP_PIPELINE_ID : ''));
    $stageId    = (string) ($__page_row['stageId']    ?? (defined('DECODA_OPP_STAGE_ID')    ? DECODA_OPP_STAGE_ID    : ''));
    if ($pipelineId !== '') $oppPayload['pipelineId']     = $pipelineId;
    if ($stageId    !== '') $oppPayload['currentStageId'] = $stageId;

    // 2. Dedupe: an open opportunity for this person gets a note instead of a duplicate card.
    $existing = DECODA_OPP_DEDUPE ? find_existing_opportunity($phoneE164, $phone10, $email) : null;

    if ($existing !== null) {
        $oppId = (string) $existing['id'];
        $note  = "Submitted the " . DECODA_FORM_NAME . " again.\n\n" . $notes;
        decoda('/opportunity/' . rawurlencode($oppId) . '/note', 'POST', ['content' => $note]);

        lead_log('LEAD_OK opportunity=' . $oppId . ' deduped=true action=note'
            . ' campaign=' . ($campaignId ?? 'none') . ' source=' . $adSource);
        respond(200, ['ok' => true, 'deduped' => true]);
    }

    // 3. Link a known patient if we can see one. Purely additive; never blocks the lead.
    if (DECODA_LINK_PATIENT) {
        $patientId = find_existing_patient($phoneE164, $phone10, $email);
        if ($patientId !== null) $oppPayload['patientId'] = $patientId;
    }

    // 4. Create the opportunity.
    $created = decoda('/opportunity', 'POST', $oppPayload);
    if (!is_array($created) || empty($created['id'])) {
        throw new DecodaError('Opportunity create returned no id');
    }

    // What Decoda actually kept, which is not necessarily what we sent.
    $storedCampaign = $created['campaignId'] ?? ($created['campaign']['id'] ?? null);
    $storedPipeline = $created['pipelineId'] ?? null;

    lead_log('LEAD_OK opportunity=' . $created['id'] . ' deduped=false'
        . ' campaign_sent=' . ($campaignId ?? 'none')
        . ' campaign_stored=' . ($storedCampaign ?? 'none')
        . ' pipeline_sent=' . ($pipelineId !== '' ? $pipelineId : 'none')
        . ' pipeline_stored=' . ($storedPipeline ?? 'none')
        . ' patient=' . (string) ($created['patientId'] ?? 'none')
        . ' stage=' . (string) ($created['currentStageId'] ?? '?')
        . ' source=' . $adSource);

    // Decoda routes an opportunity through its campaign's pipeline. Sending an explicit
    // pipelineId appears to take precedence and drop the campaign link, so say so loudly
    // rather than leaving an uncampaigned lead to be noticed on the board later.
    if ($campaignId !== null && $storedCampaign === null) {
        lead_log('WARNING campaign ' . $campaignId . ' was sent but Decoda stored none'
            . ($pipelineId !== '' ? ' — an explicit pipelineId (' . $pipelineId . ') is set; try removing it so the campaign assigns the pipeline' : ''));
    }

    $out = ['ok' => true, 'deduped' => false];
    if (LEAD_DEBUG) {
        $out['debug'] = [
            'opportunityId'     => $created['id'],
            'campaignId_sent'   => $campaignId,
            'campaignId_stored' => $storedCampaign,
            'campaignName'      => $campaignName !== '' ? $campaignName : null,
            'pipelineId_sent'   => $pipelineId !== '' ? $pipelineId : null,
            'pipelineId_stored' => $storedPipeline,
            'currentStageId'    => $created['currentStageId'] ?? null,
            'patientId'         => $created['patientId'] ?? null,
            'status'            => $created['status'] ?? null,
            'lifecycleStage'    => $created['lifecycleStage'] ?? null,
            'sourceChannel'     => $created['sourceChannel'] ?? null,
        ];
    }
    respond(200, $out);
} catch (Throwable $e) {
    // The visitor still sees success; we log and alert so no paid lead is lost.
    alert_failure($e->getMessage(), $oppPayload);
    $out = ['ok' => true, 'queued' => true];
    if (LEAD_DEBUG) $out['debug'] = $e->getMessage();
    respond(200, $out);
}