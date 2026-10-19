<?php
/**
 * Configuration template.
 *
 * Copy this file to `.config.php` and fill in your Anthropic API key.
 * `.config.php` is git-ignored and must never be committed.
 *
 * The `.htaccess` in the project root denies direct web access to any
 * dot-file, so `.config.php` is not reachable over HTTP.
 */

if (!defined('ENTRY_POINT')) {
    die('Direct access not allowed');
}

// Your Anthropic API key (https://console.anthropic.com/).
define('ANTHROPIC_API_KEY', 'sk-ant-xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx');

// Model used to grade open-ended answers.
define('ANTHROPIC_MODEL', 'claude-haiku-4-5');

// Anthropic Messages API endpoint and version.
define('ANTHROPIC_API_URL', 'https://api.anthropic.com/v1/messages');
define('ANTHROPIC_API_VERSION', '2023-06-01');
