import { Component, computed, effect, input, signal } from '@angular/core';
import { Platform } from '../../core/models';

/** Warm, readable gradients; one is picked per name so a person always gets the same one. */
const GRADIENTS = [
  ['#f9a8d4', '#c8245c'],
  ['#fcd34d', '#f97316'],
  ['#a5b4fc', '#6d28d9'],
  ['#86efac', '#0f766e'],
  ['#7dd3fc', '#1d4ed8'],
  ['#fda4af', '#be123c'],
  ['#c4b5fd', '#7c3aed'],
  ['#fdba74', '#c2410c'],
];

/**
 * Profile photo with a social-style ring. Falls back to coloured initials when
 * there is no photo or it fails to load, so a broken link never shows.
 *
 *   <app-influencer-avatar [name]="i.name" [src]="i.avatar_url" [platform]="i.platform" size="lg" />
 */
@Component({
  selector: 'app-influencer-avatar',
  host: {
    class: 'ia',
    '[class.ia--sm]': "size() === 'sm'",
    '[class.ia--lg]': "size() === 'lg'",
    '[class.ia--xl]': "size() === 'xl'",
    '[class.ia--ring]': 'ring()',
    '[attr.data-platform]': 'platform()',
  },
  template: `
    <span class="ia__inner">
      @if (src() && !failed()) {
        <img class="ia__img" [src]="src()" [alt]="alt()" loading="lazy" decoding="async"
             referrerpolicy="no-referrer" (error)="failed.set(true)" />
      } @else {
        <span class="ia__initials" [style.background]="gradient()" [attr.aria-label]="alt() || null"
              [attr.role]="alt() ? 'img' : null">{{ initials() }}</span>
      }
    </span>
  `,
})
export class InfluencerAvatar {
  readonly name = input.required<string>();
  readonly src = input<string | null | undefined>(null);
  readonly platform = input<Platform | null>(null);
  readonly size = input<'sm' | 'md' | 'lg' | 'xl'>('md');
  /** Gradient story ring around the photo. */
  readonly ring = input(true);
  /** Empty when the name is already shown next to the photo. */
  readonly alt = input('');

  protected readonly failed = signal(false);

  constructor() {
    // A new photo gets a fresh chance to load.
    effect(() => {
      this.src();
      this.failed.set(false);
    });
  }

  protected readonly initials = computed(() =>
    this.name()
      .split(/\s+/)
      .filter(Boolean)
      .slice(0, 2)
      .map((part) => part[0]!.toUpperCase())
      .join(''),
  );

  protected readonly gradient = computed(() => {
    let hash = 0;
    for (const char of this.name()) {
      hash = (hash * 31 + char.charCodeAt(0)) | 0;
    }
    const [from, to] = GRADIENTS[Math.abs(hash) % GRADIENTS.length];
    return `linear-gradient(135deg, ${from}, ${to})`;
  });
}
