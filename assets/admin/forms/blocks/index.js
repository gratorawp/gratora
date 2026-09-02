import { addAction } from '@wordpress/hooks';
import { dispatch, select } from '@wordpress/data';
import { __ } from '@wordpress/i18n';

import registerDonationAmountBlock  from './donation-amount';
import registerPaymentGatewaysBlock from './payment-gateways';
import registerSubmitButtonBlock    from './submit-button';
import registerHeadingBlock         from './heading';
import registerParagraphBlock       from './paragraph';
import registerDividerBlock         from './divider';
import registerCommentBlock         from './comment';
import registerAnonymousToggleBlock from './anonymous-toggle';
import registerNameBlock            from './name';
import registerEmailBlock           from './email';
import registerCountryBlock         from './country';
import registerPhoneBlock           from './phone';
import registerRowBlock             from './row';
import registerColumnsBlock         from './columns';
import registerCoverFeesBlock       from './cover-fees';
import registerCurrencySwitcherBlock from './currency-switcher';
import registerGoalBlock            from './goal';
import registerFundPickerBlock      from './fund-picker';
import registerAddressBlock         from './address';
import registerConsentBlock         from './consent';
import registerTermsBlock           from './terms';
import registerDonationSummaryBlock from './donation-summary';
import registerDropdownBlock        from './dropdown';
import registerRadioBlock           from './radio';
import registerCheckboxBlock        from './checkbox';
import registerMultiSelectBlock     from './multi-select';
import registerDateBlock            from './date';
import registerTextInputBlock       from './text-input';
import registerNumberInputBlock     from './number-input';
import registerRecurringToggleBlock from './recurring-toggle';
import registerSectionBlock         from './section';
import registerStepsBlock           from './steps';
import registerStepBlock            from './step';
import registerHiddenBlock          from './hidden';
import registerHtmlBlock            from './html';
import registerPrivacyNoticeBlock   from './privacy-notice';

const FUNDKIT_CATEGORIES = [
    { slug: 'fundkit-amount',  title: __( 'Donation amount',   'fundraising-toolkit' ) },
    { slug: 'fundkit-donor',   title: __( 'Donor information', 'fundraising-toolkit' ) },
    { slug: 'fundkit-fields',  title: __( 'Custom fields',     'fundraising-toolkit' ) },
    { slug: 'fundkit-content', title: __( 'Content & layout',  'fundraising-toolkit' ) },
    { slug: 'fundkit-extras',  title: __( 'Extras',            'fundraising-toolkit' ) },
];

function ensureCategories() {
    try {
        const existing = select( 'core/blocks' ).getCategories();
        const keep = existing.filter( ( c ) => ! String( c.slug ).startsWith( 'fundkit-' ) );
        dispatch( 'core/blocks' ).setCategories( [ ...FUNDKIT_CATEGORIES, ...keep ] );
    } catch ( err ) {
        // setCategories not available on this version; harmless.
    }
}

addAction( 'fundkit.editor.registerBlocks', 'fundkit/core-blocks', ( api ) => {
    ensureCategories();
    registerHeadingBlock( api );
    registerParagraphBlock( api );
    registerDividerBlock( api );
    registerRowBlock( api );
    registerColumnsBlock( api );
    registerGoalBlock( api );
    registerDonationAmountBlock( api );
    registerPaymentGatewaysBlock( api );
    registerCurrencySwitcherBlock( api );
    registerNameBlock( api );
    registerEmailBlock( api );
    registerCountryBlock( api );
    registerPhoneBlock( api );
    registerCommentBlock( api );
    registerCoverFeesBlock( api );
    registerFundPickerBlock( api );
    registerAddressBlock( api );
    registerConsentBlock( api );
    registerTermsBlock( api );
    registerDonationSummaryBlock( api );
    registerPrivacyNoticeBlock( api );
    registerAnonymousToggleBlock( api );
    registerSubmitButtonBlock( api );
    registerDropdownBlock( api );
    registerRadioBlock( api );
    registerCheckboxBlock( api );
    registerMultiSelectBlock( api );
    registerDateBlock( api );
    registerTextInputBlock( api );
    registerNumberInputBlock( api );
    registerRecurringToggleBlock( api );
    registerSectionBlock( api );
    // Step must be registered before Steps: the parent template instantiates
    // one on insertion.
    registerStepBlock( api );
    registerStepsBlock( api );
    registerHiddenBlock( api );
    registerHtmlBlock( api );
} );
