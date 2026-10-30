<?php
/**
 * decoda-config.php  —  SERVER-SIDE SECRETS. Shared by every IFAL ad landing page.
 *
 * WHERE THIS GOES: one level above public_html, i.e. next to the public_html folder:
 *     /home/customer/www/<yourdomain>/decoda-config.php          <- this file, ONE copy
 *     /home/customer/www/<yourdomain>/public_html/lp/co2-laser-resurfacing/lead.php
 *     /home/customer/www/<yourdomain>/public_html/lp/hydrafacial/lead.php
 *     /home/customer/www/<yourdomain>/public_html/lp/laser-hair-removal/lead.php
 *     /home/customer/www/<yourdomain>/public_html/lp/weight-loss/lead.php
 *
 * lead.php looks in its own folder first, then 1, 2 and 3 levels up, and loads every
 * config it finds. A PHP constant keeps its first definition, so the CLOSEST file wins.
 * You normally only need this one file: per-page values come from DECODA_PAGE_MAP below,
 * keyed by the LP's folder name. Drop a small decoda-config.php into a single LP folder
 * only if that one page needs to deviate.
 *
 * After uploading: set permissions to 600 (File Manager → right-click → Change Permissions).
 * Never commit this file to a public repo and never paste the key into Slack or a chat.
 */

// ---- Credentials (from the password manager) --------------------------------
defined('DECODA_API_KEY') || define('DECODA_API_KEY', 'PASTE_THE_KEY_HERE');
defined('DECODA_TENANT')  || define('DECODA_TENANT', 'institute-for-ageless-living');

// Location the leads belong to. Without this, new patients have no location and are
// hidden from Decoda's location-filtered Patients list.
// This is the ID on the practice's existing patient records (Institute for Ageless Living).
defined('DECODA_LOCATION_ID') || define('DECODA_LOCATION_ID', 'loc_af81f9374e624133');

// ---- Per landing page, keyed by FOLDER NAME under /lp/ ----------------------
// 'tag'    -> Decoda patient tag, created automatically if it doesn't exist yet.
//             Every lead also gets "AEVA Ads Lead" on top of this one.
// 'form'   -> stored in meta.form, for attribution inside Decoda.
// 'campaignId' -> OPPORTUNITY pages only: the Decoda campaign (cpn_…) the lead lands in,
//             which puts it on that campaign's pipeline. Leave '' and the lead goes to the
//             default pipeline. decoda-check.php lists every campaign id in the account.
//             (Alternative: 'campaign' => 'Exact Campaign Name', resolved and cached by lead.php.)
// 'source' -> the "Source:" line in Decoda's Patients list. Reuse a string the practice
//             already has (GET /user/patient/lead-sources) or their dropdown fills up with
//             near-duplicates. Decoda upper-cases whatever you send.
//             Keep "C02" spelled exactly as Decoda already has it — that typo is theirs.
//
// Add a page by adding a row. The key must match the folder name exactly.
defined('DECODA_PAGE_MAP') || define('DECODA_PAGE_MAP', [
    'laser-hair-removal' => [
        'tag'    => 'Ads - Laser Hair Removal',
        'form'   => 'IFAL LHR Ad Form',
        'source' => '60% LHR AD FORM',
    ],
    'co2-laser-resurfacing' => [
        'tag'    => 'Ads - CO2 Laser',
        'form'   => 'IFAL CO2 Ad Form',
        'source' => '60% C02 LASER AD FORM',
    ],
    'hydrafacial' => [
        'tag'    => 'Ads - HydraFacial',
        'form'   => 'IFAL HydraFacial Ad Form',
        'source' => 'HYDRAFACIAL AD FORM',
    ],
    'weight-loss' => [
        'campaignId' => 'cpn_9cbf6764d5e3f911',
        'tag'    => 'Ads - Weight Loss',
        'form'   => 'IFAL Weight Loss Ad Form',
        'source' => 'WEIGHT LOSS AD FORM',
    ],

    // Test folders, so staging traffic is tagged the same as production:
    'laser-hair-removal-test'    => [
        'tag'    => 'Ads - Laser Hair Removal',
        'form'   => 'IFAL LHR Ad Form',
        'source' => '60% LHR AD FORM',
    ],
    'co2-laser-resurfacing-test' => [
        'tag'    => 'Ads - CO2 Laser',
        'form'   => 'IFAL CO2 Ad Form',
        'source' => '60% C02 LASER AD FORM',
    ],
    'hydrafacial-test'           => [
        'tag'    => 'Ads - HydraFacial',
        'form'   => 'IFAL HydraFacial Ad Form',
        'source' => 'HYDRAFACIAL AD FORM',
    ],
    'weight-loss-test'           => [
        'campaignId' => 'cpn_9cbf6764d5e3f911',
        'tag'    => 'Ads - Weight Loss',
        'form'   => 'IFAL Weight Loss Ad Form',
        'source' => 'WEIGHT LOSS AD FORM',
    ],
]);
// (July event LP: not listed — no live ad spend, per the handoff.)

// ---- Diagnostics ------------------------------------------------------------
// Any random string. Used only by decoda-check.php, which you delete after launch.
defined('DECODA_CHECK_TOKEN') || define('DECODA_CHECK_TOKEN', 'CHANGE_ME_TO_SOMETHING_RANDOM');

// Uncomment ONLY while debugging: returns the real Decoda error in the JSON response.
// defined('LEAD_DEBUG') || define('LEAD_DEBUG', true);

// Slack incoming webhook — pings you if a lead ever fails to reach Decoda.
// Leave empty to rely on decoda-leads.log alone.
defined('LEAD_ALERT_WEBHOOK_URL') || define('LEAD_ALERT_WEBHOOK_URL', '');

// ---- Log + tag-ID cache (written next to this file, outside the web root) ----
// All three LPs share these, which is fine: the log is append-only with file locking,
// and the tag cache is keyed by tag name.
defined('LEAD_LOG_FILE')       || define('LEAD_LOG_FILE', __DIR__ . '/decoda-leads.log');
defined('LEAD_TAG_CACHE_FILE') || define('LEAD_TAG_CACHE_FILE', __DIR__ . '/decoda-tags.json');

// Per-IP throttle: max submissions per window (seconds). 0 disables it.
defined('LEAD_RATE_LIMIT')  || define('LEAD_RATE_LIMIT', 8);
defined('LEAD_RATE_WINDOW') || define('LEAD_RATE_WINDOW', 600);
