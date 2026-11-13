import { Component, computed, inject, signal } from '@angular/core';
import { RouterLink, RouterLinkActive, RouterOutlet } from '@angular/router';
import { environment } from '../../environments/environment';
import { AuthService } from '../core/auth/auth.service';
import { LanguageSwitcher } from '../shared/components/language-switcher';
import { TPipe } from '../core/i18n/t.pipe';
import { Logo } from '../shared/components/logo';

/** Sidebar and frame for every signed-in page. */
@Component({
  selector: 'app-shell',
  imports: [TPipe, LanguageSwitcher, RouterOutlet, RouterLink, RouterLinkActive, Logo],
  template: `
    <a class="skip-link" href="#main">{{ 'Skip to content' | t }}</a>
    <div class="shell">
      <header class="shell__bar">
        <app-logo class="shell__logo" />
        <button class="btn btn--quiet shell__toggle" type="button" (click)="open.set(!open())"
                [attr.aria-expanded]="open()" aria-controls="sidebar">{{ 'Menu' | t }}</button>
      </header>

      <aside class="shell__side" id="sidebar" [class.is-open]="open()">
        <p class="shell__brand"><app-logo class="shell__logo shell__logo--side" /></p>
        @if (user()?.company; as company) {
          <p class="shell__company">{{ company.name }}</p>
        }
        <nav [attr.aria-label]="'Main' | t" (click)="open.set(false)">
          @if (user()?.account_type === 'creator') {
            <a routerLink="/creator" routerLinkActive="is-active">
              <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="8" r="4" /><path d="M4 21a8 8 0 0 1 16 0" /></svg>
              {{ 'My creator profile' | t }}
            </a>
          } @else {
            <a routerLink="/dashboard" routerLinkActive="is-active">
              <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="3" width="7" height="9" rx="1.5" /><rect x="14" y="3" width="7" height="5" rx="1.5" /><rect x="14" y="12" width="7" height="9" rx="1.5" /><rect x="3" y="16" width="7" height="5" rx="1.5" /></svg>
              {{ 'Dashboard' | t }}
            </a>
          }
          @if (user()?.company) {
            @if (auth.can('influencer.search')) {
              <a routerLink="/influencers" routerLinkActive="is-active">
                <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7" /><path d="m20 20-3.5-3.5" /></svg>
                {{ 'Find influencers' | t }}
              </a>
            }
            @if (auth.can('influencer.view')) {
              <a routerLink="/shortlists" routerLinkActive="is-active">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 3h12v18l-6-4-6 4z" /></svg>
                {{ 'Shortlists' | t }}
              </a>
            }
            <a routerLink="/team" routerLinkActive="is-active">
              <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="9" cy="8" r="3.5" /><path d="M2.5 20a6.5 6.5 0 0 1 13 0" /><path d="M16 4.5a3.5 3.5 0 0 1 0 7M18 14a6.5 6.5 0 0 1 3.5 6" /></svg>
              {{ 'Team' | t }}
            </a>
          }
          <a routerLink="/settings" routerLinkActive="is-active">
            <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="3" /><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z" /></svg>
            {{ 'Settings' | t }}
          </a>
          @if (user()?.is_super_admin) {
            <a routerLink="/admin" routerLinkActive="is-active">
              <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3 4 6v6c0 4.5 3.4 8.3 8 9 4.6-.7 8-4.5 8-9V6z" /></svg>
              {{ 'Platform admin' | t }}
            </a>
          }
        </nav>
        <div class="shell__user">
          <span class="avatar" aria-hidden="true">{{ initials() }}</span>
          <div class="shell__who">
            <span class="shell__name">{{ user()?.name }}</span>
            <span class="shell__role">{{ (user()?.is_super_admin ? 'Super Admin' : user()?.account_type === 'creator' ? 'Creator' : user()?.role?.name) | t }}</span>
          </div>
          <app-language-switcher class="shell__language" />
          <button class="btn btn--onDark" type="button" (click)="auth.logout()">{{ 'Sign out' | t }}</button>
        </div>
      </aside>

      <main class="shell__main" id="main" tabindex="-1">
        <router-outlet />
      </main>
    </div>
  `,
})
export class Shell {
  protected readonly auth = inject(AuthService);
  protected readonly user = this.auth.user;
  protected readonly appName = environment.appName;
  protected readonly open = signal(false);

  protected readonly initials = computed(() =>
    (this.user()?.name ?? '')
      .split(/\s+/)
      .filter(Boolean)
      .slice(0, 2)
      .map((part) => part[0]!.toUpperCase())
      .join(''),
  );
}
