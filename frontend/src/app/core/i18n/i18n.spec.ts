import { TestBed } from '@angular/core/testing';
import { I18n } from './i18n';
import hi from './locales/hi';
import ml from './locales/ml';
import ta from './locales/ta';

describe('I18n', () => {
  let i18n: I18n;

  beforeEach(() => {
    localStorage.clear();
    i18n = TestBed.inject(I18n);
  });

  it('returns the English text until a language is chosen', () => {
    expect(i18n.t('Sign in')).toBe('Sign in');
    expect(i18n.t('Page {current} of {last}', { current: 2, last: 9 })).toBe('Page 2 of 9');
  });

  it('translates and fills in values after switching language', async () => {
    await i18n.use('hi');
    expect(i18n.lang()).toBe('hi');
    expect(i18n.t('Sign in')).toBe(hi['Sign in']);
    expect(i18n.t('Hello, {name}', { name: 'Monisa' })).toContain('Monisa');
    expect(document.documentElement.lang).toBe('hi');
  });

  it('falls back to English for a text that has no translation', async () => {
    await i18n.use('ta');
    expect(i18n.t('A sentence nobody translated yet.')).toBe('A sentence nobody translated yet.');
  });

  it('remembers the chosen language for the next visit', async () => {
    await i18n.use('ml');
    const next = TestBed.runInInjectionContext(() => new I18n());
    await next.init();
    expect(next.lang()).toBe('ml');
  });

  it('keeps the three languages in step: same entries, same placeholders', () => {
    const placeholders = (text: string) => (text.match(/\{\w+\}/g) ?? []).sort().join();
    for (const dictionary of [ta, ml]) {
      expect(Object.keys(dictionary).sort()).toEqual(Object.keys(hi).sort());
    }
    for (const dictionary of [hi, ta, ml]) {
      for (const [english, translated] of Object.entries(dictionary)) {
        expect(placeholders(translated)).toBe(placeholders(english));
      }
    }
  });
});
