<?php // text.inc.php

class Text {
	var $title;
	var $name;
	var $fascicles;
	var $firstFascicle;
	var $firstParagraph;

	static $cache = array();
	static function fromPath($path) {
		if (isset(Text::$cache[$path])) {
			return Text::$cache[$path];
		}

		$text = new Text($path);
		Text::$cache[$path] = $text;

		return $text;
	}

	function __construct($path) {
		$this->name = basename($path, '.txt');
		$this->fascicles = array();
		$this->firstFascicle = false;
		$this->firstParagraph = false;
		$this->title = false;

		$f = fopen($path, 'r');
		$currentFascicle = false;
		$currentParagraph = false;
		$lastParagraph = '';

		$lineNumber = 0;

		while (($line = fgets($f)) !== false) {
			++$lineNumber;

			if (preg_match('/^title:(.*)$/', $line, $matches)) {
				$this->title = trim($matches[1]);
			} elseif (preg_match('/^fascicle:([0-9]+[a-d]*)[ \t\r\n]*$/', $line, $matches)) {
				$newFascicleName = trim($matches[1]);

				if (isset($this->fascicles[$newFascicleName])) {
					throw new Exception("Duplicate fascicle name: $this->name $newFascicleName on line $lineNumber");
				}

				$newFascicle = new Fascicle($this, $newFascicleName);
				$this->fascicles[$newFascicleName] = $newFascicle;

				if ($currentFascicle === false) {
					$this->firstFascicle = $newFascicle;
				} else {
					$currentFascicle->nextFascicle = $newFascicle;
				}
				$currentFascicle = $newFascicle;
			} elseif (preg_match('/^([0-9]+[a-d]*)[.]([0-9]{4})[|](.*)$/', $line, $matches)) {
				$fascicleName = $matches[1];
				$paragraphNumber = $matches[2];
				$content = trim($matches[3]);

				if ($fascicleName != $currentFascicle->name) {
					throw new Exception("Paragraph in wrong fascicle: $this->name $fascicleName.$paragraphNumber on line $lineNumber");
				}

				if (isset($currentFascicle->paragraphs[$paragraphNumber])) {
					throw new Exception("Duplicate paragraph number: $this->name $fascicleName.$paragraphNumber on line $lineNumber");
				}

				$newParagraph = new Paragraph($currentFascicle, $paragraphNumber, $content);
				$currentFascicle->paragraphs[$paragraphNumber] = $newParagraph;

				if ($currentFascicle->firstParagraph === false) {
					$currentFascicle->firstParagraph = $newParagraph;
				}

				if ($currentParagraph === false) {
					$this->firstParagraph = $newParagraph;
				} else {
					$currentParagraph->nextParagraph = $newParagraph;
				}
				$currentParagraph = $newParagraph;
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
	var $text;
	var $name;
	var $paragraphs;
	var $nextFascicle;
	var $firstParagraph;
	
	function __construct($text, $name) {
		$this->text = $text;
		$this->name = $name;
		$this->paragraphs = array();
		$this->nextFascicle = false;
		$this->firstParagraph = false;
	}
}

class Paragraph {
	var $fascicle;
	var $number;
	var $content;
	var $nextParagraph;

	function __construct($fascicle, $number, $content) {
		$this->fascicle = $fascicle;
		$this->number = $number;
		$this->content = $content;
		$this->nextParagraph = false;
	}
}