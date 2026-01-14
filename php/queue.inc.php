<?php // queue.inc.php

require_once('config.inc.php');

class Queue {
  var $config;
  var $queueType;
  var $queueLocation;
  var $outputLocation;

  function __construct($config = false) {
    $this->queueType = 'FILE';
    $this->queueLocation = '/var/btawney/ai/queue';
    $this->outputLocation = false;

    if ($config === false) {
      if (file_exists('/opt/btawney/ai/deepseek.json')) {
        $c = new \Configuration('/opt/btawney/ai/deepseek.json');
        $c->apply($this);
      }
    } else {
      $c = new \Configuration($config);
      $c->apply($this);
    }

    // If output location is not specified in the configuration, then use
    // a directory called "output" that is parallel to the input location.
    if ($this->outputLocation === false) {
      $this->outputLocation = dirname($this->queueLocation) . '/output';
    }

    $this->config = $config;
  }

  function ensureOutputDirectoryExists($targetDirectory) {
    $d = $this->outputLocation . '/' . $targetDirectory;

    if (is_dir($d)) {
      return true;
    }

    if (file_exists($d)) {
      return false;
    }

    if ($targetDirectory == '') {
      return false;
    }

    if ($this->ensureOutputDirectoryExists(dirname($targetDirectory))) {
      mkdir($d);
      return true;
    }

    return false;
  }

  // sourcePath is relative to the queue location
  function moveToOutput($sourcePath) {
    $basename = basename($sourcePath);
    $relativeDirectory = dirname($sourcePath);

    if (!$this->ensureOutputDirectoryExists($relativeDirectory)) {
      return false;
    }

    $targetPath = 
    while (file_exists($targetPath)) {
      ++$sequence;
      if ($sequence > 999) {
        $suffix = $sequence;
      } else {
        $suffix = sprintf('%03d', $sequence);
      }
      $targetPath = $targetDirectory . '/' . $basename . '.' . $suffix;
    }

    rename($sourcePath, $targetPath);

    return $targetPath;
  }

  function error($requestPath) {
    
  }

  function nextRequest($tail = '') {
    foreach (glob($this->queueLocation . $tail . '/*') as $thing) {
      if (is_dir($thing)) {
        $request = $this->nextRequest($tail . '/' . basename($thing));
        if ($request !== false) {
          return $request;
        }
      } else {
        try {
          $content = file_get_contents($thing);
          $object = json_decode($content);
          if ($object === null) {
            // Can't decode json
          } else {
            return new QueuedRequest($
          }
        } catch (Exception $e) {
          // This most likely means we can't read the request
        }
      }
    }

    return false;
  }
}

class QueuedRequest {
  var $agentType;
  var $messages;

  function __construct($definition) {
    $this->agentType = 'DEEPSEEK';
    $this->messages = array();

    if (property_exists($definition, 'agentType')) {
      $this->agentType = $definition->agentType;
    }

    if (property_exists($definition, 'messages')) {
      foreach ($definition->messages as $message) {
        $this->messages[] = $message;
      }
    }
  }
}

class QueuedResponse {
  var $response;
}
