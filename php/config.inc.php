<?php // config.inc.php

class Configuration {
  var $values;

  function __construct($thing = false) {
    $values = array();

    if (is_array($thing)) {
      $this->values = $thing;
    } elseif (is_object($thing)) {
      foreach (get_object_vars($thing) as $key => $value) {
        $this->values[$key] = $value;
      }
    } elseif (file_exists($thing)) {
      try {
        $this->values = json_decode(file_get_contents($thing), true);
      } catch (Exception $e) {
        print "Error reading JSON file";
      }
    }
  }

  function has($key) {
    return isset($this->values[$key]);
  }

  function get($key, $default = false) {
    if (isset($this->values[$key])) {
      return $this->values[$key];
    } else {
      return $default;
    }
  }

  function set($key, $value) {
    $this->values[$key] = $value;
  }

  function apply($target) {
    foreach ($this->values as $key => $value) {
      if (property_exists($target, $key)) {
        $target->$key = $value;
      }
    }
  }
}
