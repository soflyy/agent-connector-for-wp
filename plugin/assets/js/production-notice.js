/**
 * "I understand, hide this warning" on the site-wide production notice
 * (src/Admin/ProductionNotice.php). Data: window.acfwProductionNotice.
 */
( function () {
	var btn = document.getElementById( 'acfw-production-warning-dismiss' );
	var cfg = window.acfwProductionNotice;
	if ( ! btn || ! cfg ) {
		return;
	}
	btn.addEventListener( 'click', function () {
		btn.disabled = true;
		fetch( cfg.dismissUrl, {
			method: 'POST',
			headers: { 'X-WP-Nonce': cfg.nonce },
		} ).finally( function () {
			var notice = document.getElementById( 'acfw-production-warning' );
			if ( notice ) {
				notice.remove();
			}
		} );
	} );
} )();
