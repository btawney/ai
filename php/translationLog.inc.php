<?php // translationLog.inc.php

require_once('parseAnnotations.inc.php');

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

		if ($data->status != 'DONE_TRANSLATING') {
			continue;
		}

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

class TranslatedText {
    var $summary;
    var $fascicles;
    var $index;

    function __construct() {
        $this->summary = false;
        $this->fascicles = array();
        $this->index = array();
    }

    static function fromLog($path) {
	    $entries = readLog($path);
	    $text = new TranslatedText();

	    $currentFascicle = false;

	    foreach ($entries as $entry) {
  	        $text->summary = $entry->textSummary;

	        if ($currentFascicle == false || $currentFascicle->name != $entry->fascicleName) {
	            $currentFascicle = new TranslatedFascicle($entry->fascicleName);
	            $text->fascicles[] = $currentFascicle;
	        }

	        $currentFascicle->summary = $entry->fascicleSummary;
	        $currentParagraph = new TranslatedParagraph($entry->paragraphNumber);
	        $currentFascicle->paragraphs[] = $currentParagraph;

	        $tLines = explode("\n", $entry->translation);
	        $inNotes = false;
	        foreach ($tLines as $tLine) {
		        $tLine = trim($tLine);

		        if ($tLine == 'END_OF_TRANSLATION') {
		            $inNotes = true;
		            continue;
		        } elseif (strlen($tLine) == 0) {
		            continue;
		        }

		        if ($inNotes) {
		            $currentParagraph->notes[] = $tLine;
		        } else {
		            $currentParagraph->translation[] = $tLine;
		        }
	        }

	        $currentParagraph->properNouns = parseAnnotation($entry->properNouns);

	        foreach ($currentParagraph->properNouns as $item) {
	        	$text->appendIndexEntry($item->source, $item->type, $item->target, $currentParagraph, $item->notes);
	        }
	    }

	    return $text;
    }

    function appendIndexEntry($name, $type, $translation, $paragraph, $notes) {
    	if (empty($this->index[$name])) {
    		$entry = new IndexEntry($name);
    		$this->index[$name] = $entry;
    	} else {
    		$entry = $this->index[$name];
    	}

    	return $entry->append($type, $translation, $paragraph, $notes);
    }
}

class TranslatedFascicle {
    var $name;
    var $summary;
    var $paragraphs;

    function __construct($name) {
        $this->name = $name;
        $this->summary = false;
        $this->paragraphs = array();
    }
}

class TranslatedParagraph {
    var $number;
	var $translation;
	var $notes;
	var $properNouns;

	function __construct($number) {
	    $this->number = $number;
	    $this->translation = array();
	    $this->notes = array();
		$this->properNouns = array();
	}

	function getProperNounIndexes() {
		// Sort the proper nouns by length descending
		$lengthProperNouns = array();
		foreach ($this->properNouns as $properNoun) {
			$length = strlen($properNoun->target);
			if (empty($lengthProperNouns[$length])) {
				$lengthProperNouns[$length] = array($properNoun->target => $properNoun);
			} else {
				$lengthProperNouns[$length][$properNoun->target] = $properNoun;
			}
		}
		krsort($lengthProperNouns);

		// Find all instances of proper nouns in translation
		$matches = array();
		$matched = array();

		// PROBLEM WAS TRANSLATION IS AN ARRAY
		$translationLength = strlen($this->translation);
		for ($i = 0; $i < $translationLength; ++$i) {
			foreach ($lengthProperNouns as $length => $properNouns) {
				$toCompare = substr($this->translation, $i, $length);

				if (isset($properNouns[$toCompare])) {
					$matches[$i] = $properNouns[$toCompare];
					$matched[$toCompare] = true;
					$i += $length - 1;
					break;
				}
			}
		}

		// Add in unmatched items with negative offsets
		$o = 0;
		foreach ($this->properNouns as $properNoun) {
			if (empty($matched[$properNoun->target])) {
				$matches[--$o] = $properNoun;
			}
		}

		return $matches;
	}
}

class IndexEntry {
	var $name;
	var $types;
	var $index;

	function __construct($name) {
		$this->name = $name;
		$this->types = array();
	}

	function append($type, $translation, $paragraph, $notes) {
		if (empty($this->types[$type])) {
			$entry = new IndexEntryType($type);
			$this->types[$type] = $entry;
		} else {
			$entry = $this->types[$type];
		}

		return $entry->append($translation, $paragraph, $notes);
	}
}

class IndexEntryType {
	var $type;
	var $translations;

	function __construct($type) {
		$this->type = $type;
		$this->translations = array();
	}

	function append($translation, $paragraph, $notes) {
		if (empty($this->translations[$translation])) {
			$entry = new IndexEntryTranslation($translation);
			$this->translations[$translation] = $entry;
		} else {
			$entry = $this->translations[$translation];
		}

		$entry->append($paragraph, $notes);

		return $entry;
	}
}

class IndexEntryTranslation {
	var $translation;
	var $notes;
	var $paragraphs;

	function __construct($translation) {
		$this->translation = $translation;
		$this->notes = array();
		$this->paragraphs = array();
	}

	function append($paragraph, $notes) {
		$this->paragraphs[] = $paragraph;

		if (strlen($notes) > 0) {
			$exists = false;
			foreach ($this->notes as $note) {
				if ($note == $notes) {
					$exists = true;
					break;
				}
			}

			if (!$exists) {
				$this->notes[] = $notes;
			}
		}
	}
}