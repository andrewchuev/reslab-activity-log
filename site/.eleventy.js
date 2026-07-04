const markdownIt = require( 'markdown-it' );

module.exports = function ( eleventyConfig ) {
	eleventyConfig.setLibrary(
		'md',
		markdownIt( { html: true, breaks: false, linkify: true } )
	);

	eleventyConfig.addPassthroughCopy( { '../.wordpress-org': 'assets/wp-org' } );
	eleventyConfig.addPassthroughCopy( 'src/assets/css/tailwind.css' );

	const md = markdownIt( { html: true, linkify: true } );

	// Block-level: for content with headings/lists/paragraphs (readme sections).
	eleventyConfig.addFilter( 'markdown', function ( value ) {
		return md.render( value || '' );
	} );

	// Inline-only: for single-line content (changelog bullets, FAQ answers)
	// where wrapping in a <p> would add unwanted block margin inside a <li>.
	eleventyConfig.addFilter( 'markdownInline', function ( value ) {
		return md.renderInline( value || '' );
	} );

	eleventyConfig.addShortcode( 'currentYear', () => `${ new Date().getFullYear() }` );

	return {
		dir: {
			input: 'src',
			output: '_site',
			includes: '_includes',
			data: '_data',
		},
	};
};
