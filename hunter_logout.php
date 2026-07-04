<?php
session_start();
session_destroy();
header('Location: hunter_login.php');
exit;
