<?php

class SharedHelpPagesHooks {

	/**
	 * @param Title $title
	 * @param Article|null $page
	 * @param IContextSource $context
	 * @return bool
	 */
	public static function onArticleFromTitle( Title &$title, &$page, $context ) {
		// If another extension's hook has already run, don't override it
		if ( $page === null
			&& $title->inNamespace( NS_HELP ) && !$title->exists()
			&& SharedHelpPage::shouldDisplaySharedPage( $title )
		) {
			$page = new SharedHelpPage(
				$title,
				ConfigFactory::getDefaultInstance()->makeConfig( 'sharedhelppages' )
			);
		}

		return true;
	}

	/**
	 * Mark shared help pages as known so they appear in blue
	 *
	 * @param Title $title Title to check
	 * @param bool &$isKnown Whether the page should be considered known
	 * @return bool
	 */
	public static function onTitleIsAlwaysKnown( $title, &$isKnown ) {
		if ( SharedHelpPage::shouldDisplaySharedPage( $title ) ) {
			$isKnown = true;
		}

		return true;
	}

	/**
	 * Whether a page is a shared help page on the Hub wiki
	 *
	 * @param Title $title
	 * @return bool
	 */
	protected static function isSharedHelpPage( Title $title ) {
		return self::determineDatabase() === WikiMap::getCurrentWikiId() // On the Hub wiki
			&& $title->inNamespace( NS_HELP ); // is a help page.
	}

	/**
	 * After a LinksUpdate runs for a help page, queue remote Squid purges
	 *
	 * @param LinksUpdate $lu
	 * @return bool
	 */
	public static function onLinksUpdateComplete( LinksUpdate &$lu ) {
		$title = $lu->getTitle();
		if ( self::isSharedHelpPage( $title ) ) {
			$inv = new SharedHelpPageCacheInvalidator( $title->getText() );
			$inv->invalidate();
		}

		return true;
	}

	/**
	 * Invalidate cache on remote wikis when a new page is created
	 * Also handles the ArticleDeleteComplete hook
	 *
	 * @param WikiPage $page
	 * @return bool
	 */
	public static function onPageSaveComplete( WikiPage $page ) {
		$title = $page->getTitle();
		if ( self::isSharedHelpPage( $title ) ) {
			$inv = new SharedHelpPageCacheInvalidator( $title->getText(), [ 'links' ] );
			$inv->invalidate();
		}

		return true;
	}

	/**
	 * Invalidate cache on remote wikis when a shared help page is deleted
	 *
	 * @param WikiPage $page
	 * @return bool
	 */
	public static function onArticleDeleteComplete( WikiPage $page ) {
		return self::onPageSaveComplete( $page );
	}

	/**
	 * Makes the red content action link (you know, the one with
	 * title="View the help page" and accesskey c) blue, i.e. as if the page
	 * existed.
	 *
	 * This function originally changed the "edit" links to point to a different
	 * wiki for pages in the Help: namespace.
	 *
	 * @param SkinTemplate $skTemplate
	 * @param array $links
	 * @return bool
	 */
	public static function onSkinTemplateNavigationUniversal( &$skTemplate, &$links ) {
		$title = $skTemplate->getTitle();

		if ( $title->inNamespace( NS_HELP ) ) {
			// Don't run this code on the source wiki of the shared help pages.
			// Also don't run this code if SharedHelpPages isn't enabled for the
			// current wiki's language.
			if ( self::determineDatabase() === WikiMap::getCurrentWikiId() || !self::isSupportedLanguage() ) {
				return true;
			}

			if ( SharedHelpPage::shouldDisplaySharedPage( $title ) ) {
				// This removes the additional "View on www.shoutwiki.com" tab/
				// link from the content actions array on Help: pages
				unset( $links['views']['view-foreign'] );
				// And this changes the edit tab's(/link's) text back to "Create"
				// from "Add local description" (which is just plain wtf as that
				// string literally makes no sense for any other context than
				// foreign _file_ pages)
				$links['views']['edit']['text'] = $skTemplate->msg( 'create' )->text();

				$links['namespaces']['help']['class'] = 'selected';
				$links['namespaces']['help']['href'] = $title->getFullURL();
			}
		}

		return true;
	}

	/**
	 * @param Title $title
	 * @param $page
	 * @return bool
	 */
	public static function onWikiPageFactory( Title $title, &$page ) {
		if ( SharedHelpPage::shouldDisplaySharedPage( $title ) ) {
			$page = new SharedHelpPagePage(
				$title,
				ConfigFactory::getDefaultInstance()->makeConfig( 'sharedhelppages' )
			);
			return false;
		}

		return true;
	}

