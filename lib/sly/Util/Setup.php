<?php
/*
 * Copyright (c) 2013, webvariants GbR, http://www.webvariants.de
 *
 * This file is released under the terms of the MIT license. You can find the
 * complete text in the attached LICENSE file or online at:
 *
 * http://www.opensource.org/licenses/mit-license.php
 */

class sly_Util_Setup {
	public static function getRequiredTables($tablePrefix) {
		return array(
			$tablePrefix.'article',
			$tablePrefix.'article_slice',
			$tablePrefix.'clang',
			$tablePrefix.'file',
			$tablePrefix.'file_category',
			$tablePrefix.'user',
			$tablePrefix.'slice',
			$tablePrefix.'registry',
			$tablePrefix.'config'
		);
	}

	public static function checkSystem(array $viewParams) {
		$errors   = $viewParams['errors'];
		$tester   = new sly_Util_Requirements();
		$exts     = array('zlib' => false, 'iconv' => false, 'mbstring' => true, 'pdo' => true, 'reflection' => false);
		$enabled  = t('enabled');
		$disabled = t('disabled');

		$results['version']          = array('5.2.3', '5.4.0', $tester->phpVersion('5.2.3', '5.4.0'));
		$results['time_limit']       = array('20s', '60s', $tester->execTime(20, 60));
		$results['mem_limit']        = array('16MB', '64MB', $tester->memoryLimit(16, 64));
		$results['register_globals'] = array($disabled, $disabled, $tester->registerGlobals());
		$results['magic_quotes']     = array($disabled, $disabled, $tester->magicQuotes());
		$results['safe_mode']        = array($disabled, $disabled, $tester->safeMode());
		$results['open_basedir']     = array($disabled, $disabled, $tester->openBasedir());

		foreach ($exts as $ext => $required) {
			$key           = 'ext_'.$ext;
			$results[$key] = array($enabled, $enabled, $tester->extAvailable($ext, $required));
		}

		foreach ($results as $result) {
			$errors |= $result[2]['status'] === sly_Util_Requirements::FAILED;
		}

		$viewParams['errors']  = $errors;
		$viewParams['results'] = $results;
		$viewParams['exts']    = array_keys($exts);

		return $viewParams;
	}

	public static function checkDirectories(array $viewParams) {
		$errors    = $viewParams['errors'];
		$s         = DIRECTORY_SEPARATOR;
		$dirs      = array();
		$writables = array(
			SLY_MEDIAFOLDER,
			SLY_DEVELOPFOLDER.$s.'templates',
			SLY_DEVELOPFOLDER.$s.'modules'
		);

		$level = error_reporting(0);

		foreach ($writables as $dir) {
			if (!sly_Util_Directory::create($dir)) {
				$dirs[] = $dir;
				$errors = true;
			}
		}

		error_reporting($level);

		$viewParams['errors']      = $errors;
		$viewParams['directories'] = $dirs;

		return $viewParams;
	}

	public static function checkHttpAccess(array $viewParams) {
		$errors    = $viewParams['errors'];
		$dirs      = array();
		$protected = array(SLY_DEVELOPFOLDER, SLY_DYNFOLDER.DIRECTORY_SEPARATOR.'internal', SLY_CONFIGFOLDER);

		foreach ($protected as $dir) {
			if (!sly_Util_Directory::createHttpProtected($dir)) {
				$dirs[] = $dir;
				$errors = true;
			}
		}

		$viewParams['errors']     = $errors;
		$viewParams['httpAccess'] = $dirs;

		return $viewParams;
	}

	public static function checkPdoDrivers(array $viewParams) {
		$drivers = sly_DB_PDO_Driver::getAvailable();

		$viewParams['availPdoDrivers'] = $drivers;

		if (empty($drivers)) {
			$viewParams['errors']     = true;
			$viewParams['pdoDrivers'] = true;
		}

		return $viewParams;
	}

