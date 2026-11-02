// Writes assets/blueprints/blueprint.json from the readable demo source in this folder.
//   node assets/wporg-src/build-blueprint.mjs
// Not part of the plugin ZIP. The release workflow swaps the plugin URL for the tag's ZIP at release time.
import { readFileSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname( fileURLToPath( import.meta.url ) );
const code = readFileSync( join( here, 'demo-setup.php' ), 'utf8' );

const blueprint = {
	$schema: 'https://playground.wordpress.net/blueprint-schema.json',
	meta: {
		title: 'SwiftForms demo',
		author: 'namithj',
		description: 'A block-editor contact form on Twenty Twenty-Five, with sample entries.',
		categories: [ 'Forms' ],
	},
	landingPage: '/wp-admin/post.php?post=100&action=edit',
	preferredVersions: { php: '8.3', wp: 'latest' },
	steps: [
		{ step: 'login', username: 'admin', password: 'password' },
		{
			step: 'installPlugin',
			pluginData: {
				resource: 'url',
				url: 'https://github.com/namithj/swiftforms/releases/latest/download/swiftforms.zip',
			},
			options: { activate: true },
		},
		{ step: 'activateTheme', themeFolderName: 'twentytwentyfive' },
		{ step: 'setSiteOptions', options: { blogname: 'SwiftForms demo' } },
		{ step: 'runPHP', code },
	],
};

writeFileSync( join( here, '..', 'blueprints', 'blueprint.json' ), JSON.stringify( blueprint, null, 2 ) + '\n' );
console.log( 'Wrote assets/blueprints/blueprint.json' );
