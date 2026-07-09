const wpContentFilesExcludedFromExport = [
	'db.php',
	'mu-plugins',
];

const joinPaths = ( ...paths ) =>
	paths
		.filter( Boolean )
		.join( '/' )
		.replace( /\/+/g, '/' );

const phpJson = ( value ) =>
	`json_decode(${ JSON.stringify( JSON.stringify( value ) ) }, true)`;

const normalizeWpContentExcludePath = ( path, documentRoot ) => {
	if ( 'string' !== typeof path ) {
		return '';
	}

	const wpContentPath = `${ documentRoot.replace( /\/+$/, '' ) }/wp-content/`;
	let normalized = path.replace( /\\/g, '/' ).replace( /\/+$/, '' );

	if ( normalized.startsWith( wpContentPath ) ) {
		normalized = normalized.slice( wpContentPath.length );
	}

	return normalized
		.replace( /^\/+/, '' )
		.replace( /^wordpress\/wp-content\//, '' )
		.replace( /^wp-content\//, '' );
};

const zipFunctions = `<?php

function zipDir($root, $output, $options = array())
{
	$root = rtrim($root, '/');
	$additionalPaths = array_key_exists('additional_paths', $options) ? $options['additional_paths'] : array();
	$excludePaths = array_key_exists('exclude_paths', $options) ? $options['exclude_paths'] : array();
	$zip_root = array_key_exists('zip_root', $options) ? $options['zip_root'] : $root;

	$zip = new ZipArchive;
	$res = $zip->open($output, ZipArchive::CREATE);
	if ($res === TRUE) {
		$directories = array(
			$root . '/'
		);
		while (sizeof($directories)) {
			$current_dir = array_pop($directories);

			if ($handle = opendir($current_dir)) {
				while (false !== ($entry = readdir($handle))) {
					if ($entry == '.' || $entry == '..') {
						continue;
					}

					$entry = join_paths($current_dir, $entry);
					if (in_array($entry, $excludePaths)) {
						continue;
					}

					if (is_dir($entry)) {
						$directory_path = $entry . '/';
						array_push($directories, $directory_path);
					} else if (is_file($entry)) {
						$zip->addFile($entry, ltrim(substr($entry, strlen($zip_root)), '/'));
					}
				}
				closedir($handle);
			}
		}
		foreach ($additionalPaths as $disk_path => $zip_path) {
			$zip->addFile($disk_path, $zip_path);
		}
		$zip->close();
		chmod($output, 0777);
	}
}

function join_paths()
{
	$paths = array();

	foreach (func_get_args() as $arg) {
		if ($arg !== '') {
			$paths[] = $arg;
		}
	}

	return preg_replace('#/+#', '/', join('/', $paths));
}
`;

export const zipWpContent = async ( playground, extraExcludedPaths = [] ) => {
	const zipPath = '/tmp/wordpress-playground.zip';
	const manifestPath = '/tmp/playground-export.json';

	const documentRoot = await playground.documentRoot;
	const wpContentPath = joinPaths( documentRoot, 'wp-content' );
	const siteUrl = await playground.absoluteUrl;

	await playground.writeFile(
		manifestPath,
		new TextEncoder().encode( JSON.stringify( { siteUrl } ) )
	);

	const relativeExcludedPaths = [
		...wpContentFilesExcludedFromExport,
		...extraExcludedPaths,
	]
		.map( ( path ) => normalizeWpContentExcludePath( path, documentRoot ) )
		.filter( Boolean );

	const absoluteExcludedPaths = [ ...new Set( relativeExcludedPaths ) ].map(
		( path ) => joinPaths( documentRoot, 'wp-content', path )
	);

	await playground.run( {
		code:
			zipFunctions +
			`
zipDir(${ phpJson( wpContentPath ) }, ${ phpJson( zipPath ) }, array(
	'exclude_paths' => ${ phpJson( absoluteExcludedPaths ) },
	'zip_root' => ${ phpJson( documentRoot ) },
	'additional_paths' => ${ phpJson( {
		[ manifestPath ]: 'playground-export.json',
	} ) }
));
`,
	} );

	const fileBuffer = await playground.readFileAsBuffer( zipPath );
	await playground.unlink( zipPath );
	await playground.unlink( manifestPath );

	return fileBuffer;
};
