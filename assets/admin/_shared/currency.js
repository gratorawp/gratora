/**
 * Currency / locale reference data + helpers now live in @fundkit/ui. Re-export
 * from the subpath (not the barrel: format.js and currency.js both export
 * groupDigits with different signatures, which the barrel can't disambiguate).
 */
export * from '@fundkit/ui/utils/currency';
