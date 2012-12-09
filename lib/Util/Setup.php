<?php
/*
 * Copyright (c) 2012, webvariants GbR, http://www.webvariants.de
 *
 * This file is released under the terms of the MIT license. You can find the
 * complete text in the attached LICENSE file or online at:
 *
 * http://www.opensource.org/licenses/mit-license.php
 */

class sly_Util_Setup {
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
		$protected = array(SLY_DEVELOPFOLDER, SLY_DYNFOLDER.DIRECTORY_SEPARATOR.'internal');

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

		if (empty($drivers)) {
			$viewParams['errors']     = true;
			$viewParams['pdoDrivers'] = true;
		}

		return $viewParams;
	}

	public static function checkDatabaseConnection(array $config, $create, $silent = false) {
		extract($config);

		try {
			$drivers = sly_DB_PDO_Driver::getAvailable();

			if (!in_array($DRIVER, $drivers)) {
				throw new sly_Exception(t('setup_invalid_driver'));
			}

			// open connection
			if ($create) {
				$db = new sly_DB_PDO_Persistence($DRIVER, $HOST, $LOGIN, $PASSWORD);
			}
			else {
				$db = new sly_DB_PDO_Persistence($DRIVER, $HOST, $LOGIN, $PASSWORD, $NAME);
			}

			// prepare version check, retrieve min versions from driver
			$driverClass = 'sly_DB_PDO_Driver_'.strtoupper($DRIVER);
			$driverImpl  = new $driverClass('', '', '', '');
			$constraints = $driverImpl->getVersionConstraints();

			// check version
			$helper = new sly_Util_Requirements();
			$result = $helper->pdoDriverVersion($db->getConnection(), $constraints);

			// warn only, but continue workflow
			if ($result['status'] === sly_Util_Requirements::WARNING) {
				$this->flash->appendWarning($result['text']);
			}

			// stop further code
			elseif ($result['status'] === sly_Util_Requirements::FAILED) {
				throw new sly_Exception($result['text']);
			}

			if ($create) {
				$createStmt = $driverImpl->getCreateDatabaseSQL($NAME);
				$db->query($createStmt);
			}

			return true;
		}
		catch (Exception $e) {
			if (!$silent) sly_Core::getFlashMessage()->appendWarning($e->getMessage());
			return false;
		}
	}

	public static function checkDatabaseTables(array $viewParams, sly_Configuration $config, sly_DB_Persistence $db) {
		$prefix         = $config->get('DATABASE/TABLE_PREFIX');
		$availTables    = $db->listTables();
		$requiredTables = array(
			$prefix.'article',
			$prefix.'article_slice',
			$prefix.'clang',
			$prefix.'file',
			$prefix.'file_category',
			$prefix.'user',
			$prefix.'slice',
			$prefix.'registry'
		);

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

	public static function checkUser(array $viewParams, sly_Configuration $config, sly_DB_Persistence $db) {
		$prefix      = $config->get('DATABASE/TABLE_PREFIX');
		$availTables = $db->listTables();

		if (in_array($prefix.'user', $availTables)) {
			$viewParams['userExists'] = $db->magicFetch('user', 'id') !== false;
		}
		else {
			$viewParams['userExists'] = false;
		}

		return $viewParams;
	}

	protected function setupImport($sqlScript) {
		if (file_exists($sqlScript)) {
			try {
				$importer = new sly_DB_Importer();
				$importer->import($sqlScript);
			}
			catch (Exception $e) {
				$this->flash->addWarning($e->getMessage());
				return false;
			}
		}
		else {
			$this->flash->addWarning(t('setup_import_dump_not_found'));
			return false;
		}

		return true;
	}

	public static function renderFlashMessage() {
		$msg      = sly_Core::getFlashMessage();
		$messages = $msg->getMessages(sly_Util_FlashMessage::TYPE_WARNING);
		$result   = array();

		foreach ($messages as $m) {
			if (is_array($m)) $m = implode("<br />\n", $m);
			$result[] = '<div class="alert alert-error">'.sly_html($m).'</div>';
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

	public static function getWidget(array $testResult, $text = null, $showRange = true, $regularFormat, $tooltippedFormat) {
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
		$tooltip = sly_html($tooltip);
		$text    = sly_html($text);
		$format  = str_replace(
			array('{bclass}', '{iclass}', '{tclass}', '{tooltip}', '{text}'),
			array($cls,       $icon,      $tCls,      $tooltip,    $text),
			$format
		);

		return $format;
	}
}
