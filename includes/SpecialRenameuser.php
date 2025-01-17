<?php

namespace MediaWiki\Extension\Renameuser;

use Exception;
use Html;
use Language;
use LogEventsList;
use LogPage;
use MediaWiki\HookContainer\HookContainer;
use MediaWiki\Page\MovePageFactory;
use MediaWiki\Permissions\PermissionManager;
use MediaWiki\User\UserFactory;
use MediaWiki\User\UserNamePrefixSearch;
use MediaWiki\User\UserNameUtils;
use OutputPage;
use SpecialPage;
use Title;
use TitleFactory;
use UserBlockedError;
use Xml;

/**
 * Special page that allows authorised users to rename
 * user accounts
 */
class SpecialRenameuser extends SpecialPage {
	/** @var RenameuserHookRunner */
	private $hookRunner;

	/** @var Language */
	private $contentLanguage;

	/** @var MovePageFactory */
	private $movePageFactory;

	/** @var PermissionManager */
	private $permissionManager;

	/** @var TitleFactory */
	private $titleFactory;

	/** @var UserFactory */
	private $userFactory;

	/** @var UserNameUtils */
	private $userNameUtils;

	/** @var UserNamePrefixSearch */
	private $userNamePrefixSearch;

	public function __construct(
		HookContainer $hookContainer,
		Language $contentLanguage,
		MovePageFactory $movePageFactory,
		PermissionManager $permissionManager,
		TitleFactory $titleFactory,
		UserFactory $userFactory,
		UserNamePrefixSearch $userNamePrefixSearch,
		UserNameUtils $userNameUtils
	) {
		parent::__construct( 'Renameuser', 'renameuser' );

		$this->hookRunner = new RenameuserHookRunner( $hookContainer );
		$this->contentLanguage = $contentLanguage;
		$this->movePageFactory = $movePageFactory;
		$this->permissionManager = $permissionManager;
		$this->titleFactory = $titleFactory;
		$this->userFactory = $userFactory;
		$this->userNamePrefixSearch = $userNamePrefixSearch;
		$this->userNameUtils = $userNameUtils;
	}

	public function doesWrites() {
		return true;
	}