	/**
	 * check database connection
	 *
	 * @param  array   $config
	 * @param  boolean $create
	 * @param  boolean $silent
	 * @param  boolean $throwException
	 * @return sly_DB_PDO_Persistence   the created persistence
	 */
	public static function checkDatabaseConnection(array $config, $create, $silent = false, $throwException = false) {
		extract($config);

		try {
			$drivers = sly_DB_PDO_Driver::getAvailable();

			if (!in_array($driver, $drivers)) {
				throw new sly_Exception(t('invalid_driver', $driver));
			}

			// OCI is impossible to create and SQLite doesn't have a CREATE DATABASE command
			if ($driver === 'sqlite' || $driver === 'oci') {
				$create = false;
			}

			// open connection
			if ($create) {
				$db = new sly_DB_PDO_Persistence($driver, $host, $login, $password, null, $table_prefix);
			}
			else {
				$db = new sly_DB_PDO_Persistence($driver, $host, $login, $password, $name, $table_prefix);
			}

			// prepare version check, retrieve min versions from driver
			$driverImpl  = $db->getConnection()->getDriver();
			$constraints = $driverImpl->getVersionConstraints();

			// check version
			$helper = new sly_Util_Requirements();
			$result = $helper->pdoDriverVersion($db->getConnection(), $constraints);

			// stop further code
			if ($result['status'] === sly_Util_Requirements::FAILED) {
				throw new sly_Exception($result['text']);
			}

			if ($create) {
				$createStmt = $driverImpl->getCreateDatabaseSQL($name);
				$db->query($createStmt);

				// re-open connection to the now hopefully existing database
				$db = new sly_DB_PDO_Persistence($driver, $host, $login, $password, $name, $table_prefix);
			}

			return $db;
		}
		catch (Exception $e) {
			if ($throwException) throw $e;
			if (!$silent) sly_Core::getFlashMessage()->appendWarning($e->getMessage());
			return null;
		}
	}

	public static function checkDatabaseTables(array $viewParams, $tablePrefix, sly_DB_Persistence $db) {
		$requiredTables = self::getRequiredTables($tablePrefix);
		$availTables    = $db->listTables();

		$actions      = array();
		$intersection = array_intersect($requiredTables, $availTables);

		// none of our tables already exist: offer 'setup' option
		if (count($intersection) === 0) {
			$actions[] = 'setup';
		}

		// at least some required tables are available: offer 'drop' action
		if (count($intersection) !== 0) {
			$actions[] = 'drop';
		}

		// all tables are available: offer 'nop' action
		if (count($intersection) === count($requiredTables)) {
			$actions[] = 'nop';
		}

		$viewParams['actions'] = $actions;

		return $viewParams;
	}

	public static function checkUser(array $viewParams, $tablePrefix, sly_DB_Persistence $db) {
		$availTables = $db->listTables();

		if (in_array($tablePrefix.'user', $availTables)) {
			$viewParams['userExists'] = $db->magicFetch('user', 'id') !== false;
		}
		else {
			$viewParams['userExists'] = false;
		}

		return $viewParams;
	}

	public static function setupDatabase($action, $tablePrefix, $driver, sly_DB_Persistence $db, $output = null, $forceAllowDrop = false) {
		$info = self::checkDatabaseTables(array(), $tablePrefix, $db);

		if ($forceAllowDrop) {
			$info['actions'][] = 'drop';
		}

		if (!in_array($action, $info['actions'], true)) {
			throw new sly_Exception(t('invalid_database_action', $action));
		}

		switch ($action) {
			case 'drop':
				if ($output) {
					$output->write('  Dropping database tables...');
				}

				$requiredTables = self::getRequiredTables($tablePrefix);

				// 'DROP TABLE IF EXISTS' is MySQL-only...
				foreach ($db->listTables() as $tblname) {
					if (in_array($tblname, $requiredTables)) {
						$db->query('DROP TABLE '.$tblname);
					}
				}

				if ($output) {
					$output->writeln(' <info>success</info>.');
				}

				// fallthrough
				// break;

			case 'setup':
				$dumpFile = SLY_COREFOLDER.'/install/'.strtolower($driver).'.sql';

				if ($output) {
					$output->write('  Creating database tables...');
				}

				if (!file_exists($dumpFile)) {
					throw new sly_Exception(t('dump_not_found', $dumpFile));
				}

				$importer = new sly_DB_Importer();
				$importer->import($dumpFile);

				if ($output) {
					$output->writeln(' <info>success</info>.');
				}
				break;
		}
	}

