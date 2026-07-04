/**
 * Pulls specific sections out of ../../README.md (the developer-facing repo
 * readme) by their `## Heading` — these have no equivalent in readme.txt
 * (which targets end users) but are exactly what the Docs > Reference pages
 * need. Kept separate from readme.js since the two files use different
 * heading conventions (`## `/`### ` markdown vs `==`/`=`).
 */

const fs = require( 'fs' );
const path = require( 'path' );

const RAW = fs.readFileSync( path.resolve( __dirname, '../../../README.md' ), 'utf8' );

/** @return {string} raw markdown body of the named "## Heading" section, exclusive of the heading itself */
function section( heading ) {
	const re = new RegExp( `^##\\s+${ heading.replace( /[.*+?^${}()|[\]\\]/g, '\\$&' ) }\\s*$`, 'm' );
	const match = RAW.match( re );
	if ( ! match ) {
		return '';
	}
	const start = match.index + match[ 0 ].length;
	const nextHeading = RAW.slice( start ).search( /^##\s+/m );
	const end = nextHeading === -1 ? RAW.length : start + nextHeading;
	return RAW.slice( start, end ).trim();
}

module.exports = {
	hooksReference: section( 'Hooks reference' ),
	security: section( 'Security' ),
	database: section( 'Database' ),
	fileStructure: section( 'File structure' ),
};
