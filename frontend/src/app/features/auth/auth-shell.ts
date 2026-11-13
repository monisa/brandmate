import { Component, input } from '@angular/core';
import { environment } from '../../../environments/environment';
import { LanguageSwitcher } from '../../shared/components/language-switcher';
import { TPipe } from '../../core/i18n/t.pipe';
import { Logo } from '../../shared/components/logo';

/** Two-column frame shared by every signed-out page. */
@Component({
  selector: 'app-auth-shell',
  imports: [TPipe, LanguageSwitcher, Logo],
  template: `
    <div class="auth">
      <aside class="auth__brand">
        <p class="auth__logo"><app-logo /></p>
        <div>
          <p class="auth__pitch">{{ 'Discover. Compare. Collaborate.' | t }}</p>
          <p class="auth__detail">
            {{ 'Brands search and compare creators on Instagram, YouTube and Facebook. Creators list their accounts and packages and get found.' | t }}
          </p>
        </div>
        <ul class="auth__platforms" [attr.aria-label]="'Supported platforms' | t">
          <li>{{ 'Instagram' | t }}</li>
          <li>{{ 'YouTube' | t }}</li>
          <li>{{ 'Facebook' | t }}</li>
        </ul>
      </aside>
      <main class="auth__main">
        <div class="auth__panel">
          <app-language-switcher class="auth__language" />
          <h1>{{ heading() }}</h1>
          @if (intro()) {
            <p class="muted auth__intro">{{ intro() }}</p>
          }
          <ng-content />
        </div>
      </main>
    </div>
  `,
})
export class AuthShell {
  readonly heading = input.required<string>();
  readonly intro = input('');
  protected readonly appName = environment.appName;
}
