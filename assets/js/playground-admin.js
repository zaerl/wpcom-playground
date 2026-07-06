import {
	startPlaygroundWeb,
	zipWpContent,
} from 'https://playground.wordpress.net/client/index.js';

const root = document.getElementById( 'wpcom-playground-admin' );
const iframe = document.getElementById( 'wpcom-playground-iframe' );
const importButton = document.getElementById( 'wpcom-playground-import' );
const importResult = document.getElementById( 'wpcom-playground-import-result' );
const status = document.getElementById( 'wpcom-playground-status' );

let playgroundClient = null;

const setStatus = ( message, state ) => {
	if ( status ) {
		status.textContent = message;
		status.dataset.state = state;
	}
};

const setImportButtonBusy = ( isBusy ) => {
	if ( ! importButton ) {
		return;
	}

	importButton.disabled = isBusy || ! playgroundClient;
	importButton.textContent = isBusy ? 'Importing...' : 'Import';
};

const setImportResult = ( attachment ) => {
	if ( ! importResult ) {
		return;
	}

	if ( ! attachment ) {
		importResult.href = '#';
		importResult.hidden = true;
		return;
	}

	importResult.href = attachment.editUrl || attachment.url || '#';
	importResult.hidden = ! importResult.href || '#' === importResult.href;
};

const getUploadFileName = () => {
	const timestamp = new Date().toISOString().replace( /[:.]/g, '-' );
	return `playground-wp-content-${ timestamp }.zip`;
};

const uploadWpContentZip = async ( zipData ) => {
	const formData = new FormData();
	const zipFile = new File( [ zipData ], getUploadFileName(), {
		type: 'application/zip',
	} );

	formData.append( 'action', root.dataset.uploadAction );
	formData.append( 'nonce', root.dataset.uploadNonce );
	formData.append( 'wp_content_zip', zipFile );

	const response = await window.fetch( root.dataset.uploadUrl, {
		body: formData,
		credentials: 'same-origin',
		method: 'POST',
	} );
	const result = await response.json();

	if ( ! response.ok || ! result.success ) {
		throw new Error(
			result.data?.message || 'The Playground archive could not be imported.'
		);
	}

	const attachment = result.data;

	if (
		! window.confirm(
			'Are you sure?\n\nImport this Playground .zip into this site?'
		)
	) {
		attachment.importCancelled = true;
		return attachment;
	}

	setStatus( 'Importing Playground archive...', 'busy' );
	attachment.importResult = await importUploadedWpContentZip( attachment );

	return attachment;
};

const importUploadedWpContentZip = async ( attachment ) => {
	if ( ! attachment?.attachmentId ) {
		throw new Error( 'The Playground archive upload did not return an attachment ID.' );
	}

	const response = await window.fetch( root.dataset.importUrl, {
		body: JSON.stringify( {
			attachmentId: attachment.attachmentId,
		} ),
		credentials: 'same-origin',
		headers: {
			'Content-Type': 'application/json',
			'X-WP-Nonce': root.dataset.restNonce,
		},
		method: 'POST',
	} );
	const result = await response.json();

	if ( ! response.ok ) {
		throw new Error(
			result.message || 'The Playground archive could not be imported.'
		);
	}

	return result;
};

const importWpContent = async () => {
	if ( ! playgroundClient ) {
		return;
	}

	try {
		setImportButtonBusy( true );
		setStatus( 'Creating Playground wp-content archive...', 'busy' );
		setImportResult( null );

		const zipData = await zipWpContent( playgroundClient, {
			selfContained: true,
		} );

		setStatus( 'Saving Playground archive in the Media Library...', 'busy' );
		const attachment = await uploadWpContentZip( zipData );

		setImportResult( attachment );
		setStatus(
			attachment.importCancelled
				? 'Playground archive saved. Import cancelled.'
				: 'Playground archive imported.',
			'ready'
		);
	} catch ( error ) {
		window.console.error( error );
		setStatus(
			error.message ||
				'The Playground archive could not be saved or imported.',
			'error'
		);
	} finally {
		setImportButtonBusy( false );
	}
};

const startPlayground = async () => {
	if ( ! root || ! iframe ) {
		return;
	}

	try {
		playgroundClient = await startPlaygroundWeb( {
			iframe,
			remoteUrl:
				root.dataset.remoteUrl ||
				'https://playground.wordpress.net/remote.html',
		} );

		root.playgroundClient = playgroundClient;
		setStatus( 'WordPress Playground is ready.', 'ready' );
		setImportButtonBusy( false );
	} catch ( error ) {
		window.console.error( error );
		setStatus(
			'WordPress Playground could not be started. Check the browser console for details.',
			'error'
		);
	}
};

if ( importButton ) {
	importButton.addEventListener( 'click', importWpContent );
}

startPlayground();
