<?php

use MediaWiki\Deferred\DeferredUpdates;
use MediaWiki\HTMLForm\HTMLForm;
use MediaWiki\MediaWikiServices;
use MediaWiki\Revision\SlotRecord;
use MediaWiki\Skin\SkinTemplate;
use MediaWiki\Storage\BlobStore;
use MediaWiki\Title\Title;
use Wikimedia\Rdbms\IDatabase;

class ActionDeletePagePermanently extends FormAction {

    /**
     * @param SkinTemplate $sktemplate
     * @param array &$links
     */
    public static function onAddSkinHook( SkinTemplate $sktemplate, array &$links ) {
        if ( $sktemplate->getUser()->isAllowed( 'deleteperm' ) ) {
            $title = $sktemplate->getRelevantTitle();
            $action = self::getActionName( $sktemplate );

            if ( self::canDeleteTitle( $title ) ) {
                $links['actions']['delete_page_permanently'] = [
                    'class' => ( $action === 'delete_page_permanently' ) ? 'selected' : false,
                    'text' => $sktemplate->msg( 'deletepagesforgood-delete_permanently' )->text(),
                    'href' => $title->getLocalUrl( 'action=delete_page_permanently' )
                ];
            }
        }
    }

    /** @inheritDoc */
    public function getName() {
        return 'delete_page_permanently';
    }

    /** @inheritDoc */
    public function doesWrites() {
        return true;
    }

    /** @inheritDoc */
    public function getDescription() {
        return '';
    }

    /** @inheritDoc */
    protected function usesOOUI() {
        return true;
    }

    /**
     * @param Title $title
     * @return bool
     */
    public static function canDeleteTitle( Title $title ) {
        global $wgDeletePagesForGoodNamespaces;

        return (
            $title->exists() &&
            $title->getArticleID() !== 0 &&
            $title->getDBkey() !== '' &&
            $title->getNamespace() !== NS_SPECIAL &&
            isset( $wgDeletePagesForGoodNamespaces[ $title->getNamespace() ] ) &&
            $wgDeletePagesForGoodNamespaces[ $title->getNamespace() ]
        );
    }

    /**
     * @param mixed $data
     * @return bool|string[]
     */
    public function onSubmit( $data ) {
        if ( self::canDeleteTitle( $this->getTitle() ) ) {
            $this->deletePermanently( $this->getTitle() );
            return true;
        }

        return [ 'deletepagesforgood-del_impossible' ];
    }