	/**
	 * Basically modify the WantedPages query to exclude pages that appear on
	 * the central wiki.
	 *
	 * Hooked into the WantedPages::getQueryInfo hook.
	 *
	 * @param WantedPagesPage $wantedPagesPage
	 * @param array $array SQL query conditions
	 * @return bool
	 */
	public static function modifyWantedPagesSQL( $wantedPagesPage, $query ) {
		global $wgDBname;

		$sharedHelpDBname = self::determineDatabase();

		// Don't run this code on the source wiki of the shared help pages.
		// Also don't run this code if SharedHelpPages isn't enabled for the
		// current wiki's language.
		if ( $wgDBname == $sharedHelpDBname || !self::isSupportedLanguage() ) {
			return true;
		}

		$helpPage = "`$sharedHelpDBname`.`page`";
		$query['tables']['pg3'] = $helpPage;
		$query['conds'][] = '(pg3.page_namespace != 12 OR pg3.page_id IS NULL)';
		$query['join_conds']['pg3'] = [
			'LEFT JOIN',
			[
				'pl_namespace = pg3.page_namespace',
				'pl_title = pg3.page_title'
			]
		];

		return true;
	}

	/**
	 * Shows a warning-ish message on &action=edit whenever a user tries to
	 * edit a shared help page
	 *
	 * @param EditPage $editPage
	 * @return bool
	 */
	public static function displayMessageOnEditPage( &$editPage ) {
		global $wgDBname;

		$title = $editPage->getTitle();

		// do not show this message on the help wiki
		// Also don't run this code if SharedHelpPages isn't enabled for the
		// current wiki's language.
		if ( $wgDBname == self::determineDatabase() || !self::isSupportedLanguage() ) {
			return true;
		}

		// show message only when editing pages from Help namespace
		if ( !$title->inNamespace( NS_HELP ) ) {
			return true;
		}

		if ( SharedHelpPage::shouldDisplaySharedPage( $title ) ) {
			// Add a notice indicating that the content was originally taken from ShoutWiki Hub
			$msg = '<div style="border: solid 1px; padding: 10px; margin: 5px" class="sharedHelpEditInfo">';
			$msg .= wfMessage( 'sharedhelppages-notice', $title->getPrefixedText() )->parse();
			$msg .= '</div>';

			$editPage->editFormPageTop .= $msg;
		}

		return true;
	}

	/**
	 * Unconditionally display the copyright stuff -- or rather, allow skins to
	 * do that -- on Help: pages.
	 *
	 * This enables the display of "Content is available under <license>" message
	 * in the page footer instead of only the copyright icon being displayed.
	 *
	 * @param OutputPage $out
	 * @return bool
	 */
	public static function onBeforePageDisplay( OutputPage $out ) {
		global $wgDBname;

		$sharedHelpDBname = self::determineDatabase();

		// Don't run this code on the source wiki of the shared help pages.
		// Also don't run this code if SharedHelpPages isn't enabled for the
		// current wiki's language.
		if ( $wgDBname == $sharedHelpDBname || !self::isSupportedLanguage() ) {
			return true;
		}

		if ( $out->getTitle()->inNamespace( NS_HELP ) ) {
			$out->setCopyright( true );
		}

		return true;
	}

	// UTILITY METHODS WHICH ARE NOT HOOKED FUNCTIONS THEMSELVES //

	/**
	 * Determine the proper help wiki database, based on current wiki's
	 * language code.
	 *
	 * By default this is assumed to follow the languagecode_wiki format.
	 * Exceptions to this rule are:
	 * 1) English and all of its variants, which fall back to the shoutwiki DB
	 * 2) Language is not in the $wgSharedHelpLanguages array --> shoutwiki DB
	 *
	 * @return string|bool Database name (string) normally, boolean true on the help
	 *                wiki
	 */
	public static function determineDatabase() {
		global $wgLanguageCode, $wgSharedHelpLanguages;

		if ( in_array( $wgLanguageCode, $wgSharedHelpLanguages ) && $wgLanguageCode !== 'en' ) {
			$helpDBname = "{$wgLanguageCode}_wiki";
		} elseif ( in_array( $wgLanguageCode, [ 'en', 'en-gb', 'en-ca' ] ) ) {
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
	 * @param string $langCode ISO 639 language code
	 * @return bool True if it's available, otherwise false
	 */
	public static function isSupportedLanguage() {
		global $wgLanguageCode, $wgSharedHelpLanguages;

		$isEnglish = in_array( $wgLanguageCode, [ 'en', 'en-gb', 'en-ca' ] );

		return ( in_array( $wgLanguageCode, $wgSharedHelpLanguages ) || $isEnglish );
	}
}
