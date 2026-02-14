<?php // fix.php <source-file> [-force | -check] <target-file> <fix-name> [<fix-name> [<fix-name> ...]]

$fixers = allFixers();
$source = false;

$blockPattern = '/^([0-9][0-9][0-9])[.]([0-9][0-9][0-9][0-9])[|](.*)$/';

$ps = 0; // Expect php script name

$source = false;
$target = false;
$force = false;
$check = false;
$fixersToApply = array();
$tempFiles = array();

foreach ($argv as $arg) {
	switch ($ps) {
		case 0: // Expect php script name
			$ps = 1; // Expect fix name
			break;
		case 1: // Expect source file
			if (file_exists($arg)) {
				$source = $arg;
				$ps = 2; // Expect target file
			} elseif ($arg == '-help' || $arg == '--help' || $arg == '-?' || $arg == '--?') {
				print "Usage:\n";
				print "  php fix.php <source-file> -check <fixers...>\n";
				print "  php fix.php <source-file> [-force] <target-file> <fixers...>\n";
				print "Fixers:\n";
				foreach ($fixers as $name => $fixer) {
					print "  $name\n";
				}
				exit();
			} else {
				print "Source file not found: $arg\n";
				exit();
			}
			break;
		case 2: // Expect target file
			if ($arg == '-force') {
				$force = true;
			} elseif ($arg == '-check') {
				$check = true;
				$ps = 3; // Expect fixer name
			} else {
				if (file_exists($arg) && $force == false) {
					print "Target file exists and -force not specified: $arg\n";
					exit();
				} else {
					if (isset($fixers[$arg])) {
						print "WARNING: target file looks like a fixer name: $arg\n";
					}

					$target = $arg;
					$ps = 3; // Expect fixer name
				}
			}
			break;
		case 3: // Expect fixer name
			if (isset($fixers[$arg])) {
				$fixersToApply[] = new FixerToApply($arg, $fixers[$arg]);
			} else {
				print "Unrecognized fixer name: $arg\n";
				exit();
			}
			break;
	}
}

if ($ps == 1) {
	print "No arguments specified. Try -help\n";
	exit();
}

if ($ps == 2) {
	print "No target or fixers specified. Try -help\n";
	exit();
}

if ($force == true && $check == true) {
	print "WARNING: It is meaningless to specify -force and -check at the same time. Ignoring -force\n";
}

if (count($fixersToApply) == 0) {
	print "No fixers specified. Try -help\n";
	exit();
}

$immediateSource = $source;
$immediateTarget = tempFile();
foreach ($fixersToApply as $fixer) {
	print "$fixer->name...";
	$c = $fixer->fix($immediateSource, $immediateTarget);
	print "$c\n";

	$immediateSource = $immediateTarget;
	$immediateTarget = tempFile();
}

if ($check == false) {
	rename($immediateSource, $target);
}

cleanupTempFiles();

class FixerToApply {
	var $name;
	var $fixer;
	var $arguments;

	function __construct($name, $fixer) {
		$this->name = $name;
		$this->fixer = $fixer;
		$this->arguments = array();
	}

	function fix($source, $target) {
		return $this->fixer->fix($source, $target, $this->arguments);
	}
}

class TextFixer {
	// Function, takes two paths, reads from input writes to output
	var $fix;

	function __construct($fix) {
		$this->fix = $fix;
	}

	function fix($source, $target, &$arguments) {
		$f = $this->fix;
		return $f($source, $target, $arguments);
	}
}

class BlockFixer {
	// Regex to match against trimmed content part of block
	var $pattern;

	// Function, takes trimmed content part, returns new content part
	var $fix;

	function __construct($pattern, $fix) {
		$this->pattern = $pattern;
		$this->fix = $fix;
	}

	function fix($source, $target, &$arguments) {
		global $blockPattern;

		$fix = $this->fix;

		$i = fopen($source, 'r');
		$o = fopen($target, 'w');
		$c = 0;

		while (($line = fgets($i)) !== false) {
			if (preg_match($blockPattern, $line, $matches)) {
				$content = trim($matches[3]);
				if (preg_match($this->pattern, $content)) {
					$newContent = $fix($content, $arguments);
					fputs($o, $matches[1] . '.' . $matches[2] . '|' . $newContent . "\n");
					if ($newContent != $content) {
						++$c;
					}
				} else {
					fputs($o, $line);
				}
			} else {
				fputs($o, $line);
			}
		}

		fclose($o);
		fclose($i);

		return $c;
	}
}

function tempFile() {
	global $tempFiles;

	if (is_dir('/tmp')) {
		$prefix = '/tmp/temp';
	} else {
		$prefix = './temp';
	}

	$prefix .= date('YmdHis');

	$i = 0;
	$path = $prefix . sprintf('%04d', $i);
	while (file_exists($path)) {
		++$i;
		$path = $prefix . sprintf('%04d', $i);
	}

	$tempFiles[] = $path;

	return $path;
}

