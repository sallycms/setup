<?php
/*
 * Copyright (c) 2013, webvariants GbR, http://www.webvariants.de
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
		$trace    = '';
		$message  = $this->exception->getMessage();
		$response = $this->container->getResponse();

		if ($this->exception instanceof sly_Controller_Exception) {
			if ($this->exception->getCode() === 404) $response->setStatusCode(404);
			$title = t('controller_error');
		}
		else {
			$response->setStatusCode(500);
			$title = t('unexpected_exception');
		}

		if (sly_Core::isDeveloperMode()) {
			$trace = $this->exception->getTraceAsString();
		}

		$content = $this->render('error/index.phtml', compact('title', 'trace', 'message'));
		$response->setContent($content);
		return $response;
	}

	public function checkPermission($action) {
		return true;
	}
}
