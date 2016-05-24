<?php
/*
 * Copyright (c) 2016, webvariants GmbH & Co. KG, http://www.webvariants.de
 *
 * This file is released under the terms of the MIT license. You can find the
 * complete text in the attached LICENSE file or online at:
 *
 * http://www.opensource.org/licenses/mit-license.php
 */

class sly_App_Setup extends sly_App_Base {
	protected $request    = null;
	protected $router     = null;
	protected $dispatcher = null;

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

		// init basic error handling
		$container->getErrorHandler()->init();

		// init request
		$this->request = $container->getRequest();

		// set timezone
		$this->initTimezone();

		// init locale
		$this->initLocale($container);

		// make sure our layout is used later on
		$this->initLayout($container);
	}

	/**
	 * Run the setup app
	 *
	 * This will perform execute the setup controller and send its response.
	 */
	public function run() {
		// resolve URL and find controller
		$this->performRouting($this->request);

		// do it, baby
		$dispatcher = $this->getDispatcher();
		$response   = $dispatcher->dispatch($this->controller, $this->action);

		// send the response :)
		$response->send();
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
		$url      = $this->router->getAbsoluteUrl($page, $params, '&');
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
			$this->dispatcher = new sly_Dispatcher_Setup($this->getContainer());
		}

		return $this->dispatcher;
	}

	protected function initLocale(sly_Container $container) {
		sly_Util_Session::start();

		// explicit locale in query string or already stored in session?
		$session = $container->getSession();
		$locales = sly_I18N::getLocales(SLY_SALLYFOLDER.'/setup/lang');
		$locale  = $this->request->get('locale', 'string', $session->get('locale', 'string'));

		// locale is still empty or not avalable?
		if (empty($locale) || !in_array($locale, $locales)) {
			// create a map of locales including short locales
			$localeMap = array_combine($locales, $locales);

			foreach($locales as $l) {
				$localeMap[mb_substr($l, 0, 2)] = $l;
			}

			// get the preferred language from the browser,
			// or at least the first language from our map
			$prefered = $this->request->getPreferredLanguage(array_keys($localeMap));
			$locale   = $localeMap[$prefered];
		}

		// remember the chosen locale
		$session->set('locale', $locale);

		// set the i18n object
		$i18n = new sly_I18N($locale, SLY_SALLYFOLDER.'/setup/lang');
		$container->setI18N($i18n);
	}

	protected function initTimezone() {
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

	protected function getControllerFromRequest(sly_Request $request) {
		return 'setup';
	}

	protected function getActionFromRequest(sly_Request $request) {
		return $this->router->getActionFromRequest($request);
	}

	protected function prepareRouter(sly_Container $container) {
		// use the basic router
		$this->router = new sly_Router_Setup(array(), $this, $this->getDispatcher());

		return $this->router;
	}
}
