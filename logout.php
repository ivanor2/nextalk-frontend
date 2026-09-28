<?php
require_once 'db.php';

    // Node API could handle status update, but for now we just destroy session
session_destroy();
header('Location: login.php');
exit;