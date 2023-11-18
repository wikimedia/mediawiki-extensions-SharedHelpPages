<?php

use MediaWiki\MediaWikiServices;

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
		if ( method_exists( MediaWikiServices::class, 'getJobQueueGroupFactory' ) ) {
			// MW 1.37+
			$jobQueueGroupFactory = MediaWikiServices::getInstance()->getJobQueueGroupFactory();
		} else {
			$jobQueueGroupFactory = null;
		}
		foreach ( SharedHelpPages::getEnabledWikis() as $wiki ) {
			if ( $jobQueueGroupFactory ) {
				// MW 1.37+
				$jobQueueGroupFactory->makeJobQueueGroup( $wiki )->push( $job );
			} else {
				JobQueueGroup::singleton( $wiki )->push( $job );
			}
		}
	}
}
