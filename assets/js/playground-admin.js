import {
	startPlaygroundAPI,
	startPlaygroundWeb,
} from 'https://pr4095.pg.ashfame.com/client/index.js';

const playgroundScope = 'wpcom-playground-import-source';

const root = document.getElementById( 'wpcom-playground-admin' );
const iframe = document.getElementById( 'wpcom-playground-iframe' );
const importButton = document.getElementById( 'wpcom-playground-import' );
const importResult = document.getElementById( 'wpcom-playground-import-result' );
const importLoader = document.getElementById( 'wpcom-playground-import-loader' );
const importLoaderMessage = document.getElementById(
	'wpcom-playground-import-loader-message'
);
const status = document.getElementById( 'wpcom-playground-status' );

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

const getWpContentExportExcludePatterns = () => [
	'/*',
	'!/wp-content/',
	'!/wp-content/**',
	'/wp-content/db.php',
	'/wp-content/mu-plugins/',
	...wpContentFilesExcludedFromExport
		.filter( ( path ) => 'string' === typeof path && '' !== path )
		.map( ( path ) =>
			`/wp-content/${ path
				.replace( /\\/g, '/' )
				.replace( /^\/+/, '' )
				.replace( /^wp-content\//, '' ) }`
		),
];

let playgroundClient = null;

const setStatus = ( message, state ) => {
	if ( status ) {
		status.textContent = message;
		status.dataset.state = state;
	}
};

const getQueryParam = ( param ) => {
	const url = new URL( window.location.href );

	return url.searchParams.get( param );
};

const fetchBlueprint = async ( blueprintUrl ) => {
	const response = await window.fetch( blueprintUrl );

	if ( ! response.ok ) {
		throw new Error( 'The Playground blueprint could not be loaded.' );
	}

	return response.json();
};

const parseBlueprintHash = ( encodedBlueprint ) => {
	const hashContent = decodeURIComponent( encodedBlueprint );

	try {
		return JSON.parse( window.atob( hashContent ) );
	} catch ( error ) {
		return JSON.parse( hashContent );
	}
};

const getBlueprintFromLocation = async () => {
	const blueprintUrl = getQueryParam( 'wpcom-blueprint-url' );

	if ( blueprintUrl ) {
		return fetchBlueprint( blueprintUrl );
	}

	/*const encodedBlueprint = window.location.hash.slice( 1 );

	if ( encodedBlueprint ) {
		return parseBlueprintHash( encodedBlueprint );
	}*/

	return undefined;
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

const getImportStepMessage = ( step ) => {
	const messages = {
		unpack_file: 'Extracting the Playground archive...',
		preprocess: 'Preparing the Playground database...',
		process_files: 'Restoring Playground files...',
		recreate_database: 'Importing the Playground database...',
		postprocess_database: 'Finalizing the imported database...',
		verify_site_integrity: 'Verifying the imported site...',
		clean_up: 'Cleaning up temporary import files...',
	};

	return messages[ step ] || 'Continuing the Playground import...';
};

const runUploadedWpContentImport = async ( attachment ) => {
	let result = null;
	let requestCount = 0;

	do {
		result = await importUploadedWpContentZip( attachment );
		requestCount += 1;

		if ( ! result.done ) {
			const message = getImportStepMessage( result.nextStep );
			setStatus( message, 'busy' );
			setImportLoaderMessage( message );
		}

		if ( requestCount > 20 ) {
			throw new Error( 'The Playground import returned too many steps.' );
		}
	} while ( ! result.done );

	return result;
};

const exportSavedSiteAsZip = async () => {
	const apiIframe = document.createElement( 'iframe' );
	apiIframe.hidden = true;
	apiIframe.sandbox.add( 'allow-scripts' );
	apiIframe.sandbox.add( 'allow-same-origin' );
	document.body.appendChild( apiIframe );

	try {
		const remoteUrl = new URL(
			root.dataset.remoteUrl ||
				'https://pr4095.pg.ashfame.com/remote.html'
		);
		const apiUrl = new URL( '/api.html', remoteUrl.origin ).toString();
		const playgroundAPI = await startPlaygroundAPI( {
			iframe: apiIframe,
			apiUrl,
		} );
		const zipData = await playgroundAPI.exportSavedSiteAsZip(
			playgroundScope,
			{
				excludePatterns: getWpContentExportExcludePatterns(),
			}
		);

		if ( ! zipData ) {
			throw new Error(
				`No exportable saved Playground found for ${ playgroundScope }.`
			);
		}

		return zipData;
	} finally {
		apiIframe.remove();
	}
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

		const zipData = await exportSavedSiteAsZip();

		setStatus( 'Saving Playground archive in the Media Library...', 'busy' );
		setImportLoaderMessage( 'Saving Playground archive in the Media Library...' );
		const attachment = await uploadWpContentZip( zipData );

		setImportResult( attachment );
		setStatus( 'Importing Playground archive...', 'busy' );
		setImportLoaderMessage( 'Importing Playground archive...' );
		attachment.importResult = await runUploadedWpContentImport( attachment );

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

	try {
		let blueprint = await getBlueprintFromLocation();

		if(!blueprint) {
			blueprint = {
				steps: [{ step: 'login', username: 'admin' }]
			}
		}

		const playgroundOptions = {
			iframe,
			mounts: [
				{
					device: {
						path: `/sites/site-${ playgroundScope }`,
						type: 'opfs',
					},
					initialSyncDirection: 'opfs-to-memfs',
					mountpoint: '/wordpress',
				},
			],
			remoteUrl:
				root.dataset.remoteUrl ||
				'https://pr4095.pg.ashfame.com/remote.html',
			scope: playgroundScope,
			wordpressInstallMode: 'install-from-existing-files-if-needed',
		};

		if ( undefined !== blueprint ) {
			playgroundOptions.blueprint = blueprint;
		}

		playgroundClient = await startPlaygroundWeb( playgroundOptions );

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
