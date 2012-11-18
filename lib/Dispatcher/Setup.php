<?php
/*
 * Copyright (c) 2012, webvariants GbR, http://www.webvariants.de
 *
 * This file is released under the terms of the MIT license. You can find the
 * complete text in the attached LICENSE file or online at:
 *
 * http://www.opensource.org/licenses/mit-license.php
 */

class sly_Dispatcher_Setup extends sly_Dispatcher_Backend {
	protected function handleControllerError(Exception $e, $controller, $action) {
		// throw away all content (including notices and warnings)
		while (ob_get_level()) ob_end_clean();

		// manually create the error controller to pass the exception
		$controller = new sly_Controller_Setup_Error($e);

		// forward to the error page
		return new sly_Response_Forward($controller, 'index');
	}
}
