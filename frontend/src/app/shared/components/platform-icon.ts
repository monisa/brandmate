import { Component, input } from '@angular/core';
import { Platform } from '../../core/models';

/** Small glyph that identifies a platform. Decorative: always pair it with the platform name. */
@Component({
  selector: 'app-platform-icon',
  host: { class: 'pi', '[attr.data-platform]': 'platform()', 'aria-hidden': 'true' },
  template: `
    <svg viewBox="0 0 24 24" focusable="false">
      @switch (platform()) {
        @case ('instagram') {
          <rect x="3" y="3" width="18" height="18" rx="5" fill="none" stroke="currentColor" stroke-width="2" />
          <circle cx="12" cy="12" r="4" fill="none" stroke="currentColor" stroke-width="2" />
          <circle cx="17.3" cy="6.7" r="1.2" fill="currentColor" />
        }
        @case ('youtube') {
          <rect x="2" y="5" width="20" height="14" rx="4" fill="currentColor" />
          <path d="M10 9v6l5-3z" fill="#fff" />
        }
        @case ('facebook') {
          <circle cx="12" cy="12" r="10" fill="currentColor" />
          <path d="M13.2 19v-6h2l.3-2.4h-2.3V9.2c0-.7.2-1.2 1.2-1.2h1.2V5.9c-.2 0-1-.1-1.8-.1-1.8 0-3 1.1-3 3.1v1.8H8.8V13h2v6z" fill="#fff" />
        }
      }
    </svg>
  `,
})
export class PlatformIcon {
  readonly platform = input.required<Platform>();
}
