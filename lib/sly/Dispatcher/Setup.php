<?php
/*
 * Copyright (c) 2013, webvariants GbR, http://www.webvariants.de
 *
 * This file is released under the terms of the MIT license. You can find the
 * complete text in the attached LICENSE file or online at:
 *
 * http://www.opensource.org/licenses/mit-license.php
 */

class sly_Dispatcher_Setup extends sly_Dispatcher {
	protected $container;
	protected $prefix;

	/**
	 * Constructor
	 *
	 * @param sly_Container $container
	 */
	public function __construct(sly_Container $container, $containerClassPrefix) {
		$this->container = $container;
		$this->prefix    = $containerClassPrefix;
	}

	/**
	 * handle a controller that printed its output
	 *
	 * @param string $content  the controller's captured output
	 */
	protected function handleStringResponse($content) {
		$layout = $this->getContainer()->getLayout();

		$layout->setContent($content);
		$content = $layout->render();

		return parent::handleStringResponse($content);
	}

	protected function handleControllerError(Exception $e, $controller, $action) {
		// throw away all content (including notices and warnings)
		while (ob_get_level()) ob_end_clean();

		// manually create the error controller to pass the exception
		$controller = new sly_Controller_Setup_Error($e);

		// forward to the error page
		return new sly_Response_Forward($controller, 'index');
	}
}
