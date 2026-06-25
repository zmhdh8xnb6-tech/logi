<?php
require 'config.php';

if (usuarioLogado()) {
    header('Location: home.php');
    exit;
}

header('Location: login.php');
exit;
