<?php
/*
 * Copyright (c) 2016, webvariants GmbH & Co. KG, http://www.webvariants.de
 *
 * This file is released under the terms of the MIT license. You can find the
 * complete text in the attached LICENSE file or online at:
 *
 * http://www.opensource.org/licenses/mit-license.php
 */

class sly_Dispatcher_Setup extends sly_Dispatcher {
	/**
	 * return the DI container identifier for a controller
	 *
	 * This identifier controls both how controllers are found and how their
	 * created instaces are stored. The implementation should not check for any
	 * existing service, but rather only check if $name is syntactically valid
	 * and then return the identifier.
	 *
	 * @param  string $name  controller name, e.g. 'login'
	 * @return string        identifier, e.g. 'sly-controller-backend-login'
	 */
	public function getControllerIdentifier($name) {
		return 'sly-setup-controller-'.strtolower($name);
	}

	/**
	 * create a controller instance
	 *
	 * This method is called if no container has been defined in the service. It
	 * should contruct the class name and then instantiate the controller.
	 *
	 * @param  string $name              controller name, e.g. 'login'
	 * @return sly_Controller_Interface  controller instance
	 */
	protected function buildController($name) {
		// controller names make start with a number because we have our prefix,
		// so sly_Controller_23 is perfectly valid (but still stupid). They are
		// not allowed to start or end with an underscore, however.
		if (mb_strlen($name) === 0 || !preg_match('#^[0-9a-z][0-9a-z_]*$#is', $name) || mb_substr($name, -1) === '_') {
			throw new sly_Controller_Exception(t('unknown_controller', $name), 404);
		}

		$className = 'sly_Controller_Setup';
		$parts     = explode('_', strtolower($name));

		foreach ($parts as $part) {
			$className .= '_'.ucfirst($part);
		}

		// class exists and is not abstract?
		$this->checkControllerClass($className);

		return new $className();
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
