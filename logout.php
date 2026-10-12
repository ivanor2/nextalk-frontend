<?php
require_once __DIR__ . '/src/Bootstrap.php';

    // Node API could handle status update, but for now we just destroy session
session_destroy();
header('Location: login.php');
exit;