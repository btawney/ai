<?php // deepseek.inc.php

namespace Deepseek;

require_once('config.inc.php');

class Session {
  var $model;
  var $stream;
  // https://api-docs.deepseek.com/quick_start/parameter_settings
  var $temperature;
  var $endpointURL;
  var $endpointAccessKey;
  var $maxTries;
  var $pauseBetweenRetriesInSeconds;

  // Listeners
  var $errorListeners;
  var $requestListeners;
  var $responseListeners;

  // Usage and pricing
  static $logPath = 'deepseek.log';

  // Pricing per 1M tokens
  var $hitPrice;
  var $missPrice;
  var $outPrice;

  var $hit;
  var $miss;
  var $out;
  var $balance;

  var $balanceURL;

  function __construct($config = false) {
    $this->model = 'deepseek-chat';
    $this->stream = false;
    $this->temperature = 1.3; // Recommended for translation
    $this->endpointURL = 'https://api.deepseek.com';
    $this->maxTries = 2;
    $this->pauseBetweenRetriesInSeconds = 30;

    // Listeners
    $this->errorListeners = array();
    $this->requestListeners = array();
    $this->responseListeners = array();

    // Pricing per 1M tokens
    $this->hitPrice = 0.028;
    $this->missPrice = 0.28;
    $this->outPrice = 0.42;
    $this->hit = 0;
    $this->miss = 0;
    $this->out = 0;
    $this->balance = false;
    $this->balanceURL = 'https://api.deepseek.com/user/balance';

    if ($config === false) {
      if (file_exists('/opt/btawney/ai/deepseek.json')) {
        $c = new \Configuration('/opt/btawney/ai/deepseek.json');
        $c->apply($this);
      }
    } else {
      $c = new \Configuration($config);
      $c->apply($this);
    }
  }

  function conversation() {
    return (new Conversation($this))->temperature(1.3);
  }

  function model($v) {
    $this->model = $v;
    return $this;
  }

  function stream($v) {
    $this->stream = $v;
    return $this;
  }

  function temperature($v) {
    $this->temperature = $v;
    return $this;
  }

  function endpointURL($v) {
    $this->endpointURL = $v;
    return $this;
  }

  function endpointAccessKey($v) {
    $this->endpointAccessKey = $v;
    return $this;
  }

  function maxTries($v) {
    $this->maxTries = $v;
    return $this;
  }

  function pauseBetweenRetriesInSeconds($v) {
    $this->pauseBetweenRetriesInSeconds = $v;
    return $this;
  }

  function hitPrice($v) {
    $this->hitPrice = $v;
    return $this;
  }

  function missPrice($v) {
    $this->missPrice = $v;
    return $this;
  }

  function outPrice($v) {
    $this->outPrice = $v;
    return $this;
  }

  function balanceURL($v) {
    $this->balanceURL = $v;
    return $this;
  }

  function onError($f) {
    $this->errorListeners[] = $f;
    return $this;
  }

  function onRequest($f) {
    $this->requestListeners[] = $f;
    return $this;
  }

  function onResponse($f) {
    $this->responseListeners[] = $f;
    return $this;
  }

  function raiseError($message) {
    foreach ($this->errorListeners as $f) {
      try {
        $f($message);
      } catch (Exception $e) {
        error_log('Error raising error: ' . $e->getMessage());
      }
    }
  }

  function raiseRequest($request) {
    foreach ($this->requestListeners as $f) {
      try {
        $f($request);
      } catch (Exception $e) {
        error_log('Error raising request: ' . $e->getMessage());
      }
    }
  }

  function raiseResponse($response) {
    foreach ($this->responseListeners as $f) {
      try {
        $f($response);
      } catch (Exception $e) {
        error_log('Error raising response: ' . $e->getMessage());
      }
    }
  }

  function updateUsage($hit, $miss, $out) {
    $this->hit += $hit;
    $this->miss += $miss;
    $this->out += $out;
  }

  function balance() {
    if ($this->balance === false) {
      $balance = $this->refreshBalance();
      // Back out any usage to get the session start balance
      $this->balance
        = $balance
        - $this->hit  * $this->hitPrice  / 1000000.0
        - $this->miss * $this->missPrice / 1000000.0
        - $this->out  * $this->outPrice  / 1000000.0
        ;
    }

    if ($this->balance !== false) {
      return $this->balance
        + $this->hit  * $this->hitPrice  / 1000000.0
        + $this->miss * $this->missPrice / 1000000.0
        + $this->out  * $this->outPrice  / 1000000.0
        ;
    } else {
      return 0;
    }
  }

