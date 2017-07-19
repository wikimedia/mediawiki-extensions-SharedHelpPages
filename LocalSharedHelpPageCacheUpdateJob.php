<?php

/**
 * A job that runs on local wikis to purge Squid and possibly
 * queue local HTMLCacheUpdate jobs
 */
class LocalSharedHelpPageCacheUpdateJob extends Job {
	/**
	 * @param Title $title
	 * @param array $params Should have 'pagename' and 'touch' keys
	 */
	public function __construct( Title $title, array $params ) {
		parent::__construct( 'LocalSharedHelpPageCacheUpdateJob', $title, $params );
	}

	public function run() {
		$title = Title::makeTitleSafe( NS_HELP, $this->params['pagename'] );
		// We want to purge the cache of the accompanying page so the tabs change colors
		$other = $title->getOtherPage();

		$title->purgeSquid();
		$other->purgeSquid();
		HTMLFileCache::clearFileCache( $title );
		HTMLFileCache::clearFileCache( $other );
		if ( $this->params['touch'] ) {
			$title->touchLinks();
		}
	}
}