	public static function createOrUpdateUser($username, $password, sly_Service_User $service, $output = null) {
		$username = trim($username);
		$password = trim($password);

		if (mb_strlen($username) === 0) {
			throw new sly_Exception(t('no_admin_username_given'));
		}

		if (mb_strlen($password) === 0) {
			throw new sly_Exception(t('no_admin_password_given'));
		}

		if ($output) {
			$output->write('  Creating/updating "'.$username.'" account...');
		}

		$user = $service->find(array('login' => $username));
		$user = empty($user) ? new sly_Model_User() : reset($user);

		$user->setName(ucfirst(strtolower($username)));
		$user->setLogin($username);
		$user->setIsAdmin(true);
		$user->setStatus(true);
		$user->setCreateDate(time());
		$user->setUpdateDate(time());
		$user->setLastTryDate(null);
		$user->setCreateUser('setup');
		$user->setUpdateUser('setup');
		$user->setPassword($password);
		$user->setRevision(0);

		try {
			$service->save($user, $user);

			if ($output) {
				$output->writeln(' <info>success</info>.');
			}
		}
		catch (Exception $e) {
			throw new sly_Exception(t('cant_create_admin', $e->getMessage()));
		}
	}

	public static function renderFlashMessage() {
		$msg      = sly_Core::getFlashMessage();
		$messages = $msg->getMessages(sly_Util_FlashMessage::TYPE_WARNING);
		$result   = array();

		foreach ($messages as $m) {
			if (is_array($m)) $m = implode("\n", $m);
			$result[] = '<div class="alert alert-error">'.nl2br(sly_html($m)).'</div>';
		}

		$msg->clear();

		return implode("\n", $result);
	}

	public static function getBadge(array $testResult, $text = null, $showRange = true) {
		return self::getWidget(
			$testResult, $text, $showRange,
			'<span class="badge badge-{bclass}"><i class="icon-{iclass} icon-white"></i> {text}</span>',
			'<span class="badge badge-{bclass}" rel="tooltip" title="{tooltip}" data-placement="bottom"><i class="icon-{iclass} icon-white"></i> {text}</span>'
		);
	}

	public static function getWidget(array $testResult, $text, $showRange, $regularFormat, $tooltippedFormat, $asHTML = true) {
		if ($text === null) $text = $testResult[2]['text'];

		switch ($testResult[2]['status']) {
			case sly_Util_Requirements::OK:
				$cls     = 'success';
				$icon    = 'ok';
				$tCls    = 'success';
				$tooltip = null;
				break;

			case sly_Util_Requirements::WARNING:
				$cls     = 'warning';
				$icon    = 'exclamation-sign';
				$tCls    = 'warning';
				$tooltip = $showRange ? t('compatible_but_old', $testResult[1]) : null;
				break;

			case sly_Util_Requirements::FAILED:
				$cls     = 'important';
				$icon    = 'remove';
				$tCls    = 'error';
				$tooltip = $showRange ? t('requires_at_least', $testResult[0]) : null;
				break;
		}

		$format  = $tooltip ? $tooltippedFormat : $regularFormat;
		$tooltip = $asHTML ? sly_html($tooltip) : $tooltip;
		$text    = $asHTML ? sly_html($text) : $text;
		$format  = str_replace(
			array('{bclass}', '{iclass}', '{tclass}', '{tooltip}', '{text}'),
			array($cls,       $icon,      $tCls,      $tooltip,    $text),
			$format
		);

		return $format;
	}
}
