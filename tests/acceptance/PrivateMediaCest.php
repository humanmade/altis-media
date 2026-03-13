<?php
/**
 * Acceptance tests for Private Media UI.
 *
 * Tests the media library interface for private/public visibility management.
 *
 * phpcs:disable WordPress.Files, WordPress.NamingConventions, PSR1.Classes.ClassDeclaration.MissingNamespace, HM.Functions.NamespacedFunctions
 *
 * @package altis/media
 */

/**
 * Test Private Media UI features.
 */
class PrivateMediaCest {

	/**
	 * Test that a newly uploaded image appears as private in the media library.
	 *
	 * @param AcceptanceTester $I Tester.
	 *
	 * @return void
	 */
	public function newUploadIsPrivate( AcceptanceTester $I ) {
		$I->wantToTest( 'Newly uploaded media is private by default.' );

		$I->loginAsAdmin();

		// Upload a file via the Add New Media page.
		$I->amOnAdminPage( 'media-new.php' );
		$I->see( 'Upload New Media' );
		$I->attachFile( 'input[type="file"]', 'wp-logo.png' );
		$I->wait( 3 );
		$I->waitForElement( '.media-item', 30 );

		// Go to the media library list view.
		$I->amOnAdminPage( 'upload.php?mode=list' );
		$I->waitForElement( '.wp-list-table', 10 );

		// The most recent upload should show "Private" in the visibility column.
		$I->see( 'Private', '.wp-list-table tbody tr:first-child .private-media-status' );

		// Row actions should include "Make Public".
		$I->moveMouseOver( '.wp-list-table tbody tr:first-child' );
		$I->seeLink( 'Make Public' );
	}

	/**
	 * Test the "Make Public" row action in the media library.
	 *
	 * @param AcceptanceTester $I Tester.
	 *
	 * @return void
	 */
	public function makePublicRowAction( AcceptanceTester $I ) {
		$I->wantToTest( 'Make Public row action sets attachment to public.' );

		$I->loginAsAdmin();

		// Upload a file.
		$I->amOnAdminPage( 'media-new.php' );
		$I->attachFile( 'input[type="file"]', 'wp-logo.png' );
		$I->wait( 3 );
		$I->waitForElement( '.media-item', 30 );

		// Go to the media library list view.
		$I->amOnAdminPage( 'upload.php?mode=list' );
		$I->waitForElement( '.wp-list-table', 10 );

		// Hover over the first row to reveal actions and click Make Public.
		$I->moveMouseOver( '.wp-list-table tbody tr:first-child' );
		$I->seeLink( 'Make Public' );
		$I->click( 'Make Public' );

		// Should redirect back with success notice.
		$I->waitForText( 'Attachment visibility updated.', 10 );

		// The visibility column should now show "Public (forced)".
		$I->see( 'Public (forced)', '.private-media-status' );
	}

	/**
	 * Test the "Make Private" row action in the media library.
	 *
	 * @param AcceptanceTester $I Tester.
	 *
	 * @return void
	 */
	public function makePrivateRowAction( AcceptanceTester $I ) {
		$I->wantToTest( 'Make Private row action sets attachment to private.' );

		$I->loginAsAdmin();

		// Upload a file and make it public first.
		$I->amOnAdminPage( 'media-new.php' );
		$I->attachFile( 'input[type="file"]', 'wp-logo.png' );
		$I->wait( 3 );
		$I->waitForElement( '.media-item', 30 );

		$I->amOnAdminPage( 'upload.php?mode=list' );
		$I->waitForElement( '.wp-list-table', 10 );
		$I->moveMouseOver( '.wp-list-table tbody tr:first-child' );
		$I->click( 'Make Public' );
		$I->waitForText( 'Attachment visibility updated.', 10 );

		// Now click Make Private.
		$I->moveMouseOver( '.wp-list-table tbody tr:first-child' );
		$I->seeLink( 'Make Private' );
		$I->click( 'Make Private' );

		// Should redirect back with success notice.
		$I->waitForText( 'Attachment visibility updated.', 10 );

		// The visibility column should now show "Private (forced)".
		$I->see( 'Private (forced)', '.private-media-status' );
	}

	/**
	 * Test the Remove Override row action in the media library.
	 *
	 * @param AcceptanceTester $I Tester.
	 *
	 * @return void
	 */
	public function removeOverrideRowAction( AcceptanceTester $I ) {
		$I->wantToTest( 'Remove Override row action resets to automatic visibility.' );

		$I->loginAsAdmin();

		// Upload and force public.
		$I->amOnAdminPage( 'media-new.php' );
		$I->attachFile( 'input[type="file"]', 'wp-logo.png' );
		$I->wait( 3 );
		$I->waitForElement( '.media-item', 30 );

		$I->amOnAdminPage( 'upload.php?mode=list' );
		$I->waitForElement( '.wp-list-table', 10 );
		$I->moveMouseOver( '.wp-list-table tbody tr:first-child' );
		$I->click( 'Make Public' );
		$I->waitForText( 'Attachment visibility updated.', 10 );

		// Now remove override.
		$I->moveMouseOver( '.wp-list-table tbody tr:first-child' );
		$I->seeLink( 'Remove Override' );
		$I->click( 'Remove Override' );
		$I->waitForText( 'Attachment visibility updated.', 10 );

		// Should revert to automatic (Private, since no references).
		$I->see( 'Private', '.wp-list-table tbody tr:first-child .private-media-status' );
	}
}
