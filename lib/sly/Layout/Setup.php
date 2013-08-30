<?php
/*
 * Copyright (c) 2013, webvariants GbR, http://www.webvariants.de
 *
 * This file is released under the terms of the MIT license. You can find the
 * complete text in the attached LICENSE file or online at:
 *
 * http://www.opensource.org/licenses/mit-license.php
 */

use sly\Assets\Util;

/**
 * @ingroup layout
 */
class sly_Layout_Setup extends sly_Layout_XHTML5 {
	public function __construct(sly_I18N $i18n, sly_Request $request) {
		$this->addCSSFile(Util::appUri('css/setup.less'));

		$this->addJavaScriptFile(Util::appUri('js/jquery.min.js'));
		$this->addJavaScriptFile(Util::appUri('js/jquery.chosen.min.js'));
		$this->addJavaScriptFile(Util::appUri('js/bootstrap.min.js'));
		$this->addJavaScriptFile(Util::appUri('js/setup.js'));

		$this->setTitle('SallyCMS Setup');

		$this->addMeta('robots', 'noindex,nofollow');
		$this->setBase($request->getAppBaseUrl().'/');

		$locale = $i18n->getLocale();
		$locale = explode('_', $locale, 2);
		$locale = reset($locale);

		if (strlen($locale) === 2) {
			$this->setLanguage(strtolower($locale));
		}
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
}
