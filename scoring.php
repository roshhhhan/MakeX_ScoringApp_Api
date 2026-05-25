<?php
if (function_exists('opcache_invalidate')) {
    @opcache_invalidate(__FILE__, true);
    @opcache_invalidate(__DIR__ . '/score_submit.php', true);
}
if (function_exists('opcache_reset')) {
    @opcache_reset();
}
clearstatcache(true, __FILE__);
clearstatcache(true, __DIR__ . '/score_submit.php');

if (isset($_GET['action']) && $_GET['action'] === 'get_today_matches') {
    require_once __DIR__ . '/scoring_championship.php';
    exit();
}

require_once 'score_submit.php';
