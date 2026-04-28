<?php
$baseUrl = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
$stylePath = $_SERVER['DOCUMENT_ROOT'] . $baseUrl . '/assets/style.css';
?>

<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<link rel="shortcut icon" href="<?= $baseUrl ?>/assets/images/logo.svg">

<link rel="icon" href="<?= $baseUrl ?>/assets/favicon.ico">

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">

<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">

<link rel="stylesheet" href="<?= $baseUrl ?>/assets/style.css?v=<?= file_exists($stylePath) ? filemtime($stylePath) : time() ?>">