<?php // text.inc.php

class Text {
	var $title;
	var $fascicles;
	var $version;

	function __construct($path = false) {
		$this->version = array(0, 0, 0);
		$this->title = false;
		$this->fascicles = array();

		if ($path !== false) {
			if (!file_exists($path)) {
				throw new Exception("Unable to find source file: $path");
			}

			$data = json_decode(file_get_contents($path));

			if (!property_exists($data, 'version')
				|| !is_array($data->version)
				|| count($data->version) != 3
				|| !property_exists($data, 'title')
				|| !is_string($data->title)
				|| !property_exists($data, 'fascicles')
				|| !is_array($data->fascicles)
				) {
				throw new Exception("Improperly formatted source file: $path");
			}

			if ($data->version[0] != $this->version[0]) {
				// No attempt to maintain compatibility across major version numbers
				throw new Exception("Incompatible source file: $path");
			}

			if ($data->version[1] > $this->version[1]) {
				// Source file is a newer version, we don't know what might be different
				throw new Exception("Source file is a newer version: $path");
			}

			$this->title = $data->title;

			foreach ($data->fascicles as $fascicleData) {
				$this->fascicles[] = new Fascicle($fascicleData);
			}
		}
	}

	function title($v) {
		$this->title = $v;
		return $this;
	}

	function save($path) {
		file_put_contents($path, json_encode($this, JSON_PRETTY_PRINT));
	}

	function newFascicle($name) {
		$f = new Fascicle();
		$f->name = $name;
		$this->fascicles[] = $f;
		return $f;
	}

	function getFascicle($name) {
		foreach ($this->fascicles as $fascicle) {
			if ($fascicle->name == $name) {
				return $fascicle;
			}
		}
		return null;
	}
}

class Fascicle {
	var $name;
	var $paragraphs;

	function __construct($data = false) {
		$this->name = false;
		$this->paragraphs = array();
		$this->id = false;

		if ($data !== false) {
			if (!property_exists($data, 'name')
				|| !is_string($data->name)
				|| !property_exists($data, 'paragraphs')
				|| !is_array($data->paragraphs)
				) {
				throw new Exception("Improperly formatted fascicle");
			}

			$this->name = $data->name;

			foreach ($data->paragraphs as $paragraph) {
				$this->paragraphs[] = new Paragraph($paragraph);
			}
		}
	}

	function newParagraph($name, $text) {
		$p = new Paragraph();
		$p->name = $name;
		$this->paragraphs[] = $p;
		$p->text = $text;
	}

	function getParagraph($name) {
		foreach ($this->paragraphs as $paragraph) {
			if ($paragraph->name == $name) {
				return $paragraph;
			}
		}
		return null;
	}
}

class Paragraph {
	var $name;
	var $text;

	function __construct($data = false) {
		$this->name = false;
		$this->text = false;

		if ($data !== false) {
			if (!property_exists($data, 'name')
				|| !is_string($data->name)
				|| !property_exists($data, 'text')
				|| !is_string($data->text)) {
				throw new Exception("Impropertly formatted paragraph");
			}

			$this->name = $data->name;
			$this->text = $data->text;
		}
	}
}