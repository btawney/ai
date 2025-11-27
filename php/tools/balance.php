<?php // balance.php

require_once('../deepseek.inc.php');

$session = new \Deepseek\Session();

print "Balance: " . $session->balance() . "\n";

