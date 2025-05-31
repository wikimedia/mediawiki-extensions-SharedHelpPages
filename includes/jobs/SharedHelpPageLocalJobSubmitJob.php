<?php

use MediaWiki\MediaWikiServices;
use MediaWiki\Title\Title;

/**
 * Job class that submits LocalSharedHelpPageCacheUpdateJob jobs
 */
class SharedHelpPageLocalJobSubmitJob extends Job {
	public function __construct( Title $title, array $params ) {
		parent::__construct( 'SharedHelpPageLocalJobSubmitJob', $title, $params );
	}

	public function run() {
		$job = new LocalSharedHelpPageCacheUpdateJob(
			Title::newFromText( 'Help:' . $this->params['pagename'] ),
			$this->params
		);

		$jobQueueGroupFactory = MediaWikiServices::getInstance()->getJobQueueGroupFactory();

		foreach ( SharedHelpPages::getEnabledWikis() as $wiki ) {
				$jobQueueGroupFactory->makeJobQueueGroup( $wiki )->push( $job );
		}
	}
}
