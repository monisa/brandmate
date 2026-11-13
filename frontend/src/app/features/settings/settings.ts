import { Component, inject } from '@angular/core';
import { RouterLink, RouterLinkActive, RouterOutlet } from '@angular/router';
import { AuthService } from '../../core/auth/auth.service';
import { TPipe } from '../../core/i18n/t.pipe';

@Component({
  selector: 'app-settings',
  imports: [TPipe, RouterOutlet, RouterLink, RouterLinkActive],
  template: `
    <header class="page-head">
      <h1>{{ 'Settings' | t }}</h1>
    </header>
    <nav class="tabs" [attr.aria-label]="'Settings sections' | t">
      <a routerLink="profile" routerLinkActive="is-active">{{ 'Profile' | t }}</a>
      <a routerLink="security" routerLinkActive="is-active">{{ 'Security' | t }}</a>
      @if (auth.user()?.company) {
        <a routerLink="company" routerLinkActive="is-active">{{ 'Company' | t }}</a>
      }
    </nav>
    <router-outlet />
  `,
})
export class Settings {
  protected readonly auth = inject(AuthService);
}
