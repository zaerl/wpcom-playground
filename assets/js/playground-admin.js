import { startPlaygroundWeb } from 'https://playground.wordpress.net/client/index.js';

const root = document.getElementById( 'wpcom-playground-admin' );
const iframe = document.getElementById( 'wpcom-playground-iframe' );
const status = document.getElementById( 'wpcom-playground-status' );

const setStatus = ( message, state ) => {
	if ( status ) {
		status.textContent = message;
		status.dataset.state = state;
	}
};

const startPlayground = async () => {
	if ( ! root || ! iframe ) {
		return;
	}

	try {
		const client = await startPlaygroundWeb( {
			iframe,
			remoteUrl:
				root.dataset.remoteUrl ||
				'https://playground.wordpress.net/remote.html',
		} );

		root.playgroundClient = client;
		setStatus( 'WordPress Playground is ready.', 'ready' );
	} catch ( error ) {
		window.console.error( error );
		setStatus(
			'WordPress Playground could not be started. Check the browser console for details.',
			'error'
		);
	}
};

startPlayground();
