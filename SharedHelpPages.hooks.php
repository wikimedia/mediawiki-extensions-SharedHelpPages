<?php

class SharedHelpPagesHooks {
	/**
	 * @param $article Article
	 * @return bool
	 */
	public static function onShowMissingArticle( $article ) {
		$context = $article->getContext();
		$output = $context->getOutput();
		$title = $article->getTitle();

		if ( $title->getNamespace() == NS_HELP ) {
			global $wgDBname;

			$sharedHelpDBname = SharedHelpPages::determineDatabase();

			// Don't run this code on the source wiki of the shared help pages.
			// Also don't run this code if SharedHelpPages isn't enabled for the
			// current wiki's language (for performance reasons).
			if ( $wgDBname == $sharedHelpDBname || !SharedHelpPages::isSupportedLanguage() ) {
				return true;
			}

			list( $text, $oldid ) = SharedHelpPages::getPagePlusFallbacks( 'Help:' . $title->getText() );
			if ( $text ) {
				// Add a notice indicating that it was taken from ShoutWiki Hub
				// noticed moved to the EditPage hook --ashley, 24 December 2013
				//$output->addHTML( $context->msg( 'sharedhelppages-notice', $oldid )->parse() );
				$output->addHTML( $text );
				// Hide the "this page does not exist" notice and edit section links
				$output->addModuleStyles( 'ext.SharedHelpPages' );
			}
		}

		return true;
	}

	/**
	 * Makes the red content action link (you know, the one with
	 * title="View the help page" and accesskey c) blue, i.e. as if the page
	 * existed.
	 *
	 * This function originally changed the "edit" links to point to a different
	 * wiki for pages in the Help: namespace.
	 *
	 * @param SkinTemplate $sktemplate
	 * @param array $links
	 * @return bool
	 */
	public static function onSkinTemplateNavigationUniversal( &$sktemplate, &$links ) {
		$context = $sktemplate->getContext();
		$title = $sktemplate->getTitle();

		if ( $title->getNamespace() == NS_HELP ) {
			global $wgDBname;

			$sharedHelpDBname = SharedHelpPages::determineDatabase();

			// Don't run this code on the source wiki of the shared help pages.
			// Also don't run this code if SharedHelpPages isn't enabled for the
			// current wiki's language.
			if ( $wgDBname == $sharedHelpDBname || !SharedHelpPages::isSupportedLanguage() ) {
				return true;
			}

			list( $text, $oldid ) = SharedHelpPages::getPagePlusFallbacks( 'Help:' . $title->getText() );
			if ( $text ) {
				/*
				global $wgLanguageCode, $wgSharedHelpLanguages;

				// Determine the correct subdomain
				if (
					in_array( $wgLanguageCode, $wgSharedHelpLanguages ) &&
					!in_array( $wgLanguageCode, array( 'en', 'en-gb', 'en-ca' ) )
				)
				{
					$langCode = $wgLanguageCode;
				} else {
					$langCode = 'www';
				}
				*/

				$links['namespaces']['help']['class'] = 'selected';
				$links['namespaces']['help']['href'] = $title->getFullURL();
				/*
				$links['namespaces']['help_talk']['class'] = '';
				$links['namespaces']['help_talk']['href'] = "http://{$langCode}.shoutwiki.com/wiki/Help_talk:" . $title->getText();
				$links['views'] = array(); // Kill the 'Create' button @todo make this suck less
				$links['views'][] = array(
					'class' => false,
					'text' => $context->msg( 'sharedhelppages-edit-tab' ),
					'href' => wfAppendQuery(
						"http://{$langCode}.shoutwiki.com/w/index.php",
						array(
							'action' => 'edit',
							'title' => $title->getPrefixedText()
						)
					)
				);
				*/
			}
		}

		return true;
	}

	/**
	 * Use action=purge to clear cache
	 *
	 * @param $article Article
	 * @return bool
	 */
	public static function onArticlePurge( &$article ) {
		global $wgMemc;

		$title = $article->getContext()->getTitle();
		$key = SharedHelpPages::getCacheKey( $title );
		$wgMemc->delete( $key );

		return true;
	}