    /**
     * @param Title $title
     * @return bool|string
     */
    public function deletePermanently( Title $title ) {
        $ns = $title->getNamespace();
        $t = $title->getDBkey();
        $id = $title->getArticleID();
        $cats = $title->getParentCategories();
        $user = $this->getContext()->getUser();

        $dbw = MediaWikiServices::getInstance()
            ->getDBLoadBalancer()
            ->getConnection( DB_PRIMARY );

        $dbw->startAtomic( __METHOD__, $dbw::ATOMIC_CANCELABLE );

        /*
         * Hook personalizado para integraciones externas.
         * Permite que otras extensiones limpien datos multimedia
         * antes de que DeletePagesForGood borre permanentemente
         * la página del namespace Archivo.
         */
        if ( $ns === NS_FILE ) {
            MediaWikiServices::getInstance()
                ->getHookContainer()
                ->run(
                    'DeletePagesForGood::BeforePermanentFileDelete',
                    [
                        $title,
                        $user
                    ]
                );
        }

        /*
         * File cleanup
         *
         * Borrado permanente real para namespace Archivo:
         * - No usa $file->deleteFile(), porque deleteFile() mueve el archivo a images/deleted.
         * - Se ejecuta antes de borrar page/revision/archive para que MediaWiki todavía pueda resolver el archivo.
         * - Borra archivo actual, miniaturas, versiones antiguas y archivos ya archivados.
         * - No debe crear nuevos archivos en images/deleted.
         */
        if ( $ns == NS_FILE ) {

            $repoGroup = MediaWikiServices::getInstance()->getRepoGroup();
            $repo = $repoGroup->getLocalRepo();

            $file = $repoGroup->findFile( $title );

            if ( !$file ) {
                $file = $repoGroup->findFile( $t );
            }

            $physicalPaths = [];
            $deletedFiles = [];

            $deletePath = static function ( $path ) use ( &$deletePath ) {
                if ( !is_string( $path ) || $path === '' ) {
                    return;
                }

                if ( is_file( $path ) ) {
                    @unlink( $path );
                    return;
                }

                if ( is_dir( $path ) ) {
                    $items = scandir( $path );

                    if ( !is_array( $items ) ) {
                        return;
                    }

                    foreach ( $items as $item ) {
                        if ( $item === '.' || $item === '..' ) {
                            continue;
                        }

                        $deletePath( $path . DIRECTORY_SEPARATOR . $item );
                    }

                    @rmdir( $path );
                }
            };

            if ( $file ) {
                if ( method_exists( $file, 'getRepo' ) ) {
                    $repo = $file->getRepo();
                }

                if ( method_exists( $file, 'getLocalRefPath' ) ) {
                    $localRefPath = $file->getLocalRefPath();

                    if ( $localRefPath ) {
                        $physicalPaths[] = $localRefPath;
                    }
                }

                if ( method_exists( $file, 'getPath' ) ) {
                    $currentPath = $file->getPath();

                    if ( $currentPath ) {
                        $physicalPaths[] = $currentPath;
                    }
                }

                if ( method_exists( $file, 'getThumbPath' ) ) {
                    $thumbPath = $file->getThumbPath();

                    if ( $thumbPath ) {
                        $physicalPaths[] = $thumbPath;
                    }
                }

                if ( method_exists( $file, 'getHistory' ) ) {
                    try {
                        $history = $file->getHistory();

                        if ( is_array( $history ) ) {
                            foreach ( $history as $oldFile ) {
                                if ( is_object( $oldFile ) && method_exists( $oldFile, 'getPath' ) ) {
                                    $oldPath = $oldFile->getPath();

                                    if ( $oldPath ) {
                                        $physicalPaths[] = $oldPath;
                                    }
                                }

                                if ( is_object( $oldFile ) && method_exists( $oldFile, 'getLocalRefPath' ) ) {
                                    $oldLocalPath = $oldFile->getLocalRefPath();

                                    if ( $oldLocalPath ) {
                                        $physicalPaths[] = $oldLocalPath;
                                    }
                                }
                            }
                        }
                    } catch ( \Throwable $e ) {
                    }
                }
            }

            /*
             * Fallback: construir ruta directa del archivo actual.
             */
            if (
                $repo &&
                method_exists( $repo, 'getZonePath' ) &&
                method_exists( $repo, 'getHashPath' )
            ) {
                $publicPath = rtrim( $repo->getZonePath( 'public' ), '/' );
                $hashPath = $repo->getHashPath( $t );

                $physicalPaths[] = $publicPath . '/' . $hashPath . $t;
                $physicalPaths[] = $publicPath . '/thumb/' . $hashPath . $t;
            }

            /*
             * Versiones antiguas en oldimage.
             */
            if ( $dbw->tableExists( 'oldimage', __METHOD__ ) ) {
                $oldRows = $dbw->select(
                    'oldimage',
                    [ 'oi_archive_name' ],
                    [ 'oi_name' => $t ],
                    __METHOD__
                );

                if (
                    $repo &&
                    method_exists( $repo, 'getZonePath' ) &&
                    method_exists( $repo, 'getHashPath' )
                ) {
                    $publicPath = rtrim( $repo->getZonePath( 'public' ), '/' );
                    $hashPath = $repo->getHashPath( $t );

                    foreach ( $oldRows as $row ) {
                        if ( !empty( $row->oi_archive_name ) ) {
                            $physicalPaths[] = $publicPath . '/archive/' . $hashPath . $row->oi_archive_name;
                        }
                    }
                }
            }

            /*
             * Archivos ya existentes en filearchive/images/deleted.
             */
            if ( $dbw->tableExists( 'filearchive', __METHOD__ ) ) {
                $fileArchiveRows = $dbw->select(
                    'filearchive',
                    [ 'fa_storage_key', 'fa_archive_name' ],
                    [ 'fa_name' => $t ],
                    __METHOD__
                );

                foreach ( $fileArchiveRows as $row ) {
                    if ( !empty( $row->fa_storage_key ) ) {
                        $deletedFiles[] = $row->fa_storage_key;
                    }

                    if (
                        !empty( $row->fa_archive_name ) &&
                        $repo &&
                        method_exists( $repo, 'getZonePath' ) &&
                        method_exists( $repo, 'getHashPath' )
                    ) {
                        $publicPath = rtrim( $repo->getZonePath( 'public' ), '/' );
                        $hashPath = $repo->getHashPath( $t );

                        $physicalPaths[] = $publicPath . '/archive/' . $hashPath . $row->fa_archive_name;
                    }
                }
            }

            $physicalPaths = array_values( array_unique( array_filter( $physicalPaths ) ) );
            $deletedFiles = array_values( array_unique( array_filter( $deletedFiles ) ) );

            foreach ( $physicalPaths as $path ) {
                $deletePath( $path );
            }

            if ( $repo && $deletedFiles ) {
                try {
                    $repo->cleanupDeletedBatch( $deletedFiles );
                } catch ( \LogicException $e ) {
                }
            }

            if (
                $repo &&
                $deletedFiles &&
                method_exists( $repo, 'getZonePath' ) &&
                method_exists( $repo, 'getDeletedHashPath' )
            ) {
                $deletedPath = rtrim( $repo->getZonePath( 'deleted' ), '/' );

                foreach ( $deletedFiles as $fileKey ) {
                    $hashPath = $repo->getDeletedHashPath( $fileKey );
                    $physicalPath = $deletedPath . '/' . $hashPath . $fileKey;

                    $deletePath( $physicalPath );
                }
            }
        }

        # Delete redirect
        $dbw->delete( 'redirect', [ 'rd_from' => $id ], __METHOD__ );

        # Delete external links
        $dbw->delete( 'externallinks', [ 'el_from' => $id ], __METHOD__ );

        # Delete language links
        $dbw->delete( 'langlinks', [ 'll_from' => $id ], __METHOD__ );

        if ( $GLOBALS['wgDBtype'] !== "postgres" && $GLOBALS['wgDBtype'] !== "sqlite" ) {
            $dbw->delete( 'searchindex', [ 'si_page' => $id ], __METHOD__ );
        }

        $dbw->delete( 'page_restrictions', [ 'pr_page' => $id ], __METHOD__ );
        $dbw->delete( 'pagelinks', [ 'pl_from' => $id ], __METHOD__ );
        $dbw->delete( 'categorylinks', [ 'cl_from' => $id ], __METHOD__ );
        $dbw->delete( 'templatelinks', [ 'tl_from' => $id ], __METHOD__ );

        $mcrSchemaMigrationStage =
            $GLOBALS['wgMultiContentRevisionSchemaMigrationStage'] ?? 0;

        if (
            ( $mcrSchemaMigrationStage & SCHEMA_COMPAT_WRITE_OLD ) ||
            $dbw->fieldExists( 'revision', 'rev_text_id', __METHOD__ )
        ) {

            $res = $dbw->select(
                'revision',
                'rev_text_id',
                [ 'rev_page' => $id ],
                __METHOD__
            );

            foreach ( $res as $row ) {
                $dbw->delete(
                    'text',
                    [ 'old_id' => $row->rev_text_id ],
                    __METHOD__
                );
            }

            $arRes = $dbw->select(
                'archive',
                'ar_text_id',
                [
                    'ar_namespace' => $ns,
                    'ar_title' => $t
                ],
                __METHOD__
            );

            foreach ( $arRes as $arRow ) {
                $dbw->delete(
                    'text',
                    [ 'old_id' => $arRow->ar_text_id ],
                    __METHOD__
                );
            }
        }

        if (
            ( $mcrSchemaMigrationStage & SCHEMA_COMPAT_WRITE_NEW ) ||
            !$dbw->fieldExists( 'revision', 'rev_text_id', __METHOD__ )
        ) {

            $revisionStore = MediaWikiServices::getInstance()->getRevisionStore();
            $blobStore = MediaWikiServices::getInstance()->getBlobStore();
            $revQuery = $revisionStore->getQueryInfo();

            $res = $dbw->select(
                $revQuery['tables'],
                $revQuery['fields'],
                [ 'rev_page' => $id ],
                __METHOD__,
                [],
                $revQuery['joins']
            );

            foreach ( $res as $row ) {
                $rev = $revisionStore->newRevisionFromRow( $row );

                $this->deleteSlotsPermanently(
                    $dbw,
                    $rev->getSlots()->getSlots(),
                    $rev->getId(),
                    $blobStore
                );
            }
        }

        $dbw->delete( 'revision', [ 'rev_page' => $id ], __METHOD__ );
        $dbw->delete( 'imagelinks', [ 'il_from' => $id ], __METHOD__ );

        $dbw->delete( 'recentchanges', [
            'rc_namespace' => $ns,
            'rc_title' => $t
        ], __METHOD__ );

        $dbw->delete( 'archive', [
            'ar_namespace' => $ns,
            'ar_title' => $t
        ], __METHOD__ );

        $dbw->delete( 'logging', [
            'log_namespace' => $ns,
            'log_title' => $t
        ], __METHOD__ );

        $dbw->delete( 'watchlist', [
            'wl_namespace' => $ns,
            'wl_title' => $t
        ], __METHOD__ );

        $dbw->delete( 'page', [ 'page_id' => $id ], __METHOD__ );

        /*
         * Update categories
         */
        if ( !empty( $cats ) ) {
            foreach ( $cats as $parentcat => $currentarticle ) {

                $catname = preg_split( '/:/', $parentcat, 2 );

                if ( !isset( $catname[1] ) ) {
                    continue;
                }

                $catTitle = Title::makeTitleSafe(
                    NS_CATEGORY,
                    $catname[1]
                );

                if ( $catTitle ) {

                    DeferredUpdates::addCallableUpdate(
                        static function () use ( $catTitle ) {

                            $wikiPageFactory = MediaWikiServices::getInstance()
                                ->getWikiPageFactory();

                            $categoryPage = $wikiPageFactory->newFromTitle( $catTitle );

                            if ( method_exists( $categoryPage, 'doCategoryUpdates' ) ) {
                                $categoryPage->doCategoryUpdates();
                            }
                        }
                    );
                }
            }
        }

        /*
         * File database cleanup
         *
         * Esto va al final para no perder fa_storage_key antes de borrar
         * archivos en images/deleted.
         */
        if ( $ns == NS_FILE ) {
            if ( $dbw->tableExists( 'filearchive', __METHOD__ ) ) {
                $dbw->delete(
                    'filearchive',
                    [ 'fa_name' => $t ],
                    __METHOD__
                );
            }

            if ( $dbw->tableExists( 'oldimage', __METHOD__ ) ) {
                $dbw->delete(
                    'oldimage',
                    [ 'oi_name' => $t ],
                    __METHOD__
                );
            }

            if ( $dbw->tableExists( 'image', __METHOD__ ) ) {
                $dbw->delete(
                    'image',
                    [ 'img_name' => $t ],
                    __METHOD__
                );
            }

            MediaWikiServices::getInstance()
                ->getLinkCache()
                ->clear();

            $this->purgeDeletedFilePageCache( $title );
        }

        $dbw->endAtomic( __METHOD__ );

        return true;
    }