  function refreshBalance() {
    $options = array(
      CURLOPT_URL => $this->balanceURL,
      CURLOPT_POST => false,
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_HTTPHEADER => array(
        'Accept: application/json',
        'Authorization: Bearer ' . $this->endpointAccessKey
      )
    );

    $ch = curl_init();
    curl_setopt_array($ch, $options);
    $responseBody = curl_exec($ch);
    if (curl_errno($ch)) {
      $this->raiseError('Error in curl in refreshBalance: ' . curl_error($ch));
    } else {

      $response = json_decode($responseBody);

      if (property_exists($response, 'is_available') && $response->is_available == true) {
        if (property_exists($response, 'balance_infos')) {
          foreach ($response->balance_infos as $info) {
            if ($info->currency == 'USD') {
              return $info->total_balance;
            }
          }
          $this->raiseError('USD balance not found');
        } else {
          $this->raiseError('balance_infos not found');
        }
      } else {
        $this->raiseError('is_available not found or not true');
      }
    }

    return false;
  }
}

class Conversation {
  var $session;
  var $messages;
  var $temperature;

  function __construct($session) {
    $this->session = $session;
    $this->messages = array();
    $this->temperature = $session->temperature;
  }

  function temperature($v) {
    $this->temperature = $v;
    return $this;
  }

  function addSystemMessage($message) {
    $this->messages[] = new Message('system', $message);
  }

  function addUserMessage($message) {
    $this->messages[] = new Message('user', $message);
  }

  function addAgentMessage($message) {
    $this->messages[] = new Message('agent', $message);
  }

  function ask($prompt = false, $semaphore = false, $depth = 3) {
    // Prompt would be false for a continuation
    if ($prompt !== false) {
      $this->messages[] = new Message('user', $prompt);
    }
    $response = $this->submit();

    // A false response here is probably a persistent HTTP error
    if ($response === false) {
      $this->session->raiseError('Conversation->ask, failed to get a response');
      return false;
    }

    if (property_exists($response, 'usage')) {
      $hit = 0;
      $miss = 0;
      $out = 0;

      if (property_exists($response->usage, 'prompt_cache_hit_tokens')) {
        $hit = $response->usage->prompt_cache_hit_tokens;
      }

      if (property_exists($response->usage, 'prompt_cache_miss_tokens')) {
        $miss = $response->usage->prompt_cache_miss_tokens;
      }

      if (property_exists($response->usage, 'completion_tokens')) {
        $out = $response->usage->completion_tokens;
      }

      $this->session->updateUsage($hit, $miss, $out);
    }

    if ($response === false
      || !property_exists($response, 'choices')
      ) {
      $this->session->raiseError('Conversation->ask, response was missing choices');
      return false;
    }

    $answer = false;
    foreach ($response->choices as $choice) {
      if (!property_exists($choice, 'message')) {
        $this->session->raiseError('Conversation->ask, response choice was missing message');
        continue;
      }

      $this->messages[] = $choice->message;
      if ($answer === false) {
        $answer = $choice->message->content;
      } else {
        $answer .= " $choice->message->content";
      }
    }

    if (is_string($semaphore) && mb_strpos($answer, $semaphore) === false && $depth > 0) {
      $answer .= $this->ask(false, $semaphore, $depth - 1);
    }

    return $answer;
  }

  function submit() {
    $request = new Request($this);
    $this->session->raiseRequest($request);

    $options = array(
      CURLOPT_URL => $this->session->endpointURL . '/chat/completions',
      CURLOPT_POST => true,
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_POSTFIELDS => json_encode($request),
      CURLOPT_HTTPHEADER => array(
        'Content-Type: application/json',
        'Authorization: Bearer ' . $this->session->endpointAccessKey
      )
    );

    for ($i = 0; $i <= $this->session->maxTries; ++$i) {
      if ($i > 0) {
        sleep($this->session->pauseBetweenRetriesInSeconds);
      }

      $ch = curl_init();
      curl_setopt_array($ch, $options);
      $response = curl_exec($ch);
      if (curl_errno($ch)) {
        $this->session->raiseError('Error in curl: ' . curl_error($ch));
      } else {
        try {
          $decoded = json_decode($response);
          $this->session->raiseResponse($decoded);
          return $decoded;
        } catch (Exception $e) {
          $this->session->raiseError('Error in json_decode: ' . $response);
        }
      }
    }

    return false;
  }
}

class Message {
  var $role;
  var $content;

  function __construct($role, $content) {
    $this->role = $role;
    $this->content = $content;
  }
}

class Request {
  var $model;
  var $messages;
  var $stream;
  var $temperature;

  function __construct($conversation) {
    $this->model = $conversation->session->model;
    $this->messages = $conversation->messages;
    $this->stream = $conversation->session->stream;
    $this->temperature = $conversation->temperature;
  }
}

