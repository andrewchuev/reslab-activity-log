/**
 * Parses ../../readme.txt (the WordPress.org readme) at build time so the
 * site never carries a second, hand-maintained copy of the plugin's
 * description, FAQ, changelog, or requirements. Only as much structure as
 * the templates actually need is extracted — everything else (Description,
 * Installation) is passed through as a markdown blob and rendered with the
 * `markdown` filter in the template.
 */

const fs = require( 'fs' );
const path = require( 'path' );

const RAW = fs.readFileSync( path.resolve( __dirname, '../../../readme.txt' ), 'utf8' );

function parseHeader( text ) {
	const lines = text.split( /\r?\n/ );
	const meta = {};
	let i = 0;

	// First line is "=== Plugin Name ===".
	const nameMatch = lines[ 0 ].match( /^===\s*(.+?)\s*===$/ );
	meta.name = nameMatch ? nameMatch[ 1 ] : 'Plugin';
	i = 1;

	for ( ; i < lines.length; i++ ) {
		const line = lines[ i ];
		if ( line.trim() === '' ) {
			continue;
		}
		const fieldMatch = line.match( /^([A-Za-z ]+):\s*(.*)$/ );
		if ( ! fieldMatch ) {
			break;
		}
		const key = fieldMatch[ 1 ].trim().toLowerCase().replace( /\s+/g, '_' );
		meta[ key ] = fieldMatch[ 2 ].trim();
	}

	// Remaining lines up to the first "== Section ==" are the short description.
	const rest = lines.slice( i ).join( '\n' );
	const shortDescMatch = rest.match( /^([\s\S]*?)(?=\n==\s)/ );
	meta.short_description = ( shortDescMatch ? shortDescMatch[ 1 ] : rest ).trim();

	return meta;
}

/** @return {Object<string,string>} section title (trimmed) => raw body text */
function splitTopSections( text ) {
	const sections = {};
	const re = /^==\s*(.+?)\s*==\s*$/gm;
	const matches = [ ...text.matchAll( re ) ];

	for ( let i = 0; i < matches.length; i++ ) {
		const title = matches[ i ][ 1 ].trim();
		const start = matches[ i ].index + matches[ i ][ 0 ].length;
		const end = i + 1 < matches.length ? matches[ i + 1 ].index : text.length;
		sections[ title ] = text.slice( start, end ).trim();
	}

	return sections;
}

/** @return {Array<{title: string, body: string}>} in source order */
function splitSubSections( text ) {
	const re = /^=\s*(.+?)\s*=\s*$/gm;
	const matches = [ ...text.matchAll( re ) ];
	const items = [];

	for ( let i = 0; i < matches.length; i++ ) {
		const title = matches[ i ][ 1 ].trim();
		const start = matches[ i ].index + matches[ i ][ 0 ].length;
		const end = i + 1 < matches.length ? matches[ i + 1 ].index : text.length;
		items.push( { title, body: text.slice( start, end ).trim() } );
	}

	return items;
}

/**
 * The Description section is organised as `**Bold Heading**` blocks (Core
 * tracking, WooCommerce integration, Security alerts, Privacy & GDPR, ...).
 * Splitting on that same convention gives the site's feature cards and
 * Configuration page structured sections instead of one long blob, without
 * inventing a heading scheme the readme doesn't already use.
 *
 * @return {Array<{title: string, body: string}>} in source order; the intro
 *   paragraph before the first bold heading is returned with title: null.
 */
function splitBoldSections( text ) {
	// Some headings carry a trailing annotation on the same line, e.g.
	// "**WooCommerce integration** *(requires WooCommerce)*" — captured
	// separately (group 2) and folded into the title rather than dropped.
	const re = /^\*\*(.+?)\*\*(.*)$/gm;
	const matches = [ ...text.matchAll( re ) ];
	const items = [];

	if ( matches.length && matches[ 0 ].index > 0 ) {
		items.push( { title: null, body: text.slice( 0, matches[ 0 ].index ).trim() } );
	}

	for ( let i = 0; i < matches.length; i++ ) {
		const annotation = matches[ i ][ 2 ].replace( /\*/g, '' ).trim();
		const title = matches[ i ][ 1 ].trim() + ( annotation ? ` ${ annotation }` : '' );
		const start = matches[ i ].index + matches[ i ][ 0 ].length;
		const end = i + 1 < matches.length ? matches[ i + 1 ].index : text.length;
		items.push( { title, body: text.slice( start, end ).trim() } );
	}

	return items;
}

const header = parseHeader( RAW );
const sections = splitTopSections( RAW );

const faq = splitSubSections( sections[ 'Frequently Asked Questions' ] || '' ).map(
	( { title, body } ) => ( { question: title, answer: body } )
);

const changelog = splitSubSections( sections.Changelog || '' ).map( ( { title, body } ) => ( {
	version: title,
	items: body
		.split( /\r?\n/ )
		.filter( ( line ) => line.trim().startsWith( '*' ) )
		.map( ( line ) => line.replace( /^\*\s*/, '' ).trim() ),
} ) );

const upgradeNotice = splitSubSections( sections[ 'Upgrade Notice' ] || '' ).map(
	( { title, body } ) => ( { version: title, note: body } )
);

const descriptionSections = splitBoldSections( sections.Description || '' );

module.exports = {
	...header,
	description: sections.Description || '',
	descriptionIntro: ( descriptionSections.find( ( s ) => s.title === null ) || {} ).body || '',
	descriptionSections: descriptionSections.filter( ( s ) => s.title !== null ),
	installation: sections.Installation || '',
	faq,
	changelog,
	upgradeNotice,
	latestVersion: changelog[ 0 ] || null,
};
