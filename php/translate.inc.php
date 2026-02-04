<?php // translate.inc.php

require_once('text.inc.php');
require_once('deepseek.inc.php');

$subStateUninitialized = 0;
$subStateReadyToTranslate = 1;
$subStateDoneTranslating = 2;
$subStatePossiblyRecoverableError = 3;
$subStateUnrecoverableError = 4;
$subStateEndOfText = 5;

class TranslationState {
    var $textPath;
    var $workPath;
    var $promptsPath;
    var $status;
    var $statusDetail;
    var $fascicleName; // The fascicle just completed or false at start
    var $paragraphNumber; // The paragraph just completed or false at start
    var $textSummary; // Or false at start
    var $fascicleSummary; // Or false at start
    var $translation;
    var $properNouns;
    var $consecutiveErrorCount;

    // Prompts
    var $translationIntroduction;
    var $priorFascicleSummary;
    var $thisFascicleSummary;
    var $translationInstruction;
    var $properNounInstruction;
    var $resummarizeFascicle;
    var $resummarizePriorFascicles;

    // Non-data members
    var $text;

    // Don't construct directly, instead call TranslationState::initial() or TranslationState::fromWorkFile()
    function __construct() {
        $this->textPath = false;
        $this->workPath = false;
        $this->fascicleName = false;
        $this->paragraphNumber = false;
        $this->textSummary = false;
        $this->fascicleSummary = false;
        $this->translation = false;
        $this->properNouns = false;
        $this->status = $subStateUninitialized;
        $this->statusDetail = false;
        $this->consecutiveErrorCount = 0;
        $this->translationIntroduction = false;
        $this->priorFascicleSummary = false;
        $this->thisFascicleSummary = false;
        $this->translationInstruction = false;
        $this->properNounInstruction = false;
        $this->resummarizeFascicle = false;
        $this->resummarizePriorFascicles = false;
    }

    static function initial($textPath, $promptsPath, $workPath) {
        $state = new TranslationState();
        $state->textPath = $textPath;
        $state->workPath = $workPath;
        $state->promptsPath = $promptsPath;

        try {
            $state->text = Text::fromPath($textPath);
            if ($state->text->firstParagraph === false) {
                $state->status = $subStateEndOfText;
            } else {
                $state->fascicleName = $state->text->firstParagraph->fascicle->name;
                $state->paragraphNumber = $state->text->firstParagraph->number;
                $state->status = $subStateReadyToTranslate;
            }

            $state->loadPrompts();
        } catch (Exception $e) {
            $state->status = $subStateUnrecoverableError;
            $state->statusDetail = $e->getMessage();
        }

        return $state;
    }

    static function fromWorkFile($workPath) {
        $state = new TranslationState();
        $state->workPath = $workPath;

        $data = json_decode(file_get_contents($workPath));
        $state->textPath = $data->textPath;
        $state->fascicleName = $data->fascicleName;
        $state->paragraphNumber = $data->paragraphNumber;
        $state->textSummary = $data->textSummary;
        $state->fascicleSummary = $data->fascicleSummary;
        $state->consecutiveErrorCount = $data->consecutiveErrorCount;
        switch ($data->status) {
            case 'Uninitialized':
                $state->status = $subStateUninitialized;
                break;
            case 'ReadyToTranslate':
                $state->status = $subStateReadyToTranslate;
                break;
            case 'DoneTranslating':
                $state->status = $subStateDoneTranslating;
                break;
            case 'PossiblyRecoverableError':
                $state->status = $subStatePossiblyRecoverableError;
                break;
            case 'UnrecoverableError':
                $state->status = $subStateUnrecoverableError;
                break;
            case 'EndOfText':
                $state->status = $subStateEndOfText;
                break;
        }

        $state->promptsPath = $data->promptsPath;
        $state->text = Text::fromPath($state->textPath);

        $state->loadPrompts();

        return $state;
    }

    function loadPrompts() {
        $templates = new \Deepseek\PromptTemplateFile($this->promptsPath);
        $this->translationIntroduction = $templates->templates['translationIntroduction'];
        $this->priorFascicleSummary = $templates->templates['priorFascicleSummary'];
        $this->thisFascicleSummary = $templates->templates['thisFascicleSummary'];
        $this->translationInstruction = $templates->templates['translationInstruction'];
        $this->properNounInstruction = $templates->templates['properNounInstruction'];
        $this->resummarizeFascicle = $templates->templates['resummarizeFascicle'];
        $this->resummarizePriorFascicles = $templates->templates['resummarizePriorFascicles'];
    }

    function getCurrentParagraph() {
        $fascicle = $this->text->fascicles[$this->fascicleName];
        return $fascicle->paragraphs[$this->paragraphNumber];
    }

