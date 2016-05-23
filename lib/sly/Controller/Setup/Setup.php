<?php
/*
 * Copyright (c) 2014, webvariants GmbH & Co. KG, http://www.webvariants.de
 *
 * This file is released under the terms of the MIT license. You can find the
 * complete text in the attached LICENSE file or online at:
 *
 * http://www.opensource.org/licenses/mit-license.php
 */

class sly_Controller_Setup_Setup extends sly_Controller_Setup_Base implements sly_Controller_Interface {
	protected $flash;

	protected function init($requireConnection = false, $requireValidDatabase = false) {
		$this->flash = sly_Core::getFlashMessage();

		if (!sly_Core::isSetup()) {
			return new sly_Response('Setup is disabled.', 403);
		}

		// check system config and stop with an error page if any serious problems arise
		$params = array('errors' => false);
		$params = sly_Util_Setup::checkSystem($params);
		$params = sly_Util_Setup::checkPdoDrivers($params);
		$params = sly_Util_Setup::checkDirectories($params);
		$params = sly_Util_Setup::checkHttpAccess($params);

		if ($params['errors']) {
			$this->syscheckView($params);
			return false;
		}

		if ($requireConnection) {
			$container = $this->getContainer();
			$config    = $container->getConfig();

			// if there is no valid db config, go back to the config page
			if (!sly_Util_Setup::checkDatabaseConnection($config->get('database'), false)) {
				return $this->redirectResponse();
			}

			if ($requireValidDatabase) {
				$db     = $container->getPersistence();
				$prefix = $config->get('database/table_prefix');
				$params = sly_Util_Setup::checkDatabaseTables(array(), $prefix, $db);

				if (!in_array('nop', $params['actions'])) {
					return $this->redirectResponse(array(), 'initdb');
				}

				$params = sly_Util_Setup::checkUser(array(), $prefix, $db);

				if (!$params['userExists']) {
					return $this->redirectResponse(array(), 'initdb');
				}
			}
		}

		sly_Util_Session::start();

		return true;
	}

	public function checkPermission($action) {
		return true;
	}

	public function indexAction()	{
		if (($ret = $this->init()) !== true) {
			return $ret;
		}

		// set some sensible initial values

		$container = $this->getContainer();
		$session   = $container->getSession();
		$curConfig = $session->get('sly-setup', 'array', array());

		if (empty($curConfig['timezone'])) {
			$curConfig['timezone'] = @date_default_timezone_get(); // has been set by the app already
			$session->set('sly-setup', $curConfig);
		}

		$this->configView();
	}

	public function saveconfigAction() {
		if (($ret = $this->init()) !== true) return $ret;

		$request = $this->getRequest();

		// allow only POST requests
		if (!$request->isMethod('POST')) {
			return $this->redirectResponse();
		}

		// save new config values
		$container = $this->getContainer();
		$config    = $container->getConfig();
		$session   = $container->getSession();

		// retrieve general config
		$projectName = $request->post('projectname', 'string', '');
		$timezone    = $request->post('timezone', 'string', 'UTC');

		// retrieve database config
		$create   = $request->post('create', 'bool');
		$dbConfig = array(
			'driver'       => $request->post('driver', 'string', 'mysql'),
			'host'         => $request->post('host', 'string', 'localhost'),
			'user'         => $request->post('username', 'string', ''),
			'password'     => $request->post('password', 'string', ''),
			'dbname'       => $request->post('database', 'string', ''),
			'table_prefix' => $request->post('prefix', 'string', 'sly_')
		);

		// remember the project settings in the session, as the database may not
		// be fully ready yet
		$session = $container->getSession();
		$session->set('sly-setup', array(
			'projectname'    => $projectName,
			'timezone'       => $timezone,
			'default_locale' => $session->get('locale', 'string', sly_Core::getDefaultLocale())
		));

		// check connection and either forward to the next page or show the config form again
		$valid = sly_Util_Setup::checkDatabaseConnection($dbConfig, $create) !== null;

		if ($valid) {
			$localWriter = $container['sly-config-writer'];
			$localWriter->writeLocal(array('database' => $dbConfig));
		}

		return $valid ? $this->redirectResponse(array(), 'initdb') : $this->configView();
	}

	public function initdbAction() {
		if (($ret = $this->init(true)) !== true) return $ret;
		$this->initdbView();
	}

