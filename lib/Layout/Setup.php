<?php
/*
 * Copyright (c) 2012, webvariants GbR, http://www.webvariants.de
 *
 * This file is released under the terms of the MIT license. You can find the
 * complete text in the attached LICENSE file or online at:
 *
 * http://www.opensource.org/licenses/mit-license.php
 */

/**
 * @ingroup layout
 */
class sly_Layout_Setup extends sly_Layout_XHTML5 {
	private $router;

	public function __construct(sly_I18N $i18n, sly_Request $request) {
		$locale = $i18n->getLocale();
		$base   = $request->getBaseUrl(true).'/';

		$this->addCSSFile('assets/css/bootstrap.min.css');

		$this->addJavaScriptFile('assets/js/jquery.min.js');
		$this->addJavaScriptFile('assets/js/bootstrap.min.js');
		$this->addJavaScriptFile('assets/js/jquery.chosen.min.js');

		$this->setTitle('SallyCMS Setup - ');

		$this->addMeta('robots', 'noindex,nofollow');
		$this->setBase($request->getAppBaseUrl().'/');

		$locale = explode('_', $locale, 2);
		$locale = reset($locale);

		if (strlen($locale) === 2) {
			$this->setLanguage(strtolower($locale));
		}
	}

	public function setRouter(sly_Router_Base $router) {
		$this->router = $router;
	}

	public function printHeader() {
		parent::printHeader();
		print $this->renderView('top.phtml');
	}

	public function printFooter() {
		print $this->renderView('bottom.phtml');
		parent::printFooter();
	}

	protected function getViewFile($file) {
		$full = SLY_SALLYFOLDER.'/setup/views/layout/'.$file;
		if (file_exists($full)) return $full;

		return parent::getViewFile($file);
	}

	/**
	 * @param  string $filename
	 * @param  array  $params
	 * @return string
	 */
	protected function renderView($filename, $params = array()) {
		// make router available to all controller views
		$params = array_merge(array('_router' => $this->router), $params);

		return parent::renderView($filename, $params);
	}
}
