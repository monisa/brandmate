import { Pipe, PipeTransform, inject } from '@angular/core';
import { I18n } from './i18n';

/** Translates a text into the chosen language. See I18n for usage. */
@Pipe({ name: 't', pure: false }) // impure: must re-run when the language changes
export class TPipe implements PipeTransform {
  private readonly i18n = inject(I18n);

  transform(text: string | null | undefined, params?: Record<string, string | number | null | undefined>): string {
    return this.i18n.t(text, params);
  }
}
