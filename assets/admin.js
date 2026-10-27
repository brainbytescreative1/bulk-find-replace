/* global bfrData */
( function () {
	'use strict';

	var toggle = document.querySelector( '.bfr-toggle-all' );
	if ( toggle ) {
		toggle.addEventListener( 'change', function () {
			document.querySelectorAll( '.bfr-tables input[type=checkbox]' ).forEach( function ( box ) {
				box.checked = toggle.checked;
			} );
		} );
	}

	var runner = document.getElementById( 'bfr-runner' );
	if ( ! runner ) {
		return;
	}

	var tables = JSON.parse( runner.dataset.tables );
	var locked = runner.dataset.locked === '1';
	var dryBtn = runner.querySelector( '[data-mode=dry]' );
	var liveBtn = runner.querySelector( '[data-mode=live]' );
	var progress = runner.querySelector( '.bfr-progress' );
	var results = runner.querySelector( '.bfr-results' );

	function el( tag, text, className ) {
		var node = document.createElement( tag );
		if ( text !== undefined && text !== null ) {
			node.textContent = String( text );
		}
		if ( className ) {
			node.className = className;
		}
		return node;
	}

	function row( cells, tag ) {
		var tr = el( 'tr' );
		cells.forEach( function ( cell ) {
			var td = el( tag || 'td' );
			if ( cell instanceof Node ) {
				td.appendChild( cell );
			} else {
				td.textContent = String( cell );
			}
			tr.appendChild( td );
		} );
		return tr;
	}

	function batch( mode, tableIndex, afterPk ) {
		var body = new URLSearchParams();
		body.set( 'action', 'bfr_batch' );
		body.set( 'nonce', bfrData.nonce );
		body.set( 'job', runner.dataset.job );
		body.set( 'mode', mode );
		body.set( 'table_index', tableIndex );
		body.set( 'after_pk', afterPk === null ? '' : afterPk );
		return fetch( bfrData.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: body,
		} )
			.then( function ( res ) {
				return res.text().then( function ( text ) {
					var json;
					try {
						json = JSON.parse( text );
					} catch ( e ) {
						throw new Error( 'HTTP ' + res.status + ': ' + text.slice( 0, 300 ) );
					}
					if ( ! json.success ) {
						throw new Error( ( json.data && json.data.message ) || 'HTTP ' + res.status );
					}
					return json.data;
				} );
			} );
	}

	function render( mode, stats ) {
		results.textContent = '';
		var live = mode === 'live';
		var totals = { rows: 0, cells: 0, replacements: 0, errors: 0, skippedObjs: 0 };

		var summary = el( 'table', null, 'widefat striped' );
		var head = el( 'thead' );
		head.appendChild( row( [ 'Table', live ? 'Rows changed' : 'Rows that would change', 'Cells', 'Replacements', 'Notes' ], 'th' ) );
		summary.appendChild( head );
		var tbody = el( 'tbody' );
		stats.forEach( function ( s ) {
			totals.rows += s.rows_changed;
			totals.cells += s.cells;
			totals.replacements += s.replacements;
			totals.errors += s.errors;
			totals.skippedObjs += s.skipped_objs;
			if ( ! s.rows_changed && ! s.skipped && ! s.errors ) {
				return;
			}
			var notes = [];
			if ( s.skipped ) {
				notes.push( 'Skipped: ' + s.skipped );
			}
			if ( s.errors ) {
				notes.push( s.errors + ' rows failed to save' );
			}
			if ( s.skipped_objs ) {
				notes.push( s.skipped_objs + ' serialized objects of inactive plugins left as is' );
			}
			tbody.appendChild( row( [ s.table, s.rows_changed, s.cells, s.replacements, notes.join( '. ' ) ] ) );
		} );
		var total = row( [ 'Total', totals.rows, totals.cells, totals.replacements, totals.errors ? totals.errors + ' rows failed to save' : '' ] );
		total.style.fontWeight = '600';
		tbody.appendChild( total );
		summary.appendChild( tbody );

		results.appendChild( el( 'h2', live ? 'Results' : 'Dry run results' ) );
		results.appendChild( summary );

		var samples = [];
		stats.forEach( function ( s ) {
			s.samples.forEach( function ( sample ) {
				samples.push( [ s.table, sample ] );
			} );
		} );
		if ( samples.length ) {
			results.appendChild( el( 'h3', 'Examples' ) );
			var table = el( 'table', null, 'widefat bfr-sample' );
			var sh = el( 'thead' );
			sh.appendChild( row( [ 'Where', 'Before', 'After' ], 'th' ) );
			table.appendChild( sh );
			var sb = el( 'tbody' );
			samples.forEach( function ( pair ) {
				var sample = pair[ 1 ];
				var before = el( 'td', null, 'bfr-before' );
				before.appendChild( el( 'code', sample.before ) );
				var after = el( 'td', null, 'bfr-after' );
				after.appendChild( el( 'code', sample.after ) );
				var tr = el( 'tr' );
				tr.appendChild( el( 'td', pair[ 0 ] + '.' + sample.column + ' #' + sample.pk ) );
				tr.appendChild( before );
				tr.appendChild( after );
				sb.appendChild( tr );
			} );
			table.appendChild( sb );
			results.appendChild( table );
		}
		return totals;
	}

	function run( mode ) {
		dryBtn.disabled = true;
		liveBtn.disabled = true;
		results.textContent = '';

		var stats = tables.map( function ( name ) {
			return { table: name, rows_changed: 0, cells: 0, replacements: 0, errors: 0, skipped_objs: 0, skipped: '', samples: [] };
		} );

		function step( index, afterPk ) {
			progress.textContent = bfrData.i18n.running + ' ' + tables[ index ] + ' (' + ( index + 1 ) + '/' + tables.length + ')…';
			return batch( mode, index, afterPk ).then( function ( r ) {
				var s = stats[ index ];
				s.rows_changed += r.rows_changed;
				s.cells += r.cells;
				s.replacements += r.replacements;
				s.errors += r.errors;
				s.skipped_objs += r.skipped_objs;
				s.skipped = r.skipped;
				s.samples = s.samples.concat( r.samples ).slice( 0, 10 );
				if ( r.all_done ) {
					return;
				}
				return r.table_done ? step( index + 1, null ) : step( index, r.next_pk );
			} );
		}

		step( 0, null )
			.then( function () {
				var totals = render( mode, stats );
				progress.textContent = mode === 'live' ? bfrData.i18n.liveDone : bfrData.i18n.dryDone;
				dryBtn.disabled = false;
				if ( mode === 'live' ) {
					locked = true;
				}
				liveBtn.disabled = locked || totals.cells === 0;
			} )
			.catch( function ( err ) {
				render( mode, stats );
				progress.textContent = bfrData.i18n.failed + ' ' + err.message;
				dryBtn.disabled = false;
				if ( mode === 'live' ) {
					locked = true;
				}
			} );
	}

	dryBtn.addEventListener( 'click', function () {
		run( 'dry' );
	} );
	// Confirm on the page instead of with window.confirm(), which some embedded
	// browsers suppress, so the button looked like it did nothing.
	var liveLabel = liveBtn.textContent;
	var armedTimer = null;
	liveBtn.addEventListener( 'click', function () {
		if ( ! armedTimer ) {
			liveBtn.textContent = bfrData.i18n.confirmAgain;
			progress.textContent = bfrData.i18n.confirm;
			armedTimer = setTimeout( function () {
				armedTimer = null;
				liveBtn.textContent = liveLabel;
				progress.textContent = '';
			}, 10000 );
			return;
		}
		clearTimeout( armedTimer );
		armedTimer = null;
		liveBtn.textContent = liveLabel;
		run( 'live' );
	} );
} )();