    /**
     * @param IDatabase $dbw
     * @param SlotRecord[] $slots
     * @param int $revId
     * @param BlobStore $blobStore
     */
    private function deleteSlotsPermanently(
        $dbw,
        $slots,
        $revId,
        $blobStore
    ) {

        foreach ( $slots as $slot ) {

            if (
                $this->shouldDeleteContent(
                    $dbw,
                    $revId,
                    $slot->getContentId()
                )
            ) {

                $textId = $blobStore->getTextIdFromAddress(
                    $slot->getAddress()
                );

                if ( $textId ) {
                    $dbw->delete(
                        'text',
                        [ 'old_id' => $textId ],
                        __METHOD__
                    );
                }

                $dbw->delete(
                    'content',
                    [ 'content_id' => $slot->getContentId() ],
                    __METHOD__
                );
            }
        }

        $dbw->delete(
            'slots',
            [ 'slot_revision_id' => $revId ],
            __METHOD__
        );
    }

    /**
     * @param IDatabase $dbw
     * @param int $revId
     * @param int $contentId
     * @return bool
     */
    private function shouldDeleteContent(
        $dbw,
        $revId,
        $contentId
    ) {

        global $wgDeletePagesForGoodDeleteContent;

        if ( !$wgDeletePagesForGoodDeleteContent ) {
            return false;
        }

        $count = $dbw->selectRowCount(
            'slots',
            '*',
            [
                'slot_content_id' => $contentId,
                "slot_revision_id != $revId"
            ],
            __METHOD__
        );

        return $count == 0;
    }

