<?php // readable.inc.php

class ReadableType {
	var $type;
	var $
}

class ReadableTypeValue {

}

class Readable {
	var $path;
	var $keyValuePairs;

	function __construct($path) {
		$this->path = $path;

		if (file_exists($path)) {
			$f = fopen($path, 'r');
			$ps = 0; // Default
			while (($line = fgets($f)) !== false) {
				switch ($ps) {
					case 0: // Default
						$trimmed = trim($line);

						if (strlen($trimmed) == 0) {
							// Do nothing, this is a blank line
						} elseif (substr($trimmed, 0, 1) == '#') {
							// Do nothing, this is a comment
						} else {
							// Colon and pipe are semantically identical
							$indexOfColon = strpos($line, ':');
							$indexOfPipe = strpos($line, '|');

							if ($indexOfColon === false) {
								if ($indexOfPipe === false) {
									throw new Exception("")
								}
							}
						}
						break;
				}
			}
			fclose($f);
		}
	}

	function parse($path) {

	}
}

class KeyValuePair {
	var $key;
	var $value;
}