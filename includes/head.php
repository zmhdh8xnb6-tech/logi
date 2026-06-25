<?php
$baseUrl = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');

if (!function_exists('assetUrl')) {
    function assetUrl(string $path): string
    {
        global $baseUrl;

        $path = ltrim($path, '/');
        $absolutePath = $_SERVER['DOCUMENT_ROOT'] . $baseUrl . '/' . $path;
        $version = file_exists($absolutePath) ? filemtime($absolutePath) : time();

        return $baseUrl . '/' . $path . '?v=' . $version;
    }
}
?>

<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<link rel="shortcut icon" href="<?= assetUrl('assets/images/logo.svg') ?>">

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">

<link rel="stylesheet" href="<?= assetUrl('assets/style.css') ?>">
<link rel="stylesheet" href="<?= assetUrl('assets/sidebar.css') ?>">

<script src="<?= assetUrl('assets/sidebar.js') ?>" defer></script>