<?php

class SharedHelpPages {

	/**
	 * Makes an API request to ShoutWiki Hub
	 *
	 * @param $params array
	 * @param $langCode string
	 * @return array
	 */
	public static function makeAPIRequest( $params, $langCode ) {
		global $wgSharedHelpLanguages;

		$params['format'] = 'json';

		if ( in_array( $langCode, $wgSharedHelpLanguages ) && $langCode != 'en' ) {
			$baseURL = "http://{$langCode}.shoutwiki.com/w/api.php";
		} else {
			// Fall back to English
			$baseURL = 'http://www.shoutwiki.com/w/api.php';
		}
		$url = wfAppendQuery( $baseURL, $params );

		$req = MWHttpRequest::factory( $url );
		$req->execute();
		$json = $req->getContent();
		$decoded = FormatJson::decode( $json, true );
		return $decoded;
	}

	/**
	 * Get the cache key for a certain title
	 *
	 * @param Title|string $title
	 * @return string
	 */
	public static function getCacheKey( $title ) {
		global $wgLanguageCode;
		return wfMemcKey( 'helppages', $wgLanguageCode, md5( $title ), 'v2' );
	}

	/**
	 * Use action=parse to get rendered HTML of a page
	 *
	 * @param $title string
	 * @param $langCode string
	 * @return array
	 */
	public static function parseWikiText( $title, $langCode ) {
		$params = array(
			'action' => 'parse',
			'page' => $title,
			'redirects' => true // follow redirects
		);
		$data = self::makeAPIRequest( $params, $langCode );
		$parsed = $data['parse']['text']['*'];
		$oldid = $data['parse']['revid'];

		// Eliminate section edit links
		// As legoktm pointed out, this is done in CSS, which works and most
		// likely is a lot faster, but I'll keep this around anyway for future
		// reference.
		/*
		$parsed = preg_replace(
			"/<span class=\"mw-editsection\"><span class=\"mw-editsection-bracket\">(.*?)<\/span><a .*?>(.*?)<\/a>\<span class=\"mw-editsection-bracket\">(.*?)<\/span><\/span>/",
			'',
			$parsed
		);
		*/

		// HACK TIME! The parsed wikitext acts as if it was parsed on the remote
		// wiki -- this is good for things like images and whatnot, but very
		// bad for things like the project namespace name and whatnot.
		// So, we need to get the remote wiki's project (&project talk) NS names;
		// either from memcached, or failing that, via an API query.
		global $wgContLang, $wgLanguageCode, $wgMemc;

		$projectNSCacheKey = wfMemcKey( 'helppages', $wgLanguageCode, 'projectns' );
		$projectTalkNSCacheKey = wfMemcKey( 'helppages', $wgLanguageCode, 'projecttalkns' );

		$remoteWikiProjectNS = $wgMemc->get( $projectNSCacheKey );
		$remoteWikiProjectTalkNS = $wgMemc->get( $projectTalkNSCacheKey );

		if (
			$remoteWikiProjectNS === false ||
			$remoteWikiProjectTalkNS === false
		)
		{
			// Damn, no cache hit, so we need to hit the API instead.
			// Yes, I realize that's a terrible pun.
			$nsQueryParams = array(
				'action' => 'query',
				'meta' => 'siteinfo',
				'siprop' => 'namespaces|namespacealiases'
			);
			$namespaceData = self::makeAPIRequest( $nsQueryParams, $langCode );

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
			$wgMemc->set( $projectNSCacheKey, $remoteWikiProjectNS, 7 * 86400 );
			$wgMemc->set( $projectTalkNSCacheKey, $remoteWikiProjectTalkNS, 7 * 86400 );
		}

		$parsed = str_replace(
			array(
				$remoteWikiProjectNS . ':',
				$remoteWikiProjectTalkNS . ':',
			),
			array(
				$wgContLang->getNsText( NS_PROJECT ) . ':',
				$wgContLang->getNsText( NS_PROJECT_TALK ) . ':',
			),
			$parsed
		);

		return array( $parsed, $oldid );
	}

	/**
	 * Get the page text in the content language or a fallback
	 *
	 * @param $title string page name
	 * @return string|bool false if couldn't be found
	 */
	public static function getPagePlusFallbacks( $title ) {
		global $wgContLang, $wgLanguageCode, $wgMemc, $wgSharedHelpPagesExpiry;

		$title = str_replace( 'Help:', $wgContLang->getNsText( NS_HELP ) . ':', $title );

		$key = self::getCacheKey( $title );
		$cached = $wgMemc->get( $key );

		if ( $cached !== false ) {
			return $cached;
		}

		$titles = array();

		$titles[$title] = $wgLanguageCode;

		$params = array(
			'action' => 'query',
			'titles' => implode( '|', array_keys( $titles ) )
		);
		$data = self::makeAPIRequest( $params, $wgLanguageCode );
		$pages = array();

		foreach ( $data['query']['pages'] as /* $id => */ $info ) {
			if ( isset( $info['missing'] ) ) {
				continue;
			}
			$lang = $titles[$info['title']];
			$pages[$lang] = $info['title'];
		}

		if ( isset( $pages[$wgLanguageCode] ) ) {
			$html = self::parseWikiText( $pages[$wgLanguageCode], $wgLanguageCode );
			$wgMemc->set( $key, $html, $wgSharedHelpPagesExpiry );
			return $html;
		}


		return false;
	}

	/**
	 * Determine the proper help wiki database, based on current wiki's
	 * language code.
	 *
	 * By default this is assumed to follow the languagecode_wiki format.
	 * Exceptions to this rule are:
	 * 1) English and all of its variants, which fall back to the shoutwiki DB
	 * 2) Language is not in the $wgSharedHelpLanguages array --> shoutwiki DB
	 *
	 * @return Mixed: database name (string) normally, boolean true on the help
	 *                wiki
	 */
	public static function determineDatabase() {
		global $wgLanguageCode, $wgSharedHelpLanguages;

		if ( in_array( $wgLanguageCode, $wgSharedHelpLanguages ) && $wgLanguageCode !== 'en' ) {
			$helpDBname = "{$wgLanguageCode}_wiki";
		} elseif ( in_array( $wgLanguageCode, array( 'en', 'en-gb', 'en-ca' ) ) ) {
			$helpDBname = 'shoutwiki';
		} else {
			// fall back to English help
			$helpDBname = 'shoutwiki';
		}

		return $helpDBname;
	}

	/**
	 * Is SharedHelpPages available for the current wiki's language (code)?
	 *
	 * @param $langCode String: language code
	 * @return Boolean: true if it's available, otherwise false
	 */
	public static function isSupportedLanguage() {
		global $wgLanguageCode, $wgSharedHelpLanguages;

		$isEnglish = in_array( $wgLanguageCode, array( 'en', 'en-gb', 'en-ca' ) );

		if ( in_array( $wgLanguageCode, $wgSharedHelpLanguages ) || $isEnglish ) {
			return true;
		} else {
			return false;
		}
	}
}
