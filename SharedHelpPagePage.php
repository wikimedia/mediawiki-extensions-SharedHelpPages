<?php

use MediaWiki\MediaWikiServices;

class SharedHelpPagePage extends WikiPage {

	/**
	 * @var Config
	 */
	private $config;

	/**
	 * @var WANObjectCache
	 */
	private $cache;

	public function __construct( Title $title, Config $config ) {
		parent::__construct( $title );
		$this->config = $config;
		$this->cache = MediaWikiServices::getInstance()->getMainWANObjectCache();
	}

	public function isLocal() {
		return $this->getTitle()->exists();
	}

	/**
	 * @return string
	 */
	public function getWikiDisplayName() {
		$url = $this->getSourceURL();
		return wfParseUrl( $url )['host'];
	}

	/**
	 * Page name for the given shared help page
	 *
	 * @return string
	 */
	public function getPagename() {
		return $this->getTitle()->getText();
	}

	/**
	 * Returns a URL to the help page on the central wiki,
	 * attempts to use SiteConfiguration if possible, else
	 * falls back to using an API request
	 *
	 * @return string
	 */
	public function getSourceURL() {
		$wiki = WikiMap::getWiki( SharedHelpPagesHooks::determineDatabase() );
		if ( $wiki ) {
			return $wiki->getCanonicalUrl(
				'Help:' . $this->getPagename()
			);
		}

		// Fallback to the API
		return $this->getRemoteURLFromAPI();
	}

	/**
	 * Returns a URL to the help page on the central wiki;
	 * if MW >= 1.24, this will be the canonical URL, otherwise
	 * it will be using whatever protocol was specified in
	 * $wgSharedHelpPagesAPIUrl.
	 *
	 * @return string
	 */
	protected function getRemoteURLFromAPI() {
		$key = 'sharedhelppages:url:' . md5( $this->getPagename() );
		$data = $this->cache->get( $key );
		if ( $data === false ) {
			$params = [
				'action' => 'query',
				'titles' => 'Help:' . $this->getPagename(),
				'prop' => 'info',
				'inprop' => 'url',
				'formatversion' => '2',
			];
			$resp = $this->makeAPIRequest( $params );
			if ( $resp === false ) {
				// Don't cache upon failure
				return '';
			}
			$data = $resp['query']['pages'][0]['canonicalurl'];
			// Don't set an expiry since we expect people not to change the
			// URL to their wiki without clearing their caches!
			$this->cache->set( $key, $data );
		}

		return $data;
	}

	/**
	 * Makes an API request to the central wiki
	 *
	 * @param $params array
	 * @param string $langCode ISO 639 language code, used to select the correct URL
	 * @return array|bool false if the request failed
	 */
	public function makeAPIRequest( $params, $langCode = 'en' ) {
		$params['format'] = 'json';

		if ( in_array( $langCode, $this->config->get( 'SharedHelpLanguages' ) ) && $langCode != 'en' ) {
			$baseURL = "http://{$langCode}.shoutwiki.com/w/api.php"; // @todo FIXME: move to config, I guess
		} else {
			// Fall back to English
			$baseURL = $this->config->get( 'SharedHelpPagesAPIUrl' );
		}

		$url = wfAppendQuery( $baseURL, $params );

		wfDebugLog( 'SharedHelpPages', "Making a request to $url" );
		$req = MediaWikiServices::getInstance()->getHttpRequestFactory()->create(
			$url,
			[ 'timeout' => $this->config->get( 'SharedHelpPagesTimeout' ) ]
		);
		$status = $req->execute();
		if ( !$status->isOK() ) {
			wfDebugLog( 'SharedHelpPages', __METHOD__ . " Error: {$status->getWikitext()}" );
			return false;
		}
		$json = $req->getContent();
		$decoded = FormatJson::decode( $json, true );
		return $decoded;
	}
}
