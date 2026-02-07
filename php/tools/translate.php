<?php // translate.php

require_once('../translate.inc.php');
require_once('../translationLog.inc.php');
require_once('../deepseek.inc.php');

$sourceFile = false;
$maximumConsecutiveErrors = 5;
$maximumRunTimeInMinutes = 5;
$minimumBalanceInDollars = 5;
$workDirectory = '../../work';

$ps = 0; // Default
foreach ($argv as $arg) {
	switch ($ps) {
		case 0:
			$ps = 1; // Expect argument
			break;
		
		case 1: // Expect argument
			if (preg_match('/^--?max-?errors$/i', $arg)) {
				$ps = 2; // Expect max errors
			} elseif (preg_match('/^--?max-?run-?time$/i', $arg)) {
				$ps = 3; // Expect max runtime
			} elseif (preg_match('/^--?min-?balance$/i', $arg)) {
				$ps = 4; // Expect min balance
			} elseif (preg_match('/^--?work-?dir$/i', $arg)) {
				$ps = 5; // Expect work dir
			} elseif ($sourceFile === false && file_exists($arg)) {
				$sourceFile = $arg;
			} else {
				print "Unrecognized argument: $arg\n";
				exit();
			}
			break;

		case 2: // max errors
			if (is_numeric($arg)) {
				$maximumConsecutiveErrors = $arg;
				$ps = 1; // Expect argument
			} else {
				print "Expected a numeric argument for maximum consecutive errors\n";
				exit();
			}
			break;

		case 3: // max runtime
			if (is_numeric($arg)) {
				$maximumRunTimeInMinutes = $arg;
				$ps = 1; // Expect argument
			} else {
				print "Expected a numeric argument for maximum runtime in minutes\n";
				exit();
			}
			break;

		case 4: // min balance
			if (is_numeric($arg)) {
				$minimumBalanceInDollars = $arg;
				$ps = 1; // Expect argument
			} else {
				print "Expected a numeric argument for minimum balance in dollars\n";
				exit();
			}
			break;

		case 5: // work dir
			if (is_dir($arg)) {
				$workDirectory = $arg;
				$ps = 1; // Expect argument
			} else {
				print "Expected an existing work directory, got $arg\n";
				exit();
			}
			break;

		default:
			// code...
			break;
	}
}

if ($ps != 1) {
	print "Incomplete argument\n";
	exit();
}

$stateFile = "$workDirectory/" . basename($sourceFile, '.txt') . '.state';
$outputFile = "$workDirectory/" . basename($sourceFile, '.txt') . '.out';

if (file_exists($stateFile)) {
	$state = TranslationState::fromWorkFile($stateFile);
} else {
	$state = TranslationState::initial($sourceFile, '../translate.prompts', $stateFile);
}

$session = new \Deepseek\Session();

$endTime = time() + $maximumRunTimeInMinutes * 60;
while (
	$state->moreToTranslate()
	&& $state->consecutiveErrorCount < $maximumConsecutiveErrors
	&& time() < $endTime
	&& $session->balance() > $minimumBalanceInDollars
	) {
	print "Fascicle $state->fascicleName paragraph $state->paragraphNumber\n";

    $state->getReadyToTranslate();
    $state->translate($session);
    $state->save();

    appendLog($state, $outputFile);
}