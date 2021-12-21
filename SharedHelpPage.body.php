<?php

use MediaWiki\MediaWikiServices;

class SharedHelpPage extends Article {

	/**
	 * Cache version of action=parse
	 * output
	 */
	const PARSED_CACHE_VERSION = 2;

	/**
	 * @var Config
	 */
	private $config;

	/**
	 * @var WANObjectCache
	 */
	private $cache;

	/**
	 * @var MapCacheLRU
	 */
	private static $displayCache;

	/**
	 * @var MapCacheLRU
	 */
	private static $touchedCache;

	public function __construct( Title $title, Config $config ) {
		$this->config = $config;
		$this->cache = MediaWikiServices::getInstance()->getMainWANObjectCache();
		parent::__construct( $title );
	}

	public function showMissingArticle() {
		$title = $this->getTitle();

		if ( !self::shouldDisplaySharedPage( $title ) ) {
			parent::showMissingArticle();
			return;
		}

		$out = $this->getContext()->getOutput();
		$parsedOutput = $this->getRemoteParsedText( self::getCentralTouched( $title ) );

		// If the help page is empty or the API request failed, show the normal
		// missing article page
		if ( !$parsedOutput || !trim( $parsedOutput['text'] ) ) {
			parent::showMissingArticle();
			return;
		}

		$out->addHTML( $parsedOutput['text'] );
		$out->addModuleStyles( 'ext.SharedHelpPages' );

		// Load ParserOutput modules...
		$this->loadModules( $out, $parsedOutput );
	}

	/**
	 * Attempts to load modules through the
	 * ParserOutput on the local wiki, if
	 * they exist.
	 *
	 * @param OutputPage $out
	 * @param array $parsedOutput
	 */
	private function loadModules( OutputPage $out, array $parsedOutput ) {
		$rl = $out->getResourceLoader();
		$map = [
			'modules' => 'addModules',
			'modulestyles' => 'addModuleStyles',
			'modulescripts' => 'addModuleScripts',
		];
		foreach ( $map as $type => $func ) {
			foreach ( $parsedOutput[$type] as $module ) {
				if ( $rl->isModuleRegistered( $module ) ) {
					$out->$func( $module );
				}
			}
		}

		$out->addJsConfigVars( $parsedOutput['jsconfigvars'] );
	}

	/**
	 * Given a Title, assuming it doesn't exist, should
	 * we display a shared help page on it
	 *
	 * @param Title $title
	 * @return bool
	 */
	public static function shouldDisplaySharedPage( Title $title ) {
		global $wgSharedHelpPagesDevelopmentMode;

		if ( !self::canBeGlobal( $title ) ) {
			return false;
		}

		// Do some instance caching since this can be
		// called frequently due do the Linker hook
		if ( !self::$displayCache ) {
			self::$displayCache = new MapCacheLRU( 100 );
		}

		$text = $title->getPrefixedText();
		if ( self::$displayCache->has( $text ) ) {
			return self::$displayCache->get( $text );
		}

		// If we're running in development mode, skip the page existence check.
		// It can be a bit of a problem when your local copy of the Hub DB is
		// *not* 100% up to date (e.g. a page exists on Hub but not on your local
		// copy of Hub's DB).
		if ( $wgSharedHelpPagesDevelopmentMode ) {
			return true;
		} else {
			$touched = (bool)self::getCentralTouched( $title );
			self::$displayCache->set( $text, $touched );
			return $touched;
		}
	}

	/**
	 * Get the page_touched of the shared help page
	 *
	 * @todo this probably shouldn't be static
	 * @param Title $title
	 * @return string|bool
	 */
	protected static function getCentralTouched( Title $title ) {
		if ( !self::$touchedCache ) {
			self::$touchedCache = new MapCacheLRU( 100 );
		}
		if ( self::$touchedCache->has( $title->getDBkey() ) ) {
			return self::$touchedCache->get( $title->getDBkey() );
		}

		$sharedHelpDB = SharedHelpPagesHooks::determineDatabase();
		$lbFactory = MediaWikiServices::getInstance()->getDBLoadBalancerFactory();
		$lb = $lbFactory->getMainLB( $sharedHelpDB );
		$dbr = $lb->getConnectionRef( DB_REPLICA, [], $sharedHelpDB );
		$row = $dbr->selectRow(
			[ 'page' ],
			[ 'page_touched' ],
			[
				'page_namespace' => NS_HELP,
				'page_title' => $title->getDBkey(),
			],
			__METHOD__
		);
		if ( $row ) {
			$touched = $row->page_touched;
		} else {
			$touched = false;
		}

		self::$touchedCache->set( $title->getDBkey(), $touched );

		return $touched;
	}

	/**
	 * Given a Title, is it a source page we might
	 * be "transcluding" on another site
	 *
	 * @return bool
	 */
	public function isSourcePage() {
		if ( WikiMap::getCurrentWikiId() !== SharedHelpPagesHooks::determineDatabase() ) {
			return false;
		}

		$title = $this->getTitle();
		if ( !$title->inNamespace( NS_HELP ) ) {
			return false;
		}

		// Root help page
		return $title->getRootTitle()->equals( $title );
	}

	/**
	 * @param string $touched The page_touched for the page
	 * @return array
	 */
	public function getRemoteParsedText( $touched ) {
		$langCode = $this->getContext()->getLanguage()->getCode();

		// Need language code in the key since we pass &uselang= to the API.
		$key = $this->cache->makeGlobalKey( 'sharedhelppages', 'parsed',
			self::PARSED_CACHE_VERSION, $touched, $langCode, md5( $this->mPage->getTitle()->getPrefixedText() )
		);
		$data = $this->cache->get( $key );
		if ( $data === false ) {
			$data = $this->parseWikiText( $this->getTitle(), $langCode );
			if ( $data ) {
				$this->cache->set( $key, $data, $this->config->get( 'SharedHelpPagesCacheExpiry' ) );
			} else {
				// Cache failure for 10 seconds
				$this->cache->set( $key, null, 10 );
			}
		}

		return $data;
	}

