import { Injectable, signal } from '@angular/core';

const KEY = 'influencer.token';

/**
 * Keeps the API token. localStorage survives a reload but is readable by any
 * script on the page, so the app must stay free of XSS: never use innerHTML
 * with API data, and keep a strict Content-Security-Policy in production.
 */
@Injectable({ providedIn: 'root' })
export class TokenStore {
  readonly token = signal<string | null>(this.read());

  set(token: string): void {
    this.token.set(token);
    try {
      localStorage.setItem(KEY, token);
    } catch {
      // Private mode: the session lasts until the tab closes.
    }
  }

  clear(): void {
    this.token.set(null);
    try {
      localStorage.removeItem(KEY);
    } catch {
      // Nothing stored.
    }
  }

  private read(): string | null {
    try {
      return localStorage.getItem(KEY);
    } catch {
      return null;
    }
  }
}
