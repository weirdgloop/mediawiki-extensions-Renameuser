<?php

use MediaWiki\MediaWikiServices;

/**
 * Loosely based on https://github.com/miraheze/RemovePII/
 */
class RenameUserEachWikiJob extends Job implements GenericParameterJob {
	/** @var string */
	private $oldName;

	/** @var string */
	private $newName;

	/** @var int */
	private $uid;

    /** @var bool */
	private $movePages;

    /** @var bool */
	private $suppressRedirects;

	/**
	 * @param array $params
	 */
	public function __construct( array $params ) {
		parent::__construct( 'RenameUserEachWikiJob', $params );

		$this->oldName = $params['oldname'];
		$this->newName = $params['newname'];
        $this->uid = $params['uid'];
        $this->movePages = $params['movepages'];
        $this->suppressRedirects = $params['suppressredirects'];
	}

	/**
	 * @return bool
	 */
	public function run() {
		$userFactory = MediaWikiServices::getInstance()->getUserFactory();
        $titleFactory = MediaWikiServices::getInstance()->getTitleFactory();
        $movePageFactory = MediaWikiServices::getInstance()->getMovePageFactory();
		$lbFactory = MediaWikiServices::getInstance()->getDBLoadBalancerFactory();

        $oldName = $userFactory->newFromName( $this->oldName );
		$newName = $userFactory->newFromName( $this->newName );
        $oldTitle = Title::makeTitle( NS_USER, $this->oldName );
		$newTitle = Title::makeTitle( NS_USER, $this->newName );

        if ( !$newName ) {
			$this->setLastError( "User {$userNewName} is not a valid name" );

			return false;
		}

		if ( !$this->uid ) {
			$this->setLastError( "User {$userNewName} ID equal to 0" );

			return false;
		}

        $dbw = $lbFactory->getMainLB()->getConnection( DB_PRIMARY );

        // Exclude user renames per T200731
		$logTypesOnUser = array_diff( SpecialLog::getLogTypesOnUser(), [ 'renameuser' ] );

        $tableUpdates = [
            // Core
            'ipblocks' => [
                [
                    'fields' => [
                        'ipb_address' => $this->newName
                    ],
                    'where' => [
                        'ipb_user' => $this->uid,
                        'ipb_address' => $this->oldName
                    ]
                ]
            ],
            'logging' => [
                [
                    'fields' => [
                        'log_title' => $newTitle->getDBkey()
                    ],
                    'where' => [
                        'log_type' => $logTypesOnUser,
                        'log_namespace' => NS_USER,
                        'log_title' => $oldTitle->getDBkey()        
                    ]
                ]
            ]
		];

        // Do table updates
		wfLogWarning( __METHOD__ . ": " . microtime( true ) . ": Starting table updates for RenameUser" );
		foreach ( $tableUpdates as $key => $value ) {
			wfLogWarning( __METHOD__ . ": " . microtime( true ) . ": Starting table update for " . $key );
			if ( $dbw->tableExists( $key, __METHOD__ ) ) {
				foreach ( $value as $name => $fields ) {
					wfLogWarning( __METHOD__ . ": " . microtime( true ) . ": Looping through " . $name );
					try {
						$dbw->update(
							$key,
							$fields['fields'],
							$fields['where'],
							__METHOD__
						);
					} catch ( Exception $e ) {
						wfLogWarning( __METHOD__ . ": " . microtime( true ) . ": Exception for dbw->update: $e->getMessage()" );
						$this->setLastError( get_class( $e ) . ': ' . $e->getMessage() );

						continue;
					}
				}
			}
		}

        // Move this user's userpages on this wiki
		if ( $this->movePages ) {
			wfLogWarning( __METHOD__ . ": " . microtime( true ) . ": Starting to move pages for RenameUser" );
			$user = User::newSystemUser( 'Weird Gloop', [ 'steal' => true ] );
			$dbr = wfGetDB( DB_REPLICA );

			wfLogWarning( __METHOD__ . ": " . microtime( true ) . ": Doing select query" );
			$pages = $dbr->select(
				'page',
				[ 'page_namespace', 'page_title' ],
				[
					'page_namespace' => [ NS_USER, NS_USER_TALK ],
					$dbr->makeList( [
						'page_title ' . $dbr->buildLike( $oldTitle->getDBkey() . '/', $dbr->anyString() ),
						'page_title = ' . $dbr->addQuotes( $oldTitle->getDBkey() ),
					], LIST_OR ),
				],
				__METHOD__
			);
			wfLogWarning( __METHOD__ . ": " . microtime( true ) . ": Finished select query" );

			$suppressRedirect = false;

			if ( $this->suppressRedirects ) {
				$suppressRedirect = true;
			}

			foreach ( $pages as $row ) {
				wfLogWarning( __METHOD__ . ": " . microtime( true ) . ": Beginning move for $row->page_title" );
				$oldPage = $titleFactory->makeTitle( $row->page_namespace, $row->page_title );

				$newPageTitle = preg_replace( '!^[^/]+!', $newTitle->getDBkey(), $row->page_title );
				$newPage = $titleFactory->makeTitleSafe( $row->page_namespace, $newPageTitle );

				if ( !$newPage ) {
					// throw new Exception(
					// 	"Encountered an invalid page title $newPageTitle in namespace $row->page_namespace"
					// );
					continue;
				}

				wfLogWarning( __METHOD__ . ": " . microtime( true ) . ": Creating new move page" );
				$movePage = $movePageFactory->newMovePage( $oldPage, $newPage );
				$validMoveStatus = $movePage->isValidMove();
				$logReason = wfMessage(
					'renameuser-move-log', $oldTitle->getText(), $newTitle->getText()
				)->inContentLanguage()->text();

				wfLogWarning( __METHOD__ . ": " . microtime( true ) . ": Checking if the page exists and stuff" );
				if ( $newPage->exists() && !$validMoveStatus->isOK() ) {
					// Could not move
				} else {
					wfLogWarning( __METHOD__ . ": " . microtime( true ) . ": Actually starting the move for $row->page_title" );
					$moveStatus = $movePage->move( $user, $logReason, !$suppressRedirect );
					wfLogWarning( __METHOD__ . ": " . microtime( true ) . ": Finished the move for $row->page_title" );
				}
			}
		}


        return true;
    }
}