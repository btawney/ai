<?php // translate.inc.php

require_once('text.inc.php');
require_once('deepseek.inc.php');
require_once('parseAnnotations.inc.php');

class TextTranslation {
  var $path;
  var $textPath;
  var $fascicleTranslations;
  var $properNouns;
  var $title;
  var $summary;
  var $minimumBalance;
  var $endTime;

  // Prompt templates
  var $translationIntroduction;
  var $priorFascicleSummary;
  var $thisFascicleSummary;
  var $listProperNouns;
  var $translationInstruction;
  var $properNounInstruction;
  var $resummarizeFascicle;
  var $resummarizePriorFascicles;

  function __construct($path = false) {
    $this->path = $path;
    $this->textPath = null;
    $this->fascicleTranslations = null;
    $this->properNouns = array();
    $this->title = null;
    $this->summary = null;
    $this->minimumBalance = 0;
    $this->endTime = 0;

    $templates = new \Deepseek\PromptTemplateFile('./translate.prompts');
    $this->translationIntroduction = $templates->templates['translationIntroduction'];
    $this->priorFascicleSummary = $templates->templates['priorFascicleSummary'];
    $this->thisFascicleSummary = $templates->templates['thisFascicleSummary'];
    $this->listProperNouns = $templates->templates['listProperNouns'];
    $this->translationInstruction = $templates->templates['translationInstruction'];
    $this->properNounInstruction = $templates->templates['properNounInstruction'];
    $this->resummarizeFascicle = $templates->templates['resummarizeFascicle'];
    $this->resummarizePriorFascicles = $templates->templates['resummarizePriorFascicles'];

    if ($path !== false && file_exists($path)) {
      $data = json_decode(file_get_contents($path));

      $this->textPath = $data->textPath;
      $this->minimumBalance = $data->minimumBalance;
      $this->properNouns = $data->properNouns;
      $this->title = $data->title;

      if ($data->fascicleTranslations != null) {
        $this->fascicleTranslations = array();
        foreach ($data->fascicleTranslations as $fascicleTranslationData) {
          $newFascicleTranslation = new FascicleTranslation($fascicleTranslationData->fascicleId);
          if ($fascicleTranslationData->paragraphTranslations != null) {
            $newFascicleTranslation->paragraphTranslations = array();
            foreach ($fascicleTranslationData->paragraphTranslations as $paragraphTranslation) {
              $newFascicleTranslation->paragraphTranslations[] = (new ParagraphTranslation($paragraphTranslation->paragraphId))
                ->chinese($paragraphTranslation->chinese)
                ->translation($paragraphTranslation->translation);
            }
          }
          $this->fascicleTranslations[] = $newFascicleTranslation;
        }
      }
    }
  }

  function textPath($v) {
    $this->textPath = $v;
    return $this;
  }

  function minimumBalance($v) {
    $this->minimumBalance = $v;
    return $this;
  }

  function translate($session, $minimumBalance = 1, $runSeconds = 604800) {
    $this->minimumBalance = $minimumBalance;
    $this->endTime = time() + $runSeconds;

    $text = new Text($this->textPath);

    if ($this->fascicleTranslations == null) {
      $this->fascicleTranslations = array();

      foreach ($text->fascicles as $fascicle) {
        $this->fascicleTranslations[] = new FascicleTranslation($fascicle->name);
      }
    }

    foreach ($this->fascicleTranslations as $fascicleTranslation) {
      $continue = $fascicleTranslation->translate($this, $session, $text);

      if (!$continue) {
        return false;
      }
    }

    return true;
  }

  function save() {
    if ($this->path !== false) {
      file_put_contents($this->path, json_encode($this, JSON_PRETTY_PRINT));
    }

    if ($session->balance() <= $this->minimumBalance) {
      return false;
    }

    if (time() >= $this->endTime) {
      return false;
    }
  }
}

class FascicleTranslation {
  var $fascicleName;
  var $paragraphTranslations;
  var $summary;

  function __construct($fascicleName) {
    $this->fascicleName = $fascicleName;
    $this->paragraphTranslations = null;
    $this->summary = null;
  }

  function translate($textTranslation, $session, $text) {
    if ($this->paragraphTranslations == null) {
      $this->paragraphTranslations = array();
      foreach ($text->getFascicle($this->fascicleName)->paragraphs as $paragraph) {
        $this->paragraphTranslations[] = new ParagraphTranslation($paragraph->name);
      }
    }

    $count = count($this->paragraphTranslations);
    for ($i = 0; $i < $count; ++$i) {
      $paragraphTranslation = $this->paragraphTranslations[$i];
      $isLast = ($i + 1 == $count);
      $continue = $paragraphTranslation->translate($textTranslation, $this, $session, $isLast, $text);

      if (!$continue) {
        return false;
      }
    }

    return true;
  }
}

class ParagraphTranslation {
  var $paragraphName;
  var $chinese;
  var $response;
  var $translation;

  function __construct($paragraphName) {
    $this->paragraphName = $paragraphName;
    $this->chinese = null;
    $this->responses = null;
    $this->translation = null;
  }

  function chinese($v) {
    $this->chinese = $v;
    return $this;
  }

  function translation($v) {
    $this->translation = $v;
    return $this;
  }

  function translate ($textTranslation, $fascicleTranslation, $session, $isLast, $text) {
    if ($this->chinese === null) {
      $this->chinese = $text->getFascicle($fascicleTranslation->fascicleName)->getParagraph($this->paragraphName)->text;
    }

    if ($this->translation === null) {
      $convo = $session->conversation();
      $convo->addUserMessage($textTranslation->translationIntroduction->format($textTranslation->title));
      if ($textTranslation->summary != null) {
        $convo->addUserMessage($textTranslation->priorFascicleSummary->format($textTranslation->summary));
      }
      if ($fascicleTranslation->summary != null) {
        $convo->addUserMessage($textTranslation->thisFascicleSummary->format($fascicleTranslation->summary));
      }

      $properNouns = false;
      foreach ($textTranslation->properNouns as $source => $list) {
        if (mb_strpos($this->chinese, $source) !== false) {
          foreach ($list as $properNoun) {
            if ($properNouns === false) {
              $properNouns = "$properNoun->source, $properNoun->target";
            } else {
              $properNouns .= "$properNouns; $properNoun->source, $properNoun->target";
            }
          }
        }
      }

      if ($properNouns !== false) {
        $convo->addUserMessage($textTranslation->listProperNouns->format($properNouns));
      }

      $this->translation = $convo->ask($textTranslation->translationInstruction->format($this->chinese), 'END_OF_TRANSLATION');
      $raw = $convo->ask($textTranslation->properNounInstruction->format(), 'END_OF_LIST');

      $list = parseAnnotations($raw, 'END_OF_LIST');

      foreach ($list as $properNoun) {
        if (isset($textTranslation->properNouns[$properNoun->source])) {
          $foundIt = false;
          foreach ($textTranslation->properNouns[$properNoun->source] as $existing) {
            if ($existing->target == $properNoun->target) {
              $foundIt = true;
            }
          }

          if ($foundIt) {
          } else {
            $textTranslation->properNouns[$properNoun->source][] = $properNoun;
          }
        } else {
          $textTranslation->properNouns[$properNoun->source] = array($properNoun);
        }
      }

      $fascicleTranslation->summary = $convo->ask($textTranslation->resummarizeFascicle->format());

      if ($isLast) {
        $textTranslation->summary = $convo->ask($textTranslation->resummarizePriorFascicles->format());
      }

      $continue = $textTranslation->save();

      if (!$continue) {
        return false;
      }
    }

    return true;
  }
}
