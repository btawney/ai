<?php // exportTranslation.php

require_once('../translationLog.inc.php');

$force = false;
$source = false;
$target = false;

function help() {
	print "Usage:\n";
	print '  ' . $argv[0] . "exportTranslation.php [-force] <source-log> <target-text>\n";
}

$ps = 0; // Expect command
foreach ($argv as $arg) {
	switch ($ps) {
		case 0: // Expect command
			$ps = 1; // Expect arguments
			break;
		case 1: // Expect arguments
			if ($arg == '-force' || $arg == '--force') {
				$force = true;
			} else if ($source === false) {
				if (file_exists($arg)) {
					$source = $arg;
				} else {
					print "Source log not found: $arg\n";
					help();
					exit();
				}
			} else if ($target === false) {
				if ($force || (!file_exists($target))) {
					$target = $arg;
				} else {
					print "Target text exists and -force not specified: $arg\n";
					help();
					exit();
				}
			}
			break;
	}
}

$text = TranslatedText::fromLog($source);

$f = fopen($target, 'w');

fputs($f, "title:\n");

fputs($f, "textSummary[\n");
fputs($f, trim($text->summary));
fputs($f, "\n]textSummary\n");

foreach ($text->fascicles as $fascicle) {
	fputs($f, "fascicle:$fascicle->name\n");

	fputs($f, "fascicleSummary[\n");
	fputs($f, trim($fascicle->summary));
	fputs($f, "\n]fascicleSummary\n");

	foreach ($fascicle->paragraphs as $paragraph) {
		foreach ($paragraph->translation as $line) {
			fputs($f, "$fascicle->name.$paragraph->number|$line\n");
		}
		foreach ($paragraph->notes as $line) {
			fputs($f, "$fascicle->name.$paragraph->number.NOTES|$line\n");
		}
	}
}

fclose($f);