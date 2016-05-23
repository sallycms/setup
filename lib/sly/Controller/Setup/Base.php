<?php
/*
 * Copyright (c) 2014, webvariants GmbH & Co. KG, http://www.webvariants.de
 *
 * This file is released under the terms of the MIT license. You can find the
 * complete text in the attached LICENSE file or online at:
 *
 * http://www.opensource.org/licenses/mit-license.php
 */

abstract class sly_Controller_Setup_Base extends sly_Controller_Base {
	protected function getViewFolder() {
		return SLY_SALLYFOLDER.'/setup/views/';
	}

	/**
	 * Render a view
	 *
	 * This method renders a view, making all keys in $params available as
	 * variables.
	 *
	 * @param  string  $filename      the filename to include, relative to the view folder
	 * @param  array   $params        additional parameters (become variables)
	 * @param  boolean $returnOutput  set to false to not use an output buffer
	 * @return string                 the generated output if $returnOutput, else null
	 */
	protected function render() {
		// make router available to all controller views
		$args         = func_get_args();
		$params       = isset($args[1]) ? $args[1] : array();
		$returnOutput = isset($args[2]) ? $args[2] : true;
		$router       = $this->getContainer()->getApplication()->getRouter();
		$params       = array_merge(array('_router' => $router), $params);


		return parent::render($args[0], $params, $returnOutput);
	}

	protected function redirect($params = array(), $page = null, $code = 302) {
		$this->container->getApplication()->redirect($page, $params, $code);
	}

	protected function redirectResponse($params = array(), $page = null, $code = 302) {
		return $this->container->getApplication()->redirectResponse($page, $params, $code);
	}
}
