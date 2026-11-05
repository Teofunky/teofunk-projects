( function () {
	'use strict';
	document.addEventListener( 'click', function ( ev ) {
		var btn = ev.target.closest ? ev.target.closest( '[data-kzsv-copy]' ) : null;
		if ( ! btn ) {
			return;
		}
		var el = document.getElementById( btn.getAttribute( 'data-kzsv-copy' ) );
		if ( ! el ) {
			return;
		}
		var text = el.textContent.trim();
		var done = function () {
			var old = btn.textContent;
			btn.textContent = 'Скопировано';
			setTimeout( function () { btn.textContent = old; }, 1500 );
		};
		if ( navigator.clipboard && navigator.clipboard.writeText ) {
			navigator.clipboard.writeText( text ).then( done, function () {} );
		} else {
			var range = document.createRange();
			range.selectNodeContents( el );
			var sel = window.getSelection();
			sel.removeAllRanges();
			sel.addRange( range );
			try { document.execCommand( 'copy' ); done(); } catch ( e ) {}
		}
	} );
} )();
