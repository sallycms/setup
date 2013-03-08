<?php
/*
 * Copyright (c) 2013, webvariants GbR, http://www.webvariants.de
 *
 * This file is released under the terms of the MIT license. You can find the
 * complete text in the attached LICENSE file or online at:
 *
 * http://www.opensource.org/licenses/mit-license.php
 */

/**
 * @author christoph@webvariants.de
 * @since  0.8
 */
class sly_Router_Setup extends sly_Router_Base {
	const ACTION_PARAM = 'action'; ///< string  the request param that contains the action

	protected $app;

	public function __construct(array $routes = array(), sly_App_Base $app) {
		parent::__construct($routes);

		$this->app = $app;
	}

	public function getUrl($action = 'index', $params = '', $sep = '&amp;') {
		$url    = './';
		$action = strtolower($action);

		if ($action && $action !== 'index') {
			$url .= urlencode($action);
		}

		if (is_string($params)) {
			$params = trim($params, '&?');
		}
		elseif ($params !== null) {
			$params = http_build_query($params, '', $sep);
		}
		else {
			$params = '';
		}

		return rtrim($url.'?'.$params, '&?');
	}

	public function getAbsoluteUrl($action = 'index', $params = '', $sep = '&amp;', $forceProtocol = null) {
		$base = $this->app->getBaseUrl($forceProtocol);
		$url  = $this->getUrl($action, $params, $sep);

		return $base.($url === './' ? '' : substr($url, 1));
	}

	public function getPlainUrl($action = 'index', $params = '') {
		return $this->getUrl($action, $params, '&');
	}

	public function getControllerFromRequest(sly_Request $request) {
		return 'setup';
	}

	public function getActionFromRequest(sly_Request $request) {
		return strtolower($request->request(self::ACTION_PARAM, 'string', 'index'));
	}
}
