<?php // text.inc.php

class Text {
	var $title;
	var $name;
	var $fascicles;
	var $fascicleIndex;

	function __construct($path) {
		$this->name = basename($path, '.txt');
		$this->fascicles = array();
		$this->fascicleIndex = array();
		$this->title = false;

		$f = fopen($path, 'r');
		$currentFascicle = false;
		$currentFascicleName = false;
		$lastParagraph = '';

		$lineNumber = 0;

		while (($line = fgets($f)) !== false) {
			++$lineNumber;

			if (preg_match('/^([0-9]+[a-d]*)[.]([0-9]{4})[|](.*)$/', $line, $matches)) {
				$fascicleName = $matches[1];
				$paragraphNumber = $matches[2];
				$content = trim($matches[3]);

				if ($fascicleName != $currentFascicleName) {
					throw new Exception("Paragraph in wrong fascicle: $this->name $fascicleName.$paragraphNumber on line $lineNumber");
				}

				if (isset($currentFascicle->paragraphs[$paragraphNumber])) {
					throw new Exception("Duplicate paragraph number: $this->name $fascicleName.$paragraphNumber on line $lineNumber");
				}

				$currentFascicle->paragraphs[$paragraphNumber] = new Paragraph($paragraphNumber, $content);
				$currentFascicle->paragraphIndex[] = $paragraphNumber;

				$lastParagraph = $paragraphNumber;
			} elseif (preg_match('/^fascicle:([0-9]+[a-d]*)[ \t\r\n]*$/', $line, $matches)) {
				$newFascicleName = trim($matches[1]);

				if (isset($this->fascicles[$newFascicleName])) {
					throw new Exception("Duplicate fascicle name: $this->name $newFascicleName on line $lineNumber");
				}

				$currentFascicle = new Fascicle($newFascicleName, $lineNumber);

				$this->fascicles[$newFascicleName] = $currentFascicle;
				$this->fascicleIndex[] = $newFascicleName;

				$currentFascicleName = $newFascicleName;
				$lastParagraph = '';
			} elseif (preg_match('/^title:(.*)$/', $line, $matches)) {
				$this->title = trim($matches[1]);
			} elseif (preg_match('/^ *#/', $line)) {
				// Ignore comments
			} else {
				throw new Exception("Unrecognized line in $this->name on line $lineNumber: $line");
			}
		}
		fclose($f);
	}
}

class Fascicle {
	var $name;
	var $paragraphs;
	var $paragraphIndex;

	// Informational
	var $startsOnLineNumber;
	
	function __construct($name, $startsOnLineNumber) {
		$this->name = $name;
		$this->paragraphs = array();
		$this->paragraphIndex = array();

		$this->startsOnLineNumber = $startsOnLineNumber;
	}
}

class Paragraph {
	var $number;
	var $content;

	function __construct($number, $content) {
		$this->number = $number;
		$this->content = $content;
	}
}