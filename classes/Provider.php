<?php
namespace Grav\Plugin\CloudStash;

abstract class Provider {
	protected $grav;
	protected $settings;

	public $client;

	public function __construct() {
		$this->grav = \Grav\Common\Grav::instance();
	}

}