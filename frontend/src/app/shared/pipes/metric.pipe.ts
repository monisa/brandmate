import { Pipe, PipeTransform, inject } from '@angular/core';
import { I18n } from '../../core/i18n/i18n';

const compact = new Intl.NumberFormat('en', { notation: 'compact', maximumFractionDigits: 1 });
const full = new Intl.NumberFormat('en');

export const UNAVAILABLE = 'Data unavailable';

/**
 * Formats a metric, and says so plainly when the platform did not provide it.
 * A missing value is never shown as 0.
 *
 *   {{ followers | metric }}            1.2M
 *   {{ followers | metric: 'full' }}    1,204,331
 *   {{ rate | metric: 'percent' }}      4.5%
 */
@Pipe({ name: 'metric', pure: false }) // impure: "Data unavailable" follows the chosen language
export class MetricPipe implements PipeTransform {
  private readonly i18n = inject(I18n);

  transform(value: number | null | undefined, kind: 'compact' | 'full' | 'percent' = 'compact'): string {
    if (value === null || value === undefined) {
      return this.i18n.t(UNAVAILABLE);
    }
    if (kind === 'percent') {
      return `${value.toFixed(2).replace(/\.?0+$/, '')}%`;
    }
    return kind === 'full' ? full.format(value) : compact.format(value);
  }
}
