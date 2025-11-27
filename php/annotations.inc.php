<?php // annotations.inc.php

namespace Annotations;

require_once('deepseek.inc.php');

$zhpat = '[^,]*';
$enpat = '[0-9a-zA-Z \'üāēīōūǖáéíóúǘǎěǐǒǔǚàèìòùǜĀĒĪŌŪǕÁ0ÉÍÓÚǗǍĚǏǑǓǙÀÈÌÒÙǛ()-]*';
$tpat = '[A-Z_\/+]*';

class Session extends \Deepseek\Session {
  var $sourceLanguage;
  var $targetLanguage;

  function __construct() {
    parent::__construct();
    $this->sourceLanguage = 'Classical Chinese';
    $this->targetLanguage = 'English';
  }

  function sourceLanguage($v) {
    $this->sourceLanguage = $v;
    return $this;
  }

  function targetLanguage($v) {
    $this->targetLanguage = $v;
    return $this;
  }

  function translateWithAnnotations($source) {
    $c = new Conversation($this);
    return $c->translateWithAnnotations($source);
  }
}

class AnnotatedTranslation {
  var $annotations;
  var $translation;
  var $translationNotes;
  var $annotationNotes;

  function __construct() {
    $this->annotations = array();
    $this->translation = false;
  }
}

class Conversation extends \Deepseek\Conversation {
  function __construct($session) {
    $this->session = $session;
    #this->temperature = 1.3;
  }

  function annotate($source) {
    $this->messages[] = new \Deepseek\Message('system', 'User will provide text in ' . $this->session->sourceLanguage . '. Please provide a simple list of proper nouns occurring in the text, with the original form in $this->session->sourceLanguage, a transliteration that would be appropriate in ' . $this->session->targetLanguage . ', and the type of entity referred to (such as "PERSON", "PLACE", "STATE", "TITLE"). For example, if the text contains the proper noun 司馬遷, then include the following entry in the list: "司馬遷, Sima Qian, PERSON". At the end of the list print "END_OF_LIST", even if there were no proper nouns at all, and the list is empty. If you have helpful observations to make, please place these after the string "END_OF_LIST".');

    $answer = $this->ask($source, 'END_OF_LIST');

    if ($answer === false) {
      return false;
    }

    $endPos = strpos($answer, 'END_OF_LIST');
    if ($endPos === false) {
      $head = $answer;
    } else {
      $head = substr($answer, 0, $endPos);
    }

    $result = array();
    foreach (explode("\n", $head) as $entry) {
      $annotations = parseAnnotation(trim($entry));
      if (is_array($annotations)) {
        foreach ($annotations as $annotation) {
          $result[] = $annotation;
        }
      }
    }

    return $result;
  }

  function translateWithAnnotations($source) {
    $result = new AnnotatedTranslation();
    $result->annotations = $this->annotate($source);

    if ($result->annotations === false) {
      return false;
    }

    $answer = $this->ask('Now, please translate the ' . $this->session->sourceLanguage . ' text into ' . $this->session->targetLanguage . ' using the same transliterations you provided above, and at the end of the translation print "END_OF_TRANSLATION". If you have helpful observations to make, please place these after the string "END_OF_TRANSLATION".', 'END_OF_TRANSLATION');

    if ($answer === false) {
      return false;
    }

    $result->translation = $answer;
    return $result;
  }
}

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
    $result[] = new Parsed(false, false, 'FAILURE', false, $raw);
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
    $result[] = new Parsed(false, false, 'FAILURE', false, $raw);
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
