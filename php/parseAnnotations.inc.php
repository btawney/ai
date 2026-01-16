<?php // parseAnnotations.inc.php

$zhpat = '[^,]*';
$enpat = '[0-9a-zA-Z \'üāēīōūǖáéíóúǘǎěǐǒǔǚàèìòùǜĀĒĪŌŪǕÁ0ÉÍÓÚǗǍĚǏǑǓǙÀÈÌÒÙǛ()-]*';
$tpat = '[A-Z_\/+]*';

class Parsed {
  var $source;
  var $target;
  var $type;
  var $notes;
  var $raw;

  function __construct($source, $target, $type, $notes, $raw) {
    $this->source = $source;
    $this->target = $target;
    $this->type = $type;
    $this->notes = $notes;
    $this->raw = $raw;
  }
}

function parseAnnotations($raw, $semaphore) {
  $result = array();

  $parts = explode("\n", $raw);
  foreach ($parts as $part) {
    $part = trim($part);
    if (strlen($part) == 0) {
      continue;
    } elseif ($part == $semaphore) {
      break;
    }
    foreach (parseAnnotation($part) as $parsed) {
      $result[] = $parsed;
    }
  }

  return $result;
}

function parseAnnotation($raw) {
  global $zhpat;
  global $enpat;
  global $tpat;

  $result = array();

  // Handle case where there are no line breaks
  if (preg_match('/^".*,.*,.*", *".*,.*,/', $raw)) {
    foreach (splitQuotedElements($raw) as $element) {
      foreach (parse($paragraphId, trim($element)) as $subResult) {
        $result[] = $subResult;
      } 
    }
    return $result;
  }

  // Preambles
  if ($raw == '' || preg_match('/^(Here is|以下是|Based on)/', $raw)) {
    $result[] = new Parsed(false, false, 'SKIPPED', false, $raw);
    return $result;
  }

  // Perfunctory type (typically on a chapter title)
  if (preg_match("/^$tpat$/", $raw)) {
    $result[] = new Parsed(false, false, 'FAILURE', 'A', $raw);
    return $result;
  }

  // Strip off terminating <br>
  if (preg_match('/^(.*)<br>$/', $raw, $matches)) {
    $raw2 = $matches[1];
  } else {
    $raw2 = $raw;
  }

  // Strip off dash used as list bullet
  if (preg_match('/^-(.*)$/', $raw2, $matches)) {
    $unlisted = trim($matches[1]);
  } elseif (preg_match('/^[0-9]+[.](.*)$/', $raw2, $matches)) {
    $unlisted = trim($matches[1]);
  } else {
    $unlisted = $raw2;
  }

  // Strip off parenthetical notes at the end
  if (preg_match('/^(.*,.*,.*)[(]([^(]*)[)]$/', $unlisted, $matches)) {
    $tuple1 = trim($matches[1]);
    $note1 = trim($matches[2]);
  } else {
    $tuple1 = $unlisted;
    $note1 = false;
  }

  // Strip off enclosing brackets with optional comma at the end
  if (preg_match('/^\[(.*)\],?$/', $tuple1, $matches)) {
    $unbracketed = trim($matches[1]);
  } else {
    $unbracketed = $tuple1;
  }

  // Strip off enclosing quotes with optional comma at the end
  if (preg_match('/^"(.*)",?$/', $unbracketed, $matches)
    || preg_match('/^"(.*),?$/', $unbracketed, $matches)
    || preg_match('/^(.*)",?$/', $unbracketed, $matches)) {
    $unquoted = trim($matches[1]);
  } else {
    $unquoted = $unbracketed;
  }

  // Strip off parenthetical notes at the end which may have been within
  // enclosing quotes
  if (preg_match('/^(.*,.*,.*)[(]([^(]*)[)]$/', $unquoted, $matches)) {
    $tuple2 = trim($matches[1]);
    $note2 = trim($matches[2]);
  } else {
    $tuple2 = $unquoted;
    $note2 = false;
  }

  // The proper case is Chinese, English, Type
  if (preg_match("/^($zhpat),($enpat), *($tpat)$/", $tuple2, $matches)) {
    $chinese1 = trim($matches[1]);
    $english1 = trim($matches[2]);
    $type1 = trim($matches[3]);

  // Handle the Type, Chinese, English case (with comma or colon)
  } elseif (preg_match("/^($tpat) *[,:]($zhpat), *($enpat)$/", $tuple2, $matches)) {
    $chinese1 = trim($matches[2]);
    $english1 = trim($matches[3]);
    $type1 = trim($matches[1]);

  // Handle the delimited English case
  } elseif (preg_match("/^($zhpat),(.*), *($tpat)$/", $tuple2, $matches)) {
    $chinese1 = trim($matches[1]);
    $english1 = trim($matches[2]);
    $type1 = trim($matches[3]);    

  } else {
    $result[] = new Parsed(false, false, 'FAILURE', 'B', $raw);
    return $result;
  }

  // Strip quotes from Chinese
  if (preg_match('/^"(.*)"$/', $chinese1, $matches)) {
    $chinese2 = $matches[1];
  } else {
    $chinese2 = $chinese1;
  }

  // Handle redundant classification at the head of Chinese
  if (preg_match('/^[A-Z_]+$/', $type1) && preg_match("/^$type1:(.*)$/", $chinese2, $matches)) {
    $chinese3 = trim($matches[1]);
  } else {
    $chinese3 = $chinese2;
  }

  // Strip Chinese-style quotes
  if (preg_match('/^《(.*)》$/', $chinese3, $matches)) {
    $chinese4 = $matches[1];
  } else {
    $chinese4 = $chinese3;
  }

  $result[] = new Parsed($chinese4, $english1, $type1, trim("$note1 $note2"), $raw);

  return $result;
}

function splitQuotedElements($list) {
  $result = array();
  $current = false;
  $depth = 0;
  $length = strlen($list);

  for ($i = 0; $i < $length; ++$i) {
    $c = substr($list, $i, 1);

    if ($depth == 0) {
      if ($c == '"') {
        $current = '';
        ++$depth;
      } elseif ($c == ',' || $c == ' ') {
        // Ignore
      } else {
        return $result;
      }
    } else {
      if ($c == '"') {
        $result[] = $current;
        --$depth;
      } else {
        $current .= $c;
      }
    }
  }

  return $result;
}
