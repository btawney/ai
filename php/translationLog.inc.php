<?php // translationLog.inc.php

function appendLog($state, $outputFile) {
    // Write to output file 
    $data = array(
    	'time' => time(),
    	'elapsedTime' => $state->endTime - $state->startTime,
    	'consumedCost' => $state->endBalance - $state->startBalance,
    	'status' => $state->status,
    	'statusDetail' => $state->statusDetail,
    	'fascicleName' => $state->fascicleName,
    	'paragraphNumber' => $state->paragraphNumber,
    	'textSummary' => $state->textSummary,
    	'fascicleSummary' => $state->fascicleSummary,
    	'translation' => $state->translation,
    	'properNouns' => $state->properNouns,
    	'consecutiveErrorCount' => $state->consecutiveErrorCount
    );

    if (file_exists($outputFile)) {
	    $f = fopen($outputFile, 'a');
    } else {
	    $f = fopen($outputFile, 'w');
	    fputs($f, "LOGVERSION:2\n");
    }
    fputs($f, json_encode($data) . "\n");
    fclose($f);
}

function readLog($path) {
	$entries = array();

	$f = fopen($path, 'r');
	$header = trim(fgets($f));

	if (preg_match('/^LOGVERSION:(.*)$/', $header, $matches)) {
		$version = $matches[1];
	} else {
		$version = '0';
		fseek($f, 0);
	}

	while (($line = fgets($f)) !== false) {
		$data = json_decode($line);
		$entry = new LogEntry();
		$entries[] = $entry;

		switch ($version) {
			case '1':
				$entry->elapsedTime = $data->elapsedTime;
				$entry->consumedCost = $data->consumedCost;
				$entry->status = $data->status;
				$entry->statusDetail = $data->statusDetail;
				$entry->fascicleName = $data->fascicleName;
				$entry->paragraphNumber = $data->paragraphNumber;
				$entry->textSummary = $data->textSummary;
				$entry->fascicleSummary = $data->fascicleSummary;
				$entry->translation = $data->translation;
				$entry->properNouns = $data->properNouns;
				$entry->consecutiveErrorCount = $data->consecutiveErrorCount;
				break;
			case '2':
				$entry->time = $data->time;
				$entry->elapsedTime = $data->elapsedTime;
				$entry->consumedCost = $data->consumedCost;
				$entry->status = $data->status;
				$entry->statusDetail = $data->statusDetail;
				$entry->fascicleName = $data->fascicleName;
				$entry->paragraphNumber = $data->paragraphNumber;
				$entry->textSummary = $data->textSummary;
				$entry->fascicleSummary = $data->fascicleSummary;
				$entry->translation = $data->translation;
				$entry->properNouns = $data->properNouns;
				$entry->consecutiveErrorCount = $data->consecutiveErrorCount;
				break;
		}
	}

	return $entries;
}

class LogEntry {
	var $time;
	var $elapsedTime;
	var $consumedCost;
	var $status;
	var $statusDetail;
	var $fascicleName;
	var $paragraphNumber;
	var $textSummary;
	var $fascicleSummary;
	var $translation;
	var $properNouns;
	var $consecutiveErrorCount;
}