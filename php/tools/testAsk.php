<?php // testAsk.php

require_once('../deepseek.inc.php');

$question = '';

foreach ($argv as $arg) {
	$question .= $arg . ' ';
}

$session = (new \Deepseek\Session())
  ->echo()
  ->onError(function($e) {
  	print "ERROR: $e\n";
  })
  ->onLimitExceeded(function ($limit) {
  	print "LIMIT EXCEEDED: $limit\n";
  });

$startTime = time();

$convo = $session->conversation();
$convo->ask($question);

print "Elapsed Seconds: " . (time() - $startTime) . "\n";
print "Usage:           " . $session->usage() . "\n";
