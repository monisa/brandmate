import { Component, input } from '@angular/core';
import { environment } from '../../../environments/environment';

/** BrandMate mark and wordmark: two linked rings, a brand and a creator meeting. Same art as public/favicon.svg. */
@Component({
  selector: 'app-logo',
  host: { class: 'logo' },
  template: `
    <svg class="logo__mark" viewBox="0 0 64 64" aria-hidden="true" focusable="false">
      <defs>
        <linearGradient [attr.id]="gradientId" x1="0" y1="0" x2="1" y2="1">
          <stop offset="0" stop-color="#ff5f8f" />
          <stop offset="0.45" stop-color="#c8245c" />
          <stop offset="1" stop-color="#7c3aed" />
        </linearGradient>
      </defs>
      <rect width="64" height="64" rx="16" [attr.fill]="'url(#' + gradientId + ')'" />
      <circle cx="25" cy="32" r="12" fill="none" stroke="#fff" stroke-width="6" />
      <circle cx="39" cy="32" r="12" fill="none" stroke="#fff" stroke-width="6" stroke-opacity="0.75" />
      <circle cx="49" cy="15" r="4" fill="#fff" />
    </svg>
    @if (wordmark()) {
      <span class="logo__word">{{ name }}</span>
    }
  `,
})
export class Logo {
  private static count = 0;
  readonly wordmark = input(true);
  protected readonly name = environment.appName;
  /** Unique per instance: two logos on one page must not share a gradient id. */
  protected readonly gradientId = `logo-grad-${++Logo.count}`;
}
