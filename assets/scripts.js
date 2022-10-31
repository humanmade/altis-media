// Event listener for when the event altisBlockContentChanged occurs.
window.addEventListener( 'altisBlockContentChanged', function () {
	// Ensure images are loaded with GaussHolder if available.
	if ( window.GaussHolder ) {
		window.GaussHolder();
	}
} );
