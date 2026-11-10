// Renders icon.svg and banner.html to PNG for the wordpress.org listing.
//   NODE_PATH=<plugin>/node_modules node assets/wporg-src/render.mjs
// Needs Playwright's Chromium (npx playwright install chromium) and ImageMagick's `convert` for the half-size files.
import { createRequire } from 'node:module';
import { execFileSync } from 'node:child_process';
import { mkdirSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

const require = createRequire( import.meta.url );
const { chromium } = require( '@playwright/test' );
const here = dirname( fileURLToPath( import.meta.url ) );
const out = join( here, '..', '..', '.wordpress-org' );
mkdirSync( out, { recursive: true } );

const browser = await chromium.launch();
const page = await browser.newPage( { viewport: { width: 1544, height: 500 } } );
await page.goto( pathToFileURL( join( here, 'banner.html' ) ).href );
await page.screenshot( { path: join( out, 'banner-1544x500.png' ) } );
await page.setViewportSize( { width: 256, height: 256 } );
await page.goto( pathToFileURL( join( here, 'icon.svg' ) ).href );
await page.screenshot( { path: join( out, 'icon-256x256.png' ), omitBackground: true } );
await browser.close();

execFileSync( 'convert', [ join( out, 'banner-1544x500.png' ), '-resize', '772x250', join( out, 'banner-772x250.png' ) ] );
execFileSync( 'convert', [ join( out, 'icon-256x256.png' ), '-resize', '128x128', join( out, 'icon-128x128.png' ) ] );
console.log( 'Rendered banner and icon into .wordpress-org/' );
