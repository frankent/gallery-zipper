<?php

/**
 * For Shared host use file_get_contents() to access route
 */
$url = "http://gallery.cloudnds.com/api/make-zip";
file_get_contents($url);

/**
 * For VPS use php artisan run instead
 * php artisan gallery-create-zip
 */
