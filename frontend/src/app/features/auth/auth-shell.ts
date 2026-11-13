import { Component, inject, input } from '@angular/core';
import { environment } from '../../../environments/environment';
import { I18n } from '../../core/i18n/i18n';
import { LanguageSwitcher } from '../../shared/components/language-switcher';
import { Logo } from '../../shared/components/logo';
import { PlatformIcon } from '../../shared/components/platform-icon';
import { TPipe } from '../../core/i18n/t.pipe';

/** Two-column frame shared by every signed-out page: product showcase on the left, form on the right. */
@Component({
  selector: 'app-auth-shell',
  imports: [TPipe, LanguageSwitcher, Logo, PlatformIcon],
  template: `
    <div class="auth">
      <aside class="auth__brand">
        <p class="auth__logo"><app-logo /></p>
        <div class="auth__story">
          <p class="auth__pitch">
            @if (i18n.lang() === 'en') {
              Discover. Compare. <span class="auth__accent">Collaborate.</span>
            } @else {
              {{ 'Discover. Compare. Collaborate.' | t }}
            }
          </p>
          <p class="auth__detail">
            {{ 'Brands search and compare creators on Instagram, YouTube and Facebook. Creators list their accounts and packages and get found.' | t }}
          </p>

          <!-- Decorative picture of the product: a fan of creator cards. -->
          <div class="showcase" aria-hidden="true">
            <div class="showcase__card showcase__card--left">
              <img src="https://randomuser.me/api/portraits/women/44.jpg" alt="" loading="lazy" referrerpolicy="no-referrer" />
              <span class="showcase__chip showcase__chip--platform"><app-platform-icon platform="youtube" /> 412K</span>
            </div>
            <div class="showcase__card showcase__card--right">
              <img src="https://randomuser.me/api/portraits/men/32.jpg" alt="" loading="lazy" referrerpolicy="no-referrer" />
              <span class="showcase__chip showcase__chip--platform"><app-platform-icon platform="facebook" /> 96K</span>
            </div>
            <div class="showcase__card showcase__card--main">
              <img src="https://randomuser.me/api/portraits/women/68.jpg" alt="" loading="lazy" referrerpolicy="no-referrer" />
              <div class="showcase__info">
                <strong>Meera Nair <span class="showcase__tick">✓</span></strong>
                <span>&#64;meera.cooks · Food</span>
                <div class="showcase__stats"><b>1.2M</b> followers <b>4.8%</b> engagement</div>
              </div>
              <span class="showcase__chip showcase__chip--platform"><app-platform-icon platform="instagram" /> Instagram</span>
            </div>
            <span class="showcase__bubble showcase__bubble--heart">♥</span>
            <span class="showcase__bubble showcase__bubble--rate">▲ 4.8% {{ 'engagement' | t }}</span>
            <span class="showcase__bubble showcase__bubble--saved">✓ {{ 'Added to shortlist' | t }}</span>
          </div>
        </div>
        <ul class="auth__platforms" [attr.aria-label]="'Supported platforms' | t">
          <li><app-platform-icon platform="instagram" /> {{ 'Instagram' | t }}</li>
          <li><app-platform-icon platform="youtube" /> {{ 'YouTube' | t }}</li>
          <li><app-platform-icon platform="facebook" /> {{ 'Facebook' | t }}</li>
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
  protected readonly i18n = inject(I18n);
}
