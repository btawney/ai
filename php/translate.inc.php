<?php // translate.inc.php

require_once('/root/ai/php/deepseek.inc.php');
require_once('/root/ai/php/parseAnnotations.inc.php');
require_once('db.inc.php');

class WorkTranslation {
  var $workId;
  var $minimumBalance;
  var $fascicleTranslations;
  var $properNouns;
  var $title;
  var $summary;

  // Prompt templates
  var $translationIntroduction;
  var $priorFascicleSummary;
  var $thisFascicleSummary;
  var $listProperNouns;
  var $translationInstruction;
  var $properNounInstruction;
  var $resummarizeFascicle;
  var $resummarizePriorFascicles;

  function __construct($workId) {
    $this->workId = $workId;
    $this->minimumBalance = 5;
    $this->fascicleTranslations = null;
    $this->properNouns = array();
    $this->title = null;
    $this->summary = null;

    $templates = new \Deepseek\PromptTemplateFile('./translate.prompts');
    $this->translationIntroduction = $templates->templates['translationIntroduction'];
    $this->priorFascicleSummary = $templates->templates['priorFascicleSummary'];
    $this->thisFascicleSummary = $templates->templates['thisFascicleSummary'];
    $this->listProperNouns = $templates->templates['listProperNouns'];
    $this->translationInstruction = $templates->templates['translationInstruction'];
    $this->properNounInstruction = $templates->templates['properNounInstruction'];
    $this->resummarizeFascicle = $templates->templates['resummarizeFascicle'];
    $this->resummarizePriorFascicles = $templates->templates['resummarizePriorFascicles'];

    if (file_exists("./$workId.json")) {
      $data = json_decode(file_get_contents("./$workId.json"));

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

  function minimumBalance($v) {
    $this->minimumBalance = $v;
    return $this;
  }

  function translate($session) {
    if ($this->title === null) {
      $this->title = db_query_one_value('SELECT name FROM work WHERE workId = %d', $this->workId);
    }

    if ($this->fascicleTranslations == null) {
      $this->fascicleTranslations = array();
      $fascicleIds = db_query_one_column('SELECT fascicleId FROM fascicle WHERE workId = %d ORDER BY fascicleOrder', $this->workId);
      foreach ($fascicleIds as $fascicleId) {
        $this->fascicleTranslations[] = new FascicleTranslation($fascicleId);
      }
    }

    foreach ($this->fascicleTranslations as $fascicleTranslation) {
      $continue = $fascicleTranslation->translate($this, $session);

      if (!$continue) {
        return false;
      }
    }

    return true;
  }

  function save() {
    file_put_contents("./$this->workId.json", json_encode($this));

    if (file_exists("/$this->workId.stop")) {
      return false;
    } else {
      return true;
    }
  }
}

class FascicleTranslation {
  var $fascicleId;
  var $paragraphTranslations;
  var $summary;

  function __construct($fascicleId) {
    $this->fascicleId = $fascicleId;
    $this->paragraphTranslations = null;
    $this->summary = null;
  }

  function translate($workTranslation, $session) {
    if ($this->paragraphTranslations == null) {
      $this->paragraphTranslations = array();
      $paragraphIds = db_query_one_column('SELECT paragraphId FROM paragraph WHERE fascicleId = %d ORDER BY paragraphNumber', $this->fascicleId);
      foreach ($paragraphIds as $paragraphId) {
        $this->paragraphTranslations[] = new ParagraphTranslation($paragraphId);
      }
    }

    $count = count($this->paragraphTranslations);
    for ($i = 0; $i < $count; ++$i) {
      $paragraphTranslation = $this->paragraphTranslations[$i];
      $isLast = ($i + 1 == $count);
      $continue = $paragraphTranslation->translate($workTranslation, $this, $session, $isLast);

      if (!$continue) {
        return false;
      }
    }

    return true;
  }
}

class ParagraphTranslation {
  var $paragraphId;
  var $chinese;
  var $response;
  var $translation;

  function __construct($paragraphId) {
    $this->paragraphId = $paragraphId;
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

  function translate ($workTranslation, $fascicleTranslation, $session, $isLast) {
    if ($this->chinese === null) {
      $this->chinese = db_query_one_value('SELECT chinese FROM paragraph WHERE paragraphId = %d', $this->paragraphId);
    }

    if ($this->translation === null) {
      $convo = $session->conversation();
      $convo->addUserMessage($workTranslation->translationIntroduction->format($workTranslation->title));
      if ($workTranslation->summary != null) {
        $convo->addUserMessage($workTranslation->priorFascicleSummary->format($workTranslation->summary));
      }
      if ($fascicleTranslation->summary != null) {
        $convo->addUserMessage($workTranslation->thisFascicleSummary->format($fascicleTranslation->summary));
      }

      $properNouns = false;
      foreach ($workTranslation->properNouns as $source => $list) {
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
        $convo->addUserMessage($workTranslation->listProperNouns->format($properNouns));
      }

      $this->translation = $convo->ask($workTranslation->translationInstruction->format($this->chinese), 'END_OF_TRANSLATION');
      $raw = $convo->ask($workTranslation->properNounInstruction->format(), 'END_OF_LIST');

      $list = parseAnnotations($raw, 'END_OF_LIST');

      foreach ($list as $properNoun) {
        if (isset($workTranslation->properNouns[$properNoun->source])) {
          $foundIt = false;
          foreach ($workTranslation->properNouns[$properNoun->source] as $existing) {
            if ($existing->target == $properNoun->target) {
              $foundIt = true;
            }
          }

          if ($foundIt) {
          } else {
            $workTranslation->properNouns[$properNoun->source][] = $properNoun;
          }
        } else {
          $workTranslation->properNouns[$properNoun->source] = array($properNoun);
        }
      }

      $fascicleTranslation->summary = $convo->ask($workTranslation->resummarizeFascicle->format());

      if ($isLast) {
        $workTranslation->summary = $convo->ask($workTranslation->resummarizePriorFascicles->format());
      }

      $continue = $workTranslation->save();

      if (!$continue) {
        return false;
      }
    }

    return true;
  }
}
