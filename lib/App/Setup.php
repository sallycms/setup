<?php
/*
 * Copyright (c) 2012, webvariants GbR, http://www.webvariants.de
 *
 * This file is released under the terms of the MIT license. You can find the
 * complete text in the attached LICENSE file or online at:
 *
 * http://www.opensource.org/licenses/mit-license.php
 */

class sly_App_Setup extends sly_App_Base {
	protected $request = null;
	protected $router  = null;

	public function isBackend() {
		return true;
	}

	/**
	 * Initialize Sally system
	 *
	 * This method will set-up the language, configuration, layout etc. After
	 * that, the addOns will be loaded, so that the application can be run
	 * via run().
	 */
	public function initialize() {
		$container = $this->getContainer();

		// init request
		$this->request = $container->getRequest();

		// init the current language
		$this->initLanguage($container, $this->request);

		// init timezone and locale
		$this->initUserSettings();

		// make sure our layout is used later on
		$this->initLayout($container);

		// instantiate asset service before addOns are loaded to make sure
		// the CSS processing is first in the line for CSS files
		$container->getAssetService();
	}

	/**
	 * Run the backend app
	 *
	 * This will perform the routing, check the controller, load and execute it
	 * and send the response including the layout to the client.
	 */
	public function run() {
		try {
			// resolve URL and find controller
			$this->performRouting($this->request);
		}
		catch (sly_Controller_Exception $e) {
			$this->controller = new sly_Controller_Error($e);
			$this->action     = 'index';
		}

		// set the appropriate page ID
		$this->updateLayout();

		// do it, baby
		$dispatcher = $this->getDispatcher();
		$response   = $dispatcher->dispatch($this->controller, $this->action);

		// send the response :)
		$response->send();
	}

	public function getControllerClassPrefix() {
		return 'sly_Controller_Setup';
	}

	public function getCurrentControllerName() {
		return $this->controller;
	}

	public function getCurrentAction() {
		return $this->action;
	}

	public function getRouter() {
		return $this->router;
	}

	public function redirect($page, $params = array(), $code = 302) {
		$url = $this->router->getAbsoluteUrl($page, null, $params, '&');
		sly_Util_HTTP::redirect($url, '', '', $code);
	}

	public function redirectResponse($page, $params = array(), $code = 302) {
		$url      = $this->router->getAbsoluteUrl($page, null, $params, '&');
		$response = $this->getContainer()->getResponse();

		$response->setStatusCode($code);
		$response->setHeader('Location', $url);
		$response->setContent(t('redirect_to', $url));

		return $response;
	}

	/**
	 * get request dispatcher
	 *
	 * @return sly_Dispatcher
	 */
	protected function getDispatcher() {
		if ($this->dispatcher === null) {
			$this->dispatcher = new sly_Dispatcher_Setup($this->getContainer(), $this->getControllerClassPrefix());
		}

		return $this->dispatcher;
	}

	protected function initLanguage(sly_Container $container, sly_Request $request) {
		// the following article API calls require to know a language
		$container->setCurrentLanguageId(sly_Core::getDefaultClangId());
	}

	protected function initUserSettings() {
		$container = $this->getContainer();

		// set timezone
		$this->setDefaultTimezone();

		$locale        = sly_Core::getDefaultLocale();
		$locales       = sly_I18N::getLocales(SLY_SALLYFOLDER.'/backend/lang');
		$requestLocale = $this->request->request('lang', 'string', '');
		$user          = null;

		if (in_array($requestLocale, $locales)) {
			$locale = $requestLocale;
		}

		// set the i18n object
		$this->initI18N($container, $locale);
	}

	protected function setDefaultTimezone() {
		$timezone = @date_default_timezone_get();

		// fix badly configured servers where the get function doesn't even return a guessed default timezone
		if (empty($timezone)) {
			$timezone = sly_Core::getTimezone();
		}

		// set the determined timezone
		date_default_timezone_set($timezone);
	}

	protected function initLayout(sly_Container $container) {
		$i18n    = $container->getI18N();
		$request = $container->getRequest();

		$container->setLayout(new sly_Layout_Setup($i18n, $request));
	}

	protected function initI18N(sly_Container $container, $locale) {
		$i18n = new sly_I18N($locale, SLY_SALLYFOLDER.'/setup/lang');
		$container->setI18N($i18n);
	}

	protected function getControllerFromRequest(sly_Request $request) {
		return 'setup';
	}

	protected function getActionFromRequest(sly_Request $request) {
		return $this->router->getActionFromRequest($request);
	}

	protected function updateLayout() {
		$layout = $this->getContainer()->getLayout();
		$layout->setRouter($this->getRouter());
	}

	protected function prepareRouter(sly_Container $container) {
		// use the basic router
		$this->router = new sly_Router_Setup(array(), $this, $this->getDispatcher());

		return $this->router;
	}
}
