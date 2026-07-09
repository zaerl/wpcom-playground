import {
	startPlaygroundWeb,
	zipWpContent as cdnZipWpContent,
} from 'https://playground.wordpress.net/client/index.js';

const root = document.getElementById( 'wpcom-playground-admin' );
const iframe = document.getElementById( 'wpcom-playground-iframe' );
const importButton = document.getElementById( 'wpcom-playground-import' );
const importResult = document.getElementById( 'wpcom-playground-import-result' );
const importLoader = document.getElementById( 'wpcom-playground-import-loader' );
const importLoaderMessage = document.getElementById(
	'wpcom-playground-import-loader-message'
);
const status = document.getElementById( 'wpcom-playground-status' );

const useLocalZipWpContent = true;

const getWpContentExportExclusions = () => {
	try {
		const exclusions = JSON.parse(
			root?.dataset.wpContentExportExclusions || '[]'
		);
		return Array.isArray( exclusions ) ? exclusions : [];
	} catch ( error ) {
		window.console.error( error );
		return [];
	}
};

const wpContentFilesExcludedFromExport = getWpContentExportExclusions();

let playgroundClient = null;

const setStatus = ( message, state ) => {
	if ( status ) {
		status.textContent = message;
		status.dataset.state = state;
	}
};

const setImportLoaderMessage = ( message ) => {
	if ( importLoaderMessage ) {
		importLoaderMessage.textContent = message;
	}
};

const showImportLoader = ( message ) => {
	setImportLoaderMessage( message );

	if ( importLoader ) {
		importLoader.hidden = false;
		importLoader.setAttribute( 'aria-busy', 'true' );
	}

	document.body.classList.add( 'wpcom-playground-admin--importing' );
};

const getImportRedirectUrl = ( importStatus, message = '' ) => {
	const url = new URL(
		root?.dataset.dashboardUrl || '/wp-admin/',
		window.location.href
	);

	url.searchParams.set( 'wpcom_playground_import', importStatus );

	if ( message ) {
		url.searchParams.set(
			'wpcom_playground_import_message',
			message.slice( 0, 300 )
		);
	}

	return url.toString();
};

const redirectToDashboard = ( importStatus, message = '' ) => {
	setImportLoaderMessage( 'Opening WordPress admin...' );
	window.location.assign( getImportRedirectUrl( importStatus, message ) );
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

	if (
		! window.confirm(
			'Are you sure?  Import this Playground .zip into this site?'
		)
	) {
		return;
	}

	try {
		setImportButtonBusy( true );
		showImportLoader( 'Creating Playground wp-content archive...' );
		setStatus( 'Creating Playground wp-content archive...', 'busy' );
		setImportResult( null );

		let zipData;
		if ( useLocalZipWpContent ) {
			const { zipWpContent: localZipWpContent } = await import(
				'./zip-wp-content.js'
			);
			zipData = await localZipWpContent(
				playgroundClient,
				wpContentFilesExcludedFromExport
			);
		} else {
			zipData = await cdnZipWpContent( playgroundClient );
		}

		setStatus( 'Saving Playground archive in the Media Library...', 'busy' );
		setImportLoaderMessage( 'Saving Playground archive in the Media Library...' );
		const attachment = await uploadWpContentZip( zipData );

		setImportResult( attachment );
		setStatus( 'Importing Playground archive...', 'busy' );
		setImportLoaderMessage( 'Importing Playground archive...' );
		attachment.importResult = await importUploadedWpContentZip( attachment );

		redirectToDashboard( 'success' );
	} catch ( error ) {
		window.console.error( error );
		const message =
			error.message ||
			'The Playground archive could not be saved or imported.';
		setStatus(
			message,
			'error'
		);
		redirectToDashboard( 'error', message );
	} finally {
		setImportButtonBusy( false );
	}
};

const startPlayground = async () => {
	if ( ! root || ! iframe ) {
		return;
	}

	/*const blueprint = {
		steps: [
			{
				step: "runPHP",
				code: "<?php require_once '/wordpress/wp-load.php'; $page_args = array( 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'Hello WPCOM', 'post_content' => '<p>Hello World</p>', ); wp_insert_post( $page_args, true );",
			}
		]
	}*/
	const blueprint = JSON.parse( `{
  "steps": [
    {
      "step": "login",
      "username": "admin"
    },
    {
      "step": "runPHP",
      "code": "<?php require_once '/wordpress/wp-load.php'; $page_args = array( 'post_type'    => 'post', 'post_status'  => 'publish', 'post_title'   => 'Hello WPCOM', 'post_content' => '<p>Hello World</p>', ); $page_id = wp_insert_post( $page_args, true ); if ( is_wp_error( $page_id ) ) { error_log( 'addPost error: ' . $page_id->get_error_message() ); }",
      "progress": {
        "caption": "addPost: Hello WPCOM"
      }
    },
    {
      "step": "installTheme",
      "themeData": {
        "resource": "wordpress.org/themes",
        "slug": "pendant"
      },
      "options": {
        "activate": true
      },
      "progress": {
        "caption": "Installing theme: pendant"
      }
    }
  ],
  "$schema": "https://playground.wordpress.net/blueprint-schema.json",
  "meta": {
    "title": "Theme (pendant) + addPost",
    "author": "https://github.com/akirk/playground-step-library"
  }
}` );

	try {
		playgroundClient = await startPlaygroundWeb( {
			iframe,
			blueprint: blueprint,
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