    /**
     * Purga e invalida cachés relacionadas con una página de archivo
     * después del borrado permanente.
     *
     * Esto ayuda a evitar que la página Archivo: siga apareciendo
     * como si el archivo todavía existiera por caché de MediaWiki.
     *
     * @param Title $title
     * @return void
     */
    private function purgeDeletedFilePageCache( Title $title ): void {
        try {
            $title->invalidateCache();
        } catch ( \Throwable $e ) {
        }

        try {
            MediaWikiServices::getInstance()
                ->getLinkCache()
                ->clear();
        } catch ( \Throwable $e ) {
        }

        DeferredUpdates::addCallableUpdate(
            static function () use ( $title ) {
                try {
                    $title->invalidateCache();
                } catch ( \Throwable $e ) {
                }

                try {
                    $services = MediaWikiServices::getInstance();

                    $services->getLinkCache()->clear();

                    $wikiPageFactory = $services->getWikiPageFactory();
                    $wikiPage = $wikiPageFactory->newFromTitle( $title );

                    if ( method_exists( $wikiPage, 'doPurge' ) ) {
                        $wikiPage->doPurge();
                    } elseif ( method_exists( $wikiPage, 'purge' ) ) {
                        $wikiPage->purge();
                    }
                } catch ( \Throwable $e ) {
                }
            }
        );
    }

    /** @inheritDoc */
    protected function getPageTitle() {
        return $this->msg(
            'deletepagesforgood-deletepagetitle',
            $this->getTitle()->getPrefixedText()
        );
    }

    /**
     * @param HTMLForm $form
     */
    protected function alterForm( HTMLForm $form ) {

        $title = $this->getTitle();
        $output = $this->getOutput();

        $output->addBacklinkSubtitle( $title );

        $form->addPreHtml(
            $this->msg( 'confirmdeletetext' )->parseAsBlock()
        );

        $form->addPreHtml(
            $this->msg(
                'deletepagesforgood-ask_deletion'
            )->parseAsBlock()
        );

        $form->setSubmitTextMsg(
            'deletepagesforgood-yes'
        );
    }

    /** @inheritDoc */
    public function getRestriction() {
        return 'deleteperm';
    }

    /**
     * @return bool
     */
    public function onSuccess() {

        $this->getOutput()->addHTML(
            $this->msg(
                'deletepagesforgood-del_done'
            )->escaped()
        );

        return false;
    }
}