	public function databaseAction() {
		if (($ret = $this->init(true)) !== true) return $ret;

		$request = $this->getRequest();

		// allow only POST requests
		if (!$request->isMethod('POST')) {
			return $this->redirectResponse();
		}

		// prepare work
		$container = $this->getContainer();
		$config    = $container->getConfig();
		$db        = $container->getPersistence();
		$action    = $request->post('dbaction', 'string');
		$prefix    = $config->get('database/table_prefix');
		$driver    = $config->get('database/driver');

		// setup the database
		try {
			sly_Util_Setup::setupDatabase($action, $prefix, $driver, $db);
		}
		catch (Exception $e) {
			$this->flash->appendWarning($e->getMessage());
			return $this->initdbView();
		}

		// allow the configuration to be written
		$writer = $container->get('sly-config-writer');
		$writer->setPersistence($db);

		// store the project config
		$session       = $container->getSession();
		$projectConfig = $session->get('sly-setup', 'array', array());

		$writer->writeProject(array(
			'addons'               => array(),
			'default_article_type' => '',
			'notfound_article_id'  => 1,
			'start_article_id'     => 1,
			'default_clang_id'     => 1,
			'default_locale'       => isset($projectConfig['default_locale']) ? $projectConfig['default_locale'] : 'de_de',
			'projectname'          => isset($projectConfig['projectname'])    ? $projectConfig['projectname']    : 'SallyCMS-Projekt',
			'timezone'             => isset($projectConfig['timezone'])       ? $projectConfig['timezone']       : 'Europe/Berlin',
		));

		// create/update user
		$username = $request->post('username', 'string');
		$password = $request->post('password', 'string');
		$create   = !$request->post('no_user', 'boolean', false);
		$prefix   = $config->get('database/table_prefix');
		$info     = sly_Util_Setup::checkUser(array(), $prefix, $db);

		try {
			if (!$create && !$info['userExists']) {
				throw new sly_Exception(t('must_create_first_user'));
			}

			if ($create) {
				$service = $container->getUserService();
				sly_Util_Setup::createOrUpdateUser($username, $password, $service);
			}
		}
		catch (Exception $e) {
			$this->flash->appendWarning($e->getMessage());
			return $this->initdbView();
		}

		return $this->redirectResponse(array(), 'profit');
	}

	public function profitAction() {
		if (($ret = $this->init(true, true)) !== true) return $ret;
		$this->render('setup/profit.phtml', array('enabled' => array('index')), false);
	}

	public function loginAction() {
		if (($ret = $this->init(true, true)) !== true) return $ret;

		// allow the configuration to be written
		$container = $this->getContainer();
		$reader    = $container->get('sly-config-reader');
		$writer    = $container->get('sly-config-writer');

		// load local configuration
		$localConfig = $reader->readLocal();

		// disable setup
		$systemID = sha1(sly_Util_Password::getRandomData(40));
		$systemID = substr($systemID, 0, 20);

		$localConfig['instname'] = 'sly'.$systemID;
		$localConfig['setup']    = false;

		// and write the config as a whole
		$writer->writeLocal($localConfig);

		// redirect to backend
		$request = $container->getRequest();
		$baseUrl = $request->getBaseUrl(true);
		$url     = $baseUrl.'/backend/';

		$response = new sly_Response(t('redirect_to', $url), 302);
		$response->setHeader('Location', $url);

		return $response;
	}

	protected function configView() {
		$container     = $this->getContainer();
		$config        = $container->getConfig();
		$session       = $container->getSession();
		$projectConfig = $session->get('sly-setup', 'array', array());
		$database      = $config->get('database');
		$params        = array(
			'projectName' => isset($projectConfig['projectname']) ? $projectConfig['projectname'] : '',
			'timezone'    => isset($projectConfig['timezone'])    ? $projectConfig['timezone']    : '',
			'errors'      => false,
			'enabled'     => array('index'),
			'database'    => array(
				'driver'   => $database['driver'],
				'host'     => $database['host'],
				'username' => $database['user'],
				'password' => $database['password'],
				'name'     => $database['dbname'],
				'prefix'   => $database['table_prefix'],
				'drivers'  => sly_DB_PDO_Driver::getAvailable()
			)
		);

		$params = sly_Util_Setup::checkSystem($params);
		$this->render('setup/index.phtml', $params, false);
	}

	protected function initdbView() {
		$container = $this->getContainer();
		$config    = $container->getConfig();
		$db        = $container->getPersistence();
		$prefix    = $config->get('database/table_prefix');
		$params    = array('enabled' => array('index'));
		$params    = sly_Util_Setup::checkDatabaseTables($params, $prefix, $db);
		$params    = sly_Util_Setup::checkUser($params, $prefix, $db);

		$this->render('setup/initdb.phtml', $params, false);
	}

	protected function syscheckView(array $viewParams) {
		$this->render('setup/syscheck.phtml', $viewParams, false);
	}

	protected function render() {
		// make router available to all controller views
		$args         = func_get_args();
		$params       = isset($args[1]) ? $args[1] : array();
		$returnOutput = isset($args[2]) ? $args[2] : true;
		$router       = $this->getContainer()->getApplication()->getRouter();
		$params       = array_merge(array('_router' => $router), $params);

		$container = $this->getContainer();
		$config    = $container->getConfig();

		if (sly_Util_Setup::checkDatabaseConnection($config->get('database'), false, true)) {
			$params['enabled'][] = 'initdb';

			$db     = $container->getPersistence();
			$prefix = $config->get('database/table_prefix');
			$info   = sly_Util_Setup::checkDatabaseTables(array(), $prefix, $db);
			$info   = sly_Util_Setup::checkUser($info, $prefix, $db);

			if ($info['userExists'] && in_array('nop', $info['actions'])) {
				$params['enabled'][] = 'profit';
			}
		}

		return parent::render($args[0], $params, $returnOutput);
	}
}
