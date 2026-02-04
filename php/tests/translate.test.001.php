<?php // translate.test.001.php

require_once('../translate.inc.php');

class DummySession {
    function conversation() {
        return new DummyConversation();
    }
}

class DummyConversation {
    function addUserMessage($m) {
        print "\n>>> User: $m\n";
    }

    function ask($q) {
        print "\n>>> User: $q\n";
        sleep(2);
    	$answer = md5($q);
        print "\n<<< Answer: $answer\n";
        return $answer;
    }
}

$session = new DummySession();
$state = TranslationState::initial('../../texts/beiqishu.txt', '../translate.prompts', './dummy.state');

if ($state->moreToTranslate()) {
    $state->getReadyToTranslate();
    $state->translate($session);
    $state->save();
} else {
    print "NO MORE TO TRANSLATE";
}
