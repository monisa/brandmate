import { Injectable, signal } from '@angular/core';

export type Lang = 'en' | 'hi' | 'ta' | 'ml';
type Dictionary = Record<string, string>;

const STORAGE_KEY = 'brandmate.lang';

// Each language is a separate file that is only downloaded when chosen.
const LOADERS: Record<Exclude<Lang, 'en'>, () => Promise<{ default: Dictionary }>> = {
  hi: () => import('./locales/hi'),
  ta: () => import('./locales/ta'),
  ml: () => import('./locales/ml'),
};

/**
 * Runtime translation. The English text is the key:
 *
 *   {{ 'Sign in' | t }}
 *   {{ 'Page {current} of {last}' | t: { current: 2, last: 9 } }}
 *   this.i18n.t('{name} was removed.', { name })
 *
 * A text with no translation is shown in English, so a missing entry never
 * breaks a screen. To add a language: add it to LANGUAGES and LOADERS and
 * create locales/<code>.ts.
 */
@Injectable({ providedIn: 'root' })
export class I18n {
  static readonly LANGUAGES: { code: Lang; name: string }[] = [
    { code: 'en', name: 'English' },
    { code: 'hi', name: 'हिन्दी' },
    { code: 'ta', name: 'தமிழ்' },
    { code: 'ml', name: 'മലയാളം' },
  ];

  readonly languages = I18n.LANGUAGES;
  readonly lang = signal<Lang>('en');
  private readonly dictionary = signal<Dictionary>({});

  /** Restores the language chosen on an earlier visit. Called once at startup. */
  async init(): Promise<void> {
    let stored: string | null = null;
    try {
      stored = localStorage.getItem(STORAGE_KEY);
    } catch {
      // Storage unavailable: stay in English.
    }
    if (stored && stored !== 'en' && stored in LOADERS) {
      await this.use(stored as Lang);
    }
  }

  async use(lang: Lang): Promise<void> {
    try {
      this.dictionary.set(lang === 'en' ? {} : (await LOADERS[lang]()).default);
    } catch {
      return; // the language file could not be downloaded: keep the current language
    }
    this.lang.set(lang);
    document.documentElement.lang = lang;
    try {
      localStorage.setItem(STORAGE_KEY, lang);
    } catch {
      // The choice lasts for this visit only.
    }
  }

  t(text: string | null | undefined, params?: Record<string, string | number | null | undefined>): string {
    if (!text) {
      return '';
    }
    const translated = this.dictionary()[text] ?? text;
    if (!params) {
      return translated;
    }
    return translated.replace(/\{(\w+)\}/g, (placeholder, name: string) =>
      params[name] === undefined || params[name] === null ? placeholder : String(params[name]),
    );
  }
}