	/**
	 * Turn red Help: links into blue ones
	 *
	 * @param $linker
	 * @param $target Title
	 * @param $text String
	 * @param $customAtrribs Array: array of custom attributes [unused]
	 * @param $query [unused]
	 * @param $ret String: return value (link HTML)
	 * @return Boolean
	 */
	public static function brokenLink( $linker, $target, &$text, &$customAttribs, &$query, &$options, &$ret ) {
		global $wgDBname;

		$sharedHelpDBname = SharedHelpPages::determineDatabase();

		// Don't run this code on the source wiki of the shared help pages.
		// Also don't run this code if SharedHelpPages isn't enabled for the
		// current wiki's language.
		if ( $wgDBname == $sharedHelpDBname || !SharedHelpPages::isSupportedLanguage() ) {
			return true;
		}

		if ( $target->getNamespace() == NS_HELP ) {
			// return immediately if we know it's real
			// this part "borrowed" from ^demon's RemoveRedlinks, dunno if
			// we really need it anymore, but idk
			if ( in_array( 'known', $options ) || $target->isKnown() ) {
				return true;
			} else {
				$ret = Linker::linkKnown( $target, $text );
				return false;
			}
		}

		return true;
	}

	/**
	 * Shows a warning-ish message on &action=edit whenever a user tries to
	 * edit a shared help page
	 *
	 * @param $editPage EditPage
	 * @return Boolean: true
	 */
	public static function displayMessageOnEditPage( &$editPage ) {
		global $wgDBname;

		$title = $editPage->getTitle();

		// do not show this message on the help wiki
		// Also don't run this code if SharedHelpPages isn't enabled for the
		// current wiki's language.
		if ( $wgDBname == SharedHelpPages::determineDatabase() || !SharedHelpPages::isSupportedLanguage() ) {
			return true;
		}

		// show message only when editing pages from Help namespace
		if ( $title->getNamespace() != 12 ) {
			return true;
		}

		list( $text, $oldid ) = SharedHelpPages::getPagePlusFallbacks( 'Help:' . $title->getText() );
		if ( $text ) {
			// Add a notice indicating that the content was originally taken from ShoutWiki Hub
			$msg = '<div style="border: solid 1px; padding: 10px; margin: 5px" class="sharedHelpEditInfo">';
			$msg .= wfMessage( 'sharedhelppages-notice', $oldid )->parse();
			$msg .= '</div>';

			$editPage->editFormPageTop .= $msg;
		}

		return true;
	}

	/**
	 * Basically modify the WantedPages query to exclude pages that appear on
	 * the central wiki.
	 *
	 * Hooked into the WantedPages::getQueryInfo hook.
	 *
	 * @param $wantedPagesPage WantedPagesPage
	 * @param $array Array: SQL query conditions
	 * @return Boolean: true
	 */
	public static function modifyWantedPagesSQL( $wantedPagesPage, $query ) {
		global $wgDBname;

		$sharedHelpDBname = SharedHelpPages::determineDatabase();

		// Don't run this code on the source wiki of the shared help pages.
		// Also don't run this code if SharedHelpPages isn't enabled for the
		// current wiki's language.
		if ( $wgDBname == $sharedHelpDBname || !SharedHelpPages::isSupportedLanguage() ) {
			return true;
		}

		$helpPage = "`$sharedHelpDBname`.`page`";
		$query['tables']['pg3'] = $helpPage;
		$query['conds'][] = '(pg3.page_namespace != 12 OR pg3.page_id IS NULL)';
		$query['join_conds']['pg3'] = array(
			'LEFT JOIN',
			array(
				'pl_namespace = pg3.page_namespace',
				'pl_title = pg3.page_title'
			)
		);
		return true;
	}

	/**
	 * Unconditionally display the copyright stuff -- or rather, allow skins to
	 * do that -- on Help: pages.
	 *
	 * This enables the display of "Content is available under <license>" message
	 * in the page footer instead of only the copyright icon being displayed.
	 *
	 * @param $skTpl SkinTemplate
	 * @param $tpl A subclass of SkinTemplate, i.e. for Monobook it'd be MonobookTemplate
	 * @return Boolean
	 */
	public static function onSkinTemplateOutputPageBeforeExec( &$skTpl, &$tpl ) {
		global $wgDBname;

		$sharedHelpDBname = SharedHelpPages::determineDatabase();

		// Don't run this code on the source wiki of the shared help pages.
		// Also don't run this code if SharedHelpPages isn't enabled for the
		// current wiki's language.
		if ( $wgDBname == $sharedHelpDBname || !SharedHelpPages::isSupportedLanguage() ) {
			return true;
		}

		$title = $skTpl->getTitle();
		if ( $title->getNamespace() == NS_HELP ) {
			$tpl->set( 'copyright', $skTpl->getCopyright() );
		}

		return true;
	}
}