function cleanupTempFiles() {
	global $tempFiles;

	foreach ($tempFiles as $tempFile) {
		if (file_exists($tempFile)) {
			unlink($tempFile);
		}
	}
}

function allFixers() {
	return array(
		'removeBlankBlocks' => new TextFixer(function($s, $t, &$a) { return removeBlankBlocks($s, $t); }),
		'removeFootnotes' => new BlockFixer('/\[[0-9]+\]/', function($s, &$a) { return removeFootnotes($s); }),
		'removeFascicleTrailerNavigation' => new TextFixer(function($s, $t, &$a) { return removeFascicleTrailerNavigation($s, $t); }),
		'removeFascicleTrailerNavigation2' => new TextFixer(function($s, $t, &$a) { return removeFascicleTrailerNavigation2($s, $t); }),
		'removeCollationNotes' => new TextFixer(function($s, $t, &$a) { return removeCollationNotes($s, $t); }),
	);
}

function removeFootnotes($text) {
	return preg_replace('/\[[0-9]+\]/', '', $text);
}

function removeBlankBlocks($source, $target) {
	global $blockPattern;

	$i = fopen($source, 'r');
	$o = fopen($target, 'w');

	$c = 0;
	$currentFascicle = '';
	$currentBlock = 0;

	while (($line = fgets($i)) !== false) {
		if (preg_match($blockPattern, $line, $matches)) {
			$fascicle = $matches[1];

			if ($fascicle != $currentFascicle) {
				$currentFascicle = $fascicle;
				$currentBlock = 1;
			}

			if (strlen(trim($matches[2])) > 0) {
				$formattedNumber = sprintf('%04d', $currentBlock);
				$content = $matches[3]; // Keep the newline at the end
				fputs($o, "$fascicle.$formattedNumber|$content");
				++$currentBlock;
			} else {
				++$c;
			}
		} else {
			fputs($o, $line);
		}
	}

	fclose($o);
	fclose($i);

	return $c;
}

function removeFascicleTrailerNavigation($source, $target) {
	global $blockPattern;

	$i = fopen($source, 'r');
	$o = fopen($target, 'w');

	$c = 0;
	$currentFascicle = '';
	$inTrailerCrap = false;

	while (($line = fgets($i)) !== false) {
		if (preg_match($blockPattern, $line, $matches)) {
			$fascicle = $matches[1];
			$content = trim($matches[3]);

			if ($fascicle != $currentFascicle) {
				$currentFascicle = $fascicle;
				$inTrailerCrap = false;
			}

			if ($content == '返回頁首' || $content == '↑返回頂部') {
				$inTrailerCrap = true;
			}

			if ($inTrailerCrap == false) {
				fputs($o, $line);
			} else {
				print "Dropping: $line";
				++$c;
			}
		} else {
			fputs($o, $line);
		}
	}

	fclose($o);
	fclose($i);

	return $c;
}

function removeFascicleTrailerNavigation2($source, $target) {
	global $blockPattern;

	$i = fopen($source, 'r');
	$o = fopen($target, 'w');

	$c = 0;
	$currentFascicle = '';
	$inTrailerCrap = false;
	$line = '';

	while (($nextLine = fgets($i)) !== false) {
		if (preg_match($blockPattern, $nextLine, $matches)) {
			$fascicle = $matches[1];
			$content = trim($matches[3]);

			if ($fascicle != $currentFascicle) {
				$currentFascicle = $fascicle;
				$inTrailerCrap = false;
			}

			if ($content == '◄') {
				$inTrailerCrap = true;
			}

			if ($inTrailerCrap == false) {
				fputs($o, $line);
			} else {
				print "Dropping: $line";
				++$c;
			}
		} else {
			fputs($o, $line);
		}
		$line = $nextLine;
	}

	if ($inTrailerCrap == false) {
		fputs($o, $line);
	} else {
		print "Dropping: $line";
		++$c;
	}

	fclose($o);
	fclose($i);

	return $c;
}

function removeCollationNotes($source, $target) {
	global $blockPattern;

	$i = fopen($source, 'r');
	$o = fopen($target, 'w');

	$c = 0;
	$currentFascicle = '';
	$inTrailerCrap = false;

	while (($line = fgets($i)) !== false) {
		if (preg_match($blockPattern, $line, $matches)) {
			$fascicle = $matches[1];
			$content = trim($matches[3]);

			if ($fascicle != $currentFascicle) {
				$currentFascicle = $fascicle;
				$inTrailerCrap = false;
			}

			if ($content == '校勘记' || $content == '校勘記' || $content == '註釋') {
				$inTrailerCrap = true;
			}

			if ($inTrailerCrap == false) {
				fputs($o, $line);
			} else {
				print "Dropping: $line";
				++$c;
			}
		} else {
			fputs($o, $line);
		}
	}

	fclose($o);
	fclose($i);

	return $c;
}