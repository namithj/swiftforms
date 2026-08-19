/**
 * @jest-environment jsdom
 */

import {
	applyServerErrors,
	clearFieldErrors,
	collectFields,
	initForm,
	refreshSecurityFields,
} from '../../src/blocks/form/view';

const formMarkup = () => `
	<form data-swf-form>
		<p data-swf-script-required>JavaScript is required.</p>
		<div data-swf-status></div>
		<div class="swf-form__fields">
			<section data-swf-step>
				<div data-swf-field data-field-slug="name" data-field-type="text" data-field-required="1">
					<input id="name" required>
					<div id="name-error" data-swf-field-error></div>
				</div>
			</section>
			<section data-swf-step>
				<div data-swf-field data-field-slug="choice" data-field-type="radio" data-field-required="1">
					<div role="radiogroup" aria-labelledby="choice-label" aria-describedby="choice-help" aria-required="true">
						<input type="radio" name="choice" value="yes" required>
						<input type="radio" name="choice" value="no" required>
					</div>
					<p id="choice-help">Choose one.</p>
					<div id="choice-error" data-swf-field-error></div>
				</div>
			</section>
		</div>
		<div class="swf-form__actions"><button class="swf-form__submit" disabled>Submit</button></div>
	</form>`;

beforeAll( () => {
	window.HTMLElement.prototype.scrollIntoView = jest.fn();
} );

beforeEach( () => {
	document.body.innerHTML = formMarkup();
} );

test( 'initialization is idempotent and server errors reveal the field step', () => {
	const form = document.querySelector( 'form' );
	initForm( form );
	initForm( form );

	expect( form.querySelectorAll( '.swf-step-progress' ) ).toHaveLength( 1 );
	expect( form.querySelectorAll( '.swf-step-nav__next' ) ).toHaveLength( 1 );
	expect( form.querySelector( '[data-swf-script-required]' ).hidden ).toBe(
		true
	);
	expect( form.querySelector( '.swf-form__submit' ).disabled ).toBe( false );

	const fields = collectFields( form );
	applyServerErrors(
		form,
		fields,
		{ choice: 'Choose one option.' },
		'Please correct one error.'
	);

	const steps = form.querySelectorAll( '[data-swf-step]' );
	const group = form.querySelector( '[role="radiogroup"]' );
	expect( steps[ 0 ].hidden ).toBe( true );
	expect( steps[ 1 ].hidden ).toBe( false );
	expect( group.getAttribute( 'aria-invalid' ) ).toBe( 'true' );
	expect( group.getAttribute( 'aria-describedby' ) ).toBe(
		'choice-help choice-error'
	);
	expect( form.querySelector( '[data-swf-status]' ).textContent ).toBe(
		'Please correct one error.'
	);
	expect( document.activeElement ).toBe( group.querySelector( 'input' ) );

	clearFieldErrors( form );
	expect( group.hasAttribute( 'aria-invalid' ) ).toBe( false );
	expect( group.getAttribute( 'aria-describedby' ) ).toBe( 'choice-help' );
} );

test( 'a dynamically inserted form initializes immediately and only once', async () => {
	document.body.innerHTML = '';
	document.body.insertAdjacentHTML( 'beforeend', formMarkup() );
	await Promise.resolve();

	const form = document.querySelector( 'form' );
	expect( form.dataset.swfStepsInit ).toBe( '1' );
	expect( form.querySelectorAll( '.swf-step-progress' ) ).toHaveLength( 1 );
} );

test( 'refreshes cached-page security fields and replaces a consumed challenge', async () => {
	const form = document.querySelector( 'form' );
	form.dataset.formId = '42';
	form.insertAdjacentHTML(
		'beforeend',
		'<input name="nonce" value="old"><input name="render_ts" value="old"><div class="swf-form__captcha"><label data-swf-captcha-label>Old</label><input name="captcha_answer" value="5"><input name="captcha_token" value="old"></div>'
	);
	global.fetch = jest.fn().mockResolvedValue( {
		ok: true,
		json: async () => ( {
			nonce: 'new-nonce',
			render_ts: 'new-time',
			captcha: { token: 'new-token', question: 'What is 7 + 8?' },
		} ),
	} );

	await refreshSecurityFields( form, { challengeUrl: '/challenge' } );

	expect( fetch ).toHaveBeenCalledWith( '/challenge/42', {
		credentials: 'same-origin',
	} );
	expect( form.querySelector( '[name="nonce"]' ).value ).toBe( 'new-nonce' );
	expect( form.querySelector( '[name="captcha_token"]' ).value ).toBe(
		'new-token'
	);
	expect( form.querySelector( '[name="captcha_answer"]' ).value ).toBe( '' );
	expect( form.querySelector( '[data-swf-captcha-label]' ).textContent ).toBe(
		'What is 7 + 8?'
	);
} );