	/**
	 * Checks whether the given page can be global
	 * doesn't check the actual database
	 *
	 * @param Title $title
	 * @return bool
	 */
	protected static function canBeGlobal( Title $title ) {
		// Don't run this code for Hub.
		if ( WikiMap::getCurrentWikiId() === SharedHelpPagesHooks::determineDatabase() ) {
			return false;
		}

		// Must be a help page
		if ( !$title->inNamespace( NS_HELP ) ) {
			return false;
		}

		// Why not?
		return true;
	}

	/**
	 * @param Title $title
	 * @return SharedHelpPagePage
	 */
	public function newPage( Title $title ) {
		return new SharedHelpPagePage( $title, $this->config );
	}

	/**
	 * Use action=parse to get rendered HTML of a page
	 *
	 * @param Title $title
	 * @param string $langCode
	 * @return array|bool
	 */
	protected function parseWikiText( Title $title, $langCode ) {
		$unLocalizedName = MediaWikiServices::getInstance()
			->getNamespaceInfo()
			->getCanonicalName( NS_HELP ) . ':' . $title->getText();
		$wikitext = '{{:' . $unLocalizedName . '}}';
		$params = [
			'action' => 'parse',
			'title' => $unLocalizedName,
			'text' => $wikitext,
			'disableeditsection' => 1,
			'disablelimitreport' => 1,
			'prop' => 'text|modules|jsconfigvars',
			'formatversion' => 2
		];
		$data = $this->mPage->makeAPIRequest( $params, $langCode );
		$parsed = $data['parse'];

		if ( $this->config->get( 'SharedHelpPagesDevelopmentMode' ) ) {
			// XXX FILTHY HACK!
			// Replace the remote wiki's script path with ours
			// My local URLs look like this: http://localhost/shoutwiki/trunk/index.php/Help:Links
			// Whereas production URLs look like this: http://www.shoutwiki.com/wiki/Help:Links
			// So this is really needed only for devboxes etc.
			global $wgArticlePath;
			$parsed['text'] = preg_replace( '/href="\/wiki\//', 'href="' . str_replace( '$1', '', $wgArticlePath ), $parsed['text'] );
		}

		// HACK TIME! The parsed wikitext acts as if it was parsed on the remote
		// wiki -- this is good for things like images and whatnot, but very
		// bad for things like the project namespace name and whatnot.
		// So, we need to get the remote wiki's project (&project talk) NS names;
		// either from memcached, or failing that, via an API query.
		global $wgLanguageCode;

		$projectNSCacheKey = $this->cache->makeKey( 'helppages', $wgLanguageCode, 'projectns' );
		$projectTalkNSCacheKey = $this->cache->makeKey( 'helppages', $wgLanguageCode, 'projecttalkns' );

		$remoteWikiProjectNS = $this->cache->get( $projectNSCacheKey );
		$remoteWikiProjectTalkNS = $this->cache->get( $projectTalkNSCacheKey );

		if (
			$remoteWikiProjectNS === false ||
			$remoteWikiProjectTalkNS === false
		)
		{
			// Damn, no cache hit, so we need to hit the API instead.
			// Yes, I realize that's a terrible pun.
			$nsQueryParams = [
				'action' => 'query',
				'meta' => 'siteinfo',
				'siprop' => 'namespaces|namespacealiases'
			];
			$namespaceData = $this->mPage->makeAPIRequest( $nsQueryParams, $langCode );

			// Get the remote wiki's NS_PROJECT & NS_PROJECT_TALK
			$remoteWikiProjectNS = $namespaceData['query']['namespaces'][NS_PROJECT]['*'];
			$remoteWikiProjectTalkNS = $namespaceData['query']['namespaces'][NS_PROJECT_TALK]['*'];

			// Sanitize it. This should have the nice side-effect of avoiding (too many)
			// false positives since about the only place where underscores are used
			// in namespace names are, unsurprisingly, URLs. :)
			$remoteWikiProjectNS = str_replace( ' ', '_', $remoteWikiProjectNS );
			$remoteWikiProjectTalkNS = str_replace( ' ', '_', $remoteWikiProjectTalkNS );

			// Store both values in memcached for a week, since namespace names
			// (especially on Hub(s)) are unlikely to change very often
			$this->cache->set( $projectNSCacheKey, $remoteWikiProjectNS, 7 * 86400 );
			$this->cache->set( $projectTalkNSCacheKey, $remoteWikiProjectTalkNS, 7 * 86400 );
		}

		$contLang = MediaWikiServices::getInstance()->getContentLanguage();
		$parsed = str_replace(
			[
				$remoteWikiProjectNS . ':',
				$remoteWikiProjectTalkNS . ':',
			],
			[
				$contLang->getNsText( NS_PROJECT ) . ':',
				$contLang->getNsText( NS_PROJECT_TALK ) . ':',
			],
			$parsed
		);

		return $data !== false ? $parsed : false;
	}

	/**
	 * @return array
	 */
	public static function getEnabledWikis() {
		static $list = null;
		if ( $list === null ) {
			$list = [];
			if ( Hooks::run( 'SharedHelpPagesWikis', [ &$list ] ) ) {
				// Fallback if no hook override
				global $wgLocalDatabases;
				$list = $wgLocalDatabases;
			}
		}

		return $list;
	}
}
