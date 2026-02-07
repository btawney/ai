<?php // translate.inc.php

require_once('text.inc.php');
require_once('deepseek.inc.php');

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

    // Statistics
    var $startTime;
    var $endTime;
    var $startBalance;
    var $endBalance;

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
        $this->status = 'UNINITIALIZED';
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
                $state->status = 'END_OF_TEXT';
            } else {
                $state->fascicleName = $state->text->firstParagraph->fascicle->name;
                $state->paragraphNumber = $state->text->firstParagraph->number;
                $state->status = 'READY_TO_TRANSLATE';
            }

            $state->loadPrompts();
        } catch (Exception $e) {
            $state->status = 'UNRECOVERABLE';
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
                $state->status = 'UNINITIALIZED';
                break;
            case 'ReadyToTranslate':
                $state->status = 'READY_TO_TRANSLATE';
                break;
            case 'DoneTranslating':
                $state->status = 'DONE_TRANSLATING';
                break;
            case 'PossiblyRecoverableError':
                $state->status = 'RETRY';
                break;
            case 'UnrecoverableError':
                $state->status = 'UNRECOVERABLE';
                break;
            case 'EndOfText':
                $state->status = 'END_OF_TEXT';
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
            case 'UNINITIALIZED':
                $data['status'] = 'Uninitialized';
                break;
            case 'READY_TO_TRANSLATE':
                $data['status'] = 'ReadyToTranslate';
                break;
            case 'DONE_TRANSLATING':
                $data['status'] = 'DoneTranslating';
                break;
            case 'RETRY':
                $data['status'] = 'PossiblyRecoverableError';
                break;
            case 'UNRECOVERABLE':
                $data['status'] = 'UnrecoverableError';
                break;
            case 'END_OF_TEXT':
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
            case 'UNINITIALIZED':
                return false;
                break;
            case 'READY_TO_TRANSLATE':
                return true;
                break;
            case 'DONE_TRANSLATING':
                $currentParagraph = $this->getCurrentParagraph();
                return ($currentParagraph->nextParagraph !== false);
                break;
            case 'RETRY':
                return true;
                break;
            case 'UNRECOVERABLE':
                return false;
                break;
            case 'END_OF_TEXT':
                return false;
                break;
        }

        // Unrecoverable error or end of text
        return false;
    }

    // Advance to state 1, ready to translate, if possible
    function getReadyToTranslate() {
        switch ($this->status) {
            case 'UNINITIALIZED':
                return false;
                break;
            case 'READY_TO_TRANSLATE':
                return true;
                break;
            case 'DONE_TRANSLATING':
                $currentParagraph = $this->getCurrentParagraph();
                $nextParagraph = $currentParagraph->nextParagraph;
                if ($nextParagraph === false) {
                    return false;
                }
                $this->fascicleName = $nextParagraph->fascicle->name;
                $this->paragraphNumber = $nextParagraph->number;
                return true;
                break;
            case 'RETRY':
                return true;
                break;
            case 'UNRECOVERABLE':
                return false;
                break;
            case 'END_OF_TEXT':
                return false;
                break;
        }
    }

    // Translate
    function translate($session) {
        try {
            $this->translation = false;
            $this->properNouns = false;
            $this->startTime = time();
            $this->startBalance = $session->balance();

            $paragraph = $this->getCurrentParagraph();

            if ($paragraph == false) {
                $this->status = 'UNRECOVERABLE';
                $this->statusDetail = "Could not get paragraph";
                $this->endTime = time();
                $this->endBalance = $session->balance();
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
                $this->status = 'RETRY';
                ++$this->consecutiveErrorCount;
                $this->statusDetail = 'Failed to get translation';
                $this->endTime = time();
                $this->endBalance = $session->balance();
                return;
            }

            $this->properNouns = $convo->ask($this->properNounInstruction->format(), 'END_OF_LIST');

            if ($this->properNouns == false) {
                $this->status = 'RETRY';
                $this->statusDetail = 'Failed to get proper nouns';
                $this->endTime = time();
                $this->endBalance = $session->balance();
                return;
            }

            $this->fascicleSummary = $convo->ask($this->resummarizeFascicle->format());

            if ($this->fascicleSummary == false) {
                $this->status = 'RETRY';
                ++$this->consecutiveErrorCount;
                $this->statusDetail = 'Failed to get fascicle summary';
                $this->endTime = time();
                $this->endBalance = $session->balance();
                return;
            }

            if ($paragraph->nextParagraph === false || $paragraph->nextParagraph->fascicle != $paragraph->fascicle) {
                $this->textSummary = $convo->ask($this->resummarizePriorFascicles->format());
                $this->statusDetail = 'Failed to get text summary';
            }

            $this->status = 'DONE_TRANSLATING';
            $this->consecutiveErrorCount = 0;
        } catch (Exception $e) {
            $this->status = 'UNRECOVERABLE';
            $this->statusDetail = $e->getMessage();
        }

        $this->endTime = time();
        $this->endBalance = $session->balance();
    }
}

