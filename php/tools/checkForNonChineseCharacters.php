<?php // checkForNonChineseCharacters.php

$ps = 0;
foreach ($argv as $arg) {
	switch ($ps) {
		case 0:
			$ps = 1;
			break;
		case 1:
			checkFile($arg);
			break;
	}
}

function checkFile($path) {
	$blockPattern = '/^([0-9][0-9][0-9])[.]([0-9][0-9][0-9][0-9])[|](.*)$/';
	$misc = array(
		0x28, 0x29, // ()
		0x7b, 0x7d, // {}
		0x5b, 0x5d, // []
		0x3a, 0x3c, // <>
		0x3e, // :
		0x20, // ASCII space
		0x21, // !
		0x2b, // Plus sign, used in ad-hoc fanqie
		0x2e, // .
		0x3f, // ?
		0x5f, // _
		0x25b2, 0x25b3, 0x25cb, 0x25ce, 0x2500, 0x2014, 0x200b, 0x25a1, // ▲△○◎──—□
		0xfe52, 0xb7, 0xfffd // ﹒·�
	);
	$stats = array();

	$f = fopen($path, 'r');
	while (($line = fgets($f)) !== false) {
		if (preg_match($blockPattern, $line, $matches)) {
			$content = trim($matches[3]);
			$length = mb_strlen($content);
			for ($i = 0; $i < $length; ++$i) {
				$o = mb_ord(mb_substr($content, $i, 1));

				if (
					($o >= 0x4E00 && $o <= 0x9FFF) // CJK unified ideographs
					|| ($o >= 0x3400 && $o <= 0x4DBF) // CJK unified ideographs Extension A
					|| ($o >= 0x3001 && $o <= 0x303f) // CJK Symbols and Punctuation
					|| ($o >= 0x2018 && $o <= 0x201D) // Quote marks
					|| (array_search($o, $misc) !== false) // misc
					|| ($o >= 0xff01 && $o <= 0xff1f) // Full-width punctuation
					|| ($o >= 0x20000) // Extended
					|| ($o >= 0xe000 && $o <= 0xf8ff) // Private use...why? I don't know
					) {
					// Normal character
				} else {
					if (empty($stats[$o])) {
						$stats[$o] = 1;
						$hex = sprintf('%04x', $o);
						print "$hex  $line\n";
					} else {
						++$stats[$o];
					}
				}
			}
		}
	}
	fclose($f);

	ksort($stats);

	foreach ($stats as $o => $n) {
		$c = mb_chr($o);
		$hex = sprintf('%04x', $o);
		print "$hex  $c  $n\n";
	}
}