	/**
	 * Show the special page
	 *
	 * @param null|string $par Parameter passed to the page
	 */
	public function execute( $par ) {
		global $wgLocalDatabases;

		$this->setHeaders();
		$this->addHelpLink( 'Help:Renameuser' );

		$this->checkPermissions();
		$this->checkReadOnly();

		$user = $this->getUser();

		$block = $user->getBlock();
		if ( $block ) {
			throw new UserBlockedError( $block );
		}

		if ( empty( $wgLocalDatabases ) ) {
			throw new ErrorPageError( 'renameuser', 'renameuser-error-nolocaldatabases' );
			return;
		}

		$out = $this->getOutput();
		$out->addWikiMsg( 'renameuser-summary' );

		$this->useTransactionalTimeLimit();

		$request = $this->getRequest();

		// this works as "/" is not valid in usernames
		$usernames = $par !== null ? explode( '/', $par, 2 ) : [];
		$oldnamePar = trim( str_replace( '_', ' ', $request->getText( 'oldusername', $usernames[0] ?? '' ) ) );
		$oldusername = $this->titleFactory->makeTitle( NS_USER, $oldnamePar );
		$newnamePar = $usernames[1] ?? '';
		$newnamePar = trim( str_replace( '_', ' ', $request->getText( 'newusername', $newnamePar ) ) );
		// Force uppercase of newusername, otherwise wikis
		// with wgCapitalLinks=false can create lc usernames
		$newusername = $this->titleFactory->makeTitleSafe( NS_USER, $this->contentLanguage->ucfirst( $newnamePar ) );
		$oun = $oldusername->getText();
		$nun = is_object( $newusername ) ? $newusername->getText() : '';
		$token = $user->getEditToken();
		$reason = $request->getText( 'reason' );

		$move_checked = $request->getBool( 'movepages', !$request->wasPosted() );
		$suppress_checked = $request->getCheck( 'suppressredirect' );

		$warnings = [];
		if ( $oun && $nun && !$request->getCheck( 'confirmaction' ) ) {
			$oldU = $this->userFactory->newFromName( $oldusername->getText(), $this->userFactory::RIGOR_NONE );
			if ( $oldU && $oldU->getBlock() ) {
				$warnings[] = [
					'renameuser-warning-currentblock',
					SpecialPage::getTitleFor( 'Log', 'block' )->getFullURL( [ 'page' => $oun ] )
				];
			}
			$this->hookRunner->onRenameUserWarning( $oun, $nun, $warnings );
		}

		$out->addHTML(
			Xml::openElement( 'form', [
				'method' => 'post',
				'action' => $this->getPageTitle()->getLocalURL(),
				'id' => 'renameuser'
			] ) .
			Xml::openElement( 'fieldset' ) .
			Xml::element( 'legend', null, $this->msg( 'renameuser' )->text() ) .
			Xml::openElement( 'table', [ 'id' => 'mw-renameuser-table' ] ) .
			"<tr>
				<td class='mw-label'>" .
			Xml::label( $this->msg( 'renameuserold' )->text(), 'oldusername' ) .
			"</td>
				<td class='mw-input'>" .
			Xml::input( 'oldusername', 20, $oun, [ 'type' => 'text', 'tabindex' => '1' ] ) . ' ' .
			"</td>
			</tr>
			<tr>
				<td class='mw-label'>" .
			Xml::label( $this->msg( 'renameusernew' )->text(), 'newusername' ) .
			"</td>
				<td class='mw-input'>" .
			Xml::input( 'newusername', 20, $nun, [ 'type' => 'text', 'tabindex' => '2' ] ) .
			"</td>
			</tr>
			<tr>
				<td class='mw-label'>" .
			Xml::label( $this->msg( 'renameuserreason' )->text(), 'reason' ) .
			"</td>
				<td class='mw-input'>" .
			Xml::input(
				'reason',
				40,
				$reason,
				[ 'type' => 'text', 'tabindex' => '3', 'maxlength' => 255 ]
			) .
			'</td>
			</tr>'
		);
		if ( $this->permissionManager->userHasRight( $user, 'move' ) ) {
			$out->addHTML( "
				<tr>
					<td>&#160;
					</td>
					<td class='mw-input'>" .
				Xml::checkLabel( $this->msg( 'renameusermove' )->text(), 'movepages', 'movepages',
					$move_checked, [ 'tabindex' => '4' ] ) .
				'</td>
				</tr>'
			);

			if ( $this->permissionManager->userHasRight( $user, 'suppressredirect' ) ) {
				$out->addHTML( "
					<tr>
						<td>&#160;
						</td>
						<td class='mw-input'>" .
					Xml::checkLabel(
						$this->msg( 'renameusersuppress' )->text(),
						'suppressredirect',
						'suppressredirect',
						$suppress_checked,
						[ 'tabindex' => '5' ]
					) .
					'</td>
					</tr>'
				);
			}
		}
		if ( $warnings ) {
			$warningsHtml = [];
			foreach ( $warnings as $warning ) {
				$warningsHtml[] = is_array( $warning ) ?
					$this->msg( $warning[0] )->params( array_slice( $warning, 1 ) )->parse() :
					$this->msg( $warning )->parse();
			}

			$out->addHTML( "
				<tr>
					<td class='mw-label'>" . $this->msg( 'renameuserwarnings' )->escaped() . "
					</td>
					<td class='mw-input'>" .
				'<ul class="error"><li>' .
				implode( '</li><li>', $warningsHtml ) . '</li></ul>' .
				'</td>
				</tr>'
			);
			$out->addHTML( "
				<tr>
					<td>&#160;
					</td>
					<td class='mw-input'>" .
				Xml::checkLabel(
					$this->msg( 'renameuserconfirm' )->text(),
					'confirmaction',
					'confirmaction',
					false,
					[ 'tabindex' => '6' ]
				) .
				'</td>
				</tr>'
			);
		}
		$out->addHTML( "
			<tr>
				<td>&#160;
				</td>
				<td class='mw-submit'>" .
			Xml::submitButton(
				$this->msg( 'renameusersubmit' )->text(),
				[
					'name' => 'submit',
					'tabindex' => '7',
					'id' => 'submit'
				]
			) .
			' ' .
			'</td>
			</tr>' .
			Xml::closeElement( 'table' ) .
			Xml::closeElement( 'fieldset' ) .
			Html::hidden( 'token', $token ) .
			Xml::closeElement( 'form' ) . "\n"
		);

		if ( $request->getText( 'token' ) === '' ) {
			# They probably haven't even submitted the form, so don't go further.
			return;
		} elseif ( $warnings ) {
			# Let user read warnings
			return;
		} elseif ( !$request->wasPosted() || !$user->matchEditToken( $request->getVal( 'token' ) ) ) {
			$out->addHTML( Html::errorBox( $out->msg( 'renameuser-error-request' )->parse() ) );

			return;
		} elseif ( !is_object( $newusername ) ) {
			$out->addHTML( Html::errorBox(
				$out->msg( 'renameusererrorinvalid' )->params( $request->getText( 'newusername' ) )->parse()
			) );

			return;
		} elseif ( $oldusername->getText() === $newusername->getText() ) {
			$out->addHTML( Html::errorBox( $out->msg( 'renameuser-error-same-user' )->parse() ) );

			return;
		}

		// Suppress username validation of old username
		$olduser = $this->userFactory->newFromName( $oldusername->getText(), $this->userFactory::RIGOR_NONE );
		$newuser = $this->userFactory->newFromName( $newusername->getText(), $this->userFactory::RIGOR_CREATABLE );

		// It won't be an object if for instance "|" is supplied as a value
		if ( !is_object( $olduser ) ) {
			$out->addHTML( Html::errorBox(
				$out->msg( 'renameusererrorinvalid' )->params( $oldusername->getText() )->parse()
			) );

			return;
		}
		if ( !is_object( $newuser ) || !$this->userNameUtils->isCreatable( $newuser->getName() ) ) {
			$out->addHTML( Html::errorBox(
				$out->msg( 'renameusererrorinvalid' )->params( $newusername->getText() )->parse()
			) );

			return;
		}

		// Check for the existence of lowercase oldusername in database.
		// Until r19631 it was possible to rename a user to a name with first character as lowercase
		if ( $oldusername->getText() !== $this->contentLanguage->ucfirst( $oldusername->getText() ) ) {
			// oldusername was entered as lowercase -> check for existence in table 'user'
			$dbr = wfGetDB( DB_REPLICA );
			$uid = $dbr->selectField( 'user', 'user_id',
				[ 'user_name' => $oldusername->getText() ],
				__METHOD__ );
			if ( $uid === false ) {
				if ( !$this->getConfig()->get( 'CapitalLinks' ) ) {
					$uid = 0; // We are on a lowercase wiki but lowercase username does not exists
				} else {
					// We are on a standard uppercase wiki, use normal
					$uid = $olduser->idForName();
					$oldusername = $this->titleFactory->makeTitleSafe( NS_USER, $olduser->getName() );
				}
			}
		} else {
			// oldusername was entered as upperase -> standard procedure
			$uid = $olduser->idForName();
		}

		if ( $uid === 0 ) {
			$out->addHTML( Html::errorBox(
				$out->msg( 'renameusererrordoesnotexist' )->params( $oldusername->getText() )->parse()
			) );

			return;
		}

		if ( $newuser->idForName() !== 0 ) {
			$out->addHTML( Html::errorBox(
				$out->msg( 'renameusererrorexists' )->params( $newusername->getText() )->parse()
			) );

			return;
		}

		// Give other affected extensions a chance to validate or abort
		if ( !$this->hookRunner->onRenameUserAbort( $uid, $oldusername->getText(), $newusername->getText() ) ) {
			return;
		}

		// Do the heavy lifting...
		$rename = new RenameuserSQL(
			$oldusername->getText(),
			$newusername->getText(),
			$uid,
			$user,
			[
				'reason' => $reason,
				'movepages' => $request->getCheck( 'movepages' ) && $this->permissionManager->userHasRight( $user, 'move' ),
				'suppressredirects' => $request->getCheck( 'suppressredirect' ) && $this->permissionManager->userHasRight( $user, 'suppressredirect' )
			]
		);
		if ( !$rename->rename() ) {
			return;
		}

		// If this user is renaming his/herself, make sure that MovePage::move()
		// doesn't make a bunch of null move edits under the old name!
		// if ( $user->getId() === $uid ) {
		// 	$user->setName( $newusername->getText() );
		// }

		// Output success message stuff :)
		$out->addHTML(
			Html::successBox(
				$out->msg( 'renameusersuccess' )
					->params( $oldusername->getText(), $newusername->getText() )
					->parse()
			)
		);
	}

	/**
	 * @param Title $username
	 * @param string $type
	 * @param OutputPage &$out
	 */
	protected function showLogExtract( $username, $type, &$out ) {
		# Show relevant lines from the logs:
		$logPage = new LogPage( $type );
		$out->addHTML( Xml::element( 'h2', null, $logPage->getName()->text() ) . "\n" );
		LogEventsList::showLogExtract( $out, $type, $username->getPrefixedText() );
	}

	/**
	 * Return an array of subpages beginning with $search that this special page will accept.
	 *
	 * @param string $search Prefix to search for
	 * @param int $limit Maximum number of results to return (usually 10)
	 * @param int $offset Number of results to skip (usually 0)
	 * @return string[] Matching subpages
	 */
	public function prefixSearchSubpages( $search, $limit, $offset ) {
		$user = $this->userFactory->newFromName( $search );
		if ( !$user ) {
			// No prefix suggestion for invalid user
			return [];
		}
		// Autocomplete subpage as user list - public to allow caching
		return $this->userNamePrefixSearch->search( 'public', $search, $limit, $offset );
	}

	protected function getGroupName() {
		return 'users';
	}
}
