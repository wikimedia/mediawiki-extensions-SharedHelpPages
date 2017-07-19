<?php

class SharedHelpPageCacheInvalidator {
	/**
	 * Page name of the page whose cache needs to be invalidated
	 *
	 * @var string
	 */
	private $pagename;

	/**
	 * Array of string options
	 *
	 * @var array
	 */
	private $options;

	public function __construct( $pagename, array $options = [] ) {
		$this->pagename = $pagename;
		$this->options = $options;
	}

	public function invalidate() {
		global $wgUseSquid, $wgUseFileCache;

		if ( !$wgUseSquid && !$wgUseFileCache && !$this->options ) {
			// No Squid and no options means nothing to do!
			return;
		}

		JobQueueGroup::singleton()->push( new SharedHelpPageLocalJobSubmitJob(
			Title::newFromText( 'Help:' . $this->pagename ),
			[
				'pagename' => $this->pagename,
				'touch' => in_array( 'links', $this->options ),
			]
		) );
	}
}
