<?php
/*
 * Copyright (c) 2012, webvariants GbR, http://www.webvariants.de
 *
 * This file is released under the terms of the MIT license. You can find the
 * complete text in the attached LICENSE file or online at:
 *
 * http://www.opensource.org/licenses/mit-license.php
 */

class sly_Controller_Setup_Error extends sly_Controller_Setup_Base implements sly_Controller_Interface {
	protected $exception;

	public function __construct(Exception $e) {
		$this->exception = $e;
	}

	public function indexAction() {
		$this->render('error/index.phtml', array('e' => $this->exception), false);
	}

	public function checkPermission($action) {
		return true;
	}
}