    function save() {
        $data = array();
        $data['textPath'] = $this->textPath;
        $data['fascicleName'] = $this->fascicleName;
        $data['paragraphNumber'] = $this->paragraphNumber;
        $data['textSummary'] = $this->textSummary;
        $data['fascicleSummary'] = $this->fascicleSummary;

        switch ($this->status) {
            case $subStateUninitialized:
                $data['status'] = 'Uninitialized';
                break;
            case $subStateReadyToTranslate:
                $data['status'] = 'ReadyToTranslate';
                break;
            case $subStateDoneTranslating:
                $data['status'] = 'DoneTranslating';
                break;
            case $subStatePossiblyRecoverableError:
                $data['status'] = 'PossiblyRecoverableError';
                break;
            case $subStateUnrecoverableError:
                $data['status'] = 'UnrecoverableError';
                break;
            case $subStateEndOfText:
                $data['status'] = 'EndOfText';
                break;
        }

        $data['statusDetail'] = $this->statusDetail;
        $data['consecutiveErrorCount'] = $this->consecutiveErrorCount;
        $data['promptsPath'] = $this->promptsPath;

        file_put_contents($this->workPath, json_encode($data));
    }

    function moreToTranslate() {
        switch ($this->status) {
            case $subStateUninitialized:
                return false;
                break;
            case $subStateReadyToTranslate:
                return true;
                break;
            case $subStateDoneTranslating:
                $currentParagraph = $this->getCurrentParagraph();
                return ($currentParagraph->nextParagraph !== false);
                break;
            case $subStatePossiblyRecoverableError:
                return true;
                break;
            case $subStateUnrecoverableError:
                return false;
                break;
            case $subStateEndOfText:
                return false;
                break;
        }

        // Unrecoverable error or end of text
        return false;
    }

    // Advance to state 1, ready to translate, if possible
    function getReadyToTranslate() {
        switch ($this->status) {
            case $subStateUninitialized:
                return false;
                break;
            case $subStateReadyToTranslate:
                return true;
                break;
            case $subStateDoneTranslating:
                $currentParagraph = $this->getCurrentParagraph();
                $nextParagraph = $currentParagraph->nextParagraph;
                if ($nextParagraph === false) {
                    return false;
                }
                $this->fascicleName = $nextParagraph->fascicle->name;
                $this->paragraphNumber = $nextParagraph->number;
                return true;
                break;
            case $subStatePossiblyRecoverableError:
                return true;
                break;
            case $subStateUnrecoverableError:
                return false;
                break;
            case $subStateEndOfText:
                return false;
                break;
        }
    }

    // Translate
    function translate($session) {
        try {
            $this->translation = false;
            $this->properNouns = false;

            $paragraph = $this->getCurrentParagraph();

            if ($paragraph == false) {
                $this->status = $subStateUnrecoverableError;
                $this->statusDetail = "Could not get paragraph";
                return;
            }

            $convo = $session->conversation();

            $convo->addUserMessage($this->translationIntroduction->format($this->text->title));
            if ($this->textSummary != false) {
                $convo->addUserMessage($this->priorFascicleSummary->format($this->textSummary));
            }
            if ($this->fascicleSummary != false) {
                $convo->addUserMessage($this->thisFascicleSummary->format($this->fascicleSummary));
            }

            $this->translation = $convo->ask($this->translationInstruction->format($paragraph->content), 'END_OF_TRANSLATION');

            if ($this->translation == false) {
                $this->status = $subStatePossiblyRecoverableError;
                ++$this->consecutiveErrorCount;
                $this->statusDetail = 'Failed to get translation';
                return;
            }

            $this->properNouns = $convo->ask($this->properNounInstruction->format(), 'END_OF_LIST');

            if ($this->properNouns == false) {
                $this->status = $subStatePossiblyRecoverableError;
                $this->statusDetail = 'Failed to get proper nouns';
                return;
            }

            $this->thisFascicleSummary = $convo->ask($this->resummarizeFascicle->format());

            if ($this->thisFascicleSummary == false) {
                $this->status = $subStatePossiblyRecoverableError;
                ++$this->consecutiveErrorCount;
                $this->statusDetail = 'Failed to get fascicle summary';
                return;
            }

            if ($paragraph->nextParagraph === false || $paragraph->nextParagraph->fascicle != $paragraph->fascicle) {
                $this->textSummary = $convo->ask($this->resummarizePriorFascicles->format());
                $this->statusDetail = 'Failed to get text summary';
            }

            $this->status = $subStateDoneTranslating;
            $this->consecutiveErrorCount = 0;
        } catch (Exception $e) {
            $this->status = $subStateUnrecoverableError;
            $this->statusDetail = $e->getMessage();
        }
    }
}

