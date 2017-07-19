<?php

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
		foreach ( SharedHelpPages::getEnabledWikis() as $wiki ) {
			JobQueueGroup::singleton( $wiki )->push( $job );
		}
	}
}
