<?php
// tests/bootstrap.php

// Define test environment
define('UMD_TESTING', true);

// Load WordPress mock functions
require_once __DIR__ . '/mock-functions.php';

// Load Composer autoloader
require_once dirname(__DIR__) . '/vendor/autoload.php';

// Load your plugin main file
require_once dirname(__DIR__) . '/ultimate-media-deletion.php';