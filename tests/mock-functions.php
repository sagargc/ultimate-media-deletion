<?php
// tests/mock-functions.php

if (!function_exists('add_action')) {
    function add_action($hook, $callback, $priority = 10, $args = 1) {
        // Mock implementation
    }
}

// Add other WordPress function mocks as needed