<?php
/*
 * Copyright (c) 2012, webvariants GbR, http://www.webvariants.de
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
			if (!sly_Util_Setup::checkDatabaseConnection($config->get('DATABASE'), false)) {
				return $this->redirectResponse();
			}

			if ($requireValidDatabase) {
				$db     = $container->getPersistence();
				$params = sly_Util_Setup::checkDatabaseTables(array(), $config, $db);

				if (!in_array('nop', $params['actions'])) {
					return $this->redirectResponse(array(), 'initdb');
				}

				$params = sly_Util_Setup::checkUser(array(), $config, $db);

				if (!$params['userExists']) {
					return $this->redirectResponse(array(), 'initdb');
				}
			}
		}

		return true;
	}

	public function checkPermission($action) {
		return true;
	}

	public function indexAction()	{
		// Just load defaults and this should be the only time to do so.
		// Beware that when restarting the setup, the configuration is already present.
		$config = $this->getContainer()->getConfig();

		if (!$config->has('DEFAULT_LOCALE')) {
			$config->loadProjectDefaults(SLY_COREFOLDER.'/config/sallyProjectDefaults.yml');
			$config->loadLocalDefaults(SLY_COREFOLDER.'/config/sallyLocalDefaults.yml');

			// create system ID
			$systemID = sha1(sly_Util_Password::getRandomData(40));
			$systemID = substr($systemID, 0, 20);

			$config->setLocal('INSTNAME', 'sly'.$systemID);
		}

		if (($ret = $this->init()) !== true) {
			return $ret;
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

		// check for accepted license
		if (!$request->post('license', 'bool', false)) {
			$this->flash->appendWarning(t('must_accept_license'));
			$session->set('license', false);

			return $this->configView();
		}

		// retrieve general config
		$projectName = $request->post('projectname', 'string', '');
		$timezone    = $request->post('timezone', 'string', 'UTC');

		// retrieve database config
		$driver   = $request->post('driver', 'string', 'mysql');
		$host     = $request->post('host', 'string', 'localhost');
		$login    = $request->post('username', 'string', '');
		$password = $request->post('password', 'string', '');
		$name     = $request->post('database', 'string', '');
		$prefix   = $request->post('prefix', 'string', 'sly_');
		$create   = $request->post('create', 'bool') && ($driver !== 'sqlite' && $driver !== 'oci');
		$dbConfig = array(
			'DRIVER'       => $driver,
			'HOST'         => $host,
			'LOGIN'        => $login,
			'PASSWORD'     => $password,
			'NAME'         => $name,
			'TABLE_PREFIX' => $prefix
		);

		// update configuration
		$config->set('PROJECTNAME', $projectName);
		$config->set('TIMEZONE', $timezone);
		$config->set('DEFAULT_LOCALE', $session->get('locale', 'string', sly_Core::getDefaultLocale()));
		$config->setLocal('DATABASE', $dbConfig);

		// remember the accepted license
		$session->set('license', true);

		// check connection and either forward to the next page or show the config form again
		$valid = sly_Util_Setup::checkDatabaseConnection($dbConfig, $create);

		return $valid ? $this->redirectResponse(array(), 'initdb') : $this->configView();
	}

	public function initdbAction() {
		if (($ret = $this->init(true)) !== true) return $ret;
		$this->initdbView();
	}

	public function databaseAction() {
		if (($ret = $this->init(true)) !== true) return $ret;
		$this->initdbView();
	}

	public function createuserAction($redirected = false) {
		$this->init();

		$config      = sly_Core::config();
		$request     = $this->getRequest();
		$prefix      = $config->get('DATABASE/TABLE_PREFIX');
		$pdo         = sly_DB_Persistence::getInstance();
		$usersExist  = $pdo->listTables($prefix.'user') && $pdo->magicFetch('user', 'id') !== false;
		$createAdmin = !$request->post('no_admin', 'boolean', false);
		$adminUser   = $request->post('admin_user', 'string');
		$adminPass   = $request->post('admin_pass', 'string');
		$success     = true;

		if ($request->isMethod('POST') && !$redirected) {
			if ($createAdmin) {
				if (empty($adminUser)) {
					$this->flash->appendWarning(t('setup_createuser_no_admin_given'));
					$success = false;
				}

				if (empty($adminPass)) {
					$this->flash->appendWarning(t('setup_createuser_no_password_given'));
					$success = false;
				}

				if ($success) {
					$service = sly_Service_Factory::getUserService();
					$user    = $service->find(array('login' => $adminUser));
					$user    = empty($user) ? new sly_Model_User() : reset($user);

					$user->setName(ucfirst(strtolower($adminUser)));
					$user->setLogin($adminUser);
					$user->setRights('#admin[]#');
					$user->setStatus(true);
					$user->setCreateDate(time());
					$user->setUpdateDate(time());
					$user->setLastTryDate(0);
					$user->setCreateUser('setup');
					$user->setUpdateUser('setup');
					$user->setPassword($adminPass);
					$user->setRevision(0);

					try {
						$service->save($user, $user);
					}
					catch (Exception $e) {
						$this->flash->appendWarning(t('setup_createuser_cant_create_admin'));
						$this->flash->appendWarning($e->getMessage());
						$success = false;
					}
				}
			}
			elseif (!$usersExist) {
				$this->flash->appendWarning(t('setup_createuser_no_users_found'));
				$success = false;
			}

			if ($success) {
				return $this->finishAction();
			}
		}

		$this->render('setup/createuser.phtml', array(
			'usersExist' => $usersExist,
			'adminUser'  => $adminUser
		), false);
	}

	public function profitAction() {
		if (($ret = $this->init(true)) !== true) return $ret;
		$this->render('setup/profit.phtml', array(), false);
	}

	public function loginAction() {
		if (($ret = $this->init(true)) !== true) return $ret;

		$config = $this->getContainer()->getConfig();
		$config->setLocal('SETUP', false);

		$this->render('setup/finish.phtml', array(), false);
	}

	protected function configView() {
		$container = $this->getContainer();
		$config    = $container->getConfig();
		$session   = $container->getSession();
		$database  = $config->get('DATABASE');
		$params    = array(
			'projectName' => $config->get('PROJECTNAME'),
			'timezone'    => $config->get('TIMEZONE'),
			'errors'      => false,
			'license'     => $session->get('license', 'bool', false),
			'enabled'     => array('index'),
			'database'    => array(
				'driver'   => $database['DRIVER'],
				'host'     => $database['HOST'],
				'username' => $database['LOGIN'],
				'password' => $database['PASSWORD'],
				'name'     => $database['NAME'],
				'prefix'   => $database['TABLE_PREFIX'],
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
		$params    = array('enabled' => array('index'));
		$params    = sly_Util_Setup::checkDatabaseTables($params, $config, $db);
		$params    = sly_Util_Setup::checkUser($params, $config, $db);

		$this->render('setup/initdb.phtml', $params, false);
	}

	protected function syscheckView(array $viewParams) {
		$this->render('setup/syscheck.phtml', $viewParams, false);
	}

	protected function render($filename, array $params = array(), $returnOutput = true) {
		// make router available to all controller views
		$router = $this->getContainer()->getApplication()->getRouter();
		$params = array_merge(array('_router' => $router), $params);

		$container = $this->getContainer();
		$config    = $container->getConfig();

		if (sly_Util_Setup::checkDatabaseConnection($config->get('DATABASE'), false, true)) {
			$params['enabled'][] = 'initdb';

			$db   = $container->getPersistence();
			$info = sly_Util_Setup::checkDatabaseTables(array(), $config, $db);
			$info = sly_Util_Setup::checkUser($info, $config, $db);

			if ($info['userExists'] && in_array('nop', $info['actions'])) {
				$params['enabled'][] = 'profit';
			}
		}

		return parent::render($filename, $params, $returnOutput);
	}
}
