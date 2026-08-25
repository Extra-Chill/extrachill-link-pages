const fs = require( 'fs' );
const path = require( 'path' );
const assert = require( 'assert' );

const root = path.resolve( __dirname, '../..' );
const source = fs.readFileSync(
	path.join( root, 'src/editor/index.js' ),
	'utf8'
);
const block = JSON.parse(
	fs.readFileSync( path.join( root, 'src/editor/block.json' ), 'utf8' )
);

assert.strictEqual( block.name, 'extrachill/link-page-editor' );
assert.match(
	source,
	/window\.ExtraChillLinkPageEditor = \{ mount, normalizeDocument \}/
);
assert.match( source, /adapter\.read/ );
assert.match( source, /adapter\.save/ );
assert.doesNotMatch( source, /artist|venue|promoter/i );
console.log( 'editor contract tests passed' );
