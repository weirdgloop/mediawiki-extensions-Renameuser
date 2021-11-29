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
		foreach ( $tableUpdates as $key => $value ) {
			if ( $dbw->tableExists( $key, __METHOD__ ) ) {
				foreach ( $value as $name => $fields ) {
					try {
						$dbw->update(
							$key,
							$fields['fields'],
							$fields['where'],
							__METHOD__
						);
					} catch ( Exception $e ) {
						$this->setLastError( get_class( $e ) . ': ' . $e->getMessage() );

						continue;
					}
				}
			}
		}

        // Move this user's userpages on this wiki
		if ( $this->movePages ) {
			$user = User::newSystemUser( 'Weird Gloop', [ 'steal' => true ] );
			$dbr = wfGetDB( DB_REPLICA );

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

			$suppressRedirect = false;

			if ( $this->suppressRedirects ) {
				$suppressRedirect = true;
			}

			foreach ( $pages as $row ) {
				$oldPage = $titleFactory->makeTitle( $row->page_namespace, $row->page_title );

				$newPageTitle = preg_replace( '!^[^/]+!', $newTitle->getDBkey(), $row->page_title );
				$newPage = $titleFactory->makeTitleSafe( $row->page_namespace, $newPageTitle );

				if ( !$newPage ) {
					// throw new Exception(
					// 	"Encountered an invalid page title $newPageTitle in namespace $row->page_namespace"
					// );
					continue
				}

				$movePage = $movePageFactory->newMovePage( $oldPage, $newPage );
				$validMoveStatus = $movePage->isValidMove();
				$logReason = wfMessage(
					'renameuser-move-log', $oldTitle->getText(), $newTitle->getText()
				)->inContentLanguage()->text();

				if ( $newPage->exists() && !$validMoveStatus->isOK() ) {
					// Could not move
				} else {
					$moveStatus = $movePage->move( $user, $logReason, !$suppressRedirect );
				}
			}
		}


        return true;
    }
}